<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Clean biometric records older than 3 months - runs daily at 2:00 AM
        $schedule->command('biometric:clean-old-records')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
