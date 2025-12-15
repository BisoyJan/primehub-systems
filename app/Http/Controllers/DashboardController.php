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
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        $startDate = $request->input('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->endOfMonth()->format('Y-m-d'));
        $campaignId = $request->input('campaign_id');
        $verificationFilter = $request->input('verification_filter', 'verified'); // all, verified, non_verified
        $presenceDate = $request->input('presence_date', now()->format('Y-m-d'));

        $user = $request->user();
        $isRestrictedRole = in_array($user->role, ['Agent', 'Utility']);

        $dashboardData = Cache::remember(
            key: 'dashboard_stats_' . $presenceDate,
            ttl: 150,
            callback: fn() => $this->dashboardService->getAllStats($presenceDate)
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

        // Add attendance statistics - combine overtime and undertime
        $attendanceStats = [
            'total' => (clone $attendanceQuery)->count(),
            'on_time' => (clone $attendanceQuery)->byStatus('on_time')->count(),
            'time_adjustment' => (clone $attendanceQuery)->where(function($q) {
                $q->where('overtime_minutes', '>', 0)
                  ->orWhere('status', 'undertime');
            })->count(),
            'overtime' => (clone $attendanceQuery)->where('overtime_minutes', '>', 0)->count(),
            'undertime' => (clone $attendanceQuery)->byStatus('undertime')->count(),
            'tardy' => (clone $attendanceQuery)->byStatus('tardy')->count(),
            'half_day' => (clone $attendanceQuery)->byStatus('half_day_absence')->count(),
            'ncns' => (clone $attendanceQuery)->byStatus('ncns')->count(),
            'advised' => (clone $attendanceQuery)->byStatus('advised_absence')->count(),
            'needs_verification' => Attendance::dateRange($startDate, $endDate)
                ->needsVerification()
                ->when($isRestrictedRole, fn($q) => $q->where('user_id', $user->id))
                ->when($campaignId, fn($q) => $q->whereHas('user.employeeSchedules', function ($query) use ($campaignId) {
                    $query->where('campaign_id', $campaignId);
                }))
                ->count(),
        ];

        // Get monthly breakdown for area chart
        $monthlyData = (clone $attendanceQuery)
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

        // Get daily breakdown for each month
        $dailyData = (clone $attendanceQuery)
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
            ->map(fn($items) => $items->toArray())
            ->toArray();

        // Get campaigns for filter
        $campaigns = null;
        if (!$isRestrictedRole) {
            $campaigns = \App\Models\Campaign::select('id', 'name')
                ->orderBy('name')
                ->get();
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

        return Inertia::render('dashboard', array_merge($dashboardData, [
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
        ]));
    }
}
