<?php

namespace App\Http\Controllers;

use App\Models\MotherboardSpec;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MotherboardSpecController extends Controller
{
    /**
     * GET /motherboards
     */
    public function index()
    {
        $motherboards = MotherboardSpec::with(['ramSpecs', 'diskSpecs', 'processorSpecs'])
            ->orderBy('id', 'desc')
            ->paginate(10)
            ->through(fn($mb) => [
                'id'              => $mb->id,
                'brand'           => $mb->brand,
                'model'           => $mb->model,
                'chipset'         => $mb->chipset,
                'memory_type'     => $mb->memory_type,
                'form_factor'     => $mb->form_factor,
                'socket_type'     => $mb->socket_type,
                'ramSpecs'        => $mb->ramSpecs->map(fn($r) => [
                    'id'           => $r->id,
                    'manufacturer' => $r->manufacturer,
                    'model'        => $r->model,
                    'capacity_gb'  => $r->capacity_gb,
                    'type'         => $r->type,
                    'speed'        => $r->speed ?? null,
                    'quantity'     => $r->pivot->quantity ?? null,
                ])->toArray(),
                'diskSpecs'       => $mb->diskSpecs->map(fn($d) => [
                    'id'                   => $d->id,
                    'manufacturer'         => $d->manufacturer,
                    'model_number'         => $d->model_number,
                    'capacity_gb'          => $d->capacity_gb,
                    'drive_type'           => $d->drive_type ?? null,
                    'interface'            => $d->interface ?? null,
                    'sequential_read_mb'   => $d->sequential_read_mb ?? null,
                    'sequential_write_mb'  => $d->sequential_write_mb ?? null,
                ])->toArray(),
                'processorSpecs'  => $mb->processorSpecs->map(fn($p) => [
                    'id'             => $p->id,
                    'brand'          => $p->brand,
                    'series'         => $p->series,
                    'socket_type'    => $p->socket_type,
                    'core_count'     => $p->core_count ?? null,
                    'thread_count'   => $p->thread_count ?? null,
                    'base_clock_ghz' => $p->base_clock_ghz ?? null,
                    'boost_clock_ghz' => $p->boost_clock_ghz ?? null,
                    'tdp_watts'      => $p->tdp_watts ?? null,
                ])->toArray(),
            ]);

        return Inertia::render('Motherboards/Index', [
            'motherboards' => $motherboards,
        ]);
    }

    /**
     * GET /motherboards/create
     */
    public function create()
    {
        return Inertia::render('Motherboards/Create', [
            'ramOptions' => RamSpec::with('stock')->get()
                ->map(fn($r) => [
                    'id'             => $r->id,
                    'label'          => "{$r->manufacturer} {$r->model} {$r->capacity_gb}GB",
                    'type'           => $r->type,
                    'capacity_gb'    => $r->capacity_gb, // <-- add this
                    'stock_quantity' => $r->stock?->quantity ?? 0,
                ]),

            'diskOptions' => DiskSpec::with('stock')->get()
                ->map(fn($d) => [
                    'id'             => $d->id,
                    'label'          => "{$d->manufacturer} {$d->model_number} - {$d->interface} {$d->capacity_gb}GB",
                    'stock_quantity' => $d->stock?->quantity ?? 0,
                ]),

            'processorOptions' => ProcessorSpec::with('stock')->get()
                ->map(fn($p) => [
                    'id'             => $p->id,
                    'label'          => "{$p->brand} {$p->series} {$p->model_number}",
                    'socket_type'    => $p->socket_type,
                    'stock_quantity' => $p->stock?->quantity ?? 0,
                ]),
        ]);
    }

    /**
     * Normalize and filter specs payload into [id => qty]
     */
    protected function validateAndNormalizeSpecs(array $specs): array
    {
        return collect($specs)
            ->mapWithKeys(fn($qty, $id) => [(int)$id => (int)$qty])
            ->filter(fn($qty) => $qty > 0)
            ->toArray();
    }

    /**
     * Compute total installed RAM capacity in GB from ramSpecs map [id => qty]
     */
    protected function computeTotalRamCapacity(array $ramSpecs): int
    {
        $totalGb = 0;
        foreach ($ramSpecs as $id => $qty) {
            $ram = RamSpec::find($id);
            if (! $ram) continue;
            $totalGb += ($ram->capacity_gb ?? 0) * (int)$qty;
        }
        return (int)$totalGb;
    }

    /**
     * Reserve and decrement stock for given items inside a transaction-safe lock.
     * items: [specId => qty]
     * modelClass: RamSpec::class | DiskSpec::class | ProcessorSpec::class
     */
    protected function reserveAndDecrement(array $items, string $modelClass, string $label): void
    {
        $items = collect($items)->mapWithKeys(fn($q, $id) => [(int)$id => (int)$q])->filter()->toArray();
        if (empty($items)) return;

        foreach ($items as $id => $qty) {
            $spec = $modelClass::with('stock')->lockForUpdate()->find($id);
            if (! $spec || ! $spec->stock) {
                throw ValidationException::withMessages([$label => "{$label} #{$id} not found or missing stock"]);
            }
            if ($spec->stock->quantity < $qty) {
                throw ValidationException::withMessages([$label => "Not enough stock for {$label} '{$id}' (requested {$qty}, available {$spec->stock->quantity})"]);
            }
            $spec->stock->decrement('quantity', $qty);
        }
    }

    /**
     * Update stock diffs for RAM (increment where removed, decrement where added).
     * newItems: [id => qty]
     */
    protected function applyRamStockDiffs(MotherboardSpec $mb, array $newItems): void
    {
        // existing pivot quantities keyed by ram_spec_id => qty
        $existing = $mb->ramSpecs()->pluck('motherboard_spec_ram_spec.quantity', 'ram_spec_id')
            ->mapWithKeys(fn($v, $k) => [(int)$k => (int)$v])
            ->toArray();

        // restore stock for decreases / removals
        foreach ($existing as $id => $oldQty) {
            $newQty = $newItems[$id] ?? 0;
            if ($newQty < $oldQty) {
                $ram = RamSpec::with('stock')->lockForUpdate()->find($id);
                if ($ram && $ram->stock) {
                    $ram->stock->increment('quantity', $oldQty - $newQty);
                }
            }
        }

        // reserve and decrement for increases / new
        foreach ($newItems as $id => $qty) {
            $oldQty = $existing[$id] ?? 0;
            if ($qty > $oldQty) {
                $ram = RamSpec::with('stock')->lockForUpdate()->find($id);
                if (! $ram || ! $ram->stock) {
                    throw ValidationException::withMessages(['RAM' => "RAM #{$id} not found or missing stock"]);
                }
                $needed = $qty - $oldQty;
                if ($ram->stock->quantity < $needed) {
                    throw ValidationException::withMessages(['RAM' => "Not enough stock for RAM '{$id}' (needed {$needed}, available {$ram->stock->quantity})"]);
                }
                $ram->stock->decrement('quantity', $needed);
            }
        }

        // sync pivot with quantities (performed by caller)
    }

    /**
     * Apply disk presence diffs (presence-based disks). If disk pivot tracks quantity, adapt accordingly.
     * newItems: [id => qty] (qty used if pivot quantity supported)
     */
    protected function applyDiskStockDiffs(MotherboardSpec $mb, array $newItems): void
    {
        $existingIds = $mb->diskSpecs()->pluck('id')->map(fn($v) => (int)$v)->toArray();
        $newIds = array_map('intval', array_keys($newItems));

        $toRemove = array_diff($existingIds, $newIds);
        $toAdd = array_diff($newIds, $existingIds);

        foreach ($toRemove as $id) {
            $disk = DiskSpec::with('stock')->lockForUpdate()->find($id);
            if ($disk && $disk->stock) {
                $disk->stock->increment('quantity', 1);
            }
        }
        foreach ($toAdd as $id) {
            $disk = DiskSpec::with('stock')->lockForUpdate()->find($id);
            if (! $disk || ! $disk->stock) {
                throw ValidationException::withMessages(['Disk' => "Disk #{$id} not found or missing stock"]);
            }
            if ($disk->stock->quantity < 1) {
                throw ValidationException::withMessages(['Disk' => "Not enough stock for Disk '{$id}'"]);
            }
            $disk->stock->decrement('quantity', 1);
        }
    }

    /**
     * Apply processor presence diffs (presence-based).
     * newIds: array of processor ids
     */
    protected function applyProcessorStockDiffs(MotherboardSpec $mb, array $newIds): void
    {
        $existing = $mb->processorSpecs()->pluck('id')->map(fn($v) => (int)$v)->toArray();
        $toRemove = array_diff($existing, $newIds);
        $toAdd = array_diff($newIds, $existing);

        foreach ($toRemove as $id) {
            $cpu = ProcessorSpec::with('stock')->lockForUpdate()->find($id);
            if ($cpu && $cpu->stock) {
                $cpu->stock->increment('quantity', 1);
            }
        }

        foreach ($toAdd as $id) {
            $cpu = ProcessorSpec::with('stock')->lockForUpdate()->find($id);
            if (! $cpu || ! $cpu->stock) {
                throw ValidationException::withMessages(['Processor' => "Processor #{$id} not found or missing stock"]);
            }
            if ($cpu->stock->quantity < 1) {
                throw ValidationException::withMessages(['Processor' => "Not enough stock for Processor '{$id}'"]);
            }
            $cpu->stock->decrement('quantity', 1);
        }
    }

    /**
     * POST /motherboards
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'brand'                => 'required|string|max:255',
            'model'                => 'required|string|max:255',
            'chipset'              => 'required|string|max:255',
            'form_factor'          => 'required|string|max:50',
            'socket_type'          => 'required|string|max:50',
            'memory_type'          => 'required|string|max:10',
            'ram_slots'            => 'required|integer|min:1',
            'max_ram_capacity_gb'  => 'required|integer|min:1',
            'max_ram_speed'        => 'required|string|max:50',
            'pcie_slots'           => 'required|string|max:100',
            'm2_slots'             => 'required|integer|min:0',
            'sata_ports'           => 'required|integer|min:0',
            'usb_ports'            => 'required|string|max:100',
            'ethernet_speed'       => 'required|string|max:50',
            'wifi'                 => 'boolean',
            'ram_mode'             => 'nullable|in:same,different',
            'ram_specs'            => 'array',
            'disk_mode'            => 'nullable|in:same,different',
            'disk_specs'           => 'array',
            'processor_spec_ids'   => 'array',
            'processor_spec_ids.*' => 'exists:processor_specs,id',
        ]);

        $ramSpecs = $this->validateAndNormalizeSpecs($request->input('ram_specs', []));
        $diskSpecs = $this->validateAndNormalizeSpecs($request->input('disk_specs', []));

        // allow unused slots: total sticks must be <= ram_slots
        $totalRamSticks = array_sum($ramSpecs);
        $ramSlots = (int) $request->input('ram_slots');
        if ($totalRamSticks > $ramSlots) {
            throw ValidationException::withMessages([
                'ram_specs' => "You selected {$totalRamSticks} RAM sticks, but the motherboard only supports {$ramSlots} slots.",
            ]);
        }


        // capacity check: ensure total installed capacity does not exceed motherboard max
        $totalCapacityGb = $this->computeTotalRamCapacity($ramSpecs);
        $maxCapacityGb = (int) ($request->input('max_ram_capacity_gb') ?? 0);
        if ($maxCapacityGb > 0 && $totalCapacityGb > $maxCapacityGb) {
            throw ValidationException::withMessages([
                'ram_specs' => "Selected modules total {$totalCapacityGb} GB which exceeds motherboard max capacity of {$maxCapacityGb} GB.",
            ]);
        }

        // Use transaction with retry attempts to reduce deadlock risk
        DB::transaction(function () use ($data, $ramSpecs, $diskSpecs) {
            $mb = MotherboardSpec::create($data);

            // reserve/decrement stock for RAM and disks and processors inside transaction with locks
            $this->reserveAndDecrement($ramSpecs, RamSpec::class, 'RAM');
            $this->reserveAndDecrement($diskSpecs, DiskSpec::class, 'Disk');

            // attach ram with pivot quantities and disk presence (adapt if disk pivot has quantities)
            $mb->ramSpecs()->sync(collect($ramSpecs)->mapWithKeys(fn($q, $id) => [$id => ['quantity' => $q]])->toArray());
            $mb->diskSpecs()->sync(array_keys($diskSpecs));

            // processors (presence-based)
            $procIds = array_map('intval', $data['processor_spec_ids'] ?? []);
            $this->reserveAndDecrement(array_fill_keys($procIds, 1), ProcessorSpec::class, 'Processor');
            $mb->processorSpecs()->sync($procIds);
        }, 3);

        return redirect()->route('motherboards.index')
            ->with('message', 'Motherboard created')
            ->with('type', 'success');
    }

    /**
     * GET /motherboards/{motherboard}/edit
     */
    public function edit(MotherboardSpec $motherboard)
    {
        return Inertia::render('Motherboards/Edit', [
            'motherboard' => [
                'id'                  => $motherboard->id,
                'brand'               => $motherboard->brand,
                'model'               => $motherboard->model,
                'chipset'             => $motherboard->chipset,
                'form_factor'         => $motherboard->form_factor,
                'socket_type'         => $motherboard->socket_type,
                'memory_type'         => $motherboard->memory_type,
                'ram_slots'           => $motherboard->ram_slots,
                'max_ram_capacity_gb' => $motherboard->max_ram_capacity_gb,
                'max_ram_speed'       => $motherboard->max_ram_speed,
                'pcie_slots'          => $motherboard->pcie_slots,
                'm2_slots'            => $motherboard->m2_slots,
                'sata_ports'          => $motherboard->sata_ports,
                'usb_ports'           => $motherboard->usb_ports,
                'ethernet_speed'      => $motherboard->ethernet_speed,
                'wifi'                => $motherboard->wifi,
                // return pivot-aware ramSpecs as array of {id, pivot: {quantity}}
                'ramSpecs'            => $motherboard->ramSpecs()->get()->map(fn($r) => [
                    'id' => $r->id,
                    'pivot' => ['quantity' => $r->pivot->quantity ?? 1],
                ])->toArray(),
                'diskSpecs'           => $motherboard->diskSpecs()->pluck('id'),
                'processorSpecs'      => $motherboard->processorSpecs()->pluck('id'),
            ],
            'ramOptions' => RamSpec::with('stock')->get()
                ->map(fn($r) => [
                    'id'             => $r->id,
                    'label'          => "{$r->manufacturer} {$r->model} {$r->capacity_gb}GB",
                    'type'           => $r->type,
                    'capacity_gb'    => $r->capacity_gb, // <-- add this
                    'stock_quantity' => $r->stock?->quantity ?? 0,
                ]),
            'diskOptions' => DiskSpec::with('stock')->get()
                ->map(fn($d) => [
                    'id'    => $d->id,
                    'label' => "{$d->manufacturer} {$d->model_number} {$d->interface} {$d->capacity_gb}GB",
                    'stock_quantity' => $d->stock?->quantity ?? 0,
                ]),
            'processorOptions' => ProcessorSpec::with('stock')->get()
                ->map(fn($p) => [
                    'id'          => $p->id,
                    'label'       => "{$p->brand} {$p->series} {$p->model_number}",
                    'socket_type' => $p->socket_type,
                    'stock_quantity' => $p->stock?->quantity ?? 0,
                ]),
        ]);
    }

    /**
     * PUT /motherboards/{motherboard}
     */
    public function update(Request $request, MotherboardSpec $motherboard)
    {
        $data = $request->validate([
            'brand'                => 'required|string|max:255',
            'model'                => 'required|string|max:255',
            'chipset'              => 'required|string|max:255',
            'form_factor'          => 'required|string|max:50',
            'socket_type'          => 'required|string|max:50',
            'memory_type'          => 'required|string|max:10',
            'ram_slots'            => 'required|integer|min:1',
            'max_ram_capacity_gb'  => 'required|integer|min:1',
            'max_ram_speed'        => 'required|string|max:50',
            'pcie_slots'           => 'required|string|max:100',
            'm2_slots'             => 'required|integer|min:0',
            'sata_ports'           => 'required|integer|min:0',
            'usb_ports'            => 'required|string|max:100',
            'ethernet_speed'       => 'required|string|max:50',
            'wifi'                 => 'boolean',
            'ram_mode'             => 'nullable|in:same,different',
            'ram_specs'            => 'array',
            'disk_mode'            => 'nullable|in:same,different',
            'disk_specs'           => 'array',
            'processor_spec_ids'   => 'array',
            'processor_spec_ids.*' => 'exists:processor_specs,id',
        ]);

        $newRamSpecs = $this->validateAndNormalizeSpecs($request->input('ram_specs', []));
        $newDiskSpecs = $this->validateAndNormalizeSpecs($request->input('disk_specs', []));

        // allow unused slots: total sticks must be <= ram_slots
        $totalRamSticks = array_sum($newRamSpecs);
        $ramSlots = (int) $request->input('ram_slots');
        if ($totalRamSticks > $ramSlots) {
            throw ValidationException::withMessages([
                'ram_specs' => "You selected {$totalRamSticks} RAM sticks, but the motherboard only supports {$ramSlots} slots.",
            ]);
        }

        // capacity check: ensure total installed capacity does not exceed motherboard max
        $totalCapacityGb = $this->computeTotalRamCapacity($newRamSpecs);
        $maxCapacityGb = (int) ($request->input('max_ram_capacity_gb') ?? $motherboard->max_ram_capacity_gb ?? 0);
        if ($maxCapacityGb > 0 && $totalCapacityGb > $maxCapacityGb) {
            throw ValidationException::withMessages(['ram_specs' => "Selected modules total {$totalCapacityGb} GB which exceeds motherboard max capacity of {$maxCapacityGb} GB."]);
        }

        // Perform update inside transaction with retry attempts
        DB::transaction(function () use ($motherboard, $data, $newRamSpecs, $newDiskSpecs) {
            // update core attributes
            $motherboard->update($data);

            // apply RAM stock diffs (locks and increments/decrements inside)
            $this->applyRamStockDiffs($motherboard, $newRamSpecs);
            // sync pivot quantities
            $motherboard->ramSpecs()->sync(collect($newRamSpecs)->mapWithKeys(fn($q, $id) => [$id => ['quantity' => $q]])->toArray());

            // apply disk diffs and sync
            $this->applyDiskStockDiffs($motherboard, $newDiskSpecs);
            $motherboard->diskSpecs()->sync(array_keys($newDiskSpecs));

            // processors
            $newProcIds = array_map('intval', $data['processor_spec_ids'] ?? []);
            $this->applyProcessorStockDiffs($motherboard, $newProcIds);
            $motherboard->processorSpecs()->sync($newProcIds);
        }, 3);

        return redirect()->route('motherboards.index')
            ->with('message', 'Motherboard updated')
            ->with('type', 'success');
    }

    /**
     * DELETE /motherboards/{motherboard}
     */
    public function destroy(MotherboardSpec $motherboard)
    {
        DB::transaction(function () use ($motherboard) {
            // restore RAM stocks using pivot quantities (guard nulls)
            foreach ($motherboard->ramSpecs as $ram) {
                if ($ram->stock) {
                    $qty = $ram->pivot->quantity ?? 1;
                    $ram->stock->increment('quantity', $qty);
                }
            }

            // restore disk stocks (presence-based)
            foreach ($motherboard->diskSpecs as $disk) {
                if ($disk->stock) {
                    $disk->stock->increment('quantity', 1);
                }
            }

            // restore processor stocks
            foreach ($motherboard->processorSpecs as $cpu) {
                if ($cpu->stock) {
                    $cpu->stock->increment('quantity', 1);
                }
            }

            // detach relations then delete
            $motherboard->ramSpecs()->detach();
            $motherboard->diskSpecs()->detach();
            $motherboard->processorSpecs()->detach();
            $motherboard->delete();
        });

        return redirect()->route('motherboards.index')
            ->with('message', 'Motherboard deleted and stocks restored')
            ->with('type', 'success');
    }
}
