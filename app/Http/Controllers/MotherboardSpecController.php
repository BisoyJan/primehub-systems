<?php

namespace App\Http\Controllers;

use App\Models\MotherboardSpec;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;
use Illuminate\Http\Request;
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
                // guaranteed arrays:
                'ramSpecs'        => $mb->ramSpecs->toArray(),
                'diskSpecs'       => $mb->diskSpecs->toArray(),
                'processorSpecs'  => $mb->processorSpecs->toArray(),
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
            'ram_spec_ids'         => 'array',
            'ram_spec_ids.*'       => 'exists:ram_specs,id',
            'disk_spec_ids'        => 'array',
            'disk_spec_ids.*'      => 'exists:disk_specs,id',
            'processor_spec_ids'   => 'array',
            'processor_spec_ids.*' => 'exists:processor_specs,id',
        ]);

        // ✅ Validate RAM stock and slots
        $totalSticks = 0;
        foreach ($request->ram_specs ?? [] as $ramId => $qty) {
            $ram = RamSpec::with('stock')->find($ramId);
            if (!$ram || !$ram->stock || $ram->stock->quantity < $qty) {
                return back()->withErrors([
                    'ram_specs' => "{$ram->model} does not have enough stock.",
                ])->withInput();
            }
            $totalSticks += $qty;
        }

        if ($totalSticks > $request->ram_slots) {
            return back()->withErrors([
                'ram_specs' => "You selected {$totalSticks} RAM sticks but this motherboard only has {$request->ram_slots} slots.",
            ])->withInput();
        }

        // ✅ Validate disks
        foreach ($request->disk_spec_ids ?? [] as $diskId) {
            $disk = DiskSpec::with('stock')->find($diskId);
            if (!$disk || !$disk->stock || $disk->stock->quantity < 1) {
                return back()->withErrors([
                    'disk_spec_ids' => "Disk {$disk->model_number} is out of stock.",
                ])->withInput();
            }
        }

        // ✅ Validate processors
        foreach ($request->processor_spec_ids ?? [] as $cpuId) {
            $cpu = ProcessorSpec::with('stock')->find($cpuId);
            if (!$cpu || !$cpu->stock || $cpu->stock->quantity < 1) {
                return back()->withErrors([
                    'processor_spec_ids' => "Processor {$cpu->model} is out of stock.",
                ])->withInput();
            }
        }

        $mb = MotherboardSpec::create($data);

        // attach RAM with quantities
        $syncData = collect($request->ram_specs)->mapWithKeys(fn($qty, $ramId) => [
            $ramId => ['quantity' => $qty]
        ]);
        $mb->ramSpecs()->sync($syncData);

        // decrement RAM stock
        foreach ($request->ram_specs ?? [] as $ramId => $qty) {
            RamSpec::find($ramId)->stock->decrement('quantity', $qty);
        }

        // attach disks and processors
        $mb->diskSpecs()->sync($data['disk_spec_ids'] ?? []);
        $mb->processorSpecs()->sync($data['processor_spec_ids'] ?? []);

        // decrement disk stock
        foreach ($request->disk_spec_ids ?? [] as $diskId) {
            DiskSpec::find($diskId)->stock->decrement('quantity');
        }

        // decrement processor stock
        foreach ($request->processor_spec_ids ?? [] as $cpuId) {
            ProcessorSpec::find($cpuId)->stock->decrement('quantity');
        }

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
                'id'                 => $motherboard->id,
                'brand'              => $motherboard->brand,
                'model'              => $motherboard->model,
                'chipset'            => $motherboard->chipset,
                'form_factor'        => $motherboard->form_factor,
                'socket_type'        => $motherboard->socket_type,
                'memory_type'        => $motherboard->memory_type,
                'ram_slots'          => $motherboard->ram_slots,
                'max_ram_capacity_gb' => $motherboard->max_ram_capacity_gb,
                'max_ram_speed'      => $motherboard->max_ram_speed,
                'pcie_slots'         => $motherboard->pcie_slots,
                'm2_slots'           => $motherboard->m2_slots,
                'sata_ports'         => $motherboard->sata_ports,
                'usb_ports'          => $motherboard->usb_ports,
                'ethernet_speed'     => $motherboard->ethernet_speed,
                'wifi'               => $motherboard->wifi,
                'ramSpecs'           => $motherboard->ramSpecs()->pluck('id'),
                'diskSpecs'          => $motherboard->diskSpecs()->pluck('id'),
                'processorSpecs'     => $motherboard->processorSpecs()->pluck('id'),
            ],
            'ramOptions' => RamSpec::all()
                ->map(fn($r) => [
                    'id'    => $r->id,
                    'label' => "{$r->manufacturer} {$r->model} {$r->capacity_gb}GB",
                    'type'  => $r->type,
                    'stock_quantity' => $r->stock?->quantity ?? 0,
                ]),
            'diskOptions' => DiskSpec::all()
                ->map(fn($d) => [
                    'id'    => $d->id,
                    'label' => "{$d->manufacturer} {$d->model_number} {$d->capacity_gb}GB",
                    'stock_quantity' => $d->stock?->quantity ?? 0,
                ]),
            'processorOptions' => ProcessorSpec::all()
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
            'ram_spec_ids'         => 'array',
            'ram_spec_ids.*'       => 'exists:ram_specs,id',
            'disk_spec_ids'        => 'array',
            'disk_spec_ids.*'      => 'exists:disk_specs,id',
            'processor_spec_ids'   => 'array',
            'processor_spec_ids.*' => 'exists:processor_specs,id',
        ]);

        $motherboard->update($data);

        $motherboard->ramSpecs()->sync($data['ram_spec_ids'] ?? []);
        $motherboard->diskSpecs()->sync($data['disk_spec_ids'] ?? []);
        $motherboard->processorSpecs()->sync($data['processor_spec_ids'] ?? []);

        return redirect()->route('motherboards.index')
            ->with('message', 'Motherboard updated')
            ->with('type', 'success');
    }

    /**
     * DELETE /motherboards/{motherboard}
     */
    public function destroy(MotherboardSpec $motherboard)
    {
        foreach ($motherboard->ramSpecs as $ram) {
            if ($ram->stock) {
                $ram->stock->increment('quantity', $ram->pivot->quantity);
            }
        }

        foreach ($motherboard->diskSpecs as $disk) {
            if ($disk->stock) {
                $disk->stock->increment('quantity');
            }
        }

        foreach ($motherboard->processorSpecs as $cpu) {
            if ($cpu->stock) {
                $cpu->stock->increment('quantity');
            }
        }

        $motherboard->ramSpecs()->detach();
        $motherboard->diskSpecs()->detach();
        $motherboard->processorSpecs()->detach();
        $motherboard->delete();

        return redirect()->route('motherboards.index')
            ->with('message', 'Motherboard deleted and stocks restored')
            ->with('type', 'success');
    }
}
