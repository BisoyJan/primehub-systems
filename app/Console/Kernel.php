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

        // Clean form request records based on retention policies - runs daily at 2:30 AM
        $schedule->command('form-request:clean-old-records --force')
            ->dailyAt('02:30')
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

        // Check biometric retention policy expiry and notify admins - runs daily at 4:00 AM
        $schedule->command('retention:check-expiry --days=7')
            ->dailyAt('04:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Check form request retention policy expiry and notify admins - runs daily at 4:15 AM
        $schedule->command('form-request:check-expiry --days=7')
            ->dailyAt('04:15')
            ->withoutOverlapping()
            ->onOneServer();

        // Check activity log expiry and notify admins - runs daily at 4:30 AM
        $schedule->command('activitylog:check-expiry --days=7 --retention=90')
            ->dailyAt('04:30')
            ->withoutOverlapping()
            ->onOneServer();

        // Clean old activity logs (older than 90 days) - runs daily at 1:00 AM
        $schedule->command('activitylog:clean')->dailyAt('01:00');
    }
}
