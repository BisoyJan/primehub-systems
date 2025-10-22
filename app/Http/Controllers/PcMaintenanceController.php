<?php

namespace App\Http\Controllers;

use App\Models\PcMaintenance;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\Station;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class PcMaintenanceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = PcMaintenance::with(['station.site', 'station.pcSpec']);

        // Filter by status
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Filter by site
        if ($request->has('site') && $request->site !== '') {
            $query->whereHas('station', function ($q) use ($request) {
                $q->where('site_id', $request->site);
            });
        }

        // Filter by Station number or PC number
        if ($request->has('search') && $request->search !== '') {
            $query->whereHas('station', function ($q) use ($request) {
                $q->where('station_number', 'like', '%' . $request->search . '%')
                  ->orWhereHas('pcSpec', function ($pcQ) use ($request) {
                      $pcQ->where('pc_number', 'like', '%' . $request->search . '%');
                  });
            });
        }

        // Auto-update overdue statuses
        $this->updateOverdueStatuses();

        $maintenances = $query->orderBy('next_due_date', 'asc')
            ->paginate(10)
            ->withQueryString();

        $sites = Site::orderBy('name')->get();

        return Inertia::render('PcMaintenance/Index', [
            'maintenances' => $maintenances,
            'sites' => $sites,
            'filters' => $request->only(['status', 'search', 'site']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Get all stations with PC specs and site info
        $stations = Station::with(['pcSpec', 'site'])
            ->whereNotNull('pc_spec_id') // Only stations with assigned PCs
            ->orderBy('station_number')
            ->get();

        $sites = Site::orderBy('name')->get();

        return Inertia::render('PcMaintenance/Create', [
            'stations' => $stations,
            'sites' => $sites,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'station_ids' => 'required|array|min:1',
            'station_ids.*' => 'exists:stations,id',
            'last_maintenance_date' => 'required|date',
            'next_due_date' => 'required|date|after:last_maintenance_date',
            'maintenance_type' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'performed_by' => 'nullable|string|max:255',
            'status' => 'required|in:completed,pending,overdue',
        ]);

        // Create maintenance record for each selected station
        $records = [];
        foreach ($validated['station_ids'] as $stationId) {
            $records[] = [
                'station_id' => $stationId,
                'last_maintenance_date' => $validated['last_maintenance_date'],
                'next_due_date' => $validated['next_due_date'],
                'maintenance_type' => $validated['maintenance_type'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'performed_by' => $validated['performed_by'] ?? null,
                'status' => $validated['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        PcMaintenance::insert($records);

        return redirect()->route('pc-maintenance.index')
            ->with('success', count($records) . ' PC Maintenance record(s) created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(PcMaintenance $pcMaintenance)
    {
        $pcMaintenance->load('pcSpec');

        return Inertia::render('PcMaintenance/Show', [
            'maintenance' => $pcMaintenance,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PcMaintenance $pcMaintenance)
    {
        $stations = Station::with(['pcSpec', 'site'])
            ->whereNotNull('pc_spec_id')
            ->orderBy('station_number')
            ->get();

        $sites = Site::orderBy('name')->get();

        return Inertia::render('PcMaintenance/Edit', [
            'maintenance' => $pcMaintenance->load('station.pcSpec', 'station.site'),
            'stations' => $stations,
            'sites' => $sites,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PcMaintenance $pcMaintenance)
    {
        $validated = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'last_maintenance_date' => 'required|date',
            'next_due_date' => 'required|date|after:last_maintenance_date',
            'maintenance_type' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'performed_by' => 'nullable|string|max:255',
            'status' => 'required|in:completed,pending,overdue',
        ]);

        $pcMaintenance->update($validated);

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
    private function updateOverdueStatuses()
    {
        PcMaintenance::where('next_due_date', '<', Carbon::now())
            ->where('status', '!=', 'completed')
            ->update(['status' => 'overdue']);
    }
}
