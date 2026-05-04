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

        // Find points that have reached their expiration date (compare dates only, not time).
        // Bug #10 fix: stream with cursor() so memory stays flat regardless of dataset size.
        $baseQuery = AttendancePoint::with('user:id,first_name,last_name')
            ->where('is_expired', false)
            ->where('is_excused', false)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', today());

        $count = (clone $baseQuery)->count();

        if ($count === 0) {
            $this->comment('  No points ready for SRO expiration.');
            return 0;
        }

        $this->comment("  Found {$count} points ready for SRO expiration:");

        /** @var AttendancePoint $point */
        foreach ($baseQuery->cursor() as $point) {
            $this->line("    - {$point->user->name}: {$point->formatted_type} ({$point->points} pts) - {$point->shift_date->format('Y-m-d')}");

            if ($dryRun) {
                continue;
            }

            // Bug #6 fix: each point's mutation + notification dispatched together.
            DB::transaction(function () use ($point) {
                $point->markAsExpired('sro');
            });

            if ($notify) {
                $this->notificationService->notifyAttendancePointExpired(
                    $point->user_id,
                    $point->point_type,
                    $point->shift_date->format('M d, Y'),
                    (float) $point->points,
                    $point->isNcnsOrFtn() ? 'ncns' : 'sro'
                );
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

        $force = $this->option('force');
        $totalExpired = 0;
        $batchId = now()->format('YmdHis');

        // Get all users with active GBRO-eligible points
        $usersWithPoints = User::whereHas('attendancePoints', function ($query) {
            $query->where('is_expired', false)
                ->where('is_excused', false)
                ->where('eligible_for_gbro', true);
        })->get();

        foreach ($usersWithPoints as $user) {
            // Bug #5 fix: per-user same-day guard (was global — caused new
            // users in the afternoon to be silently skipped).
            if (!$force && !$dryRun) {
                $userBatchExists = AttendancePoint::where('user_id', $user->id)
                    ->where('expiration_type', 'gbro')
                    ->whereDate('gbro_applied_at', today())
                    ->exists();

                if ($userBatchExists) {
                    continue;
                }
            }

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
                $pointsToExpire = $pointsWithGbroDate;

                if ($pointsToExpire->count() > 0) {
                    $daysOverdue = $todayDate->diffInDays($firstPointGbroDate);
                    $this->comment("  {$user->name}: GBRO date {$firstPointGbroDate->format('Y-m-d')} reached (" . ($daysOverdue > 0 ? "{$daysOverdue} days overdue" : "today") . ")");

                    if ($dryRun) {
                        foreach ($pointsToExpire as $point) {
                            $this->line("    - Expiring: {$point->formatted_type} ({$point->points} pts) - " . Carbon::parse($point->shift_date)->format('Y-m-d'));
                            $totalExpired++;
                        }
                        continue;
                    }

                    // Bug #6 fix: atomic per-user batch (expire pair + recompute remaining).
                    DB::transaction(function () use ($user, $pointsToExpire, $batchId, $firstPointGbroDate) {
                        foreach ($pointsToExpire as $point) {
                            $point->update([
                                'is_expired' => true,
                                'expired_at' => now(),
                                'expiration_type' => 'gbro',
                                'gbro_applied_at' => now(),
                                'gbro_batch_id' => $batchId,
                            ]);
                        }

                        // After expiring points, update remaining points' gbro_expires_at
                        $remainingPoints = $user->attendancePoints()
                            ->where('is_expired', false)
                            ->where('is_excused', false)
                            ->where('eligible_for_gbro', true)
                            ->whereNull('gbro_applied_at')
                            ->orderBy('shift_date', 'desc')
                            ->get();

                        if ($remainingPoints->isNotEmpty()) {
                            $newGbroPrediction = $firstPointGbroDate->copy()->addDays(60);
                            $this->updateGbroExpiresAt($remainingPoints, $newGbroPrediction);
                        }
                    });

                    foreach ($pointsToExpire as $point) {
                        $this->line("    - Expired: {$point->formatted_type} ({$point->points} pts) - " . Carbon::parse($point->shift_date)->format('Y-m-d'));
                        $totalExpired++;

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
