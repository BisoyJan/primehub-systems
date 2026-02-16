<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Services\DashboardService;
use App\Services\LeaveCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Dashboard service instance
     */
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly LeaveCreditService $leaveCreditService
    ) {}

    /**
     * Display the dashboard with cached statistics.
     *
     * Statistics are cached for 150 seconds (2.5 minutes) to improve performance.
     * Cache can be manually cleared when data updates are needed immediately.
     */
    public function index(Request $request): Response
    {
        $startDate = $request->input('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->endOfMonth()->format('Y-m-d'));
        $campaignId = $request->input('campaign_id');
        $verificationFilter = $request->input('verification_filter', 'verified'); // all, verified, non_verified
        $presenceDate = $request->input('presence_date', now()->format('Y-m-d'));
        $leaveCalendarMonth = $request->input('leave_calendar_month', now()->format('Y-m-d'));

        $user = $request->user();
        $role = $user->role;
        $isRestrictedRole = in_array($role, ['Agent', 'Utility']);

        // Get user's active campaign ID for leave calendar filtering (Agents can see same-campaign leaves)
        $leaveCalendarCampaignId = null;
        if ($isRestrictedRole) {
            $activeSchedule = $user->employeeSchedules()
                ->where('is_active', true)
                ->first();
            $leaveCalendarCampaignId = $activeSchedule?->campaign_id;
        }

        // Cache key includes role for per-role caching
        $cacheKey = 'dashboard_stats_'.$role.'_'.$presenceDate.'_'.$leaveCalendarMonth.'_campaign_'.($leaveCalendarCampaignId ?? 'all');

        $dashboardData = Cache::remember(
            key: $cacheKey,
            ttl: 150,
            callback: fn () => $this->dashboardService->getAllStats($role, $presenceDate, $leaveCalendarMonth, $leaveCalendarCampaignId)
        );

        // Build attendance query with filters
        $attendanceQuery = Attendance::dateRange($startDate, $endDate);

        // Apply verification filter
        if ($verificationFilter === 'verified') {
            $attendanceQuery->where('admin_verified', 1);
        } elseif ($verificationFilter === 'non_verified') {
            $attendanceQuery->where('admin_verified', 0);
        }
        // 'all' means no verification filter

        if ($isRestrictedRole) {
            $attendanceQuery->where('user_id', $user->id);
        }

        if ($campaignId) {
            $attendanceQuery->whereHas('user.employeeSchedules', function ($query) use ($campaignId) {
                $query->where('campaign_id', $campaignId);
            });
        }

        // Determine DB driver for date functions
        $driver = $attendanceQuery->getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        $monthExpression = $isSqlite ? 'strftime("%Y-%m", shift_date)' : 'DATE_FORMAT(shift_date, "%Y-%m")';
        $dayExpression = $isSqlite ? 'CAST(strftime("%d", shift_date) AS INTEGER)' : 'DAY(shift_date)';

        // Cache attendance data — stats, monthly/daily breakdowns, campaigns (was uncached, 4+ queries per request)
        $attendanceCacheKey = 'dashboard_attendance_'.$role.'_'.$startDate.'_'.$endDate.'_'.$verificationFilter.'_'.($campaignId ?? 'all').'_'.($isRestrictedRole ? $user->id : 'global');
        $attendanceCached = Cache::remember($attendanceCacheKey, 150, function () use ($attendanceQuery, $monthExpression, $dayExpression, $startDate, $endDate, $isRestrictedRole, $user, $campaignId) {
            // Single conditional aggregation query (was 10 separate COUNTs)
            $statRow = (clone $attendanceQuery)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'on_time' THEN 1 ELSE 0 END) as on_time,
                    SUM(CASE WHEN overtime_minutes > 0 OR status = 'undertime' THEN 1 ELSE 0 END) as time_adjustment,
                    SUM(CASE WHEN overtime_minutes > 0 THEN 1 ELSE 0 END) as overtime,
                    SUM(CASE WHEN status = 'undertime' THEN 1 ELSE 0 END) as undertime,
                    SUM(CASE WHEN status = 'tardy' THEN 1 ELSE 0 END) as tardy,
                    SUM(CASE WHEN status = 'half_day_absence' THEN 1 ELSE 0 END) as half_day,
                    SUM(CASE WHEN status = 'ncns' THEN 1 ELSE 0 END) as ncns,
                    SUM(CASE WHEN status = 'advised_absence' THEN 1 ELSE 0 END) as advised
                ")
                ->first();

            $stats = [
                'total' => (int) ($statRow->total ?? 0),
                'on_time' => (int) ($statRow->on_time ?? 0),
                'time_adjustment' => (int) ($statRow->time_adjustment ?? 0),
                'overtime' => (int) ($statRow->overtime ?? 0),
                'undertime' => (int) ($statRow->undertime ?? 0),
                'tardy' => (int) ($statRow->tardy ?? 0),
                'half_day' => (int) ($statRow->half_day ?? 0),
                'ncns' => (int) ($statRow->ncns ?? 0),
                'advised' => (int) ($statRow->advised ?? 0),
                'needs_verification' => Attendance::dateRange($startDate, $endDate)
                    ->needsVerification()
                    ->when($isRestrictedRole, fn ($q) => $q->where('user_id', $user->id))
                    ->when($campaignId, fn ($q) => $q->whereHas('user.employeeSchedules', function ($query) use ($campaignId) {
                        $query->where('campaign_id', $campaignId);
                    }))
                    ->count(),
            ];

            // Monthly breakdown for area chart
            $monthly = (clone $attendanceQuery)
                ->selectRaw("
                    {$monthExpression} as month,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'on_time' THEN 1 ELSE 0 END) as on_time,
                    SUM(CASE WHEN overtime_minutes > 0 OR status = 'undertime' THEN 1 ELSE 0 END) as time_adjustment,
                    SUM(CASE WHEN status = 'tardy' THEN 1 ELSE 0 END) as tardy,
                    SUM(CASE WHEN status = 'half_day_absence' THEN 1 ELSE 0 END) as half_day,
                    SUM(CASE WHEN status = 'ncns' THEN 1 ELSE 0 END) as ncns,
                    SUM(CASE WHEN status = 'advised_absence' THEN 1 ELSE 0 END) as advised
                ")
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->keyBy('month')
                ->toArray();

            // Daily breakdown for each month
            $daily = (clone $attendanceQuery)
                ->selectRaw("
                    {$monthExpression} as month,
                    {$dayExpression} as day,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'on_time' THEN 1 ELSE 0 END) as on_time,
                    SUM(CASE WHEN overtime_minutes > 0 OR status = 'undertime' THEN 1 ELSE 0 END) as time_adjustment,
                    SUM(CASE WHEN status = 'tardy' THEN 1 ELSE 0 END) as tardy,
                    SUM(CASE WHEN status = 'half_day_absence' THEN 1 ELSE 0 END) as half_day,
                    SUM(CASE WHEN status = 'ncns' THEN 1 ELSE 0 END) as ncns,
                    SUM(CASE WHEN status = 'advised_absence' THEN 1 ELSE 0 END) as advised
                ")
                ->groupBy('month', 'day')
                ->orderBy('month')
                ->orderBy('day')
                ->get()
                ->groupBy('month')
                ->map(fn ($items) => $items->toArray())
                ->toArray();

            return [
                'stats' => $stats,
                'monthly' => $monthly,
                'daily' => $daily,
            ];
        });

        $attendanceStats = $attendanceCached['stats'];
        $monthlyData = $attendanceCached['monthly'];
        $dailyData = $attendanceCached['daily'];

        // Get campaigns for filter (cached separately — rarely changes)
        $campaigns = null;
        if (! $isRestrictedRole) {
            $campaigns = Cache::remember('dashboard_campaigns', 300, fn () => \App\Models\Campaign::select('id', 'name')
                ->orderBy('name')
                ->get());
        }

        // Get user's personal leave credits summary
        $leaveCredits = null;
        if ($user->hired_date) {
            $summary = $this->leaveCreditService->getSummary($user);
            $leaveCredits = [
                'year' => $summary['year'],
                'is_eligible' => $summary['is_eligible'],
                'eligibility_date' => $summary['eligibility_date']?->format('Y-m-d'),
                'monthly_rate' => $summary['monthly_rate'],
                'total_earned' => $summary['total_earned'],
                'total_used' => $summary['total_used'],
                'balance' => $summary['balance'],
            ];
        }

        // Personal data for Agent/Utility (cached per-user, short TTL)
        $personalData = [];
        if ($isRestrictedRole) {
            $personalData = Cache::remember("dashboard_personal_{$user->id}", 120, fn () => [
                'personalSchedule' => $this->dashboardService->getPersonalSchedule($user->id),
                'personalRequests' => $this->dashboardService->getPersonalRequestsSummary($user->id),
                'personalAttendanceSummary' => $this->dashboardService->getPersonalAttendanceSummary($user->id),
            ]);
        }

        // Notification summary (cached per-user, short TTL)
        $notificationSummary = Cache::remember("dashboard_notifications_{$user->id}", 60, fn () => $this->dashboardService->getNotificationSummary($user->id));

        return Inertia::render('dashboard', array_merge($dashboardData, $personalData, [
            'attendanceStatistics' => $attendanceStats,
            'verificationFilter' => $verificationFilter,
            'monthlyAttendanceData' => $monthlyData,
            'dailyAttendanceData' => $dailyData,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'campaignId' => $campaignId,
            'campaigns' => $campaigns,
            'isRestrictedRole' => $isRestrictedRole,
            'leaveCredits' => $leaveCredits,
            'leaveCalendarMonth' => $leaveCalendarMonth,
            'notificationSummary' => $notificationSummary,
            'userRole' => $role,
        ]));
    }
}
