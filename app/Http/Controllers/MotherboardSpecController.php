<?php

namespace App\Http\Controllers;

use App\Models\MotherboardSpec;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Validation\ValidationException;


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
                // include ramSpecs as full objects expected by frontend (with optional pivot.quantity)
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
                    'stock_quantity' => $r->stock?->quantity ?? 0,
                ]),

            'diskOptions' => DiskSpec::with('stock')->get()
                ->map(fn($d) => [
                    'id'             => $d->id,
                    'label'          => "{$d->manufacturer} {$d->model_number} {$d->capacity_gb}GB",
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
     * Attach with stock checks and decrements (for create)
     */
    protected function createAndAttachWithStock(MotherboardSpec $mb, array $ramSpecs, array $diskSpecs = [])
    {
        // check availability first
        foreach ($ramSpecs as $id => $qty) {
            $ram = RamSpec::with('stock')->find($id);
            if (! $ram || ! $ram->stock || $ram->stock->quantity < $qty) {
                abort(422, "Not enough stock for RAM #{$id}");
            }
        }

        foreach ($diskSpecs as $id => $qty) {
            $disk = DiskSpec::with('stock')->find($id);
            if (! $disk || ! $disk->stock || $disk->stock->quantity < $qty) {
                abort(422, "Not enough stock for Disk #{$id}");
            }
        }

        // attach ram with pivot quantities and decrement stock
        $mb->ramSpecs()->sync(collect($ramSpecs)->mapWithKeys(fn($q, $id) => [$id => ['quantity' => $q]])->toArray());
        foreach ($ramSpecs as $id => $qty) {
            RamSpec::find($id)->stock->decrement('quantity', $qty);
        }

        // attach disks (if you track quantities on disk pivot, adapt similarly)
        $mb->diskSpecs()->sync(array_keys($diskSpecs));
        foreach ($diskSpecs as $id => $qty) {
            DiskSpec::find($id)->stock->decrement('quantity', $qty);
        }
    }

    /**
     * Update existing motherboard with stock diffs (for update)
     */
    protected function updateWithStockDiffs(MotherboardSpec $mb, array $newRamSpecs, array $newDiskSpecs = [])
    {
        $existing = $mb->ramSpecs()->pluck('motherboard_spec_ram_spec.quantity', 'ram_spec_id')
            ->mapWithKeys(fn($v, $k) => [(int)$k => (int)$v])
            ->toArray();

        // restore stock where decreased or removed
        foreach ($existing as $id => $oldQty) {
            $newQty = $newRamSpecs[$id] ?? 0;
            if ($newQty < $oldQty) {
                RamSpec::find($id)->stock->increment('quantity', $oldQty - $newQty);
            }
        }

        // ensure availability for increases/new additions and decrement
        foreach ($newRamSpecs as $id => $qty) {
            $oldQty = $existing[$id] ?? 0;
            if ($qty > $oldQty) {
                $ram = RamSpec::with('stock')->find($id);
                if (! $ram || ! $ram->stock || $ram->stock->quantity < ($qty - $oldQty)) {
                    abort(422, "Not enough stock for RAM #{$id}");
                }
                $ram->stock->decrement('quantity', $qty - $oldQty);
            }
        }

        // sync pivot
        $mb->ramSpecs()->sync(collect($newRamSpecs)->mapWithKeys(fn($q, $id) => [$id => ['quantity' => $q]])->toArray());

        // Disk handling (presence-based): restore and decrement by presence difference
        $existingDisks = $mb->diskSpecs()->pluck('id')->map(fn($v) => (int)$v)->toArray();
        $toRemove = array_diff($existingDisks, array_keys($newDiskSpecs));
        $toAdd = array_diff(array_keys($newDiskSpecs), $existingDisks);

        foreach ($toRemove as $id) {
            DiskSpec::find($id)->stock->increment('quantity', 1);
        }
        foreach ($toAdd as $id) {
            $disk = DiskSpec::with('stock')->find($id);
            if (! $disk || ! $disk->stock || $disk->stock->quantity < 1) {
                abort(422, "Not enough stock for Disk #{$id}");
            }
            $disk->stock->decrement('quantity', 1);
        }

        $mb->diskSpecs()->sync(array_keys($newDiskSpecs));
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
        if ($totalRamSticks > (int)$request->input('ram_slots')) {
            return back()->withErrors([
                'ram_specs' => "Total RAM sticks ({$totalRamSticks}) exceeds available ram_slots ({$request->ram_slots}).",
            ])->withInput();
        }

        /* inside store() after $ramSpecs computed */
        $totalCapacityGb = $this->computeTotalRamCapacity($ramSpecs);
        $maxCapacityGb = (int) ($request->input('max_ram_capacity_gb') ?? 0);
        if ($maxCapacityGb > 0 && $totalCapacityGb > $maxCapacityGb) {
            throw ValidationException::withMessages([
                'ram_specs' => "Selected modules total {$totalCapacityGb} GB which exceeds motherboard max capacity of {$maxCapacityGb} GB."
            ]);
        }

        DB::beginTransaction();
        try {
            $mb = MotherboardSpec::create($data);

            $this->createAndAttachWithStock($mb, $ramSpecs, $diskSpecs);

            // processors
            $mb->processorSpecs()->sync($data['processor_spec_ids'] ?? []);
            foreach ($data['processor_spec_ids'] ?? [] as $cpuId) {
                $cpu = ProcessorSpec::find($cpuId);
                if ($cpu && $cpu->stock) {
                    $cpu->stock->decrement('quantity', 1);
                }
            }

            DB::commit();

            return redirect()->route('motherboards.index')
                ->with('message', 'Motherboard created')
                ->with('type', 'success');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
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
                'ramSpecs'            => $motherboard->ramSpecs()->pluck('id'),
                'diskSpecs'           => $motherboard->diskSpecs()->pluck('id'),
                'processorSpecs'      => $motherboard->processorSpecs()->pluck('id'),
            ],
            'ramOptions' => RamSpec::with('stock')->get()
                ->map(fn($r) => [
                    'id'    => $r->id,
                    'label' => "{$r->manufacturer} {$r->model} {$r->capacity_gb}GB",
                    'type'  => $r->type,
                    'stock_quantity' => $r->stock?->quantity ?? 0,
                ]),
            'diskOptions' => DiskSpec::with('stock')->get()
                ->map(fn($d) => [
                    'id'    => $d->id,
                    'label' => "{$d->manufacturer} {$d->model_number} {$d->capacity_gb}GB",
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
        if ($totalRamSticks > (int)$request->input('ram_slots')) {
            return back()->withErrors([
                'error' => "Total RAM sticks ({$totalRamSticks}) exceeds available ram_slots ({$request->ram_slots}).",
            ])->withInput();
        }

        /* inside update() after $newRamSpecs computed */
        $totalCapacityGb = $this->computeTotalRamCapacity($newRamSpecs);
        $maxCapacityGb = (int) ($request->input('max_ram_capacity_gb') ?? $motherboard->max_ram_capacity_gb ?? 0);
        if ($maxCapacityGb > 0 && $totalCapacityGb > $maxCapacityGb) {
            throw ValidationException::withMessages([
                'ram_specs' => "Selected modules total {$totalCapacityGb} GB which exceeds motherboard max capacity of {$maxCapacityGb} GB."
            ]);
        }

        DB::beginTransaction();
        try {
            $motherboard->update($data);

            $this->updateWithStockDiffs($motherboard, $newRamSpecs, $newDiskSpecs);

            // processors: handle presence diffs
            $existingProcs = $motherboard->processorSpecs()->pluck('id')->map(fn($v) => (int)$v)->toArray();
            $toRemoveProcs = array_diff($existingProcs, $data['processor_spec_ids'] ?? []);
            $toAddProcs = array_diff($data['processor_spec_ids'] ?? [], $existingProcs);

            foreach ($toRemoveProcs as $id) {
                $cpu = ProcessorSpec::find($id);
                if ($cpu && $cpu->stock) {
                    $cpu->stock->increment('quantity', 1);
                }
            }
            foreach ($toAddProcs as $id) {
                $cpu = ProcessorSpec::with('stock')->find($id);
                if (! $cpu || ! $cpu->stock || $cpu->stock->quantity < 1) {
                    abort(422, "Not enough stock for Processor #{$id}");
                }
                $cpu->stock->decrement('quantity', 1);
            }
            $motherboard->processorSpecs()->sync($data['processor_spec_ids'] ?? []);

            DB::commit();

            return redirect()->route('motherboards.index')
                ->with('message', 'Motherboard updated')
                ->with('type', 'success');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
    }

    /**
     * DELETE /motherboards/{motherboard}
     */
    public function destroy(MotherboardSpec $motherboard)
    {
        DB::transaction(function () use ($motherboard) {
            foreach ($motherboard->ramSpecs as $ram) {
                if ($ram->stock) {
                    $ram->stock->increment('quantity', $ram->pivot->quantity ?? 1);
                }
            }

            foreach ($motherboard->diskSpecs as $disk) {
                if ($disk->stock) {
                    $disk->stock->increment('quantity', 1);
                }
            }

            foreach ($motherboard->processorSpecs as $cpu) {
                if ($cpu->stock) {
                    $cpu->stock->increment('quantity', 1);
                }
            }

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
