<?php

namespace App\Services;

use App\Models\PcSpec;
use App\Models\PcTransfer;
use App\Models\Station;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PcTransferService
{
    /**
     * Execute a batch of PC transfers atomically.
     *
     * Source stations and displaced PCs are snapshotted before any mutation so
     * history records keep the true origin even for chained moves in one batch.
     *
     * @param  array<int, array{to_station_id: int|string, pc_spec_id: int|string, transfer_type?: string|null}>  $transfers
     * @return array{count: int, floating_pcs: array<int, array{id: int, pc_number: string|null, from_station: string}>}
     *
     * @throws ValidationException
     */
    public function bulkTransfer(array $transfers, ?string $notes, int $userId): array
    {
        return DB::transaction(function () use ($transfers, $notes, $userId) {
            $pcIds = collect($transfers)->pluck('pc_spec_id')->map(fn ($id) => (int) $id);
            $targetIds = collect($transfers)->pluck('to_station_id')->map(fn ($id) => (int) $id);

            $targets = Station::query()->whereIn('id', $targetIds)->lockForUpdate()->get()->keyBy('id');
            $sources = Station::query()->whereIn('pc_spec_id', $pcIds)->lockForUpdate()->get()->groupBy('pc_spec_id');
            $pcs = PcSpec::query()
                ->whereIn('id', $pcIds->merge($targets->pluck('pc_spec_id')->filter())->unique())
                ->get()
                ->keyBy('id');

            $steps = $this->buildPlan($transfers, $targets, $sources, $pcs, $pcIds, $targetIds);

            $floatingPcs = [];

            foreach ($steps as $step) {
                $this->detachPcFromAllStations($step['pc_id']);

                $target = $step['target'];
                $target->pc_spec_id = $step['pc_id'];
                $target->save();

                PcTransfer::create([
                    'from_station_id' => $step['source']?->id,
                    'to_station_id' => $target->id,
                    'pc_spec_id' => $step['pc_id'],
                    'user_id' => $userId,
                    'transfer_type' => $step['is_swap'] ? 'swap' : 'assign',
                    'notes' => $notes,
                ]);

                if ($step['replaced_pc_id'] === null) {
                    continue;
                }

                if ($step['is_swap']) {
                    $source = $step['source'];
                    $source->pc_spec_id = $step['replaced_pc_id'];
                    $source->save();

                    PcTransfer::create([
                        'from_station_id' => $target->id,
                        'to_station_id' => $source->id,
                        'pc_spec_id' => $step['replaced_pc_id'],
                        'user_id' => $userId,
                        'transfer_type' => 'swap',
                        'notes' => $notes,
                    ]);
                } elseif (! $pcIds->contains($step['replaced_pc_id'])) {
                    $replaced = $pcs->get($step['replaced_pc_id']);
                    $floatingPcs[] = [
                        'id' => $step['replaced_pc_id'],
                        'pc_number' => $replaced?->pc_number,
                        'from_station' => $target->station_number,
                    ];
                }
            }

            return [
                'count' => count($steps),
                'floating_pcs' => $floatingPcs,
            ];
        });
    }

    /**
     * Unassign the PC currently at the given station.
     */
    public function remove(Station $station, ?string $notes, int $userId): void
    {
        if (! $station->pc_spec_id) {
            throw ValidationException::withMessages([
                'station_id' => ['Station has no PC assigned.'],
            ]);
        }

        DB::transaction(function () use ($station, $notes, $userId) {
            $pcSpecId = $station->pc_spec_id;
            $station->pc_spec_id = null;
            $station->save();

            PcTransfer::create([
                'from_station_id' => $station->id,
                'to_station_id' => $station->id,
                'pc_spec_id' => $pcSpecId,
                'user_id' => $userId,
                'transfer_type' => 'remove',
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Build a human readable flash message for a completed batch.
     *
     * @param  array{count: int, floating_pcs: array<int, array{id: int, pc_number: string|null, from_station: string}>}  $result
     */
    public function describeResult(array $result): string
    {
        $message = $result['count'].' PC(s) transferred successfully';

        if ($result['floating_pcs'] !== []) {
            $floating = collect($result['floating_pcs'])
                ->map(fn (array $pc) => ($pc['pc_number'] ?? '#'.$pc['id']).' (from '.$pc['from_station'].')')
                ->join(', ');

            $message .= '. Now unassigned: '.$floating;
        }

        return $message;
    }

    /**
     * Validate the batch against the snapshot and return ordered execution steps.
     *
     * @param  array<int, array{to_station_id: int|string, pc_spec_id: int|string, transfer_type?: string|null}>  $transfers
     * @param  Collection<int, Station>  $targets
     * @param  Collection<int, Collection<int, Station>>  $sources
     * @param  Collection<int, PcSpec>  $pcs
     * @param  Collection<int, int>  $pcIds
     * @param  Collection<int, int>  $targetIds
     * @return array<int, array{pc_id: int, target: Station, source: Station|null, replaced_pc_id: int|null, is_swap: bool}>
     *
     * @throws ValidationException
     */
    protected function buildPlan(
        array $transfers,
        Collection $targets,
        Collection $sources,
        Collection $pcs,
        Collection $pcIds,
        Collection $targetIds,
    ): array {
        $errors = [];
        $seenPcs = [];
        $seenStations = [];
        $steps = [];

        foreach (array_values($transfers) as $index => $transfer) {
            $n = $index + 1;
            $pcId = (int) $transfer['pc_spec_id'];
            $stationId = (int) $transfer['to_station_id'];
            $requestedType = $transfer['transfer_type'] ?? 'assign';

            /** @var Station|null $target */
            $target = $targets->get($stationId);
            /** @var Station|null $source */
            $source = $sources->get($pcId)?->first();
            $pcLabel = $pcs->get($pcId)?->pc_number ?? "#{$pcId}";
            $stationLabel = $target?->station_number ?? "#{$stationId}";

            if (isset($seenPcs[$pcId])) {
                $errors[] = "Transfer #{$n}: PC {$pcLabel} is selected more than once.";
            }

            if (isset($seenStations[$stationId])) {
                $errors[] = "Transfer #{$n}: Station {$stationLabel} receives more than one PC.";
            }

            $seenPcs[$pcId] = true;
            $seenStations[$stationId] = true;

            if ($target === null) {
                $errors[] = "Transfer #{$n}: Station {$stationLabel} no longer exists.";

                continue;
            }

            if ($source !== null && $source->id === $stationId) {
                $errors[] = "Transfer #{$n}: PC {$pcLabel} is already at station {$stationLabel}.";
            }

            $replacedPcId = $target->pc_spec_id ? (int) $target->pc_spec_id : null;
            $isSwap = $requestedType === 'swap' && $replacedPcId !== null && $source !== null;

            if ($isSwap) {
                $replacedLabel = $pcs->get($replacedPcId)?->pc_number ?? "#{$replacedPcId}";

                if ($pcIds->contains($replacedPcId)) {
                    $errors[] = "Transfer #{$n}: cannot swap, PC {$replacedLabel} is also being transferred in this batch.";
                }

                if ($targetIds->contains($source->id)) {
                    $errors[] = "Transfer #{$n}: cannot swap, station {$source->station_number} is also a destination in this batch.";
                }
            }

            $steps[] = [
                'pc_id' => $pcId,
                'target' => $target,
                'source' => $source,
                'replaced_pc_id' => $replacedPcId,
                'is_swap' => $isSwap,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['transfers' => $errors]);
        }

        return $steps;
    }

    /**
     * Clear the PC from every station currently holding it, logging each change.
     */
    protected function detachPcFromAllStations(int $pcId): void
    {
        Station::query()->where('pc_spec_id', $pcId)->get()->each(function (Station $station) {
            $station->pc_spec_id = null;
            $station->save();
        });
    }
}
