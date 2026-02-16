<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\ItConcern;
use App\Models\LeaveRequest;
use App\Models\MedicationRequest;
use App\Models\Notification;
use App\Models\PcMaintenance;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\Station;
use App\Models\Stock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class DashboardService
{
    /**
     * Cached active attendance points to avoid duplicate queries.
     * Loaded once by getActiveAttendancePoints(), reused by:
     * - getPresenceInsights()
     * - getPointsEscalation()
     * - getPointsByCampaign()
     */
    private ?Collection $activeAttendancePoints = null;

    /**
     * Get active (non-excused, non-expired) attendance points with user relation.
     * Cached in memory so the identical query only runs once per request.
     */
    protected function getActiveAttendancePoints(): Collection
    {
        if ($this->activeAttendancePoints === null) {
            $this->activeAttendancePoints = AttendancePoint::with('user:id,first_name,last_name,role')
                ->where('is_excused', false)
                ->where('is_expired', false)
                ->get();
        }

        return $this->activeAttendancePoints;
    }

    /**
     * Get total stations count and breakdown by site
     */
    public function getTotalStations(): array
    {
        $total = Station::count();
        $bySite = $this->getStationCountBySite();

        return [
            'total' => $total,
            'bysite' => $bySite,
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
            ->map(fn ($station) => [
                'station' => $station->station_number,
                'site' => $station->site->name,
                'campaign' => $station->campaign->name,
            ])
            ->toArray();

        return [
            'total' => count($stations),
            'stations' => $stations,
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
            ->map(fn ($station) => [
                'site' => $station->site->name,
                'station_number' => $station->station_number,
            ])
            ->toArray();

        return [
            'total' => $total,
            'bysite' => $bySite,
            'stations' => $stations,
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
            'bysite' => $bySite,
        ];
    }

    /**
     * Get stations with maintenance due/overdue
     */
    public function getMaintenanceDue(): array
    {
        $now = Carbon::now();

        $maintenances = PcMaintenance::with(['pcSpec.stations.site'])
            ->where(function ($query) use ($now) {
                $query->where('status', 'overdue')
                    ->orWhere(function ($q) use ($now) {
                        $q->where('status', 'pending')
                            ->where('next_due_date', '<', $now);
                    });
            })
            ->orderBy('next_due_date', 'asc')
            ->get();

        $stations = $maintenances->flatMap(function ($maintenance) use ($now) {
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
                    'daysOverdue' => $this->formatDaysOverdue($daysOverdue),
                ]];
            }

            return $pcStations->map(fn ($station) => [
                'station' => $station->station_number,
                'site' => $station->site?->name ?? 'Unknown Site',
                'dueDate' => $dueDate->format('Y-m-d'),
                'daysOverdue' => $this->formatDaysOverdue($daysOverdue),
            ]);
        })->toArray();

        return [
            'total' => count($stations),
            'stations' => $stations,
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

        return $unassigned->map(fn ($pc) => [
            'id' => $pc->id,
            'pc_number' => $pc->pc_number,
            'model' => $pc->model,
            'ram' => $pc->ramSpecs->map(fn ($ram) => $ram->model)->implode(', '),
            'ram_gb' => $pc->ramSpecs->sum('capacity_gb'),
            'ram_count' => $pc->ramSpecs->count(),
            'disk' => $pc->diskSpecs->map(fn ($disk) => $disk->model)->implode(', '),
            'disk_tb' => round($pc->diskSpecs->sum('capacity_gb') / 1024, 2),
            'disk_count' => $pc->diskSpecs->count(),
            'processor' => $pc->processorSpecs->pluck('model')->implode(', '),
            'cpu_count' => $pc->processorSpecs->count(),
            'issue' => $pc->issue,
        ])->toArray();
    }

    /**
     * Get all dashboard statistics, filtered by user role.
     *
     * @param  string  $role  User role to filter stats for
     * @param  string|null  $presenceDate  Date for presence insights
     * @param  string|null  $leaveCalendarMonth  Month for leave calendar
     * @param  int|null  $leaveCalendarCampaignId  Campaign ID to filter leave calendar (for Agents to see same-campaign leaves)
     */
    public function getAllStats(string $role, ?string $presenceDate = null, ?string $leaveCalendarMonth = null, ?int $leaveCalendarCampaignId = null): array
    {
        $data = [];

        // Infrastructure stats — Super Admin, Admin, IT
        if (in_array($role, ['Super Admin', 'Admin', 'IT'])) {
            $data['totalStations'] = $this->getTotalStations();
            $data['noPcs'] = $this->getStationsWithoutPcs();
            $data['vacantStations'] = $this->getVacantStations();

            $data['dualMonitor'] = $this->getDualMonitorStations();
            $data['maintenanceDue'] = $this->getMaintenanceDue();
            $data['unassignedPcSpecs'] = $this->getUnassignedPcSpecs();
        }

        // IT Concern stats — Super Admin, IT
        if (in_array($role, ['Super Admin', 'IT'])) {
            $data['itConcernStats'] = $this->getItConcernStats();
            $data['itConcernTrends'] = $this->getItConcernTrends();
        }

        // Presence insights — Super Admin, Admin, HR, Team Lead, Agent
        if (in_array($role, ['Super Admin', 'Admin', 'HR', 'Team Lead', 'Agent'])) {
            $data['presenceInsights'] = $this->getPresenceInsights($presenceDate, $leaveCalendarMonth, $leaveCalendarCampaignId);
        }

        // Leave conflicts — Super Admin, Admin, HR
        if (in_array($role, ['Super Admin', 'Admin', 'HR'])) {
            $data['leaveConflicts'] = $this->getLeaveConflicts();
        }

        // Stock summary — Super Admin, IT
        if (in_array($role, ['Super Admin', 'IT'])) {
            $data['stockSummary'] = $this->getStockSummary();
        }

        // User account stats — Super Admin, Admin
        if (in_array($role, ['Super Admin', 'Admin'])) {
            $data['userAccountStats'] = $this->getUserAccountStats();
        }

        // Recent activity logs — Super Admin, Admin
        if (in_array($role, ['Super Admin', 'Admin'])) {
            $data['recentActivityLogs'] = $this->getRecentActivityLogs();
        }

        // Biometric anomalies — Super Admin, Admin, HR
        if (in_array($role, ['Super Admin', 'Admin', 'HR'])) {
            $data['biometricAnomalies'] = $this->getBiometricAnomalySummary();
        }

        // Phase 4: Enhanced analytics — Super Admin (Admin/HR partial)
        if (in_array($role, ['Super Admin', 'Admin', 'HR'])) {
            $data['pointsEscalation'] = $this->getPointsEscalation();
            $data['ncnsTrend'] = $this->getNcnsTrend();
            $data['leaveUtilization'] = $this->getLeaveUtilizationData();
        }

        if (in_array($role, ['Super Admin', 'Admin'])) {
            $data['campaignPresence'] = $this->getCampaignPresenceComparison($presenceDate);
            $data['pointsByCampaign'] = $this->getPointsByCampaign();
        }

        return $data;
    }

    /**
     * Get leave conflicts - attendance records where employee has biometric activity during approved leave
     * These require HR/Admin review to either cancel/adjust leave or confirm as accidental clock-in
     */
    public function getLeaveConflicts(): array
    {
        $conflicts = Attendance::with([
            'user:id,first_name,last_name,role',
            'leaveRequest:id,leave_type,start_date,end_date,days_requested',
            'employeeSchedule.campaign:id,name',
        ])
            ->whereNotNull('leave_request_id')
            ->where('status', '!=', 'on_leave')
            ->where('admin_verified', false)
            ->where(function ($q) {
                $q->whereNotNull('actual_time_in')
                    ->orWhereNotNull('actual_time_out');
            })
            ->orderBy('shift_date', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($attendance) {
                return [
                    'id' => $attendance->id,
                    'user_id' => $attendance->user_id,
                    'user_name' => $attendance->user?->last_name.', '.$attendance->user?->first_name,
                    'user_role' => $attendance->user?->role,
                    'campaign_name' => $attendance->employeeSchedule?->campaign?->name ?? 'No Campaign',
                    'shift_date' => $attendance->shift_date,
                    'leave_type' => $attendance->leaveRequest?->leave_type,
                    'leave_start' => $attendance->leaveRequest?->start_date?->format('Y-m-d'),
                    'leave_end' => $attendance->leaveRequest?->end_date?->format('Y-m-d'),
                    'actual_time_in' => $attendance->actual_time_in,
                    'actual_time_out' => $attendance->actual_time_out,
                ];
            })
            ->toArray();

        $totalCount = Attendance::whereNotNull('leave_request_id')
            ->where('status', '!=', 'on_leave')
            ->where('admin_verified', false)
            ->where(function ($q) {
                $q->whereNotNull('actual_time_in')
                    ->orWhereNotNull('actual_time_out');
            })
            ->count();

        return [
            'total' => $totalCount,
            'records' => $conflicts,
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
            ->map(fn ($item) => [
                'site' => $item->site,
                'count' => (int) $item->count,
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
    /**
     * Get presence insights including leave calendar
     *
     * @param  string|null  $date  Date for presence stats
     * @param  string|null  $leaveCalendarMonth  Month for leave calendar
     * @param  int|null  $campaignId  If provided, filter leave calendar to only show users from this campaign
     */
    public function getPresenceInsights(?string $date = null, ?string $leaveCalendarMonth = null, ?int $campaignId = null): array
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

        $leaveCalendarQuery = LeaveRequest::with(['user:id,first_name,last_name,role,avatar', 'user.employeeSchedules.campaign:id,name'])
            ->where('status', 'approved')
            ->where(function ($query) use ($startOfMonth, $endOfMonth) {
                $query->whereBetween('start_date', [$startOfMonth, $endOfMonth])
                    ->orWhereBetween('end_date', [$startOfMonth, $endOfMonth])
                    ->orWhere(function ($q) use ($startOfMonth, $endOfMonth) {
                        $q->where('start_date', '<=', $startOfMonth)
                            ->where('end_date', '>=', $endOfMonth);
                    });
            });

        // If campaign ID is provided, filter to only show users from that campaign
        if ($campaignId) {
            $leaveCalendarQuery->whereHas('user.employeeSchedules', function ($query) use ($campaignId) {
                $query->where('campaign_id', $campaignId)
                    ->where('is_active', true);
            });
        }

        $leaveCalendarData = $leaveCalendarQuery
            ->orderBy('start_date')
            ->get()
            ->map(function ($leave) {
                $activeSchedule = $leave->user->employeeSchedules->where('is_active', true)->first();

                return [
                    'id' => $leave->id,
                    'user_id' => $leave->user_id,
                    'user_name' => $leave->user->last_name.', '.$leave->user->first_name,
                    'user_role' => $leave->user->role,
                    'avatar_url' => $leave->user->avatar_url,
                    'campaign_name' => $activeSchedule?->campaign?->name ?? 'No Campaign',
                    'leave_type' => $leave->leave_type,
                    'start_date' => $leave->start_date->format('Y-m-d'),
                    'end_date' => $leave->end_date->format('Y-m-d'),
                    'days_requested' => $leave->days_requested,
                    'reason' => $leave->reason,
                ];
            })
            ->toArray();

        // Get attendance points statistics (shared cached query)
        $allPoints = $this->getActiveAttendancePoints();

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
                        'user_name' => $user->last_name.', '.$user->first_name,
                        'user_role' => $user->role,
                        'total_points' => round($totalPoints, 2),
                        'violations_count' => $userPoints->count(),
                        'points' => $userPoints->sortByDesc('shift_date')->take(5)->map(fn ($point) => [
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

    /**
     * Get stock summary aggregated by stockable type (RAM, Disk, Monitor, etc.)
     *
     * @return array<string, array{total: int, reserved: int, available: int, items: int}>
     */
    public function getStockSummary(): array
    {
        $stocks = Stock::select('stockable_type', DB::raw('SUM(quantity) as total'), DB::raw('SUM(reserved) as reserved'), DB::raw('COUNT(*) as items'))
            ->groupBy('stockable_type')
            ->get();

        $typeLabels = [
            'App\\Models\\RamSpec' => 'RAM',
            'App\\Models\\DiskSpec' => 'Disk',
            'App\\Models\\MonitorSpec' => 'Monitor',
            'App\\Models\\ProcessorSpec' => 'Processor',
        ];

        $summary = [];
        foreach ($stocks as $stock) {
            $label = $typeLabels[$stock->stockable_type] ?? class_basename($stock->stockable_type);
            $total = (int) $stock->total;
            $reserved = (int) $stock->reserved;
            $summary[$label] = [
                'total' => $total,
                'reserved' => $reserved,
                'available' => $total - $reserved,
                'items' => (int) $stock->items,
            ];
        }

        return $summary;
    }

    /**
     * Get recent activity log entries for admin dashboard widget.
     *
     * @return array<int, array{id: int, description: string, event: string, causer_name: string, subject_type: string, subject_id: int, created_at: string}>
     */
    public function getRecentActivityLogs(int $limit = 10): array
    {
        return Activity::with('causer:id,first_name,last_name')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($activity) {
                $causerName = 'System';
                if ($activity->causer) {
                    $causerName = $activity->causer->last_name.', '.$activity->causer->first_name;
                }

                return [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'event' => $activity->event ?? 'unknown',
                    'causer_name' => $causerName,
                    'subject_type' => $activity->subject_type ?? '',
                    'subject_id' => $activity->subject_id ?? 0,
                    'created_at' => $activity->created_at->toISOString(),
                ];
            })
            ->toArray();
    }

    /**
     * Get user account statistics for admin dashboard widget.
     *
     * @return array{total: int, by_role: array<string, int>, pending_approvals: int, recently_deactivated: int}
     */
    public function getUserAccountStats(): array
    {
        // Query 1: Active users — role counts + pending approvals in one query (was 3 separate)
        $activeRows = User::active()
            ->selectRaw('role, COUNT(*) as count, SUM(CASE WHEN hired_date IS NULL THEN 1 ELSE 0 END) as pending')
            ->groupBy('role')
            ->get();

        $byRole = [];
        $total = 0;
        $pendingApprovals = 0;
        foreach ($activeRows as $row) {
            $byRole[$row->role] = (int) $row->count;
            $total += (int) $row->count;
            $pendingApprovals += (int) $row->pending;
        }

        // Query 2: Inactive users — recently deactivated + resigned in one query (was 2 separate)
        $inactiveRow = User::where('is_active', false)
            ->selectRaw('
                SUM(CASE WHEN updated_at >= ? THEN 1 ELSE 0 END) as recently_deactivated,
                SUM(CASE WHEN hired_date IS NOT NULL THEN 1 ELSE 0 END) as resigned
            ', [now()->subDays(30)])
            ->first();

        return [
            'total' => $total,
            'by_role' => $byRole,
            'pending_approvals' => $pendingApprovals,
            'recently_deactivated' => (int) ($inactiveRow->recently_deactivated ?? 0),
            'resigned' => (int) ($inactiveRow->resigned ?? 0),
        ];
    }

    /**
     * Get notification summary for the given user.
     *
     * @return array{unread_count: int, recent: array}
     */
    public function getNotificationSummary(int $userId): array
    {
        $unreadCount = Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        $recent = Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'created_at' => $n->created_at->toISOString(),
            ])
            ->toArray();

        return [
            'unread_count' => $unreadCount,
            'recent' => $recent,
        ];
    }

    /**
     * Get biometric anomaly summary counts for the last 7 days.
     *
     * @return array{simultaneous_sites: int, impossible_gaps: int, duplicate_scans: int, unusual_hours: int, excessive_scans: int, total: int}
     */
    public function getBiometricAnomalySummary(): array
    {
        $detector = app(BiometricAnomalyDetector::class);
        $stats = $detector->getStatistics(now()->subDays(7), now());

        $byType = $stats['by_type'] ?? [];

        return [
            'simultaneous_sites' => $byType['simultaneous_sites'] ?? 0,
            'impossible_gaps' => $byType['impossible_gaps'] ?? 0,
            'duplicate_scans' => $byType['duplicate_scans'] ?? 0,
            'unusual_hours' => $byType['unusual_hours'] ?? 0,
            'excessive_scans' => $byType['excessive_scans'] ?? 0,
            'total' => $stats['total_anomalies'] ?? 0,
        ];
    }

    /**
     * Get personal schedule info for a specific user (Agent/Utility).
     */
    public function getPersonalSchedule(int $userId): ?array
    {
        $user = User::with(['employeeSchedules' => function ($query) {
            $query->where('is_active', true)
                ->with(['campaign:id,name', 'site:id,name']);
        }])->find($userId);

        if (! $user) {
            return null;
        }

        $schedule = $user->employeeSchedules->first();
        if (! $schedule) {
            return null;
        }

        // Compute next 7 work days
        $workDays = $schedule->work_days ?? [];
        $nextShifts = [];
        $dayMap = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $today = now()->startOfDay();

        for ($i = 1; $i <= 14 && count($nextShifts) < 7; $i++) {
            $candidateDate = $today->copy()->addDays($i);
            $dayName = $dayMap[$candidateDate->dayOfWeek];
            if (in_array($dayName, $workDays)) {
                $nextShifts[] = $candidateDate->format('Y-m-d');
            }
        }

        return [
            'campaign' => $schedule->campaign?->name ?? 'Unassigned',
            'site' => $schedule->site?->name ?? 'Unassigned',
            'shift_type' => $schedule->shift_type ?? 'Regular',
            'time_in' => $schedule->scheduled_time_in,
            'time_out' => $schedule->scheduled_time_out,
            'work_days' => $workDays,
            'grace_period_minutes' => $schedule->grace_period_minutes ?? 0,
            'next_shifts' => $nextShifts,
        ];
    }

    /**
     * Get personal request summaries for a user (last 5 of each type).
     *
     * @return array{leaves: array, it_concerns: array, medication_requests: array}
     */
    public function getPersonalRequestsSummary(int $userId): array
    {
        $leaves = LeaveRequest::where('user_id', $userId)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'leave_type' => $l->leave_type,
                'start_date' => $l->start_date?->format('Y-m-d'),
                'end_date' => $l->end_date?->format('Y-m-d'),
                'days_requested' => $l->days_requested,
                'status' => $l->status,
                'created_at' => $l->created_at->toISOString(),
            ])
            ->toArray();

        $itConcerns = ItConcern::where('user_id', $userId)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'category' => $c->category,
                'description' => $c->description,
                'status' => $c->status,
                'priority' => $c->priority,
                'created_at' => $c->created_at->toISOString(),
            ])
            ->toArray();

        $medicationRequests = MedicationRequest::where('user_id', $userId)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'medication_type' => $m->medication_type,
                'status' => $m->status,
                'created_at' => $m->created_at->toISOString(),
            ])
            ->toArray();

        return [
            'leaves' => $leaves,
            'it_concerns' => $itConcerns,
            'medication_requests' => $medicationRequests,
        ];
    }

    /**
     * Get personal attendance summary for the current month.
     *
     * @return array{month: string, total: int, present: int, on_time: int, tardy: int, absent: int, ncns: int, half_day: int, on_leave: int, total_points: float, points_by_type: array, points_threshold: int, upcoming_expirations: array}
     */
    public function getPersonalAttendanceSummary(int $userId): array
    {
        $startOfMonth = now()->startOfMonth()->format('Y-m-d');
        $endOfMonth = now()->endOfMonth()->format('Y-m-d');

        $attendances = Attendance::where('user_id', $userId)
            ->dateRange($startOfMonth, $endOfMonth)
            ->get();

        $total = $attendances->count();
        $onTime = $attendances->where('status', 'on_time')->count();
        $tardy = $attendances->where('status', 'tardy')->count();
        $absent = $attendances->whereIn('status', ['ncns', 'advised_absence'])->count();
        $ncns = $attendances->where('status', 'ncns')->count();
        $halfDay = $attendances->where('status', 'half_day_absence')->count();
        $onLeave = $attendances->where('status', 'on_leave')->count();

        // Active points
        $activePoints = AttendancePoint::where('user_id', $userId)
            ->where('is_excused', false)
            ->where('is_expired', false)
            ->get();

        $totalPoints = $activePoints->sum('points');
        $pointsByType = [];
        foreach (['whole_day_absence', 'half_day_absence', 'tardy', 'undertime', 'undertime_more_than_hour'] as $type) {
            $pointsByType[$type] = round($activePoints->where('point_type', $type)->sum('points'), 2);
        }

        // Upcoming expirations (next 30 days)
        $upcomingExpirations = $activePoints
            ->whereBetween('expires_at', [now()->format('Y-m-d'), now()->addDays(30)->format('Y-m-d')])
            ->sortBy('expires_at')
            ->take(5)
            ->map(fn ($p) => [
                'point_type' => $p->point_type,
                'points' => $p->points,
                'expires_at' => $p->expires_at,
            ])
            ->values()
            ->toArray();

        return [
            'month' => now()->format('F Y'),
            'total' => $total,
            'present' => $onTime + $tardy,
            'on_time' => $onTime,
            'tardy' => $tardy,
            'absent' => $absent,
            'ncns' => $ncns,
            'half_day' => $halfDay,
            'on_leave' => $onLeave,
            'total_points' => round($totalPoints, 2),
            'points_by_type' => $pointsByType,
            'points_threshold' => 6,
            'upcoming_expirations' => $upcomingExpirations,
        ];
    }

    /**
     * Get employees nearing the 6-point threshold (4.00–5.99 active points).
     *
     * @return array{count: int, employees: array}
     */
    public function getPointsEscalation(): array
    {
        $allPoints = $this->getActiveAttendancePoints();

        $employees = $allPoints->groupBy('user_id')
            ->map(function ($userPoints) {
                $user = $userPoints->first()->user;
                $totalPoints = round($userPoints->sum('points'), 2);

                if ($totalPoints >= 4.0 && $totalPoints < 6.0) {
                    return [
                        'user_id' => $user->id,
                        'user_name' => $user->last_name.', '.$user->first_name,
                        'user_role' => $user->role,
                        'total_points' => $totalPoints,
                        'remaining_before_threshold' => round(6.0 - $totalPoints, 2),
                        'violations_count' => $userPoints->count(),
                        'latest_violation' => $userPoints->sortByDesc('shift_date')->first()?->shift_date,
                    ];
                }

                return null;
            })
            ->filter()
            ->sortByDesc('total_points')
            ->values()
            ->toArray();

        return [
            'count' => count($employees),
            'employees' => $employees,
        ];
    }

    /**
     * Get NCNS count trend for the last 6 months.
     *
     * @return array<int, array{month: string, label: string, ncns_count: int, change: string}>
     */
    public function getNcnsTrend(): array
    {
        $startDate = now()->subMonths(5)->startOfMonth();

        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");

        if ($connection === 'sqlite') {
            $monthKeyExpr = "strftime('%Y-%m', shift_date)";
        } else {
            $monthKeyExpr = 'DATE_FORMAT(shift_date, "%Y-%m")';
        }

        $data = Attendance::selectRaw("
                {$monthKeyExpr} as month,
                COUNT(*) as ncns_count
            ")
            ->where('status', 'ncns')
            ->where('shift_date', '>=', $startDate)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $result = [];
        $prevCount = null;

        foreach ($data as $row) {
            $date = Carbon::createFromFormat('Y-m', $row->month);
            $count = (int) $row->ncns_count;

            $change = 'stable';
            if ($prevCount !== null) {
                if ($count > $prevCount) {
                    $change = 'increasing';
                } elseif ($count < $prevCount) {
                    $change = 'decreasing';
                }
            }

            $result[] = [
                'month' => $row->month,
                'label' => $date->format('M Y'),
                'ncns_count' => $count,
                'change' => $change,
            ];

            $prevCount = $count;
        }

        return $result;
    }

    /**
     * Get leave utilization data — monthly earned vs used credits aggregated across all employees.
     *
     * @return array{months: array, totals: array{total_earned: float, total_used: float, utilization_rate: float}}
     */
    public function getLeaveUtilizationData(): array
    {
        $year = now()->year;
        $currentMonth = now()->month;

        $monthlyData = \App\Models\LeaveCredit::selectRaw('
                month,
                SUM(credits_earned) as total_earned,
                SUM(credits_used) as total_used
            ')
            ->where('year', $year)
            ->where('month', '>', 0)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $months = [];
        foreach ($monthlyData as $row) {
            $earned = round((float) $row->total_earned, 2);
            $used = round((float) $row->total_used, 2);
            $date = Carbon::create($year, (int) $row->month, 1);

            $months[] = [
                'month' => $date->format('Y-m'),
                'label' => $date->format('M'),
                'earned' => $earned,
                'used' => $used,
                'utilization_rate' => $earned > 0 ? round(($used / $earned) * 100, 1) : 0,
            ];
        }

        $totalEarned = array_sum(array_column($months, 'earned'));
        $totalUsed = array_sum(array_column($months, 'used'));

        return [
            'months' => $months,
            'totals' => [
                'total_earned' => round($totalEarned, 2),
                'total_used' => round($totalUsed, 2),
                'utilization_rate' => $totalEarned > 0 ? round(($totalUsed / $totalEarned) * 100, 1) : 0,
            ],
        ];
    }

    /**
     * Get presence comparison stats grouped by campaign for today (or given date).
     *
     * @return array<int, array{campaign_id: int, campaign_name: string, total_scheduled: int, present: int, absent: int, on_leave: int, presence_rate: float}>
     */
    public function getCampaignPresenceComparison(?string $date = null): array
    {
        $today = $date ?? now()->toDateString();

        $campaigns = \App\Models\Campaign::select('id', 'name')->orderBy('name')->get();

        // Bulk-load all active schedules grouped by campaign (was N queries in loop)
        $schedulesByCampaign = EmployeeSchedule::where('is_active', true)
            ->whereIn('campaign_id', $campaigns->pluck('id'))
            ->get()
            ->groupBy('campaign_id')
            ->map(fn ($items) => $items->pluck('user_id')->unique());

        // Collect all scheduled user IDs across all campaigns
        $allScheduledUserIds = $schedulesByCampaign->flatten()->unique();

        // Filter to approved users with relevant roles
        $approvedUserIds = User::whereIn('id', $allScheduledUserIds)
            ->whereIn('role', ['Agent', 'Team Lead', 'IT', 'Utility'])
            ->where('is_approved', true)
            ->pluck('id');

        // Bulk-load today's attendance + leaves for all scheduled users (was 2N queries)
        $todayAttendance = Attendance::whereDate('shift_date', $today)
            ->where('admin_verified', true)
            ->whereIn('user_id', $approvedUserIds)
            ->get()
            ->groupBy('user_id');

        $usersOnLeave = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->whereIn('user_id', $approvedUserIds)
            ->pluck('user_id')
            ->unique();

        return $campaigns->map(function ($campaign) use ($schedulesByCampaign, $approvedUserIds, $todayAttendance, $usersOnLeave) {
            $campaignUserIds = $schedulesByCampaign->get($campaign->id, collect());
            $scheduledUsers = $campaignUserIds->intersect($approvedUserIds);

            $totalScheduled = $scheduledUsers->count();
            if ($totalScheduled === 0) {
                return null;
            }

            // Filter from preloaded data — zero queries
            $campaignAttendance = $scheduledUsers->flatMap(fn ($uid) => $todayAttendance->get($uid, collect()));
            $present = $campaignAttendance->whereIn('status', ['on_time', 'tardy', 'undertime', 'undertime_more_than_hour'])->count();
            $absent = $campaignAttendance->whereIn('status', ['ncns', 'advised_absence', 'half_day_absence'])->count();
            $onLeave = $usersOnLeave->intersect($scheduledUsers)->count();

            return [
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'total_scheduled' => $totalScheduled,
                'present' => $present,
                'absent' => $absent,
                'on_leave' => $onLeave,
                'presence_rate' => $totalScheduled > 0 ? round(($present / $totalScheduled) * 100, 1) : 0,
            ];
        })
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Get total active attendance points grouped by campaign.
     *
     * @return array<int, array{campaign_id: int, campaign_name: string, total_points: float, violations_count: int, high_risk_count: int, employees_with_points: int}>
     */
    public function getPointsByCampaign(): array
    {
        $campaigns = \App\Models\Campaign::select('id', 'name')->orderBy('name')->get();

        $allActivePoints = $this->getActiveAttendancePoints();

        // Bulk-load all active schedules grouped by campaign (was N queries in loop)
        $schedulesByCampaign = EmployeeSchedule::where('is_active', true)
            ->whereIn('campaign_id', $campaigns->pluck('id'))
            ->get()
            ->groupBy('campaign_id')
            ->map(fn ($items) => $items->pluck('user_id')->unique());

        return $campaigns->map(function ($campaign) use ($allActivePoints, $schedulesByCampaign) {
            $userIds = $schedulesByCampaign->get($campaign->id, collect());

            $campaignPoints = $allActivePoints->whereIn('user_id', $userIds);

            if ($campaignPoints->isEmpty()) {
                return null;
            }

            $totalPoints = round($campaignPoints->sum('points'), 2);
            $violationsCount = $campaignPoints->count();

            // High risk: users in this campaign with >= 6 points
            $pointsByUser = $campaignPoints->groupBy('user_id')->map(fn ($pts) => round($pts->sum('points'), 2));
            $highRiskCount = $pointsByUser->filter(fn ($pts) => $pts >= 6.0)->count();

            return [
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'total_points' => $totalPoints,
                'violations_count' => $violationsCount,
                'high_risk_count' => $highRiskCount,
                'employees_with_points' => $pointsByUser->count(),
            ];
        })
            ->filter()
            ->values()
            ->toArray();
    }
}
