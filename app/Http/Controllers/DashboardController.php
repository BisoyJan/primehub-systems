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
        $dashboardData = Cache::remember('dashboard_stats', 150, function () {
       return [
            'totalStations' => $this->getTotalStations(),
            'noPcs' => $this->getStationsWithoutPcs(),
            'vacantStations' => $this->getVacantStations(),
            'ssdPcs' => $this->getPcsWithSsd(),
            'hddPcs' => $this->getPcsWithHdd(),
            'dualMonitor' => $this->getDualMonitorStations(),
            'maintenanceDue' => $this->getMaintenanceDue(),
            'lastMaintenance' => $this->getLastMaintenance(),
            'avgDaysOverdue' => $this->getAverageDaysOverdue(),
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

                return [
                    'station' => $maintenance->station->station_number,
                    'site' => $maintenance->station->site->name,
                    'dueDate' => $dueDate->format('Y-m-d'),
                    'daysOverdue' => abs($daysOverdue)
                ];
            })
            ->toArray();

        return [
            'total' => count($stations),
            'stations' => $stations
        ];
    }

    /**
     * Get last maintenance record
     */
    public function getLastMaintenance(): array
    {
        $lastMaintenance = PcMaintenance::with(['station.site'])
            ->where('status', 'completed')
            ->orderBy('last_maintenance_date', 'desc')
            ->first();

        if (!$lastMaintenance) {
            return [
                'date' => 'N/A',
                'station' => 'N/A',
                'site' => 'N/A',
                'performedBy' => 'N/A'
            ];
        }

        return [
            'date' => Carbon::parse($lastMaintenance->last_maintenance_date)->format('Y-m-d'),
            'station' => $lastMaintenance->station->station_number,
            'site' => $lastMaintenance->station->site->name,
            'performedBy' => $lastMaintenance->performed_by ?? 'N/A'
        ];
    }

    /**
     * Get average days overdue for maintenance by site
     */
    public function getAverageDaysOverdue(): array
    {
        $now = Carbon::now();

        $overdueMaintenance = PcMaintenance::with(['station.site'])
            ->where('status', 'overdue')
            ->orWhere(function($q) use ($now) {
                $q->where('status', 'pending')
                  ->where('next_due_date', '<', $now);
            })
            ->get();

        if ($overdueMaintenance->isEmpty()) {
            return [
                'average' => 0,
                'bySite' => []
            ];
        }

        // Calculate by site
        $bySite = $overdueMaintenance->groupBy(function($item) {
            return $item->station->site->name;
        })->map(function($siteMaintenances) use ($now) {
            $totalDays = $siteMaintenances->sum(function($maintenance) use ($now) {
                $dueDate = Carbon::parse($maintenance->next_due_date);
                return abs($now->diffInDays($dueDate, false));
            });

            return round($totalDays / $siteMaintenances->count());
        });

        // Calculate overall average
        $totalOverdueDays = $overdueMaintenance->sum(function($maintenance) use ($now) {
            $dueDate = Carbon::parse($maintenance->next_due_date);
            return abs($now->diffInDays($dueDate, false));
        });

        $average = round($totalOverdueDays / $overdueMaintenance->count());

        return [
            'average' => $average,
            'bySite' => $bySite->map(fn($days, $site) => [
                'site' => $site,
                'days' => $days
            ])->values()->toArray()
        ];
    }
}
