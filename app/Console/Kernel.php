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
        // Clean biometric records based on retention policies - runs daily at 2:00 AM
        $schedule->command('biometric:clean-old-records --force')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Process attendance point expirations (SRO and GBRO) - runs daily at 3:00 AM
        $schedule->command('points:process-expirations')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Accrue monthly leave credits - runs on last day of month at 11:00 PM
        $schedule->command('leave:accrue-credits')
            ->monthlyOn(date('t'), '23:00') // Last day of the month at 11 PM
            ->withoutOverlapping()
            ->onOneServer();
    }
}
