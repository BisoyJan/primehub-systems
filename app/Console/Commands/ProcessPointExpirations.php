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
                            {--no-notify : Skip sending notifications to employees}';

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

        // Find points that have reached their expiration date
        $expiredPoints = AttendancePoint::where('is_expired', false)
            ->where('is_excused', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
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
     * If no violation in 60 days, the last 2 violation points will be deducted
     */
    protected function processGBRO(bool $dryRun, bool $notify = true): int
    {
        $this->info('Processing Good Behavior Roll Off (GBRO)...');

        $totalExpired = 0;
        $batchId = now()->format('YmdHis');

        // Get all users with active points
        $usersWithPoints = User::whereHas('attendancePoints', function ($query) {
            $query->where('is_expired', false)
                ->where('is_excused', false);
        })->get();

        foreach ($usersWithPoints as $user) {
            // Get all active, non-excused, non-expired points for this user
            $activePoints = $user->attendancePoints()
                ->where('is_expired', false)
                ->where('is_excused', false)
                ->orderBy('shift_date', 'desc')
                ->get();

            if ($activePoints->isEmpty()) {
                continue;
            }

            // Get the most recent point date
            $lastViolationDate = $activePoints->first()->shift_date;
            $daysSinceLastViolation = Carbon::parse($lastViolationDate)->diffInDays(now());

            // Check if eligible for GBRO (60+ days without violation)
            if ($daysSinceLastViolation >= 60) {
                // Get the last 2 points (most recent) that are eligible for GBRO
                $pointsToExpire = $activePoints->filter(function ($point) {
                    return $point->eligible_for_gbro && !$point->gbro_applied_at;
                })->take(2);

                if ($pointsToExpire->count() > 0) {
                    $this->comment("  {$user->name}: {$daysSinceLastViolation} days without violation");

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
                }
            }
        }

        if ($totalExpired === 0) {
            $this->comment('  No users eligible for GBRO at this time.');
        }

        return $totalExpired;
    }
}
