<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\Site;
use App\Models\PcSpec;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
        Station::create($data);
        return redirect()->back()->with('flash', ['message' => 'Station saved', 'type' => 'success']);
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
            'pc_spec_id' => 'required|exists:pc_specs,id',
        ], [
            'station_number.unique' => 'The station number has already been used.',
        ], [
            'site_id' => 'site',
            'station_number' => 'station number',
            'campaign_id' => 'campaign',
            'pc_spec_id' => 'PC spec',
        ]);
    }
}
