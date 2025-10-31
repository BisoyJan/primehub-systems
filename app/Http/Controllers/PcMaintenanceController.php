<?php

namespace App\Http\Controllers;

use App\Models\PcMaintenance;
use App\Models\Site;
use App\Models\Station;
use App\Http\Requests\PcMaintenanceRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;

class PcMaintenanceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $query = PcMaintenance::with(['station.site', 'station.pcSpec']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by site
        if ($request->filled('site')) {
            $query->whereHas('station', fn($q) => $q->where('site_id', $request->site));
        }

        // Filter by Station number or PC number
        if ($request->filled('search')) {
            $query->whereHas('station', function ($q) use ($request) {
                $q->where('station_number', 'like', "%{$request->search}%")
                  ->orWhereHas('pcSpec', fn($pcQ) => 
                      $pcQ->where('pc_number', 'like', "%{$request->search}%")
                  );
            });
        }

        // Auto-update overdue statuses
        $this->updateOverdueStatuses();

        $maintenances = $query->orderBy('next_due_date', 'asc')
            ->paginate(10)
            ->withQueryString();

        $sites = Site::orderBy('name')->get();

        return Inertia::render('Station/PcMaintenance/Index', [
            'maintenances' => $maintenances,
            'sites' => $sites,
            'filters' => $request->only(['status', 'search', 'site']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        $stations = Station::with(['pcSpec', 'site'])
        ->whereNotNull('pc_spec_id')
            ->orderBy('station_number')
            ->get();

        $sites = Site::orderBy('name')->get();

        return Inertia::render('Station/PcMaintenance/Create', [
            'stations' => $stations,
            'sites' => $sites,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PcMaintenanceRequest $request)
    {
        $validated = $request->validated();

        $records = collect($validated['station_ids'])->map(fn($stationId) => [
            'station_id' => $stationId,
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
            ->with('success', count($records) . ' PC Maintenance record(s) created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(PcMaintenance $pcMaintenance): Response
    {
        $pcMaintenance->load('station.site', 'station.pcSpec');

        return Inertia::render('Station/PcMaintenance/Show', [
            'maintenance' => $pcMaintenance,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PcMaintenance $pcMaintenance): Response
    {
        $stations = Station::with(['pcSpec', 'site'])
            ->whereNotNull('pc_spec_id')
            ->orderBy('station_number')
            ->get();

        $sites = Site::orderBy('name')->get();

        return Inertia::render('Station/PcMaintenance/Edit', [
            'maintenance' => $pcMaintenance->load('station.pcSpec', 'station.site'),
            'stations' => $stations,
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
     * Update overdue statuses
     */
    private function updateOverdueStatuses(): void
    {
        PcMaintenance::where('next_due_date', '<', Carbon::now())
            ->where('status', '!=', 'completed')
            ->update(['status' => 'overdue']);
    }
}
