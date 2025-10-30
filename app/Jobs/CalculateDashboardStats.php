<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\DashboardController;

class CalculateDashboardStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        // You can pass parameters if needed
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        // Instantiate the controller to reuse its stat methods
        $controller = new DashboardController();
        $stats = [
            'totalStations' => $controller->getTotalStations(),
            'noPcs' => $controller->getStationsWithoutPcs(),
            'vacantStations' => $controller->getVacantStations(),
            'ssdPcs' => $controller->getPcsWithSsd(),
            'hddPcs' => $controller->getPcsWithHdd(),
            'dualMonitor' => $controller->getDualMonitorStations(),
            'maintenanceDue' => $controller->getMaintenanceDue(),
            'lastMaintenance' => $controller->getLastMaintenance(),
            'avgDaysOverdue' => $controller->getAverageDaysOverdue(),
        ];
        // Store in cache for dashboard use
        Cache::put('dashboard_stats', $stats, 150);
    }
}
