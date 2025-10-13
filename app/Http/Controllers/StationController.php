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
        $search = $request->query('search');
        $perPage = 10;

        $query = Station::query()->with(['site', 'pcSpec', 'campaign']);
        if ($search) {
            $query->where('station_number', 'like', "%{$search}%")
                ->orWhereHas('site', fn($q) => $q->where('name', 'like', "%{$search}%"))
                ->orWhereHas('campaign', fn($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $paginated = $query->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $items = $paginated->getCollection()->map(function (Station $station) {
            return [
                'id' => $station->id,
                'site' => $station->site?->name,
                'station_number' => $station->station_number,
                'campaign' => $station->campaign?->name,
                'status' => $station->status,
                'pc_spec' => $station->pcSpec?->model,
                'created_at' => optional($station->created_at)->toDateTimeString(),
                'updated_at' => optional($station->updated_at)->toDateTimeString(),
            ];
        })->toArray();

        $paginatorArray = $paginated->toArray();
        $links = $paginatorArray['links'] ?? [];

        return Inertia::render('Station/Index', [
            'stations' => [
                'data' => $items,
                'links' => $links,
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'flash' => session('flash') ?? null,
        ]);
    }

    // Show create form
    public function create()
    {
        $sites = Site::all(['id', 'name']);
        $campaigns = Campaign::all(['id', 'name']);
        $pcSpecs = PcSpec::with(['ramSpecs', 'diskSpecs', 'processorSpecs'])->get()->map(function ($pc) {
            return [
                'id' => $pc->id,
                'model' => $pc->model,
                'ram' => $pc->ramSpecs->map(fn($ram) => $ram->model)->implode(', '),
                'ram_gb' => $pc->ramSpecs->map(fn($ram) => $ram->capacity_gb)->implode(' + '),
                'disk' => $pc->diskSpecs->map(fn($disk) => $disk->model)->implode(', '),
                'disk_gb' => $pc->diskSpecs->map(fn($disk) => $disk->capacity_gb)->implode(' + '),
                'processor' => $pc->processorSpecs->pluck('model')->implode(', '),
            ];
        });
        $usedPcSpecIds = Station::pluck('pc_spec_id')->toArray();
        return Inertia::render('Station/Create', [
            'sites' => $sites,
            'campaigns' => $campaigns,
            'pcSpecs' => $pcSpecs,
            'usedPcSpecIds' => $usedPcSpecIds,
        ]);
    }

    // Store new station
    public function store(Request $request)
    {
        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'station_number' => 'required|string|max:255',
            'campaign_id' => 'required|exists:campaigns,id',
            'status' => 'required|string|max:255',
            'pc_spec_id' => 'required|exists:pc_specs,id',
        ]);
        Station::create($data);
        return redirect()->back()->with('flash', ['message' => 'Station saved', 'type' => 'success']);
    }

    // Show edit form
    public function edit(Station $station)
    {
        $sites = Site::all(['id', 'name']);
        $campaigns = Campaign::all(['id', 'name']);
        $pcSpecs = PcSpec::with(['ramSpecs', 'diskSpecs', 'processorSpecs'])->get()->map(function ($pc) {
            return [
                'id' => $pc->id,
                'model' => $pc->model,
                'ram' => $pc->ramSpecs->map(fn($ram) => $ram->model)->implode(', '),
                'ram_gb' => $pc->ramSpecs->map(fn($ram) => $ram->capacity_gb)->implode(' + '),
                'disk' => $pc->diskSpecs->map(fn($disk) => $disk->model)->implode(', '),
                'disk_gb' => $pc->diskSpecs->map(fn($disk) => $disk->capacity_gb)->implode(' + '),
                'processor' => $pc->processorSpecs->pluck('model')->implode(', '),
            ];
        });
        $usedPcSpecIds = Station::pluck('pc_spec_id')->toArray();
        return Inertia::render('Station/Edit', [
            'station' => $station,
            'sites' => $sites,
            'campaigns' => $campaigns,
            'pcSpecs' => $pcSpecs,
            'usedPcSpecIds' => $usedPcSpecIds,
        ]);
    }

    // Update station
    public function update(Request $request, Station $station)
    {
        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'station_number' => 'required|string|max:255',
            'campaign_id' => 'required|exists:campaigns,id',
            'status' => 'required|string|max:255',
            'pc_spec_id' => 'required|exists:pc_specs,id',
        ]);
        $station->update($data);
        return redirect()->back()->with('flash', ['message' => 'Station updated', 'type' => 'success']);
    }

    // Delete station
    public function destroy(Station $station)
    {
        $station->delete();
        return redirect()->back()->with('flash', ['message' => 'Station deleted', 'type' => 'success']);
    }
}
