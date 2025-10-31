<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Dashboard service instance
     */
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {}

    /**
     * Display the dashboard with cached statistics.
     *
     * Statistics are cached for 150 seconds (2.5 minutes) to improve performance.
     * Cache can be manually cleared when data updates are needed immediately.
     *
     * @return Response
     */
    public function index(): Response
    {
        $dashboardData = Cache::remember(
            key: 'dashboard_stats',
            ttl: 150,
            callback: fn() => $this->dashboardService->getAllStats()
        );

        return Inertia::render('dashboard', $dashboardData);
    }
}
