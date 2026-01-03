<?php

namespace App\Console\Commands;

use App\Models\AttendancePoint;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessPointExpirations extends Command
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'points:process-expirations
                            {--dry-run : Show what would be processed without making changes}
                            {--no-notify : Skip sending notifications to employees}
                            {--force : Force GBRO processing even if already ran today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process attendance point expirations (SRO and GBRO)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $noNotify = $this->option('no-notify');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        if ($noNotify) {
            $this->comment('📭 Notifications are DISABLED');
            $this->newLine();
        }

        $this->info('Processing Attendance Point Expirations');
        $this->info('========================================');
        $this->newLine();

        // Process Standard Roll Off (SRO)
        $sroExpired = $this->processSRO($dryRun, !$noNotify);

        // Process Good Behavior Roll Off (GBRO)
        $gbroExpired = $this->processGBRO($dryRun, !$noNotify);

        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Type', 'Points Expired'],
            [
                ['SRO (Standard Roll Off)', $sroExpired],
                ['GBRO (Good Behavior)', $gbroExpired],
                ['Total', $sroExpired + $gbroExpired],
            ]
        );

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        } else {
            $this->info('✅ Expiration processing complete!');
        }

        return Command::SUCCESS;
    }

    /**
     * Process Standard Roll Off (SRO) - 6 months expiration for standard violations
     */
    protected function processSRO(bool $dryRun, bool $notify = true): int
    {
        $this->info('Processing Standard Roll Off (SRO)...');

        // Find points that have reached their expiration date (compare dates only, not time)
        $expiredPoints = AttendancePoint::where('is_expired', false)
            ->where('is_excused', false)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', today())
            ->get();

        $count = $expiredPoints->count();

        if ($count === 0) {
            $this->comment('  No points ready for SRO expiration.');
            return 0;
        }

        $this->comment("  Found {$count} points ready for SRO expiration:");

        foreach ($expiredPoints as $point) {
            $this->line("    - {$point->user->name}: {$point->formatted_type} ({$point->points} pts) - {$point->shift_date->format('Y-m-d')}");

            if (!$dryRun) {
                $point->markAsExpired('sro');

                // Send notification to the employee (if enabled)
                if ($notify) {
                    $this->notificationService->notifyAttendancePointExpired(
                        $point->user_id,
                        $point->point_type,
                        $point->shift_date->format('M d, Y'),
                        (float) $point->points,
                        'sro'
                    );
                }
            }
        }

        return $count;
    }

    /**
     * Process Good Behavior Roll Off (GBRO)
     * If no violation in 60 days, the last 2 violation points will be deducted.
     * After GBRO is applied, the 60-day clock resets from the GBRO application date.
     * The gbro_expires_at column stores the prediction date (reference_date + 60 days).
     */
    protected function processGBRO(bool $dryRun, bool $notify = true): int
    {
        $this->info('Processing Good Behavior Roll Off (GBRO)...');

        // Check if GBRO already ran today (prevent multiple cascading cycles)
        $force = $this->option('force');
        if (!$force && !$dryRun) {
            $todayBatchExists = AttendancePoint::where('expiration_type', 'gbro')
                ->whereDate('gbro_applied_at', today())
                ->exists();

            if ($todayBatchExists) {
                $this->warn('  ⚠️  GBRO already processed today. Use --force to run again.');
                $this->comment('  This prevents multiple cascading GBRO cycles in a single day.');
                return 0;
            }
        }

        $totalExpired = 0;
        $batchId = now()->format('YmdHis');

        // Get all users with active GBRO-eligible points
        $usersWithPoints = User::whereHas('attendancePoints', function ($query) {
            $query->where('is_expired', false)
                ->where('is_excused', false)
                ->where('eligible_for_gbro', true);
        })->get();

        foreach ($usersWithPoints as $user) {
            // Get all active, non-excused, non-expired, GBRO-eligible points for this user
            $activeGbroEligiblePoints = $user->attendancePoints()
                ->where('is_expired', false)
                ->where('is_excused', false)
                ->where('eligible_for_gbro', true)
                ->whereNull('gbro_applied_at')
                ->orderBy('shift_date', 'desc')
                ->get();

            if ($activeGbroEligiblePoints->isEmpty()) {
                continue;
            }

            // Get the first 2 points (newest) that have gbro_expires_at set and check if expired
            $pointsWithGbroDate = $activeGbroEligiblePoints->filter(fn($p) => $p->gbro_expires_at !== null)->take(2);

            if ($pointsWithGbroDate->isEmpty()) {
                // No GBRO dates set yet, calculate and set them
                $gbroReferenceDate = $this->calculateGbroReferenceDate($user, $activeGbroEligiblePoints);
                $gbroPredictionDate = $gbroReferenceDate->copy()->addDays(60);

                if (!$dryRun) {
                    $this->updateGbroExpiresAt($activeGbroEligiblePoints, $gbroPredictionDate);
                }
                continue;
            }

            // Check if the GBRO date has been reached (compare dates only)
            $firstPointGbroDate = Carbon::parse($pointsWithGbroDate->first()->gbro_expires_at)->startOfDay();
            $todayDate = today()->startOfDay();

            if ($firstPointGbroDate->lte($todayDate)) {
                // GBRO date reached - expire the first 2 points
                $pointsToExpire = $pointsWithGbroDate;

                if ($pointsToExpire->count() > 0) {
                    $daysOverdue = $todayDate->diffInDays($firstPointGbroDate);
                    $this->comment("  {$user->name}: GBRO date {$firstPointGbroDate->format('Y-m-d')} reached (" . ($daysOverdue > 0 ? "{$daysOverdue} days overdue" : "today") . ")");

                    foreach ($pointsToExpire as $point) {
                        $this->line("    - Expiring: {$point->formatted_type} ({$point->points} pts) - " . Carbon::parse($point->shift_date)->format('Y-m-d'));

                        if (!$dryRun) {
                            $point->update([
                                'is_expired' => true,
                                'expired_at' => now(),
                                'expiration_type' => 'gbro',
                                'gbro_applied_at' => now(),
                                'gbro_batch_id' => $batchId,
                            ]);

                            // Send notification to the employee (if enabled)
                            if ($notify) {
                                $this->notificationService->notifyAttendancePointExpired(
                                    $point->user_id,
                                    $point->point_type,
                                    Carbon::parse($point->shift_date)->format('M d, Y'),
                                    (float) $point->points,
                                    'gbro'
                                );
                            }
                        }

                        $totalExpired++;
                    }

                    // After expiring points, update remaining points' gbro_expires_at
                    // The new reference date is the SCHEDULED GBRO date (not the actual run date)
                    // This ensures fairness - employee shouldn't be penalized if the system runs late
                    if (!$dryRun) {
                        $remainingPoints = $user->attendancePoints()
                            ->where('is_expired', false)
                            ->where('is_excused', false)
                            ->where('eligible_for_gbro', true)
                            ->whereNull('gbro_applied_at')
                            ->orderBy('shift_date', 'desc')
                            ->get();

                        if ($remainingPoints->isNotEmpty()) {
                            // Use the scheduled GBRO date + 60 days for the new prediction
                            $newGbroPrediction = $firstPointGbroDate->copy()->addDays(60);
                            $this->updateGbroExpiresAt($remainingPoints, $newGbroPrediction);
                            $this->line("    → Updated remaining {$remainingPoints->count()} points: new GBRO prediction = " . $newGbroPrediction->format('Y-m-d'));
                        }
                    }
                }
            }
        }

        if ($totalExpired === 0) {
            $this->comment('  No users eligible for GBRO at this time.');
        }

        return $totalExpired;
    }

    /**
     * Calculate the GBRO reference date for a user.
     *
     * The reference date is based on when the GBRO clock started for the CURRENT batch
     * of active points. This is simply the newest violation date among active points.
     *
     * The logic is:
     * - Each time GBRO expires points, the remaining points get a new GBRO prediction
     *   based on the scheduled GBRO date + 60 days
     * - If a new violation occurs before that prediction date, it resets the clock
     * - The reference is always the newest active point's shift_date
     *
     * Note: We don't use gbro_applied_at from expired points because those are from
     * a previous batch and don't affect the current batch's eligibility.
     */
    protected function calculateGbroReferenceDate(User $user, $activePoints): Carbon
    {
        // The reference date is simply the newest violation among active GBRO-eligible points
        // This is because that's when the GBRO clock started for these points
        return Carbon::parse($activePoints->first()->shift_date);
    }

    /**
     * Get human-readable GBRO reference info for logging.
     */
    protected function getGbroReferenceInfo(User $user, Carbon $referenceDate): string
    {
        $lastGbroDate = $user->attendancePoints()
            ->whereNotNull('gbro_applied_at')
            ->max('gbro_applied_at');

        if ($lastGbroDate && Carbon::parse($lastGbroDate)->equalTo($referenceDate)) {
            return "last GBRO on " . $referenceDate->format('Y-m-d');
        }

        return "last violation on " . $referenceDate->format('Y-m-d');
    }

    /**
     * Update gbro_expires_at for a collection of points.
     * Only the first 2 points get the GBRO date, rest get NULL.
     * Points must be sorted by shift_date DESC (newest first) before calling this method.
     */
    protected function updateGbroExpiresAt($points, Carbon $gbroPredictionDate): void
    {
        $gbroExpiresAt = $gbroPredictionDate->format('Y-m-d');

        foreach ($points as $index => $point) {
            if ($index < 2) {
                // First 2 points get the GBRO date
                if ($point->gbro_expires_at !== $gbroExpiresAt) {
                    $point->update(['gbro_expires_at' => $gbroExpiresAt]);
                }
            } else {
                // Points beyond first 2 get NULL (not calculated)
                if ($point->gbro_expires_at !== null) {
                    $point->update(['gbro_expires_at' => null]);
                }
            }
        }
    }
}
