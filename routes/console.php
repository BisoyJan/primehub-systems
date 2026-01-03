<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

// Clean old activity logs (older than 90 days) - runs daily at 1:00 PM
Schedule::command('activitylog:clean')->dailyAt('13:00');

// Clean biometric records based on retention policies - runs daily at 2:00 PM
Schedule::command('biometric:clean-old-records --force')
    ->dailyAt('14:00')
    ->withoutOverlapping()
    ->onOneServer();

// Clean form request records based on retention policies - runs daily at 2:30 PM
Schedule::command('form-request:clean-old-records --force')
    ->dailyAt('14:30')
    ->withoutOverlapping()
    ->onOneServer();

// Process attendance point expirations (SRO and GBRO) - runs daily at 3:00 PM
Schedule::command('points:process-expirations')
    ->dailyAt('15:00')
    ->withoutOverlapping()
    ->onOneServer();

// Check biometric retention policy expiry and notify admins - runs daily at 4:00 PM
Schedule::command('retention:check-expiry --days=7')
    ->dailyAt('16:00')
    ->withoutOverlapping()
    ->onOneServer();

// Check form request retention policy expiry and notify admins - runs daily at 4:15 PM
Schedule::command('form-request:check-expiry --days=7')
    ->dailyAt('16:15')
    ->withoutOverlapping()
    ->onOneServer();

// Check activity log expiry and notify admins - runs daily at 4:30 PM
Schedule::command('activitylog:check-expiry --days=7 --retention=90')
    ->dailyAt('16:30')
    ->withoutOverlapping()
    ->onOneServer();

// Accrue monthly leave credits - runs on last day of month at 1:00 AM
Schedule::command('leave:accrue-credits')
    ->monthlyOn((int) date('t'), '01:00')
    ->withoutOverlapping()
    ->onOneServer();
