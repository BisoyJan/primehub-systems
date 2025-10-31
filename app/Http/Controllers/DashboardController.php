<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\PcMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        /**
         * Retrieves dashboard statistics from the cache or computes them if not present.
         * The cached data is stored under the key 'dashboard_stats' for 15 minutes.
         *
         * @return mixed The dashboard statistics data.
         */
        $dashboardData = Cache::remember('dashboard_stats', 150, function () {
            return [
                'totalStations' => $this->getTotalStations(),
                'noPcs' => $this->getStationsWithoutPcs(),
                'vacantStations' => $this->getVacantStations(),
                'ssdPcs' => $this->getPcsWithSsd(),
                'hddPcs' => $this->getPcsWithHdd(),
                'dualMonitor' => $this->getDualMonitorStations(),
                'maintenanceDue' => $this->getMaintenanceDue(),
                'unassignedPcSpecs' => $this->getUnassignedPcSpecs(),
            ];
        });

        return Inertia::render('dashboard', $dashboardData);
    }

    /**
     * Get total stations count and breakdown by site
     */
    public function getTotalStations(): array
    {
        $total = Station::count();

        $bySite = Station::select('sites.name as site', DB::raw('COUNT(stations.id) as count'))
            ->join('sites', 'stations.site_id', '=', 'sites.id')
            ->groupBy('sites.name')
            ->orderBy('sites.name')
            ->get()
            ->map(fn($item) => [
                'site' => $item->site,
                'count' => (int) $item->count
            ])
            ->toArray();

        return [
            'total' => $total,
            'bysite' => $bySite
        ];
    }

    /**
     * Get stations without PCs assigned
     */
    public function getStationsWithoutPcs(): array
    {
        $stations = Station::with(['site', 'campaign'])
            ->whereNull('pc_spec_id')
            ->orderBy('station_number')
            ->get()
            ->map(fn($station) => [
                'station' => $station->station_number,
                'site' => $station->site->name,
                'campaign' => $station->campaign->name,
            ])
            ->toArray();

        return [
            'total' => count($stations),
            'stations' => $stations
        ];
    }

    /**
     * Get vacant stations breakdown by site
     */
    public function getVacantStations(): array
    {
        $total = Station::where('status', 'Vacant')->count();

        $bySite = Station::select('sites.name as site', DB::raw('COUNT(stations.id) as count'))
            ->join('sites', 'stations.site_id', '=', 'sites.id')
            ->where('stations.status', 'Vacant')
            ->groupBy('sites.name')
            ->orderBy('sites.name')
            ->get()
            ->map(fn($item) => [
                'site' => $item->site,
                'count' => (int) $item->count
            ])
            ->toArray();

        $stations = Station::with('site')
            ->where('status', 'Vacant')
            ->get()
            ->map(fn($station) => [
                'site' => $station->site->name,
                'station_number' => $station->station_number
            ])
            ->toArray();

        return [
            'total' => $total,
            'bysite' => $bySite,
            'stations' => $stations
        ];
    }

    /**
     * Get PCs with SSD drives
     */
    public function getPcsWithSsd(): array
    {
        $pcIds = DB::table('pc_spec_disk_spec')
            ->join('disk_specs', 'pc_spec_disk_spec.disk_spec_id', '=', 'disk_specs.id')
            ->where('disk_specs.drive_type', 'SSD')
            ->distinct()
            ->pluck('pc_spec_disk_spec.pc_spec_id');

        $total = $pcIds->count();

        $details = Station::select('sites.name as site', DB::raw('COUNT(DISTINCT stations.pc_spec_id) as count'))
            ->join('sites', 'stations.site_id', '=', 'sites.id')
            ->whereIn('stations.pc_spec_id', $pcIds)
            ->whereNotNull('stations.pc_spec_id')
            ->groupBy('sites.name')
            ->orderBy('sites.name')
            ->get()
            ->map(fn($item) => [
                'site' => $item->site,
                'count' => (int) $item->count
            ])
            ->toArray();

        return [
            'total' => $total,
            'details' => $details
        ];
    }

    /**
     * Get PCs with HDD drives
     */
    public function getPcsWithHdd(): array
    {
        $pcIds = DB::table('pc_spec_disk_spec')
            ->join('disk_specs', 'pc_spec_disk_spec.disk_spec_id', '=', 'disk_specs.id')
            ->where('disk_specs.drive_type', 'HDD')
            ->distinct()
            ->pluck('pc_spec_disk_spec.pc_spec_id');

        $total = $pcIds->count();

        $details = Station::select('sites.name as site', DB::raw('COUNT(DISTINCT stations.pc_spec_id) as count'))
            ->join('sites', 'stations.site_id', '=', 'sites.id')
            ->whereIn('stations.pc_spec_id', $pcIds)
            ->whereNotNull('stations.pc_spec_id')
            ->groupBy('sites.name')
            ->orderBy('sites.name')
            ->get()
            ->map(fn($item) => [
                'site' => $item->site,
                'count' => (int) $item->count
            ])
            ->toArray();

        return [
            'total' => $total,
            'details' => $details
        ];
    }

    /**
     * Get stations with dual monitor setup
     */
    public function getDualMonitorStations(): array
    {
        $total = Station::where('monitor_type', 'dual')->count();

        $bySite = Station::select('sites.name as site', DB::raw('COUNT(stations.id) as count'))
            ->join('sites', 'stations.site_id', '=', 'sites.id')
            ->where('stations.monitor_type', 'dual')
            ->groupBy('sites.name')
            ->orderBy('sites.name')
            ->get()
            ->map(fn($item) => [
                'site' => $item->site,
                'count' => (int) $item->count
            ])
            ->toArray();

        return [
            'total' => $total,
            'bysite' => $bySite
        ];
    }

    /**
     * Get stations with maintenance due/overdue
     */
    public function getMaintenanceDue(): array
    {
        $now = Carbon::now();

        $stations = PcMaintenance::with(['station.site'])
            ->where(function($query) use ($now) {
                $query->where('status', 'overdue')
                      ->orWhere(function($q) use ($now) {
                          $q->where('status', 'pending')
                            ->where('next_due_date', '<', $now);
                      });
            })
            ->orderBy('next_due_date', 'asc')
            ->get()
            ->map(function($maintenance) use ($now) {
                $dueDate = Carbon::parse($maintenance->next_due_date);
                $daysOverdue = $now->diffInDays($dueDate, false);

                $days = abs((int) $daysOverdue);
                $daysText = $days === 1 ? '1 day overdue' : "$days days overdue";
                return [
                    'station' => $maintenance->station->station_number,
                    'site' => $maintenance->station->site->name,
                    'dueDate' => $dueDate->format('Y-m-d'),
                    'daysOverdue' => $daysText
                ];
            })
            ->toArray();

        return [
            'total' => count($stations),
            'stations' => $stations
        ];
    }


    /**
     * Get available PC specs not assigned to any station
     */
    public function getUnassignedPcSpecs(): array
    {
        $unassigned = PcSpec::whereDoesntHave('stations')->get();
        return $unassigned->map(fn($pc) => [
            'id' => $pc->id,
            'pc_number' => $pc->pc_number,
            'model' => $pc->model,
            'ram' => $pc->ramSpecs->map(fn($ram) => $ram->model)->implode(', '),
            'ram_gb' => $pc->ramSpecs->sum('capacity_gb'),
            'ram_count' => $pc->ramSpecs->count(),
            'disk' => $pc->diskSpecs->map(fn($disk) => $disk->model)->implode(', '),
            'disk_tb' => round($pc->diskSpecs->sum('capacity_gb') / 1024, 2),
            'disk_count' => $pc->diskSpecs->count(),
            'processor' => $pc->processorSpecs->pluck('model')->implode(', '),
            'cpu_count' => $pc->processorSpecs->count(),
            'issue' => $pc->issue,
        ])->toArray();
    }

}
