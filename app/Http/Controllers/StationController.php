<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\Site;
use App\Models\PcSpec;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Utils\StationNumberUtil;

class StationController extends Controller
{
    // Paginated, searchable index
    public function index(Request $request)
    {
        $paginated = Station::query()
            ->with(['site', 'pcSpec', 'campaign'])
            ->search($request->query('search'))
            ->filterBySite($request->query('site'))
            ->filterByCampaign($request->query('campaign'))
            ->filterByStatus($request->query('status'))
            ->orderBy('id', 'desc')
            ->paginate(10)
            ->withQueryString();

        $items = $paginated->getCollection()->map(function (Station $station) {
            return [
                'id' => $station->id,
                'site' => $station->site?->name,
                'station_number' => $station->station_number,
                'campaign' => $station->campaign?->name,
                'status' => $station->status,
                'monitor_type' => $station->monitor_type,
                'pc_spec' => $station->pcSpec?->model,
                'pc_spec_details' => $station->pcSpec?->getFormattedDetails(),
                'created_at' => optional($station->created_at)->toDateTimeString(),
                'updated_at' => optional($station->updated_at)->toDateTimeString(),
            ];
        })->toArray();

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
            'flash' => session('flash') ?? null,
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
    public function store(Request $request)
    {
        $data = $this->validateStation($request);

        // Convert any letters in station_number to uppercase
        $data['station_number'] = $this->normalizeStationNumber($data['station_number']);

        // Convert empty pc_spec_id to null
        if (empty($data['pc_spec_id'])) {
            $data['pc_spec_id'] = null;
        }

        Station::create($data);
        return redirect()->back()->with('flash', ['message' => 'Station saved', 'type' => 'success']);
    }

    // Store multiple stations at once
    public function storeBulk(Request $request)
    {
        $validated = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'starting_number' => 'required|string|max:255',
            'campaign_id' => 'required|exists:campaigns,id',
            'status' => 'required|string|max:255',
            'monitor_type' => 'required|in:single,dual',
            'pc_spec_id' => 'nullable|exists:pc_specs,id',
            'pc_spec_ids' => 'nullable|array',
            'pc_spec_ids.*' => 'exists:pc_specs,id',
            'quantity' => 'required|integer|min:1|max:100',
            'increment_type' => 'required|in:number,letter,both',
        ], [], [
            'site_id' => 'site',
            'starting_number' => 'starting station number',
            'campaign_id' => 'campaign',
            'monitor_type' => 'monitor type',
            'pc_spec_id' => 'PC spec',
            'pc_spec_ids' => 'PC specs',
            'increment_type' => 'increment type',
        ]);

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
                if (!empty($pcSpecIds) && isset($pcSpecIds[$index])) {
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
        if (!empty($existingNumbers)) {
            return redirect()->back()
                ->withErrors(['starting_number' => 'The following station numbers already exist: ' . implode(', ', array_slice($existingNumbers, 0, 5)) . (count($existingNumbers) > 5 ? '...' : '')])
                ->withInput();
        }
        // Bulk insert all stations using Eloquent create()
        foreach ($createdStations as $stationData) {
            Station::create($stationData);
        }

        return redirect()->back()->with('flash', [
            'message' => "Successfully created {$quantity} station(s)",
            'type' => 'success'
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
            $numbers[] = $prefix . $paddedNum . $newLetter . $suffix;
        }

        return $numbers;
    }

    // Generate simple sequence for numbers without patterns
    private function generateSimpleSequence(string $base, int $quantity): array
    {
        $numbers = [];
        for ($i = 1; $i <= $quantity; $i++) {
            $numbers[] = $base . $i;
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
    public function update(Request $request, Station $station)
    {
        $data = $this->validateStation($request, $station);

        // Convert any letters in station_number to uppercase
        $data['station_number'] = $this->normalizeStationNumber($data['station_number']);

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
        return PcSpec::with(['ramSpecs', 'diskSpecs', 'processorSpecs'])
            ->get()
            ->map(fn($pc) => $pc->getFormSelectionData());
    }

    private function validateStation(Request $request, ?Station $station = null): array
    {
        $uniqueRule = $station
            ? "unique:stations,station_number,{$station->id}"
            : 'unique:stations,station_number';

        return $request->validate([
            'site_id' => 'required|exists:sites,id',
            'station_number' => "required|string|max:255|{$uniqueRule}",
            'campaign_id' => 'required|exists:campaigns,id',
            'status' => 'required|string|max:255',
            'monitor_type' => 'required|in:single,dual',
            'pc_spec_id' => 'nullable|exists:pc_specs,id',
        ], [
            'station_number.unique' => 'The station number has already been used.',
        ], [
            'site_id' => 'site',
            'station_number' => 'station number',
            'campaign_id' => 'campaign',
            'monitor_type' => 'monitor type',
            'pc_spec_id' => 'PC spec',
        ]);
    }
}
