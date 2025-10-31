<?php

namespace App\Services;

use App\Models\Station;
use App\Models\PcSpec;
use App\Models\PcMaintenance;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardService
{
    /**
     * Get total stations count and breakdown by site
     */
    public function getTotalStations(): array
    {
        $total = Station::count();
        $bySite = $this->getStationCountBySite();

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
        $query = Station::where('status', 'Vacant');
        $total = $query->count();

        $bySite = $this->getStationCountBySite(['status' => 'Vacant']);

        $stations = $query->with('site')
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
        return $this->getPcsByDriveType('SSD');
    }

    /**
     * Get PCs with HDD drives
     */
    public function getPcsWithHdd(): array
    {
        return $this->getPcsByDriveType('HDD');
    }

    /**
     * Get stations with dual monitor setup
     */
    public function getDualMonitorStations(): array
    {
        $total = Station::where('monitor_type', 'dual')->count();
        $bySite = $this->getStationCountBySite(['monitor_type' => 'dual']);

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
                $daysOverdue = abs($now->diffInDays($dueDate, false));

                return [
                    'station' => $maintenance->station->station_number,
                    'site' => $maintenance->station->site->name,
                    'dueDate' => $dueDate->format('Y-m-d'),
                    'daysOverdue' => $this->formatDaysOverdue($daysOverdue)
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
        $unassigned = PcSpec::with(['ramSpecs', 'diskSpecs', 'processorSpecs'])
            ->whereDoesntHave('stations')
            ->get();

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

    /**
     * Get all dashboard statistics
     */
    public function getAllStats(): array
    {
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
    }

    /**
     * Get station count grouped by site with optional filters
     */
    private function getStationCountBySite(array $conditions = []): array
    {
        $query = Station::select('sites.name as site', DB::raw('COUNT(stations.id) as count'))
            ->join('sites', 'stations.site_id', '=', 'sites.id');

        foreach ($conditions as $column => $value) {
            $query->where("stations.$column", $value);
        }

        return $query->groupBy('sites.name')
            ->orderBy('sites.name')
            ->get()
            ->map(fn($item) => [
                'site' => $item->site,
                'count' => (int) $item->count
            ])
            ->toArray();
    }

    /**
     * Get PCs by drive type (SSD/HDD)
     */
    private function getPcsByDriveType(string $driveType): array
    {
        $pcIds = DB::table('pc_spec_disk_spec')
            ->join('disk_specs', 'pc_spec_disk_spec.disk_spec_id', '=', 'disk_specs.id')
            ->where('disk_specs.drive_type', $driveType)
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
     * Format days overdue text
     */
    private function formatDaysOverdue(int $days): string
    {
        return $days === 1 ? '1 day overdue' : "$days days overdue";
    }
}
