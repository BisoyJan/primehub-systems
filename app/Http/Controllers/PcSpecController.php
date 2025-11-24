<?php

namespace App\Http\Controllers;

use App\Models\PcSpec;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Inertia\Inertia;
use App\Jobs\GenerateAllPcSpecQRCodesZip;
use App\Jobs\GenerateSelectedPcSpecQRCodesZip;


class PcSpecController extends Controller
{
    /**
     * GET /motherboards
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', PcSpec::class);

        $search = trim((string) $request->input('search', ''));

        $query = PcSpec::with(['ramSpecs', 'diskSpecs', 'processorSpecs'])
            ->orderBy('id', 'desc');

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('manufacturer', 'like', "%{$search}%")
                      ->orWhere('model', 'like', "%{$search}%")
                      ->orWhere('pc_number', 'like', "%{$search}%")
                      // Search by processor model/manufacturer
                      ->orWhereHas('processorSpecs', function ($procQ) use ($search) {
                          $procQ->where('model', 'like', "%{$search}%")
                                ->orWhere('manufacturer', 'like', "%{$search}%");
                      });
                });
            }

        $pcspecs = $query
            ->paginate(10)
            ->appends($request->only('search'))
            ->through(fn($pc) => [
                'id'              => $pc->id,
                'pc_number'       => $pc->pc_number,
                'manufacturer'    => $pc->manufacturer,
                'model'           => $pc->model,
                'memory_type'     => $pc->memory_type,
                'form_factor'     => $pc->form_factor,
                'issue'           => $pc->issue,
                'ramSpecs'        => $pc->ramSpecs->map(fn($r) => [
                    'id'           => $r->id,
                    'manufacturer' => $r->manufacturer,
                    'model'        => $r->model,
                    'capacity_gb'  => $r->capacity_gb,
                    'type'         => $r->type,
                    'speed'        => $r->speed ?? null,
                    'quantity'     => $r->pivot->quantity ?? null,
                ])->toArray(),
                'diskSpecs'        => $pc->diskSpecs->map(fn($d) => [
                    'id'                   => $d->id,
                    'manufacturer'         => $d->manufacturer,
                    'model'                => $d->model,
                    'capacity_gb'          => $d->capacity_gb,
                    'drive_type'           => $d->drive_type ?? null,
                    'interface'            => $d->interface ?? null,
                    'sequential_read_mb'   => $d->sequential_read_mb ?? null,
                    'sequential_write_mb'  => $d->sequential_write_mb ?? null,
                ])->toArray(),
                'processorSpecs'  => $pc->processorSpecs->map(fn($p) => [
                    'id'             => $p->id,
                    'manufacturer'   => $p->manufacturer,
                    'model'          => $p->model,
                    'socket_type'    => $p->socket_type,
                    'core_count'     => $p->core_count ?? null,
                    'thread_count'   => $p->thread_count ?? null,
                    'base_clock_ghz' => $p->base_clock_ghz ?? null,
                    'boost_clock_ghz' => $p->boost_clock_ghz ?? null,
                    'tdp_watts'      => $p->tdp_watts ?? null,
                ])->toArray(),
            ]);

        return Inertia::render('Computer/PcSpecs/Index', [
            'pcspecs' => $pcspecs,
            'search' => $request->input('search', ''),
        ]);
    }

    /**
     * GET /motherboards/create
     */
    public function create()
    {
        return Inertia::render('Computer/PcSpecs/Create', [
            'ramOptions' => RamSpec::with('stock')->get()
                ->map(fn($r) => [
                    'id'             => $r->id,
                    'label'          => "{$r->manufacturer} {$r->model} {$r->capacity_gb}GB",
                    'type'           => $r->type,
                    'capacity_gb'    => $r->capacity_gb,
                    'stock_quantity' => $r->stock?->quantity ?? 0,
                ]),

            'diskOptions' => DiskSpec::with('stock')->get()
                ->map(fn($d) => [
                    'id'             => $d->id,
                    'label'          => "{$d->manufacturer} {$d->model} - {$d->interface} {$d->capacity_gb}GB",
                    'stock_quantity' => $d->stock?->quantity ?? 0,
                ]),

            'processorOptions' => ProcessorSpec::with('stock')->get()
                ->map(fn($p) => [
                    'id'             => $p->id,
                    'label'       => "{$p->manufacturer} {$p->model}",
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
    protected function applyRamStockDiffs(PcSpec $pc, array $newItems): void
    {
        $existing = $pc->ramSpecs()->pluck('pc_spec_ram_spec.quantity', 'ram_spec_id')
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
    protected function applyDiskStockDiffs(PcSpec $pc, array $newItems): void
    {
        $existingIds = $pc->diskSpecs()->pluck('id')->map(fn($v) => (int)$v)->toArray();
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
    protected function applyProcessorStockDiffs(PcSpec $pc, array $newIds): void
    {
        $existing = $pc->processorSpecs()->pluck('id')->map(fn($v) => (int)$v)->toArray();
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
            'pc_number'                   => 'nullable|string|max:100|unique:pc_specs,pc_number',
            'manufacturer'                => 'required|string|max:255',
            'model'                       => 'required|string|max:255',
            'form_factor'                 => 'required|string|max:50',
            'memory_type'                 => 'required|string|max:10',
            'ram_slots'                   => 'required|integer|min:1',
            'max_ram_capacity_gb'         => 'required|integer|min:1',
            'max_ram_speed'               => 'required|string|max:50',
            'm2_slots'                    => 'required|integer|min:0',
            'sata_ports'                  => 'required|integer|min:0',
            'ram_mode'                    => 'nullable|in:same,different',
            'ram_specs'                   => 'array',
            'disk_mode'                   => 'nullable|in:same,different',
            'disk_specs'                  => 'array',
            'processor_spec_id'           => 'required|exists:processor_specs,id', // Changed from processor_spec_ids
            'quantity'                    => 'nullable|integer|min:1|max:100',
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

        // Get quantity (default to 1)
        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        unset($data['quantity']); // Remove from creation data

        // Check if we have enough stock for multiple PCs
        $procId = $data['processor_spec_id']; // Changed from processor_spec_ids array

        // First verify we have enough stock for all components × quantity
        $this->verifyStockForMultiple($ramSpecs, RamSpec::class, 'RAM', $quantity);
        $this->verifyStockForMultiple($diskSpecs, DiskSpec::class, 'Disk', $quantity);
        $this->verifyStockForMultiple([$procId => 1], ProcessorSpec::class, 'Processor', $quantity); // Changed to use single ID

        $pcs = [];

        // Use transaction with retry attempts to reduce deadlock risk
        DB::transaction(function () use ($data, $ramSpecs, $diskSpecs, $procId, $quantity, &$pcs) {
            // Create multiple PCs with the same specifications
            for ($i = 0; $i < $quantity; $i++) {
                $pc = PcSpec::create($data);
                $pcs[] = $pc;

                // Attach RAM, disk, and processors with pivot data
                $pc->ramSpecs()->sync(collect($ramSpecs)->mapWithKeys(fn($q, $id) => [$id => ['quantity' => $q]])->toArray());
                $pc->diskSpecs()->sync(array_keys($diskSpecs));
                $pc->processorSpecs()->sync([$procId]); // Changed to use single ID in array
            }

            // Decrement stock all at once after successful creation
            $this->decrementStockForMultiple($ramSpecs, RamSpec::class, $quantity);
            $this->decrementStockForMultiple($diskSpecs, DiskSpec::class, $quantity);
            $this->decrementStockForMultiple([$procId => 1], ProcessorSpec::class, $quantity); // Changed to use single ID
        }, 3);

        $message = $quantity > 1
            ? "{$quantity} identical PC Specs created successfully"
            : "PC Spec created successfully";

        return redirect()->route('pcspecs.index')
            ->with('message', $message)
            ->with('type', 'success');
    }

    /**
     * Verify if enough stock exists for multiple items
     */
    protected function verifyStockForMultiple(array $items, string $modelClass, string $label, int $quantity): void
    {
        $items = collect($items)->mapWithKeys(fn($q, $id) => [(int)$id => (int)$q])->filter()->toArray();
        if (empty($items)) return;

        foreach ($items as $id => $qty) {
            $spec = $modelClass::with('stock')->find($id);
            if (!$spec || !$spec->stock) {
                throw ValidationException::withMessages([$label => "{$label} #{$id} not found or missing stock"]);
            }
            $totalNeeded = $qty * $quantity;
            if ($spec->stock->quantity < $totalNeeded) {
                throw ValidationException::withMessages([$label => "Not enough stock for {$label} '{$id}' (requested {$totalNeeded} for {$quantity} PCs, available {$spec->stock->quantity})"]);
            }
        }
    }

    /**
     * Decrement stock for multiple items at once
     */
    protected function decrementStockForMultiple(array $items, string $modelClass, int $quantity): void
    {
        $items = collect($items)->mapWithKeys(fn($q, $id) => [(int)$id => (int)$q])->filter()->toArray();
        if (empty($items)) return;

        foreach ($items as $id => $qty) {
            $spec = $modelClass::with('stock')->lockForUpdate()->find($id);
            if ($spec && $spec->stock) {
                $totalToDecrement = $qty * $quantity;
                $spec->stock->decrement('quantity', $totalToDecrement);
            }
        }
    }

    /**
     * GET /motherboards/{motherboard}/edit
     */
    public function edit(PcSpec $pcspec)
    {
        return Inertia::render('Computer/PcSpecs/Edit', [
            'pcspec' => [
                'id'                  => $pcspec->id,
                'pc_number'           => $pcspec->pc_number,
                'manufacturer'        => $pcspec->manufacturer,
                'model'               => $pcspec->model,
                'form_factor'         => $pcspec->form_factor,
                'memory_type'         => $pcspec->memory_type,
                'ram_slots'           => $pcspec->ram_slots,
                'max_ram_capacity_gb' => $pcspec->max_ram_capacity_gb,
                'max_ram_speed'       => $pcspec->max_ram_speed,
                'm2_slots'            => $pcspec->m2_slots,
                'sata_ports'          => $pcspec->sata_ports,
                'ramSpecs'            => $pcspec->ramSpecs()->get()->map(fn($r) => [
                    'id' => $r->id,
                    'pivot' => ['quantity' => $r->pivot->quantity ?? 1],
                ])->toArray(),
                'diskSpecs'           => $pcspec->diskSpecs()->pluck('id'),
                'processorSpecs'      => $pcspec->processorSpecs()->pluck('id'),
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
                    'label' => "{$d->manufacturer} {$d->model} {$d->interface} {$d->capacity_gb}GB",
                    'stock_quantity' => $d->stock?->quantity ?? 0,
                ]),
            'processorOptions' => ProcessorSpec::with('stock')->get()
                ->map(fn($p) => [
                    'id'          => $p->id,
                    'label'       => "{$p->manufacturer} {$p->model}",
                    'socket_type' => $p->socket_type,
                    'stock_quantity' => $p->stock?->quantity ?? 0,
                ]),
        ]);
    }

    /**
     * PUT /motherboards/{motherboard}
     */
    public function update(Request $request, PcSpec $pcspec)
    {
        $data = $request->validate([
            'pc_number'                   => 'nullable|string|max:100|unique:pc_specs,pc_number,' . $pcspec->id,
            'manufacturer'                => 'required|string|max:255',
            'model'                       => 'required|string|max:255',
            'form_factor'                 => 'required|string|max:50',
            'memory_type'                 => 'required|string|max:10',
            'ram_slots'                   => 'required|integer|min:1',
            'max_ram_capacity_gb'         => 'required|integer|min:1',
            'max_ram_speed'               => 'required|string|max:50',
            'm2_slots'                    => 'required|integer|min:0',
            'sata_ports'                  => 'required|integer|min:0',
            'ram_mode'                    => 'nullable|in:same,different',
            'ram_specs'                   => 'array',
            'disk_mode'                   => 'nullable|in:same,different',
            'disk_specs'                  => 'array',
            'processor_spec_id'           => 'required|exists:processor_specs,id',
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
        DB::transaction(function () use ($pcspec, $data, $newRamSpecs, $newDiskSpecs) {
            $pcspec->update($data);

            $this->applyRamStockDiffs($pcspec, $newRamSpecs);
            $pcspec->ramSpecs()->sync(collect($newRamSpecs)->mapWithKeys(fn($q, $id) => [$id => ['quantity' => $q]])->toArray());

            $this->applyDiskStockDiffs($pcspec, $newDiskSpecs);
            $pcspec->diskSpecs()->sync(array_keys($newDiskSpecs));

            // Changed from array to single processor
            $newProcId = $data['processor_spec_id'];
            $this->applyProcessorStockDiffs($pcspec, [$newProcId]); // Still accepts an array for compatibility
            $pcspec->processorSpecs()->sync([$newProcId]);
        }, 3);

        return redirect()->route('pcspecs.index')
            ->with('message', 'PC Spec updated')
            ->with('type', 'success');
    }

    /**
     * PATCH /pcspecs/{pcspec}/issue
     * Update only the issue field
     */
    public function updateIssue(Request $request, PcSpec $pcspec)
    {
        $data = $request->validate([
            'issue' => 'nullable|string',
        ]);

        $pcspec->update($data);

        return back()->with([
            'message' => 'Issue updated successfully',
            'type' => 'success'
        ]);
    }

    /**
     * DELETE /motherboards/{motherboard}
     */
    public function destroy(PcSpec $pcspec)
    {
        // Check if PC spec is assigned to any station
        $stationCount = $pcspec->stations()->count();
        if ($stationCount > 0) {
            return back()->with([
                'message' => 'Cannot delete PC specification. It is being used in ' . $stationCount . ' station(s).',
                'type' => 'error'
            ]);
        }

        DB::transaction(function () use ($pcspec) {
            foreach ($pcspec->ramSpecs as $ram) {
                if ($ram->stock) {
                    $qty = $ram->pivot->quantity ?? 1;
                    $ram->stock->increment('quantity', $qty);
                }
            }

            foreach ($pcspec->diskSpecs as $disk) {
                if ($disk->stock) {
                    $disk->stock->increment('quantity', 1);
                }
            }

            foreach ($pcspec->processorSpecs as $cpu) {
                if ($cpu->stock) {
                    $cpu->stock->increment('quantity', 1);
                }
            }

            $pcspec->ramSpecs()->detach();
            $pcspec->diskSpecs()->detach();
            $pcspec->processorSpecs()->detach();
            $pcspec->delete();
        });

        return redirect()->route('pcspecs.index')
            ->with('message', 'PC Spec deleted and stocks restored')
            ->with('type', 'success');
    }

    /**
     * GET /pcspecs/{pcspec}
     */
    public function show(PcSpec $pcspec)
    {
        // Eager load relationships if needed
        $pcspec->load(['ramSpecs', 'diskSpecs', 'processorSpecs', 'station']);
        return Inertia::render('Computer/PcSpecs/Show', [
            'pcspec' => $pcspec,
        ]);
    }



    public function bulkAll(Request $request)
{
    $request->validate([
        'format' => 'required|string|in:png,svg',
        'size' => 'required|integer|min:64|max:1024',
        'metadata' => 'required|integer|in:0,1',
    ]);

    // Generate a unique job ID
    $jobId = (string) Str::uuid();

    // Dispatch the job (queue should be configured)
    Bus::dispatch(
        new GenerateAllPcSpecQRCodesZip(
            $jobId,
            $request->input('format'),
            $request->input('size'),
            $request->input('metadata')
        )
    );

    return response()->json(['jobId' => $jobId]);
}

public function bulkProgress($jobId)
{
    $statusKey = "qrcode_zip_job:{$jobId}";
    $progress = Cache::get($statusKey, [
        'percent' => 0,
        'status' => 'Not started',
        'finished' => false,
        'downloadUrl' => null,
    ]);
    return response()->json($progress);
}

public function downloadZip($jobId)
{
    $zipFileName = "pc-qrcodes-{$jobId}.zip";
    $zipPath = storage_path("app/temp/{$zipFileName}");

    if (!file_exists($zipPath)) {
        abort(404, 'ZIP file not found');
    }

    return Response::download($zipPath, $zipFileName);
}

public function zipSelected(Request $request)
{
    $request->validate([
        'pc_ids' => 'required|array',
        'pc_ids.*' => 'integer|exists:pc_specs,id',
        'format' => 'required|string|in:png,svg',
        'size' => 'required|integer|min:64|max:1024',
        'metadata' => 'required|integer|in:0,1',
    ]);

    $jobId = (string) Str::uuid();

    // Dispatch a job similar to GenerateAllPcSpecQRCodesZip, but for selected IDs
    dispatch(new GenerateSelectedPcSpecQRCodesZip(
        $jobId,
        $request->input('pc_ids'),
        $request->input('format'),
        $request->input('size'),
        $request->input('metadata')
    ));

    return response()->json(['jobId' => $jobId]);
}

public function selectedZipProgress($jobId)
{
    $statusKey = "qrcode_zip_selected_job:{$jobId}";
    $progress = Cache::get($statusKey, [
        'percent' => 0,
        'status' => 'Not started',
        'finished' => false,
        'downloadUrl' => null,
    ]);
    return response()->json($progress);
}

public function downloadSelectedZip($jobId)
{
    $zipFileName = "pc-qrcodes-selected-{$jobId}.zip";
    $zipPath = storage_path("app/temp/{$zipFileName}");

    if (!file_exists($zipPath)) {
        abort(404, 'ZIP file not found');
    }

    return Response::download($zipPath, $zipFileName);
}

}
