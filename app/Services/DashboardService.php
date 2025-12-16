<?php

namespace App\Services;

use App\Models\Station;
use App\Models\PcSpec;
use App\Models\PcMaintenance;
use App\Models\ItConcern;
use App\Models\Site;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\AttendancePoint;
use App\Models\User;
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
     * @deprecated Drive type is no longer tracked in disk_specs
     */
    public function getPcsWithSsd(): array
    {
        return [
            'total' => 0,
            'details' => []
        ];
    }

    /**
     * Get PCs with HDD drives
     * @deprecated Drive type is no longer tracked in disk_specs
     */
    public function getPcsWithHdd(): array
    {
        return [
            'total' => 0,
            'details' => []
        ];
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

        $maintenances = PcMaintenance::with(['pcSpec.stations.site'])
            ->where(function($query) use ($now) {
                $query->where('status', 'overdue')
                      ->orWhere(function($q) use ($now) {
                          $q->where('status', 'pending')
                            ->where('next_due_date', '<', $now);
                      });
            })
            ->orderBy('next_due_date', 'asc')
            ->get();

        $stations = $maintenances->flatMap(function($maintenance) use ($now) {
            $dueDate = Carbon::parse($maintenance->next_due_date);
            $daysOverdue = abs($now->diffInDays($dueDate, false));

            // Get all stations that have this PC spec assigned
            $pcStations = $maintenance->pcSpec?->stations ?? collect();

            if ($pcStations->isEmpty()) {
                // If no station is assigned, still report the maintenance with PC number
                return [[
                    'station' => $maintenance->pcSpec?->pc_number ?? 'Unknown PC',
                    'site' => 'Unassigned',
                    'dueDate' => $dueDate->format('Y-m-d'),
                    'daysOverdue' => $this->formatDaysOverdue($daysOverdue)
                ]];
            }

            return $pcStations->map(fn($station) => [
                'station' => $station->station_number,
                'site' => $station->site?->name ?? 'Unknown Site',
                'dueDate' => $dueDate->format('Y-m-d'),
                'daysOverdue' => $this->formatDaysOverdue($daysOverdue)
            ]);
        })->toArray();

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
    public function getAllStats(?string $presenceDate = null, ?string $leaveCalendarMonth = null): array
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
            'itConcernStats' => $this->getItConcernStats(),
            'itConcernTrends' => $this->getItConcernTrends(),
            'presenceInsights' => $this->getPresenceInsights($presenceDate, $leaveCalendarMonth),
        ];
    }

    /**
     * Get IT concern counts by status for quick dashboard view
     */
    public function getItConcernStats(): array
    {
        $statuses = ['pending', 'in_progress', 'resolved'];

        $counts = ItConcern::select('status', DB::raw('COUNT(*) as total'))
            ->whereIn('status', $statuses)
            ->groupBy('status')
            ->pluck('total', 'status');

        $siteAggregates = ItConcern::select('site_id', 'status', DB::raw('COUNT(*) as total'))
            ->whereIn('status', $statuses)
            ->groupBy('site_id', 'status')
            ->get()
            ->groupBy('site_id');

        $sites = Site::select('id', 'name')->orderBy('name')->get();

        $bySite = $sites->map(function ($site) use ($siteAggregates, $statuses) {
            $statusCounts = array_fill_keys($statuses, 0);

            if ($siteAggregates->has($site->id)) {
                foreach ($siteAggregates[$site->id] as $aggregate) {
                    $statusCounts[$aggregate->status] = (int) $aggregate->total;
                }
            }

            $total = array_sum($statusCounts);

            return [
                'site' => $site->name,
                'pending' => $statusCounts['pending'],
                'in_progress' => $statusCounts['in_progress'],
                'resolved' => $statusCounts['resolved'],
                'total' => $total,
            ];
        })
        ->filter(fn ($row) => $row['total'] > 0)
        ->values()
        ->toArray();

        return [
            'pending' => (int) ($counts['pending'] ?? 0),
            'in_progress' => (int) ($counts['in_progress'] ?? 0),
            'resolved' => (int) ($counts['resolved'] ?? 0),
            'bySite' => $bySite,
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
     * Format days overdue text
     */
    private function formatDaysOverdue(int $days): string
    {
        return $days === 1 ? '1 day overdue' : "$days days overdue";
    }

    /**
     * Get monthly IT concern trend data for charts.
     */
    public function getItConcernTrends(): array
    {
        $startDate = now()->subMonths(11)->startOfMonth();

        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");

        if ($connection === 'sqlite') {
            $monthKeyExpr = "strftime('%Y-%m', created_at)";
            $labelExpr = "strftime('%m %Y', created_at)"; // SQLite doesn't have %b, will need to format in PHP
        } else {
            // MySQL/MariaDB
            $monthKeyExpr = 'DATE_FORMAT(created_at, "%Y-%m")';
            $labelExpr = 'DATE_FORMAT(created_at, "%b %Y")';
        }

        return ItConcern::selectRaw("
                {$monthKeyExpr} as month_key,
                {$labelExpr} as label,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved
            ")
            ->where('created_at', '>=', $startDate)
            ->groupBy('month_key', 'label')
            ->orderBy('month_key')
            ->get()
            ->map(function ($row) {
                return [
                    'month' => $row->month_key,
                    'label' => $row->label,
                    'total' => (int) $row->total,
                    'pending' => (int) $row->pending,
                    'in_progress' => (int) $row->in_progress,
                    'resolved' => (int) $row->resolved,
                ];
            })
            ->toArray();
    }

    /**
     * Get presence insights statistics
     */
    public function getPresenceInsights(?string $date = null, ?string $leaveCalendarMonth = null): array
    {
        $today = $date ?? now()->toDateString();

        // Get today's attendance stats
        $todayAttendance = Attendance::whereDate('shift_date', $today)
            ->where('admin_verified', true)
            ->get();

        $totalScheduled = User::whereIn('role', ['Agent', 'Team Lead', 'IT', 'Utility'])
            ->where('is_approved', true)
            ->whereHas('employeeSchedules', function ($query) {
                $query->where('is_active', true);
            })
            ->count();

        $present = $todayAttendance->whereIn('status', ['on_time', 'tardy', 'undertime', 'undertime_more_than_hour'])->count();
        $absent = $todayAttendance->whereIn('status', ['ncns', 'advised_absence', 'half_day_absence'])->count();

        // Get employees currently on leave (approved leaves for today)
        $onLeaveToday = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->count();

        // Get leave calendar data for the specified month (or current month if not provided)
        $calendarDate = $leaveCalendarMonth ? \Carbon\Carbon::parse($leaveCalendarMonth) : now();
        $startOfMonth = $calendarDate->copy()->startOfMonth()->toDateString();
        $endOfMonth = $calendarDate->copy()->endOfMonth()->toDateString();

        $leaveCalendarData = LeaveRequest::with(['user:id,first_name,last_name,role', 'user.employeeSchedules.campaign:id,name'])
            ->where('status', 'approved')
            ->where(function ($query) use ($startOfMonth, $endOfMonth) {
                $query->whereBetween('start_date', [$startOfMonth, $endOfMonth])
                    ->orWhereBetween('end_date', [$startOfMonth, $endOfMonth])
                    ->orWhere(function ($q) use ($startOfMonth, $endOfMonth) {
                        $q->where('start_date', '<=', $startOfMonth)
                          ->where('end_date', '>=', $endOfMonth);
                    });
            })
            ->orderBy('start_date')
            ->get()
            ->map(function($leave) {
                $activeSchedule = $leave->user->employeeSchedules->where('is_active', true)->first();
                return [
                    'id' => $leave->id,
                    'user_id' => $leave->user_id,
                    'user_name' => $leave->user->last_name . ', ' . $leave->user->first_name,
                    'user_role' => $leave->user->role,
                    'campaign_name' => $activeSchedule?->campaign?->name ?? 'No Campaign',
                    'leave_type' => $leave->leave_type,
                    'start_date' => $leave->start_date->format('Y-m-d'),
                    'end_date' => $leave->end_date->format('Y-m-d'),
                    'days_requested' => $leave->days_requested,
                    'reason' => $leave->reason,
                ];
            })
            ->toArray();

        // Get attendance points statistics
        $allPoints = AttendancePoint::with('user:id,first_name,last_name,role')
            ->where('is_excused', false)
            ->where('is_expired', false)
            ->get();

        $totalActivePoints = $allPoints->sum('points');
        $totalViolations = $allPoints->count();

        // Get high risk employees (6+ points)
        $highRiskEmployees = $allPoints->groupBy('user_id')
            ->map(function ($userPoints) {
                $user = $userPoints->first()->user;
                $totalPoints = $userPoints->sum('points');

                if ($totalPoints >= 6) {
                    return [
                        'user_id' => $user->id,
                        'user_name' => $user->last_name . ', ' . $user->first_name,
                        'user_role' => $user->role,
                        'total_points' => round($totalPoints, 2),
                        'violations_count' => $userPoints->count(),
                        'points' => $userPoints->sortByDesc('shift_date')->take(5)->map(fn($point) => [
                            'id' => $point->id,
                            'shift_date' => $point->shift_date,
                            'point_type' => $point->point_type,
                            'points' => $point->points,
                            'violation_details' => $point->violation_details,
                            'expires_at' => $point->expires_at,
                        ])->values()->toArray(),
                    ];
                }
                return null;
            })
            ->filter()
            ->sortByDesc('total_points')
            ->values()
            ->toArray();

        // Points by type breakdown
        $pointsByType = [
            'whole_day_absence' => $allPoints->where('point_type', 'whole_day_absence')->sum('points'),
            'half_day_absence' => $allPoints->where('point_type', 'half_day_absence')->sum('points'),
            'tardy' => $allPoints->where('point_type', 'tardy')->sum('points'),
            'undertime' => $allPoints->where('point_type', 'undertime')->sum('points'),
            'undertime_more_than_hour' => $allPoints->where('point_type', 'undertime_more_than_hour')->sum('points'),
        ];

        // Monthly attendance points trend (last 6 months)
        $startDate = now()->subMonths(5)->startOfMonth();
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");

        if ($connection === 'sqlite') {
            $monthKeyExpr = "strftime('%Y-%m', shift_date)";
        } else {
            $monthKeyExpr = 'DATE_FORMAT(shift_date, "%Y-%m")';
        }

        $pointsTrend = AttendancePoint::selectRaw("
                {$monthKeyExpr} as month,
                SUM(CASE WHEN is_excused = 0 AND is_expired = 0 THEN points ELSE 0 END) as total_points,
                COUNT(CASE WHEN is_excused = 0 AND is_expired = 0 THEN 1 END) as violations_count
            ")
            ->where('shift_date', '>=', $startDate)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function ($row) {
                $date = Carbon::createFromFormat('Y-m', $row->month);
                return [
                    'month' => $row->month,
                    'label' => $date->format('M Y'),
                    'total_points' => round((float) $row->total_points, 2),
                    'violations_count' => (int) $row->violations_count,
                ];
            })
            ->toArray();

        return [
            'todayPresence' => [
                'total_scheduled' => $totalScheduled,
                'present' => $present,
                'absent' => $absent,
                'on_leave' => $onLeaveToday,
                'unaccounted' => max(0, $totalScheduled - $present - $absent - $onLeaveToday),
            ],
            'leaveCalendar' => $leaveCalendarData,
            'attendancePoints' => [
                'total_active_points' => round($totalActivePoints, 2),
                'total_violations' => $totalViolations,
                'high_risk_count' => count($highRiskEmployees),
                'high_risk_employees' => $highRiskEmployees,
                'by_type' => $pointsByType,
                'trend' => $pointsTrend,
            ],
        ];
    }
}
