<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Services\AttendanceProcessor;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ManageAttendancePoints extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'points:manage
                            {action : Action to perform (regenerate, remove-duplicates, expire-all, cleanup)}
                            {--from= : Start date (YYYY-MM-DD)}
                            {--to= : End date (YYYY-MM-DD)}
                            {--user= : Specific user ID}
                            {--notify : Send notifications (default: no notifications)}
                            {--dry-run : Show what would be done without making changes}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage attendance points: regenerate, remove duplicates, expire, or cleanup';

    protected AttendanceProcessor $processor;
    protected NotificationService $notificationService;

    public function __construct(AttendanceProcessor $processor, NotificationService $notificationService)
    {
        parent::__construct();
        $this->processor = $processor;
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $dryRun = $this->option('dry-run');
        $notify = $this->option('notify');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        if (!$notify) {
            $this->comment('📭 Notifications are DISABLED');
            $this->newLine();
        }

        return match ($action) {
            'regenerate' => $this->regeneratePoints($dryRun, $notify),
            'remove-duplicates' => $this->removeDuplicates($dryRun),
            'expire-all' => $this->expireAllPending($dryRun, $notify),
            'reset-expired' => $this->resetExpiredPoints($dryRun),
            'cleanup' => $this->cleanup($dryRun),
            default => $this->showHelp(),
        };
    }

    /**
     * Regenerate attendance points from attendance records
     */
    protected function regeneratePoints(bool $dryRun, bool $notify): int
    {
        $this->info('📊 Regenerating Attendance Points');
        $this->info('=================================');
        $this->newLine();

        $query = Attendance::whereIn('status', ['ncns', 'half_day_absence', 'tardy', 'undertime', 'undertime_more_than_hour', 'advised_absence'])
            ->where('admin_verified', true);

        $this->applyFilters($query);

        $attendances = $query->orderBy('shift_date')->get();

        if ($attendances->isEmpty()) {
            $this->info('No attendance records found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$attendances->count()} verified attendance records with violations");

        if (!$this->option('force') && !$dryRun) {
            if (!$this->confirm('Do you want to proceed?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $created = 0;
        $skipped = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar($attendances->count());
        $progressBar->start();

        foreach ($attendances as $attendance) {
            try {
                $existingPoint = AttendancePoint::where('attendance_id', $attendance->id)->first();

                if ($existingPoint) {
                    $skipped++;
                } elseif (!$dryRun) {
                    $this->processor->regeneratePointsForAttendance($attendance);
                    $created++;
                } else {
                    $created++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error: {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->showSummary([
            ['Total Records', $attendances->count()],
            ['Points Created', $created],
            ['Skipped (already exist)', $skipped],
            ['Errors', $errors],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Remove duplicate attendance points (same user, date, type)
     */
    protected function removeDuplicates(bool $dryRun): int
    {
        $this->info('🧹 Removing Duplicate Attendance Points');
        $this->info('=======================================');
        $this->newLine();

        // Find duplicates: same user_id, shift_date, point_type
        $duplicates = DB::table('attendance_points')
            ->select('user_id', 'shift_date', 'point_type', DB::raw('COUNT(*) as count'), DB::raw('MIN(id) as keep_id'))
            ->groupBy('user_id', 'shift_date', 'point_type')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('✅ No duplicate attendance points found.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$duplicates->count()} sets of duplicates");
        $this->newLine();

        $totalRemoved = 0;

        foreach ($duplicates as $dup) {
            $user = \App\Models\User::find($dup->user_id);
            $userName = $user ? $user->name : "User #{$dup->user_id}";

            $this->line("  {$userName} | {$dup->shift_date} | {$dup->point_type} ({$dup->count} entries, keeping ID: {$dup->keep_id})");

            if (!$dryRun) {
                // Delete all except the oldest one (keep_id)
                $deleted = AttendancePoint::where('user_id', $dup->user_id)
                    ->where('shift_date', $dup->shift_date)
                    ->where('point_type', $dup->point_type)
                    ->where('id', '!=', $dup->keep_id)
                    ->delete();

                $totalRemoved += $deleted;
            } else {
                $totalRemoved += ($dup->count - 1);
            }
        }

        $this->showSummary([
            ['Duplicate Sets Found', $duplicates->count()],
            ['Points Removed', $totalRemoved],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Expire all points that have passed their expiration date
     */
    protected function expireAllPending(bool $dryRun, bool $notify): int
    {
        $this->info('⏰ Expiring All Pending Points');
        $this->info('==============================');
        $this->newLine();

        $pendingPoints = AttendancePoint::where('is_expired', false)
            ->where('is_excused', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('user')
            ->get();

        if ($pendingPoints->isEmpty()) {
            $this->info('✅ No pending expirations found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$pendingPoints->count()} points ready for expiration");

        if (!$this->option('force') && !$dryRun) {
            if (!$this->confirm('Do you want to expire all these points?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $expired = 0;
        $notified = 0;

        foreach ($pendingPoints as $point) {
            $this->line("  - {$point->user->name}: {$point->formatted_type} ({$point->points} pts) - {$point->shift_date->format('Y-m-d')}");

            if (!$dryRun) {
                $point->markAsExpired('sro');
                $expired++;

                if ($notify) {
                    $this->notificationService->notifyAttendancePointExpired(
                        $point->user_id,
                        $point->point_type,
                        $point->shift_date->format('M d, Y'),
                        (float) $point->points,
                        'sro'
                    );
                    $notified++;
                }
            } else {
                $expired++;
            }
        }

        $summary = [
            ['Points Expired', $expired],
        ];

        if ($notify) {
            $summary[] = ['Notifications Sent', $notified];
        }

        $this->showSummary($summary);

        return Command::SUCCESS;
    }

    /**
     * Full cleanup: remove duplicates and expire pending points
     */
    protected function cleanup(bool $dryRun): int
    {
        $this->info('🧹 Full Cleanup');
        $this->info('===============');
        $this->newLine();

        $this->info('Step 1: Removing duplicates...');
        $this->removeDuplicates($dryRun);

        $this->newLine();
        $this->info('Step 2: Expiring pending points (without notifications)...');
        $this->expireAllPending($dryRun, false);

        $this->newLine();
        $this->info('✅ Cleanup complete!');

        return Command::SUCCESS;
    }

    /**
     * Reset expired attendance points back to active status
     */
    protected function resetExpiredPoints(bool $dryRun): int
    {
        $this->info('🔄 Resetting Expired Attendance Points');
        $this->info('======================================');
        $this->newLine();

        $query = AttendancePoint::where('is_expired', true);

        // Apply filters
        if ($this->option('from')) {
            $query->where('shift_date', '>=', Carbon::parse($this->option('from')));
        }

        if ($this->option('to')) {
            $query->where('shift_date', '<=', Carbon::parse($this->option('to')));
        }

        if ($this->option('user')) {
            $query->where('user_id', $this->option('user'));
        }

        $expiredPoints = $query->with('user')->get();

        if ($expiredPoints->isEmpty()) {
            $this->info('✅ No expired attendance points found matching the criteria.');
            return Command::SUCCESS;
        }

        $this->info("Found {$expiredPoints->count()} expired points to reset");
        $this->newLine();

        // Group by user for display
        $groupedByUser = $expiredPoints->groupBy('user_id');

        foreach ($groupedByUser as $userId => $points) {
            $user = $points->first()->user;
            $userName = $user ? $user->name : "User #{$userId}";
            $this->line("  {$userName}: {$points->count()} points");

            foreach ($points as $point) {
                $expirationInfo = $point->expiration_type === 'gbro' ? '(was GBRO)' : '(was SRO)';
                $shiftDateStr = Carbon::parse($point->shift_date)->format('Y-m-d');
                $this->line("    - {$shiftDateStr} | {$point->formatted_type} | {$point->points} pts {$expirationInfo}");
            }
        }

        $this->newLine();

        if (!$this->option('force') && !$dryRun) {
            if (!$this->confirm('Do you want to reset these points to active status?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $reset = 0;

        if (!$dryRun) {
            foreach ($expiredPoints as $point) {
                // Recalculate expiration date based on current date
                $shiftDate = Carbon::parse($point->shift_date);
                $isNcnsOrFtn = $point->point_type === 'whole_day_absence' && !$point->is_advised;
                $newExpiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);

                $point->update([
                    'is_expired' => false,
                    'expired_at' => null,
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                    'gbro_applied_at' => null,
                    'gbro_batch_id' => null,
                    'expires_at' => $newExpiresAt,
                ]);

                $reset++;
            }
        } else {
            $reset = $expiredPoints->count();
        }

        $this->showSummary([
            ['Points Reset to Active', $reset],
        ]);

        if (!$dryRun) {
            $this->newLine();
            $this->warn('⚠️  Note: Expiration dates have been recalculated from the original shift dates.');
            $this->line('   Points that have already passed their new expiration date will need to be');
            $this->line('   re-expired by running: php artisan points:process-expirations --no-notify');
        }

        return Command::SUCCESS;
    }

    /**
     * Apply date and user filters to query
     */
    protected function applyFilters($query): void
    {
        if ($this->option('from')) {
            $query->where('shift_date', '>=', Carbon::parse($this->option('from')));
        }

        if ($this->option('to')) {
            $query->where('shift_date', '<=', Carbon::parse($this->option('to')));
        }

        if ($this->option('user')) {
            $query->where('user_id', $this->option('user'));
        }
    }

    /**
     * Show summary table
     */
    protected function showSummary(array $data): void
    {
        $this->newLine(2);
        $this->info('Summary:');
        $this->table(['Metric', 'Count'], $data);
    }

    /**
     * Show help for available actions
     */
    protected function showHelp(): int
    {
        $this->error('Invalid action. Available actions:');
        $this->newLine();
        $this->line('  <info>regenerate</info>        - Regenerate points from verified attendance records');
        $this->line('  <info>remove-duplicates</info>  - Remove duplicate points (same user, date, type)');
        $this->line('  <info>expire-all</info>        - Expire all points that have passed expiration date');
        $this->line('  <info>reset-expired</info>     - Reset expired points back to active (unexpire)');
        $this->line('  <info>cleanup</info>           - Full cleanup (remove duplicates + expire pending)');
        $this->newLine();
        $this->line('Options:');
        $this->line('  <info>--from=YYYY-MM-DD</info>  Filter by start date');
        $this->line('  <info>--to=YYYY-MM-DD</info>    Filter by end date');
        $this->line('  <info>--user=ID</info>         Filter by user ID');
        $this->line('  <info>--notify</info>          Send notifications (default: off)');
        $this->line('  <info>--dry-run</info>         Preview changes without applying');
        $this->line('  <info>--force</info>           Skip confirmation prompts');
        $this->newLine();
        $this->line('Examples:');
        $this->line('  php artisan points:manage remove-duplicates --dry-run');
        $this->line('  php artisan points:manage expire-all --force');
        $this->line('  php artisan points:manage reset-expired --force');
        $this->line('  php artisan points:manage reset-expired --user=123 --force');
        $this->line('  php artisan points:manage cleanup --force');
        $this->line('  php artisan points:manage regenerate --from=2025-01-01 --to=2025-12-31');

        return Command::FAILURE;
    }
}
