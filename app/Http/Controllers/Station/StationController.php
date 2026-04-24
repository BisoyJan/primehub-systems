<?php

namespace App\Http\Controllers\Station;

use App\Http\Controllers\Controller;
use App\Http\Requests\StationBulkRequest;
use App\Http\Requests\StationRequest;
use App\Jobs\GenerateAllStationQRCodesZip;
use App\Jobs\GenerateSelectedStationQRCodesZip;
use App\Models\Campaign;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\Station;
use App\Traits\AddsQrCodeBorder;
use App\Utils\StationNumberUtil;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;
use ZipArchive;

class StationController extends Controller
{
    use AddsQrCodeBorder;

    // Bulk all stations QR ZIP
    public function bulkAllQRCodes(Request $request)
    {
        $request->validate([
            'format' => 'required|string|in:png,svg',
            'size' => 'required|integer|min:64|max:1024',
            'metadata' => 'required|integer|in:0,1',
        ]);
        $jobId = (string) Str::uuid();
        Bus::dispatch(new GenerateAllStationQRCodesZip(
            $jobId,
            $request->input('format'),
            $request->input('size'),
            $request->input('metadata')
        ));

        return response()->json(['jobId' => $jobId]);
    }

    public function bulkProgress($jobId)
    {
        $statusKey = "station_qrcode_zip_job:{$jobId}";
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
        $zipFileName = "station-qrcodes-{$jobId}.zip";
        $zipPath = storage_path("app/temp/{$zipFileName}");
        if (! file_exists($zipPath)) {
            abort(404, 'ZIP file not found');
        }

        return Response::download($zipPath, 'all-station-qrcodes.zip')->deleteFileAfterSend(true);
    }

    public function zipSelected(Request $request)
    {
        $request->validate([
            'station_ids' => 'required|array',
            'station_ids.*' => 'integer|exists:stations,id',
            'format' => 'required|string|in:png,svg',
            'size' => 'required|integer|min:64|max:1024',
            'metadata' => 'required|integer|in:0,1',
        ]);
        $jobId = (string) Str::uuid();
        Bus::dispatch(new GenerateSelectedStationQRCodesZip(
            $jobId,
            $request->input('station_ids'),
            $request->input('format'),
            $request->input('size'),
            $request->input('metadata')
        ));

        return response()->json(['jobId' => $jobId]);
    }

    public function selectedZipProgress($jobId)
    {
        $statusKey = "station_qrcode_zip_selected_job:{$jobId}";
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
        $zipFileName = "station-qrcodes-selected-{$jobId}.zip";
        $zipPath = storage_path("app/temp/{$zipFileName}");
        if (! file_exists($zipPath)) {
            abort(404, 'ZIP file not found');
        }

        return Response::download($zipPath, 'selected-station-qrcodes.zip')->deleteFileAfterSend(true);
    }

    /**
     * Stream all stations QR codes as a ZIP file directly
     * Similar to Excel export - generates and streams in one request
     */
    public function bulkAllQRCodesStream(Request $request)
    {
        $request->validate([
            'format' => 'required|string|in:png,svg',
            'size' => 'required|integer|min:64|max:1024',
            'metadata' => 'required|integer|in:0,1',
        ]);

        $format = $request->input('format');
        $size = $request->input('size');

        set_time_limit(300);

        $stations = Station::cursor();

        // Generate ZIP file
        $zipFileName = 'stations_qr_codes_all_'.date('Y-m-d_His').'.zip';
        $zipPath = storage_path("app/temp/{$zipFileName}");

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0777, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'Failed to create ZIP file'], 500);
        }

        foreach ($stations as $station) {
            $qrData = url("/stations/scan/{$station->id}");
            $writer = $format === 'svg' ? new SvgWriter : new PngWriter;
            $stationNumber = $station->station_number;

            $builder = new Builder(
                writer: $writer,
                data: $qrData,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: $size,
                margin: 10,
                labelText: $stationNumber
            );

            $result = $builder->build();
            $fileName = "station-{$stationNumber}.{$format}";
            $zip->addFromString($fileName, $this->addQrCodeBorder($result->getString(), $format));
        }

        $zip->close();

        // Create empty ZIP if no records were processed
        if (! file_exists($zipPath) || filesize($zipPath) === 0) {
            file_put_contents($zipPath, hex2bin('504b0506'.str_repeat('00', 18)));
        }

        // Stream the file and delete after sending
        return response()->download($zipPath, $zipFileName, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$zipFileName.'"',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Stream selected stations QR codes as a ZIP file directly
     * Similar to Excel export - generates and streams in one request
     */
    public function zipSelectedStream(Request $request)
    {
        $request->validate([
            'station_ids' => 'required|array',
            'station_ids.*' => 'integer|exists:stations,id',
            'format' => 'required|string|in:png,svg',
            'size' => 'required|integer|min:64|max:1024',
            'metadata' => 'required|integer|in:0,1',
        ]);

        set_time_limit(300);

        $format = $request->input('format');
        $size = $request->input('size');
        $stationIds = $request->input('station_ids');

        $stations = Station::whereIn('id', $stationIds)->cursor();

        // Generate ZIP file
        $zipFileName = 'stations_qr_codes_selected_'.date('Y-m-d_His').'.zip';
        $zipPath = storage_path("app/temp/{$zipFileName}");

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0777, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'Failed to create ZIP file'], 500);
        }

        foreach ($stations as $station) {
            $qrData = url("/stations/scan/{$station->id}");
            $writer = $format === 'svg' ? new SvgWriter : new PngWriter;
            $stationNumber = $station->station_number;

            $builder = new Builder(
                writer: $writer,
                data: $qrData,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: $size,
                margin: 10,
                labelText: $stationNumber
            );

            $result = $builder->build();
            $fileName = "station-{$stationNumber}.{$format}";
            $zip->addFromString($fileName, $this->addQrCodeBorder($result->getString(), $format));
        }

        $zip->close();

        // Create empty ZIP if no records were processed
        if (! file_exists($zipPath) || filesize($zipPath) === 0) {
            file_put_contents($zipPath, hex2bin('504b0506'.str_repeat('00', 18)));
        }

        // Stream the file and delete after sending
        return response()->download($zipPath, $zipFileName, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$zipFileName.'"',
        ])->deleteFileAfterSend(true);
    }

    public function json(Station $station)
    {
        $station->load(['site', 'campaign', 'pcSpec']);

        return response()->json($station);
    }

    // Paginated, searchable index
    public function index(Request $request)
    {
        $query = Station::query()
            ->with([
                'site',
                'pcSpec.processorSpecs',
                'campaign',
            ]);

        // Filter by selected station IDs (multi-select)
        $stationIds = $request->input('station_ids', []);
        if (is_array($stationIds) && count($stationIds) > 0) {
            $query->whereIn('id', array_map('intval', $stationIds));
        }

        $query->filterBySite($request->query('site'))
            ->filterByCampaign($request->query('campaign'))
            ->filterByStatus($request->query('status'))
            ->orderBy('id', 'desc');

        // Get all IDs matching current filters (for bulk select all)
        $allMatchingIds = (clone $query)->pluck('id')->toArray();

        $paginated = $query->paginate(10)
            ->withQueryString();

        $items = $paginated->getCollection()->map(function (Station $station) {
            // Ensure pcSpec is loaded with all specs
            $pcSpecDetails = $station->pcSpec ? $station->pcSpec->getFormattedDetails() : null;

            return [
                'id' => $station->id,
                'site' => $station->site?->name,
                'station_number' => $station->station_number,
                'campaign' => $station->campaign?->name,
                'status' => $station->status,
                'monitor_type' => $station->monitor_type,
                'pc_spec' => $station->pcSpec?->model,
                'pc_spec_details' => $pcSpecDetails,
                'created_at' => optional($station->created_at)->toDateTimeString(),
                'updated_at' => optional($station->updated_at)->toDateTimeString(),
            ];
        })->toArray();

        // All stations for multi-select dropdown (lightweight, numerically sorted)
        $allStations = Station::select('id', 'station_number')
            ->orderByRaw("CAST(REGEXP_REPLACE(station_number, '[^0-9]', '') AS UNSIGNED)")
            ->orderBy('station_number')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'label' => $s->station_number,
            ]);

        return Inertia::render('Station/Index', [
            'stations' => [
                'data' => $items,
                'links' => $paginated->toArray()['links'] ?? [],
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'filters' => $this->getFilterOptions(),
            'allStations' => $allStations,
            'flash' => session('flash') ?? null,
            'allMatchingIds' => $allMatchingIds,
        ]);
    }

    // Show create form
    public function create()
    {
        return Inertia::render('Station/Create', [
            'sites' => Site::all(['id', 'name']),
            'campaigns' => Campaign::all(['id', 'name']),
            'pcSpecs' => $this->getFormattedPcSpecs(),
            'usedPcSpecIds' => Station::pluck('pc_spec_id')->toArray(),
        ]);
    }

    // Store new station
    public function store(StationRequest $request)
    {
        $data = $request->validated();

        // Convert empty pc_spec_id to null
        if (empty($data['pc_spec_id'])) {
            $data['pc_spec_id'] = null;
        }

        $station = Station::create($data);

        return redirect()->back()->with('flash', ['message' => 'Station saved', 'type' => 'success']);
    }

    // Store multiple stations at once
    public function storeBulk(StationBulkRequest $request)
    {
        $validated = $request->validated();

        $quantity = (int) $validated['quantity'];
        $startingNumber = $this->normalizeStationNumber($validated['starting_number']);
        $incrementType = $validated['increment_type'];
        $createdStations = [];
        $existingNumbers = [];

        // Extract parts from starting number (e.g., "PC-1A" -> prefix="PC-", number=1, letter="A")
        preg_match(StationNumberUtil::REGEX_PATTERN, $startingNumber, $matches);

        if (count($matches) < 3) {
            // No numeric part found, use sequential numeric suffixes
            $stationNumbers = $this->generateSimpleSequence($startingNumber, $quantity);
        } else {
            $prefix = $matches[1];
            $numPart = (int) $matches[2];
            $letterPart = $matches[3] ?? '';
            $suffix = $matches[4] ?? '';
            $numLength = strlen($matches[2]);

            $stationNumbers = $this->generateStationNumbers(
                $prefix,
                $numPart,
                $letterPart,
                $suffix,
                $numLength,
                $quantity,
                $incrementType
            );
        }

        // Use pc_spec_ids array if provided, otherwise fall back to single pc_spec_id
        $pcSpecIds = isset($validated['pc_spec_ids']) && is_array($validated['pc_spec_ids'])
            ? $validated['pc_spec_ids']
            : [];

        // Check for duplicates
        foreach ($stationNumbers as $index => $stationNumber) {
            if (Station::where('station_number', $stationNumber)->exists()) {
                $existingNumbers[] = $stationNumber;
            } else {
                // Assign PC spec from array (if available), or use single pc_spec_id, or null
                $pcSpecId = null;
                if (! empty($pcSpecIds) && isset($pcSpecIds[$index])) {
                    $pcSpecId = $pcSpecIds[$index];
                } elseif (isset($validated['pc_spec_id'])) {
                    $pcSpecId = $validated['pc_spec_id'];
                }

                $createdStations[] = [
                    'site_id' => $validated['site_id'],
                    'station_number' => $stationNumber,
                    'campaign_id' => $validated['campaign_id'],
                    'status' => $validated['status'],
                    'monitor_type' => $validated['monitor_type'],
                    'pc_spec_id' => $pcSpecId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // If there are existing numbers, return error
        if (! empty($existingNumbers)) {
            return redirect()->back()
                ->withErrors(['starting_number' => 'The following station numbers already exist: '.implode(', ', array_slice($existingNumbers, 0, 5)).(count($existingNumbers) > 5 ? '...' : '')])
                ->withInput();
        }
        // Bulk insert all stations using Eloquent create()
        foreach ($createdStations as $stationData) {
            Station::create($stationData);
        }

        return redirect()->back()->with('flash', [
            'message' => "Successfully created {$quantity} station(s)",
            'type' => 'success',
        ]);
    }

    // Generate station numbers based on increment type
    private function generateStationNumbers(
        string $prefix,
        int $numPart,
        string $letterPart,
        string $suffix,
        int $numLength,
        int $quantity,
        string $incrementType
    ): array {
        $numbers = [];

        for ($i = 0; $i < $quantity; $i++) {
            $newNum = $numPart;
            $newLetter = $letterPart;

            if ($incrementType === 'number') {
                // Increment only the number part
                $newNum = $numPart + $i;
            } elseif ($incrementType === 'letter' && $letterPart !== '') {
                // Increment only the letter part
                $isUpper = $letterPart === strtoupper($letterPart);
                $baseCharCode = $isUpper ? ord('A') : ord('a');
                $currentCharCode = ord($letterPart);
                $offset = $currentCharCode - $baseCharCode;
                $newCharCode = $baseCharCode + (($offset + $i) % 26);
                $newLetter = chr($newCharCode);
            } elseif ($incrementType === 'both') {
                // Increment both number and letter
                $newNum = $numPart + $i;
                if ($letterPart !== '') {
                    $isUpper = $letterPart === strtoupper($letterPart);
                    $baseCharCode = $isUpper ? ord('A') : ord('a');
                    $currentCharCode = ord($letterPart);
                    $offset = $currentCharCode - $baseCharCode;
                    $newCharCode = $baseCharCode + (($offset + $i) % 26);
                    $newLetter = chr($newCharCode);
                }
            }

            $paddedNum = str_pad($newNum, $numLength, '0', STR_PAD_LEFT);
            $numbers[] = $prefix.$paddedNum.$newLetter.$suffix;
        }

        return $numbers;
    }

    // Generate simple sequence for numbers without patterns
    private function generateSimpleSequence(string $base, int $quantity): array
    {
        $numbers = [];
        for ($i = 1; $i <= $quantity; $i++) {
            $numbers[] = $base.$i;
        }

        return $numbers;
    }

    // Show edit form
    public function edit(Station $station)
    {
        return Inertia::render('Station/Edit', [
            'station' => $station,
            'sites' => Site::all(['id', 'name']),
            'campaigns' => Campaign::all(['id', 'name']),
            'pcSpecs' => $this->getFormattedPcSpecs(),
            'usedPcSpecIds' => Station::pluck('pc_spec_id')->toArray(),
        ]);
    }

    // Update station
    public function update(StationRequest $request, Station $station)
    {
        $data = $request->validated();

        // Convert empty pc_spec_id to null
        if (empty($data['pc_spec_id'])) {
            $data['pc_spec_id'] = null;
        }

        $station->update($data);

        return redirect()->back()->with('flash', ['message' => 'Station updated', 'type' => 'success']);
    }

    // Delete station
    public function destroy(Station $station)
    {
        $station->delete();

        return redirect()->back()->with('flash', ['message' => 'Station deleted', 'type' => 'success']);
    }

    // ScanResult page for a station
    public function scanResult($stationId)
    {
        $station = Station::with(['site', 'campaign', 'pcSpec.processorSpecs'])->find($stationId);
        if (! $station) {
            return Inertia::render('Station/ScanResult', ['error' => 'Station not found.']);
        }
        $pcSpecDetails = $station->pcSpec ? $station->pcSpec->getFormattedDetails() : null;
        $stationArr = $station->toArray();
        $stationArr['pcSpec'] = $pcSpecDetails;

        return Inertia::render('Station/ScanResult', ['station' => $stationArr]);
    }
    // Private helper methods

    /**
     * Normalize station number by converting all letters to uppercase
     * Examples: "pc-1a" -> "PC-1A", "st-001b" -> "ST-001B"
     */
    private function normalizeStationNumber(string $stationNumber): string
    {
        return strtoupper($stationNumber);
    }

    private function getFilterOptions(): array
    {
        return [
            'sites' => Site::all(['id', 'name']),
            'campaigns' => Campaign::all(['id', 'name']),
            'statuses' => Station::select('status')->distinct()->pluck('status'),
        ];
    }

    private function getFormattedPcSpecs()
    {
        return PcSpec::with(['processorSpecs'])
            ->get()
            ->map(fn ($pc) => $pc->getFormSelectionData());
    }
}
