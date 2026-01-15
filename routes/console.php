<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks (1:00 AM - 2:30 AM)
|--------------------------------------------------------------------------
| Tasks are ordered by data volume/processing time (heaviest first)
*/

// ============================================================================
// HEAVY DATA PROCESSING (1:00 AM - 1:45 AM)
// ============================================================================

// Clean old activity logs (older than 90 days) - runs daily at 1:00 AM
// Priority: HIGH - Large volume of log records to process
Schedule::command('activitylog:clean')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer();

// Clean biometric records based on retention policies - runs daily at 1:15 AM
// Priority: HIGH - Large volume of biometric attendance records
Schedule::command('biometric:clean-old-records --force')
    ->dailyAt('01:15')
    ->withoutOverlapping()
    ->onOneServer();

// Clean form request records based on retention policies - runs daily at 1:30 AM
// Priority: MEDIUM-HIGH - Moderate volume of form request records
Schedule::command('form-request:clean-old-records --force')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onOneServer();

// Process attendance point expirations (SRO and GBRO) - runs daily at 1:45 AM
// Priority: MEDIUM - Processes all active attendance points
Schedule::command('points:process-expirations')
    ->dailyAt('01:45')
    ->withoutOverlapping()
    ->onOneServer();

// ============================================================================
// LEAVE CREDIT PROCESSING (2:00 AM - 2:10 AM)
// ============================================================================

// Process first-time regularization credit transfers - runs daily at 2:00 AM
// Transfers ALL credits from hire year to regularization year for newly regularized employees
// Runs daily because users are regularized throughout the year (6 months after their hire date)
Schedule::command('leave:process-regularization')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

// Accrue monthly leave credits - runs on last day of month at 2:05 AM
Schedule::command('leave:accrue-credits')
    ->monthlyOn((int) date('t'), '02:05')
    ->withoutOverlapping()
    ->onOneServer();

// Process year-end leave credit carryovers - runs on January 1st at 2:10 AM
// Carries over up to 4 unused credits for cash conversion (not for leave applications)
Schedule::command('leave:process-carryover --year=' . (date('Y') - 1))
    ->yearlyOn(1, 1, '02:10')
    ->withoutOverlapping()
    ->onOneServer();

// ============================================================================
// EXPIRY NOTIFICATIONS (2:15 AM - 2:30 AM)
// ============================================================================

// Check biometric retention policy expiry and notify admins - runs daily at 2:15 AM
// Priority: LOW - Quick check and notification only
Schedule::command('retention:check-expiry --days=7')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->onOneServer();

// Check form request retention policy expiry and notify admins - runs daily at 2:20 AM
// Priority: LOW - Quick check and notification only
Schedule::command('form-request:check-expiry --days=7')
    ->dailyAt('02:20')
    ->withoutOverlapping()
    ->onOneServer();

// Check activity log expiry and notify admins - runs daily at 2:25 AM
// Priority: LOW - Quick check and notification only
Schedule::command('activitylog:check-expiry --days=7 --retention=90')
    ->dailyAt('02:25')
    ->withoutOverlapping()
    ->onOneServer();
