<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks (8:00 AM - 9:00 AM)
|--------------------------------------------------------------------------
| Tasks are ordered by data volume/processing time (heaviest first)
*/

// ============================================================================
// DATABASE BACKUPS (Spatie Laravel Backup) (8:00 AM - 8:05 AM)
// ============================================================================

// Automated daily database backup at 8:00 AM
// Uses Spatie backup:run with --only-db (dump config in config/database.php)
Schedule::command('backup:run --only-db')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

// Clean old backups based on retention strategy in config/backup.php at 8:03 AM
Schedule::command('backup:clean')
    ->dailyAt('08:03')
    ->withoutOverlapping()
    ->onOneServer();

// ============================================================================
// HEAVY DATA PROCESSING (8:05 AM - 8:22 AM)
// ============================================================================

// Process attendance point expirations (SRO and GBRO) - runs daily at 8:05 AM
// Priority: MEDIUM - Processes all active attendance points
Schedule::command('points:process-expirations')
    ->dailyAt('08:05')
    ->withoutOverlapping()
    ->onOneServer();

// Clean biometric records based on retention policies - runs daily at 8:08 AM
// Priority: HIGH - Large volume of biometric attendance records
Schedule::command('biometric:clean-old-records --force')
    ->dailyAt('08:08')
    ->withoutOverlapping()
    ->onOneServer();

// Clean form request records based on retention policies - runs daily at 8:11 AM
// Priority: MEDIUM-HIGH - Moderate volume of form request records
Schedule::command('form-request:clean-old-records --force')
    ->dailyAt('08:11')
    ->withoutOverlapping()
    ->onOneServer();

// Clean old activity logs (older than 60 days per config) - runs daily at 8:14 AM
// Priority: HIGH - Large volume of log records to process
Schedule::command('activitylog:clean')
    ->dailyAt('08:14')
    ->withoutOverlapping()
    ->onOneServer();

// Clean break sessions based on active policy retention period - runs daily at 8:17 AM
// Priority: MEDIUM - Purges expired break sessions and events
Schedule::command('break-timer:clean-old-sessions --force')
    ->dailyAt('08:17')
    ->withoutOverlapping()
    ->onOneServer();

// Clean old notifications (read: 30 days, unread: 30 days) - runs daily at 8:20 AM
// Priority: MEDIUM - Keeps notifications table from growing unbounded
Schedule::command('notifications:clean --force')
    ->dailyAt('08:20')
    ->withoutOverlapping()
    ->onOneServer();

// ============================================================================
// LEAVE CREDIT PROCESSING (8:25 AM - 8:32 AM)
// ============================================================================

// Process first-time regularization credit transfers - runs daily at 8:25 AM
// Transfers ALL credits from hire year to regularization year for newly regularized employees
// Runs daily because users are regularized throughout the year (6 months after their hire date)
Schedule::command('leave:process-regularization')
    ->dailyAt('08:25')
    ->withoutOverlapping()
    ->onOneServer();

// Accrue monthly leave credits - runs on last day of month at 8:28 AM
Schedule::command('leave:accrue-credits')
    ->monthlyOn((int) date('t'), '08:28')
    ->withoutOverlapping()
    ->onOneServer();

// Process year-end leave credit carryovers - runs on January 1st at 8:32 AM
// Carries over up to 4 unused credits for conversion and leave application
Schedule::command('leave:process-carryover --year='.(date('Y') - 1))
    ->yearlyOn(1, 1, '08:32')
    ->withoutOverlapping()
    ->onOneServer();

// ============================================================================
// BREAK TIMER AUTO-RESET
// ============================================================================

// Auto-end orphaned break sessions from previous shifts based on policy shift_reset_time
// Runs every 15 minutes to reliably catch the configurable reset time (e.g. 07:00)
// A dailyAt() approach would miss if the schedule runs before the policy's reset time
// Priority: LOW - Lightweight query, most runs are no-ops
Schedule::command('break-timer:auto-reset')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// ============================================================================
// EXPIRY NOTIFICATIONS (8:35 AM - 8:45 AM)
// ============================================================================

// Check biometric retention policy expiry and notify admins - runs daily at 8:35 AM
// Priority: LOW - Quick check and notification only
Schedule::command('retention:check-expiry --days=7')
    ->dailyAt('08:35')
    ->withoutOverlapping()
    ->onOneServer();

// Check form request retention policy expiry and notify admins - runs daily at 8:38 AM
// Priority: LOW - Quick check and notification only
Schedule::command('form-request:check-expiry --days=7')
    ->dailyAt('08:38')
    ->withoutOverlapping()
    ->onOneServer();

// Check activity log expiry and notify admins - runs daily at 8:41 AM
// Priority: LOW - Quick check and notification only (reads retention from config/activitylog.php)
Schedule::command('activitylog:check-expiry --days=7')
    ->dailyAt('08:41')
    ->withoutOverlapping()
    ->onOneServer();

// Check notification retention expiry and notify admins - runs daily at 8:44 AM
// Priority: LOW - Quick check and notification only
Schedule::command('notifications:check-expiry --days=7')
    ->dailyAt('08:44')
    ->withoutOverlapping()
    ->onOneServer();

// ============================================================================
// COACHING ESCALATION CHECKS (8:48 AM)
// ============================================================================

// Check for overdue coaching follow-ups and at-risk agents - runs daily at 8:48 AM
// Priority: MEDIUM - Sends escalation notifications to coaches and team leads
Schedule::command('coaching:check-escalations')
    ->dailyAt('08:48')
    ->withoutOverlapping()
    ->onOneServer();

// ============================================================================
// BACKUP MONITORING (8:55 AM)
// ============================================================================

// Monitor backup health (alerts if backups are too old or too large) at 8:55 AM
Schedule::command('backup:monitor')
    ->dailyAt('08:55')
    ->onOneServer();
