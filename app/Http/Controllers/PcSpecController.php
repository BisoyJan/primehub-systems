<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAllPcSpecQRCodesZip;
use App\Jobs\GenerateSelectedPcSpecQRCodesZip;
use App\Models\PcSpec;
use App\Models\ProcessorSpec;
use App\Models\Station;
use App\Traits\AddsQrCodeBorder;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PcSpecController extends Controller
{
    use AddsQrCodeBorder;

    /**
     * GET /motherboards
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', PcSpec::class);

        $sortDirection = in_array($request->input('sort_dir'), ['asc', 'desc']) ? $request->input('sort_dir') : 'asc';

        $query = PcSpec::with(['processorSpecs', 'stations'])
            ->orderByRaw("CAST(REGEXP_REPLACE(pc_number, '[^0-9]', '') AS UNSIGNED) {$sortDirection}")
            ->orderBy('pc_number', $sortDirection);

        // Filter by selected PC IDs (multi-select)
        $pcIds = $request->input('pc_ids', []);
        if (is_array($pcIds) && count($pcIds) > 0) {
            $query->whereIn('id', array_map('intval', $pcIds));
        }

        // Filter by PC number range (pc_number_from / pc_number_to)
        $pcNumberFrom = $request->input('pc_number_from');
        $pcNumberTo = $request->input('pc_number_to');

        if (is_numeric($pcNumberFrom) && (int) $pcNumberFrom > 0) {
            $query->whereRaw("CAST(REGEXP_REPLACE(pc_number, '[^0-9]', '') AS UNSIGNED) >= ?", [(int) $pcNumberFrom]);
        }

        if (is_numeric($pcNumberTo) && (int) $pcNumberTo > 0) {
            $query->whereRaw("CAST(REGEXP_REPLACE(pc_number, '[^0-9]', '') AS UNSIGNED) <= ?", [(int) $pcNumberTo]);
        }

        // Filter by processors (multi-select or singular processor_id)
        $processorIds = $request->input('processor_ids', []);
        if (! is_array($processorIds)) {
            $processorIds = [];
        }
        // Support singular processor_id filter
        $singleProcessorId = $request->input('processor_id');
        if ($singleProcessorId && ! count($processorIds)) {
            $processorIds = [(int) $singleProcessorId];
        }
        $processorIds = array_values(array_filter(array_map('intval', $processorIds), fn ($id) => $id > 0));

        if (count($processorIds) > 0) {
            $query->whereHas('processorSpecs', function ($q) use ($processorIds) {
                $q->whereIn('processor_specs.id', $processorIds);
            });
        }

        // Search by pc_number
        $search = $request->input('search');
        if ($search) {
            $query->where('pc_number', 'like', '%'.$search.'%');
        }

        $pcspecs = $query
            ->paginate(10)
            ->appends($request->only(['pc_ids', 'processor_ids', 'sort_dir', 'pc_number_from', 'pc_number_to']))
            ->through(fn ($pc) => [
                'id' => $pc->id,
                'pc_number' => $pc->pc_number,
                'manufacturer' => $pc->manufacturer,
                'memory_type' => $pc->memory_type,
                'issue' => $pc->issue,
                'notes' => $pc->notes,
                'ram_gb' => $pc->ram_gb,
                'disk_gb' => $pc->disk_gb,
                'available_ports' => $pc->available_ports,
                'bios_release_date' => $pc->bios_release_date?->format('Y-m-d'),
                'processorSpecs' => $pc->processorSpecs->map(fn ($p) => [
                    'id' => $p->id,
                    'manufacturer' => $p->manufacturer,
                    'model' => $p->model,
                    'core_count' => $p->core_count ?? null,
                    'thread_count' => $p->thread_count ?? null,
                    'base_clock_ghz' => $p->base_clock_ghz ?? null,
                    'boost_clock_ghz' => $p->boost_clock_ghz ?? null,
                ])->toArray(),
                'station_numbers' => $pc->stations->pluck('station_number')->filter()->values()->toArray(),
            ]);

        // All PCs for multi-select dropdown (lightweight)
        $allPcSpecs = PcSpec::select('id', 'pc_number', 'manufacturer')
            ->orderByRaw("CAST(REGEXP_REPLACE(pc_number, '[^0-9]', '') AS UNSIGNED)")
            ->orderBy('pc_number')
            ->orderBy('manufacturer')
            ->get()
            ->map(fn ($pc) => [
                'id' => $pc->id,
                'label' => $pc->pc_number
                    ? "{$pc->pc_number} — {$pc->manufacturer}"
                    : "{$pc->manufacturer} (ID: {$pc->id})",
            ]);

        // All processors for filter dropdown
        $allProcessors = ProcessorSpec::select('id', 'manufacturer', 'model', 'core_count', 'thread_count')
            ->orderBy('manufacturer')
            ->orderBy('model')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'label' => "{$p->manufacturer} {$p->model}",
                'core_count' => $p->core_count,
                'thread_count' => $p->thread_count,
            ]);

        return Inertia::render('Computer/PcSpecs/Index', [
            'pcspecs' => $pcspecs,
            'allPcSpecs' => $allPcSpecs,
            'allProcessors' => $allProcessors,
            'filters' => [
                'pc_ids' => $pcIds,
                'processor_ids' => $processorIds,
                'sort_dir' => $sortDirection,
                'pc_number_from' => $pcNumberFrom !== null ? (int) $pcNumberFrom : null,
                'pc_number_to' => $pcNumberTo !== null ? (int) $pcNumberTo : null,
            ],
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
                    'manufacturer' => $p->manufacturer,
                    'core_count' => $p->core_count,
                    'thread_count' => $p->thread_count,
                    'base_clock_ghz' => $p->base_clock_ghz,
                    'boost_clock_ghz' => $p->boost_clock_ghz,
                ]),
        ]);
    }

    /**
     * POST /motherboards
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'pc_number' => ['required', 'string', 'regex:/^\d+$/', 'max:50'],
            'manufacturer' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'memory_type' => 'required|string|max:10',
            'ram_gb' => 'required|integer|min:0',
            'disk_gb' => 'required|integer|min:0',
            'available_ports' => 'nullable|string|max:500',
            'processor_mode' => 'required|in:existing,new',
            'processor_spec_id' => 'exclude_if:processor_mode,new|required_if:processor_mode,existing|nullable|exists:processor_specs,id',
            'processor_manufacturer' => 'required_if:processor_mode,new|nullable|string|max:255',
            'processor_model' => 'required_if:processor_mode,new|nullable|string|max:255',
            'processor_core_count' => 'nullable|integer|min:1',
            'processor_thread_count' => 'nullable|integer|min:1',
            'processor_base_clock_ghz' => 'nullable|numeric|min:0',
            'processor_boost_clock_ghz' => 'nullable|numeric|min:0',
            'bios_release_date' => 'nullable|date',
            'quantity' => 'nullable|integer|min:1|max:100',
        ], [
            'pc_number.required' => 'The QR Number is required.',
            'pc_number.regex' => 'The QR Number must contain digits only.',
        ]);

        // Normalize: pad to 4 digits, no prefix (e.g. "27" → "0027")
        $pcNumber = $this->normalizePcNumber($data['pc_number']);
        $data['pc_number'] = $pcNumber;

        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        $pcNumbers = $this->generateSequentialPcNumbers($pcNumber, $quantity);

        // Validate that none of the generated PC numbers are already taken
        $takenNumbers = PcSpec::whereIn('pc_number', $pcNumbers)->pluck('pc_number')->toArray();
        if (! empty($takenNumbers)) {
            return back()->withErrors([
                'pc_number' => 'The following QR Number(s) are already taken: '.implode(', ', $takenNumbers),
            ])->withInput();
        }

        $processorFields = [
            'processor_mode', 'processor_spec_id', 'processor_manufacturer',
            'processor_model', 'processor_core_count', 'processor_thread_count',
            'processor_base_clock_ghz', 'processor_boost_clock_ghz',
        ];

        $pcData = collect($data)->except(array_merge($processorFields, ['quantity', 'pc_number']))->toArray();

        DB::transaction(function () use ($pcData, $data, $pcNumbers) {
            $procId = $this->resolveProcessorId($data);

            foreach ($pcNumbers as $pcNumber) {
                $pc = PcSpec::create(array_merge($pcData, ['pc_number' => $pcNumber]));
                $pc->processorSpecs()->sync([$procId]);
            }
        });

        $quantity = count($pcNumbers);
        $message = $quantity > 1
            ? "{$quantity} PC Specs created successfully (".implode(', ', $pcNumbers).')'
            : 'PC Spec created successfully';

        return redirect()->route('pcspecs.index', $this->indexRedirectParams($request))
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
                'memory_type' => $pcspec->memory_type,
                'ram_gb' => $pcspec->ram_gb,
                'disk_gb' => $pcspec->disk_gb,
                'available_ports' => $pcspec->available_ports,
                'notes' => $pcspec->notes,
                'bios_release_date' => $pcspec->bios_release_date?->format('Y-m-d'),
                'processorSpecs' => $pcspec->processorSpecs->map(fn ($p) => [
                    'id' => $p->id,
                    'manufacturer' => $p->manufacturer,
                    'model' => $p->model,
                    'core_count' => $p->core_count,
                    'thread_count' => $p->thread_count,
                    'base_clock_ghz' => $p->base_clock_ghz,
                    'boost_clock_ghz' => $p->boost_clock_ghz,
                ]),
            ],
            'processorOptions' => ProcessorSpec::all()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'label' => "{$p->manufacturer} {$p->model}",
                    'manufacturer' => $p->manufacturer,
                    'core_count' => $p->core_count,
                    'thread_count' => $p->thread_count,
                    'base_clock_ghz' => $p->base_clock_ghz,
                    'boost_clock_ghz' => $p->boost_clock_ghz,
                ]),
        ]);
    }

    /**
     * PUT /motherboards/{motherboard}
     */
    public function update(Request $request, PcSpec $pcspec)
    {
        $data = $request->validate([
            'pc_number' => ['required', 'string', 'regex:/^\d+$/', 'max:50'],
            'manufacturer' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'memory_type' => 'required|string|max:10',
            'ram_gb' => 'required|integer|min:0',
            'disk_gb' => 'required|integer|min:0',
            'available_ports' => 'nullable|string|max:500',
            'processor_mode' => 'required|in:existing,new',
            'processor_spec_id' => 'exclude_if:processor_mode,new|required_if:processor_mode,existing|nullable|exists:processor_specs,id',
            'processor_manufacturer' => 'required_if:processor_mode,new|nullable|string|max:255',
            'processor_model' => 'required_if:processor_mode,new|nullable|string|max:255',
            'processor_core_count' => 'nullable|integer|min:1',
            'processor_thread_count' => 'nullable|integer|min:1',
            'processor_base_clock_ghz' => 'nullable|numeric|min:0',
            'processor_boost_clock_ghz' => 'nullable|numeric|min:0',
            'bios_release_date' => 'nullable|date',
        ], [
            'pc_number.required' => 'The QR Number is required.',
            'pc_number.regex' => 'The QR Number must contain digits only.',
        ]);

        // Normalize: pad to 4 digits, no prefix (e.g. "27" → "0027")
        $data['pc_number'] = $this->normalizePcNumber($data['pc_number']);

        // Check uniqueness (excluding current record) after normalization
        $duplicate = PcSpec::where('pc_number', $data['pc_number'])
            ->where('id', '!=', $pcspec->id)
            ->exists();

        if ($duplicate) {
            return back()->withErrors(['pc_number' => 'This QR Number is already taken.'])->withInput();
        }

        $processorFields = [
            'processor_mode', 'processor_spec_id', 'processor_manufacturer',
            'processor_model', 'processor_core_count', 'processor_thread_count',
            'processor_base_clock_ghz', 'processor_boost_clock_ghz',
        ];

        $pcData = collect($data)->except($processorFields)->toArray();

        DB::transaction(function () use ($pcspec, $pcData, $data) {
            $pcspec->update($pcData);
            $procId = $this->resolveProcessorId($data);
            $pcspec->processorSpecs()->sync([$procId]);
        });

        return redirect()->route('pcspecs.index', $this->indexRedirectParams($request))
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
     * PATCH /pcspecs/{pcspec}/notes
     * Update only the notes field
     */
    public function updateNotes(Request $request, PcSpec $pcspec)
    {
        $data = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $pcspec->update($data);

        return back()->with([
            'message' => 'Notes updated successfully',
            'type' => 'success',
        ]);
    }

    /**
     * GET /pcspecs/check-availability
     * Live-check whether a QR Number (digits only) is available.
     * Optional `quantity` validates a sequential range starting at pc_number.
     */
    public function checkAvailability(Request $request)
    {
        $data = $request->validate([
            'pc_number' => ['required', 'string', 'regex:/^\d+$/', 'max:50'],
            'exclude_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $startNormalized = $this->normalizePcNumber($data['pc_number']);
        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        $candidates = $this->generateSequentialPcNumbers($startNormalized, $quantity);

        $query = PcSpec::whereIn('pc_number', $candidates);
        if (! empty($data['exclude_id'])) {
            $query->where('id', '!=', (int) $data['exclude_id']);
        }

        $takenNormalized = $query->pluck('pc_number')->toArray();

        return response()->json([
            'available' => count($takenNormalized) === 0,
            'normalized' => $startNormalized,
            'pc_number' => $data['pc_number'],
            'taken' => $takenNormalized,
        ]);
    }

    /**
     * Build redirect parameters preserving pagination page from request.
     *
     * @return array<string, mixed>
     */
    private function indexRedirectParams(Request $request): array
    {
        $page = $request->input('_page');

        return $page ? ['page' => (int) $page] : [];
    }

    /**
     * Normalize a user-entered QR Number (digits only) into the storage form:
     * pad to minimum 4 digits, no prefix. e.g. "27" → "0027", "12345" → "12345".
     */
    private function normalizePcNumber(string $digits): string
    {
        return strlen($digits) < 4 ? str_pad($digits, 4, '0', STR_PAD_LEFT) : $digits;
    }

    /**
     * Generate sequential PC numbers starting from the given PC number.
     * Extracts the numeric suffix and increments it for each quantity.
     *
     * @return string[]
     */
    private function generateSequentialPcNumbers(string $pcNumber, int $quantity): array
    {
        if ($quantity === 1) {
            return [$pcNumber];
        }

        if (preg_match('/^(.*?)(\d+)$/', $pcNumber, $matches)) {
            $prefix = $matches[1];
            $startNum = (int) $matches[2];
            $padLength = strlen($matches[2]);

            $numbers = [];
            for ($i = 0; $i < $quantity; $i++) {
                $num = $startNum + $i;
                $numStr = (string) $num;
                // Preserve zero-padding only when the number fits within the original digit width
                $formatted = strlen($numStr) <= $padLength
                    ? str_pad($numStr, $padLength, '0', STR_PAD_LEFT)
                    : $numStr;
                $numbers[] = $prefix.$formatted;
            }

            return $numbers;
        }

        // No numeric suffix — append -2, -3, etc. for extras
        $numbers = [$pcNumber];
        for ($i = 2; $i <= $quantity; $i++) {
            $numbers[] = $pcNumber.'-'.$i;
        }

        return $numbers;
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

        return redirect()->route('pcspecs.index', $this->indexRedirectParams(request()))
            ->with('message', 'PC Spec deleted')
            ->with('type', 'success');
    }

    /**
     * DELETE /pcspecs/bulk-delete
     * Delete multiple PC specs at once.
     *
     * PC specs currently assigned to a station are automatically
     * unassigned first (station row preserved, only pc_spec_id is
     * cleared) — station assignment is never a reason to skip deletion.
     *
     * PC specs with existing transfer or maintenance history are
     * skipped by default (force=false) since deleting them would
     * cascade-delete that history. Pass force=true to delete them
     * anyway and let the DB cascade remove the history.
     */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:pc_specs,id'],
            'force' => ['nullable', 'boolean'],
        ]);

        $force = $validated['force'] ?? false;

        $pcSpecs = PcSpec::whereIn('id', $validated['ids'])
            ->withCount(['transfers', 'maintenances'])
            ->get();

        $deletable = $force
            ? $pcSpecs
            : $pcSpecs->filter(fn ($pc) => $pc->transfers_count === 0 && $pc->maintenances_count === 0);
        $skipped = $force ? collect() : $pcSpecs->diff($deletable);

        $stationsUnassigned = 0;

        DB::transaction(function () use ($deletable, &$stationsUnassigned) {
            foreach ($deletable as $pcSpec) {
                $stations = Station::where('pc_spec_id', $pcSpec->id)->get();
                foreach ($stations as $station) {
                    $station->update(['pc_spec_id' => null]);
                    $stationsUnassigned++;
                }

                $pcSpec->processorSpecs()->detach();
                $pcSpec->delete();
            }
        });

        $count = $deletable->count();
        $message = "Deleted {$count} PC spec".($count === 1 ? '' : 's').'.';
        if ($stationsUnassigned > 0) {
            $message .= " ({$stationsUnassigned} station".($stationsUnassigned === 1 ? '' : 's').' unassigned.)';
        }
        if ($skipped->isNotEmpty()) {
            $message .= ' Skipped '.$skipped->count().' PC spec'.($skipped->count() === 1 ? '' : 's').' with existing transfer/maintenance history.';
        }

        return redirect()->back()->with([
            'message' => $message,
            'type' => $skipped->isNotEmpty() ? 'warning' : 'success',
        ]);
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

        // Run job synchronously to avoid dependency on queue worker
        dispatch_sync(
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

        // Run job synchronously to avoid dependency on queue worker
        dispatch_sync(new GenerateSelectedPcSpecQRCodesZip(
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
     * POST /pcspecs/qrcode/bulk-all-stream
     * Stream all PC spec QR codes as a ZIP file directly (no queue needed)
     */
    public function bulkAllStream(Request $request)
    {
        $request->validate([
            'format' => 'required|string|in:png,svg',
            'size' => 'required|integer|min:64|max:1024',
            'metadata' => 'required|integer|in:0,1',
        ]);

        set_time_limit(300);

        $format = $request->input('format');
        $size = $request->input('size');
        $metadata = $request->input('metadata');

        $pcSpecs = PcSpec::cursor();

        $zipFileName = 'pc_qr_codes_all_'.date('Y-m-d_His').'.zip';
        $zipPath = storage_path("app/temp/{$zipFileName}");

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0777, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'Failed to create ZIP file'], 500);
        }

        foreach ($pcSpecs as $pcspec) {
            $data = $metadata
                ? json_encode([
                    'url' => route('pcspecs.scanResult', $pcspec->id),
                    'pc_number' => $pcspec->pc_number ?? "PC-{$pcspec->id}",
                    'manufacturer' => $pcspec->manufacturer,
                    'memory_type' => $pcspec->memory_type,
                ])
                : route('pcspecs.scanResult', $pcspec->id);

            $writer = $format === 'svg' ? new SvgWriter : new PngWriter;
            $pcNumber = $pcspec->pc_number ?? "PC-{$pcspec->id}";

            $builder = new Builder(
                writer: $writer,
                data: $data,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: $size,
                margin: 4,
                labelText: preg_replace('/[^0-9]/', '', $pcNumber)
            );

            $result = $builder->build();
            $zip->addFromString($pcNumber.".{$format}", $this->addQrCodeBorder($result->getString(), $format));
        }

        $zip->close();

        if (! file_exists($zipPath) || filesize($zipPath) === 0) {
            file_put_contents($zipPath, hex2bin('504b0506'.str_repeat('00', 18)));
        }

        return response()->download($zipPath, $zipFileName, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$zipFileName.'"',
        ])->deleteFileAfterSend(true);
    }

    /**
     * POST /pcspecs/qrcode/zip-selected-stream
     * Stream selected PC spec QR codes as a ZIP file directly (no queue needed)
     */
    public function zipSelectedStream(Request $request)
    {
        $request->validate([
            'pc_ids' => 'required|array',
            'pc_ids.*' => 'integer|exists:pc_specs,id',
            'format' => 'required|string|in:png,svg',
            'size' => 'required|integer|min:64|max:1024',
            'metadata' => 'required|integer|in:0,1',
        ]);

        set_time_limit(300);

        $format = $request->input('format');
        $size = $request->input('size');
        $metadata = $request->input('metadata');
        $pcIds = $request->input('pc_ids');

        $pcSpecs = PcSpec::whereIn('id', $pcIds)->cursor();

        $zipFileName = 'pc_qr_codes_selected_'.date('Y-m-d_His').'.zip';
        $zipPath = storage_path("app/temp/{$zipFileName}");

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0777, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'Failed to create ZIP file'], 500);
        }

        foreach ($pcSpecs as $pcspec) {
            $data = $metadata
                ? json_encode([
                    'url' => route('pcspecs.scanResult', $pcspec->id),
                    'pc_number' => $pcspec->pc_number ?? "PC-{$pcspec->id}",
                    'manufacturer' => $pcspec->manufacturer,
                    'memory_type' => $pcspec->memory_type,
                ])
                : route('pcspecs.scanResult', $pcspec->id);

            $writer = $format === 'svg' ? new SvgWriter : new PngWriter;
            $pcNumber = $pcspec->pc_number ?? "PC-{$pcspec->id}";

            $builder = new Builder(
                writer: $writer,
                data: $data,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: $size,
                margin: 4,
                labelText: preg_replace('/[^0-9]/', '', $pcNumber)
            );

            $result = $builder->build();
            $zip->addFromString($pcNumber.".{$format}", $this->addQrCodeBorder($result->getString(), $format));
        }

        $zip->close();

        if (! file_exists($zipPath) || filesize($zipPath) === 0) {
            file_put_contents($zipPath, hex2bin('504b0506'.str_repeat('00', 18)));
        }

        return response()->download($zipPath, $zipFileName, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$zipFileName.'"',
        ])->deleteFileAfterSend(true);
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

    /**
     * GET /pcspecs/scan/{pcspec}
     * Scan result page for a PC spec QR code
     */
    public function scanResult($pcspecId)
    {
        $pcspec = PcSpec::with(['processorSpecs', 'stations'])->find($pcspecId);

        if (! $pcspec) {
            return Inertia::render('Computer/PcSpecs/ScanResult', ['error' => 'PC Spec not found.']);
        }

        return Inertia::render('Computer/PcSpecs/ScanResult', [
            'pcspec' => [
                'id' => $pcspec->id,
                'pc_number' => $pcspec->pc_number,
                'manufacturer' => $pcspec->manufacturer,
                'memory_type' => $pcspec->memory_type,
                'ram_gb' => $pcspec->ram_gb,
                'disk_gb' => $pcspec->disk_gb,
                'available_ports' => $pcspec->available_ports,
                'notes' => $pcspec->notes,
                'issue' => $pcspec->issue,
                'bios_release_date' => $pcspec->bios_release_date?->format('Y-m-d'),
                'processorSpecs' => $pcspec->processorSpecs->map(fn ($p) => [
                    'id' => $p->id,
                    'manufacturer' => $p->manufacturer,
                    'model' => $p->model,
                    'core_count' => $p->core_count,
                    'thread_count' => $p->thread_count,
                    'base_clock_ghz' => $p->base_clock_ghz,
                    'boost_clock_ghz' => $p->boost_clock_ghz,
                ])->toArray(),
                'stations' => $pcspec->stations->map(fn ($s) => [
                    'id' => $s->id,
                    'station_number' => $s->station_number,
                    'status' => $s->status,
                ])->toArray(),
            ],
        ]);
    }
}
