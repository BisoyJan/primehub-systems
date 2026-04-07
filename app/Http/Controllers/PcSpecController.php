<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAllPcSpecQRCodesZip;
use App\Jobs\GenerateSelectedPcSpecQRCodesZip;
use App\Models\PcSpec;
use App\Models\ProcessorSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PcSpecController extends Controller
{
    /**
     * GET /motherboards
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', PcSpec::class);

        $search = trim((string) $request->input('search', ''));

        $query = PcSpec::with(['processorSpecs'])
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
            ->through(fn ($pc) => [
                'id' => $pc->id,
                'pc_number' => $pc->pc_number,
                'manufacturer' => $pc->manufacturer,
                'model' => $pc->model,
                'memory_type' => $pc->memory_type,
                'issue' => $pc->issue,
                'ram_gb' => $pc->ram_gb,
                'disk_gb' => $pc->disk_gb,
                'available_ports' => $pc->available_ports,
                'processorSpecs' => $pc->processorSpecs->map(fn ($p) => [
                    'id' => $p->id,
                    'manufacturer' => $p->manufacturer,
                    'model' => $p->model,
                    'core_count' => $p->core_count ?? null,
                    'thread_count' => $p->thread_count ?? null,
                    'base_clock_ghz' => $p->base_clock_ghz ?? null,
                    'boost_clock_ghz' => $p->boost_clock_ghz ?? null,
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
            'processorOptions' => ProcessorSpec::all()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'label' => "{$p->manufacturer} {$p->model}",
                ]),
        ]);
    }

    /**
     * POST /motherboards
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'pc_number' => 'nullable|string|max:100|unique:pc_specs,pc_number',
            'manufacturer' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'memory_type' => 'required|string|max:10',
            'm2_slots' => 'required|integer|min:0',
            'sata_ports' => 'required|integer|min:0',
            'ram_gb' => 'required|integer|min:0',
            'disk_gb' => 'required|integer|min:0',
            'available_ports' => 'nullable|string|max:500',
            'processor_mode' => 'required|in:existing,new',
            'processor_spec_id' => 'required_if:processor_mode,existing|nullable|exists:processor_specs,id',
            'processor_manufacturer' => 'required_if:processor_mode,new|nullable|string|max:255',
            'processor_model' => 'required_if:processor_mode,new|nullable|string|max:255',
            'processor_core_count' => 'nullable|integer|min:1',
            'processor_thread_count' => 'nullable|integer|min:1',
            'processor_base_clock_ghz' => 'nullable|numeric|min:0',
            'processor_boost_clock_ghz' => 'nullable|numeric|min:0',
            'processor_release_date' => 'nullable|date',
            'quantity' => 'nullable|integer|min:1|max:100',
        ]);

        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        $processorFields = [
            'processor_mode', 'processor_spec_id', 'processor_manufacturer',
            'processor_model', 'processor_core_count', 'processor_thread_count',
            'processor_base_clock_ghz', 'processor_boost_clock_ghz', 'processor_release_date',
        ];

        $pcData = collect($data)->except(array_merge($processorFields, ['quantity']))->toArray();

        DB::transaction(function () use ($pcData, $data, $quantity) {
            $procId = $this->resolveProcessorId($data);

            for ($i = 0; $i < $quantity; $i++) {
                $pc = PcSpec::create($pcData);
                $pc->processorSpecs()->sync([$procId]);
            }
        });

        $message = $quantity > 1
            ? "{$quantity} identical PC Specs created successfully"
            : 'PC Spec created successfully';

        return redirect()->route('pcspecs.index')
            ->with('message', $message)
            ->with('type', 'success');
    }

    /**
     * GET /motherboards/{motherboard}/edit
     */
    public function edit(PcSpec $pcspec)
    {
        return Inertia::render('Computer/PcSpecs/Edit', [
            'pcspec' => [
                'id' => $pcspec->id,
                'pc_number' => $pcspec->pc_number,
                'manufacturer' => $pcspec->manufacturer,
                'model' => $pcspec->model,
                'memory_type' => $pcspec->memory_type,
                'm2_slots' => $pcspec->m2_slots,
                'sata_ports' => $pcspec->sata_ports,
                'ram_gb' => $pcspec->ram_gb,
                'disk_gb' => $pcspec->disk_gb,
                'available_ports' => $pcspec->available_ports,
                'processorSpecs' => $pcspec->processorSpecs()->pluck('id'),
            ],
            'processorOptions' => ProcessorSpec::all()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'label' => "{$p->manufacturer} {$p->model}",
                ]),
        ]);
    }

    /**
     * PUT /motherboards/{motherboard}
     */
    public function update(Request $request, PcSpec $pcspec)
    {
        $data = $request->validate([
            'pc_number' => 'nullable|string|max:100|unique:pc_specs,pc_number,'.$pcspec->id,
            'manufacturer' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'memory_type' => 'required|string|max:10',
            'm2_slots' => 'required|integer|min:0',
            'sata_ports' => 'required|integer|min:0',
            'ram_gb' => 'required|integer|min:0',
            'disk_gb' => 'required|integer|min:0',
            'available_ports' => 'nullable|string|max:500',
            'processor_mode' => 'required|in:existing,new',
            'processor_spec_id' => 'required_if:processor_mode,existing|nullable|exists:processor_specs,id',
            'processor_manufacturer' => 'required_if:processor_mode,new|nullable|string|max:255',
            'processor_model' => 'required_if:processor_mode,new|nullable|string|max:255',
            'processor_core_count' => 'nullable|integer|min:1',
            'processor_thread_count' => 'nullable|integer|min:1',
            'processor_base_clock_ghz' => 'nullable|numeric|min:0',
            'processor_boost_clock_ghz' => 'nullable|numeric|min:0',
            'processor_release_date' => 'nullable|date',
        ]);

        $processorFields = [
            'processor_mode', 'processor_spec_id', 'processor_manufacturer',
            'processor_model', 'processor_core_count', 'processor_thread_count',
            'processor_base_clock_ghz', 'processor_boost_clock_ghz', 'processor_release_date',
        ];

        $pcData = collect($data)->except($processorFields)->toArray();

        DB::transaction(function () use ($pcspec, $pcData, $data) {
            $pcspec->update($pcData);
            $procId = $this->resolveProcessorId($data);
            $pcspec->processorSpecs()->sync([$procId]);
        });

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
            'type' => 'success',
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
                'message' => 'Cannot delete PC specification. It is being used in '.$stationCount.' station(s).',
                'type' => 'error',
            ]);
        }

        DB::transaction(function () use ($pcspec) {
            $pcspec->processorSpecs()->detach();
            $pcspec->delete();
        });

        return redirect()->route('pcspecs.index')
            ->with('message', 'PC Spec deleted')
            ->with('type', 'success');
    }

    /**
     * GET /pcspecs/{pcspec}
     */
    public function show(PcSpec $pcspec)
    {
        // Eager load relationships if needed
        $pcspec->load(['processorSpecs', 'station']);

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

        if (! file_exists($zipPath)) {
            abort(404, 'ZIP file not found');
        }

        return Response::download($zipPath, $zipFileName)->deleteFileAfterSend(true);
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

        if (! file_exists($zipPath)) {
            abort(404, 'ZIP file not found');
        }

        return Response::download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    /**
     * Resolve processor spec ID: either use existing or create new.
     */
    private function resolveProcessorId(array $data): int
    {
        if ($data['processor_mode'] === 'new') {
            $attributes = array_filter([
                'manufacturer' => $data['processor_manufacturer'],
                'model' => $data['processor_model'],
                'core_count' => $data['processor_core_count'] ?? null,
                'thread_count' => $data['processor_thread_count'] ?? null,
                'base_clock_ghz' => $data['processor_base_clock_ghz'] ?? null,
                'boost_clock_ghz' => $data['processor_boost_clock_ghz'] ?? null,
                'release_date' => $data['processor_release_date'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            $processor = ProcessorSpec::whereRaw('LOWER(manufacturer) = ?', [strtolower($attributes['manufacturer'])])
                ->whereRaw('LOWER(model) = ?', [strtolower($attributes['model'])])
                ->first();

            if (! $processor) {
                $processor = ProcessorSpec::create($attributes);
            }

            return $processor->id;
        }

        return (int) $data['processor_spec_id'];
    }
}
