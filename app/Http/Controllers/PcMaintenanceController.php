<?php

namespace App\Http\Controllers;

use App\Http\Requests\PcMaintenanceRequest;
use App\Models\PcMaintenance;
use App\Models\PcSpec;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PcMaintenanceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $query = PcMaintenance::with(['pcSpec.stations.site']);

        // Filter by assignment status (default: only PCs assigned to a station)
        $assignment = $request->input('assignment', 'assigned');
        if ($assignment === 'assigned') {
            $query->whereHas('pcSpec.stations');
        } elseif ($assignment === 'unassigned') {
            $query->whereDoesntHave('pcSpec.stations');
        }
        // 'all' shows everything

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by site (via pc_spec -> stations -> site)
        if ($request->filled('site')) {
            $query->whereHas('pcSpec.stations', fn ($q) => $q->where('site_id', $request->site));
        }

        // Filter by PC number
        if ($request->filled('search')) {
            $query->whereHas('pcSpec', fn ($q) => $q->where('pc_number', 'like', "%{$request->search}%")
                ->orWhere('model', 'like', "%{$request->search}%")
            );
        }

        // Filter by station number range (e.g., 1 to 20 for stations 1E-20E)
        if ($request->filled('station_from') || $request->filled('station_to')) {
            $stationFrom = $request->input('station_from');
            $stationTo = $request->input('station_to');

            $query->whereHas('pcSpec.stations', function ($q) use ($stationFrom, $stationTo) {
                if ($stationFrom !== null && $stationTo !== null) {
                    $q->whereRaw('CAST(station_number AS UNSIGNED) BETWEEN ? AND ?', [(int) $stationFrom, (int) $stationTo]);
                } elseif ($stationFrom !== null) {
                    $q->whereRaw('CAST(station_number AS UNSIGNED) >= ?', [(int) $stationFrom]);
                } elseif ($stationTo !== null) {
                    $q->whereRaw('CAST(station_number AS UNSIGNED) <= ?', [(int) $stationTo]);
                }
            });
        }

        // Auto-update overdue statuses
        $this->updateOverdueStatuses();

        // Sort by station number numerically (1E, 2E, 3E... instead of 1E, 10E, 100E...)
        $query->orderByRaw('ISNULL((SELECT CAST(s.station_number AS UNSIGNED) FROM stations s WHERE s.pc_spec_id = pc_maintenances.pc_spec_id LIMIT 1))')
            ->orderByRaw('(SELECT CAST(s.station_number AS UNSIGNED) FROM stations s WHERE s.pc_spec_id = pc_maintenances.pc_spec_id LIMIT 1) ASC');

        // Get all IDs matching current filters (for bulk select all)
        $allMatchingIds = (clone $query)->pluck('id')->toArray();

        $maintenances = $query->paginate(10)
            ->withQueryString();

        // Transform data to include current station info
        $maintenances->through(function ($maintenance) {
            $currentStation = $maintenance->pcSpec?->stations?->first();
            $maintenance->current_station = $currentStation ? [
                'id' => $currentStation->id,
                'station_number' => $currentStation->station_number,
                'site' => $currentStation->site ? [
                    'id' => $currentStation->site->id,
                    'name' => $currentStation->site->name,
                ] : null,
            ] : null;

            return $maintenance;
        });

        $sites = Site::orderBy('name')->get();

        return Inertia::render('Computer/PcMaintenance/Index', [
            'maintenances' => $maintenances,
            'sites' => $sites,
            'filters' => $request->only(['status', 'search', 'site', 'assignment', 'station_from', 'station_to']),
            'allMatchingIds' => $allMatchingIds,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        // Get only PC specs that are assigned to a station, sorted by station number numerically
        $pcSpecs = PcSpec::with(['stations.site'])
            ->whereHas('stations')
            ->orderByRaw('(SELECT CAST(s.station_number AS UNSIGNED) FROM stations s WHERE s.pc_spec_id = pc_specs.id LIMIT 1) ASC')
            ->get()
            ->map(function ($pcSpec) {
                $currentStation = $pcSpec->stations->first();

                return [
                    'id' => $pcSpec->id,
                    'pc_number' => $pcSpec->pc_number,
                    'model' => $pcSpec->model,
                    'manufacturer' => $pcSpec->manufacturer,
                    'current_station' => $currentStation ? [
                        'id' => $currentStation->id,
                        'station_number' => $currentStation->station_number,
                        'site' => $currentStation->site ? [
                            'id' => $currentStation->site->id,
                            'name' => $currentStation->site->name,
                        ] : null,
                    ] : null,
                ];
            });

        $sites = Site::orderBy('name')->get();

        return Inertia::render('Computer/PcMaintenance/Create', [
            'pcSpecs' => $pcSpecs,
            'sites' => $sites,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PcMaintenanceRequest $request)
    {
        $validated = $request->validated();

        $records = collect($validated['pc_spec_ids'])->map(fn ($pcSpecId) => [
            'pc_spec_id' => $pcSpecId,
            'last_maintenance_date' => $validated['last_maintenance_date'],
            'next_due_date' => $validated['next_due_date'],
            'maintenance_type' => $validated['maintenance_type'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'performed_by' => $validated['performed_by'] ?? null,
            'status' => $validated['status'],
            'created_at' => now(),
            'updated_at' => now(),
        ])->toArray();

        PcMaintenance::insert($records);

        return redirect()->route('pc-maintenance.index')
            ->with('success', count($records).' PC Maintenance record(s) created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(PcMaintenance $pcMaintenance): Response
    {
        $pcMaintenance->load('pcSpec.stations.site');

        $currentStation = $pcMaintenance->pcSpec?->stations?->first();
        $pcMaintenance->current_station = $currentStation ? [
            'id' => $currentStation->id,
            'station_number' => $currentStation->station_number,
            'site' => $currentStation->site ? [
                'id' => $currentStation->site->id,
                'name' => $currentStation->site->name,
            ] : null,
        ] : null;

        return Inertia::render('Computer/PcMaintenance/Show', [
            'maintenance' => $pcMaintenance,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PcMaintenance $pcMaintenance): Response
    {
        $pcMaintenance->load('pcSpec.stations.site');

        $currentStation = $pcMaintenance->pcSpec?->stations?->first();
        $pcMaintenance->current_station = $currentStation ? [
            'id' => $currentStation->id,
            'station_number' => $currentStation->station_number,
            'site' => $currentStation->site ? [
                'id' => $currentStation->site->id,
                'name' => $currentStation->site->name,
            ] : null,
        ] : null;

        // Get PCs assigned to stations, plus the currently selected PC
        $currentPcSpecId = $pcMaintenance->pc_spec_id;
        $pcSpecs = PcSpec::with(['stations.site'])
            ->where(function ($q) use ($currentPcSpecId) {
                $q->whereHas('stations')
                    ->orWhere('id', $currentPcSpecId);
            })
            ->orderByRaw('ISNULL((SELECT CAST(s.station_number AS UNSIGNED) FROM stations s WHERE s.pc_spec_id = pc_specs.id LIMIT 1))')
            ->orderByRaw('(SELECT CAST(s.station_number AS UNSIGNED) FROM stations s WHERE s.pc_spec_id = pc_specs.id LIMIT 1) ASC')
            ->get()
            ->map(function ($pcSpec) {
                $currentStation = $pcSpec->stations->first();

                return [
                    'id' => $pcSpec->id,
                    'pc_number' => $pcSpec->pc_number,
                    'model' => $pcSpec->model,
                    'manufacturer' => $pcSpec->manufacturer,
                    'current_station' => $currentStation ? [
                        'id' => $currentStation->id,
                        'station_number' => $currentStation->station_number,
                        'site' => $currentStation->site ? [
                            'id' => $currentStation->site->id,
                            'name' => $currentStation->site->name,
                        ] : null,
                    ] : null,
                ];
            });

        $sites = Site::orderBy('name')->get();

        return Inertia::render('Computer/PcMaintenance/Edit', [
            'maintenance' => $pcMaintenance,
            'pcSpecs' => $pcSpecs,
            'sites' => $sites,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PcMaintenanceRequest $request, PcMaintenance $pcMaintenance)
    {
        $pcMaintenance->update($request->validated());

        return redirect()->route('pc-maintenance.index')
            ->with('success', 'PC Maintenance record updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PcMaintenance $pcMaintenance)
    {
        $pcMaintenance->delete();

        return redirect()->route('pc-maintenance.index')
            ->with('success', 'PC Maintenance record deleted successfully.');
    }

    /**
     * Bulk update maintenance records.
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:pc_maintenances,id',
            'last_maintenance_date' => 'required|date',
            'next_due_date' => 'required|date|after:last_maintenance_date',
            'maintenance_type' => 'nullable|string|max:255',
            'performed_by' => 'nullable|string|max:255',
            'status' => 'required|in:completed,pending,overdue',
            'notes' => 'nullable|string',
        ]);

        $count = PcMaintenance::whereIn('id', $validated['ids'])
            ->update([
                'last_maintenance_date' => $validated['last_maintenance_date'],
                'next_due_date' => $validated['next_due_date'],
                'maintenance_type' => $validated['maintenance_type'] ?? null,
                'performed_by' => $validated['performed_by'] ?? null,
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
            ]);

        return redirect()->route('pc-maintenance.index')
            ->with('success', "{$count} PC Maintenance record(s) updated successfully.");
    }

    /**
     * Update overdue statuses
     */
    private function updateOverdueStatuses(): void
    {
        PcMaintenance::where('next_due_date', '<', Carbon::now())
            ->where('status', '!=', 'completed')
            ->update(['status' => 'overdue']);
    }
}
