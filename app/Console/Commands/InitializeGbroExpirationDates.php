<?php

namespace App\Console\Commands;

use App\Models\AttendancePoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class InitializeGbroExpirationDates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'points:initialize-gbro-dates
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize gbro_expires_at for existing active GBRO-eligible points';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info('Initializing GBRO Expiration Dates');
        $this->info('===================================');
        $this->newLine();

        $totalUpdated = 0;

        // Get all users with active GBRO-eligible points
        $usersWithPoints = User::whereHas('attendancePoints', function ($query) {
            $query->where('is_expired', false)
                ->where('is_excused', false)
                ->where('eligible_for_gbro', true)
                ->whereNull('gbro_applied_at');
        })->get();

        foreach ($usersWithPoints as $user) {
            // Get active GBRO-eligible points
            $activePoints = $user->attendancePoints()
                ->where('is_expired', false)
                ->where('is_excused', false)
                ->where('eligible_for_gbro', true)
                ->whereNull('gbro_applied_at')
                ->orderBy('shift_date', 'desc')
                ->get();

            if ($activePoints->isEmpty()) {
                continue;
            }

            // Calculate GBRO reference date
            $lastViolationDate = Carbon::parse($activePoints->first()->shift_date);

            $lastGbroDate = $user->attendancePoints()
                ->whereNotNull('gbro_applied_at')
                ->max('gbro_applied_at');

            $gbroReferenceDate = $lastViolationDate;
            if ($lastGbroDate) {
                $lastGbroCarbon = Carbon::parse($lastGbroDate);
                if ($lastGbroCarbon->greaterThan($lastViolationDate)) {
                    $gbroReferenceDate = $lastGbroCarbon;
                }
            }

            $gbroPredictionDate = $gbroReferenceDate->copy()->addDays(60);

            $this->comment("  {$user->name}:");
            $this->line("    Reference: {$gbroReferenceDate->format('Y-m-d')} → Base GBRO prediction: {$gbroPredictionDate->format('Y-m-d')}");

            // Update points using cascading pair logic
            // Points 1-2: base date, Points 3-4: base + 60, Points 5-6: base + 120, etc.
            foreach ($activePoints as $index => $point) {
                $pairIndex = intdiv($index, 2);
                $pointGbroDate = $gbroPredictionDate->copy()->addDays(60 * $pairIndex)->format('Y-m-d');

                if (!$point->gbro_expires_at || $point->gbro_expires_at !== $pointGbroDate) {
                    $pairLabel = $pairIndex === 0 ? '(last 2)' : "(pair " . ($pairIndex + 1) . ")";
                    $this->line("    - {$point->formatted_type} ({$point->shift_date->format('Y-m-d')}) {$pairLabel} → gbro_expires_at = {$pointGbroDate}");

                    if (!$dryRun) {
                        $point->update(['gbro_expires_at' => $pointGbroDate]);
                    }

                    $totalUpdated++;
                }
            }
        }

        $this->newLine();
        $this->info("Total points updated: {$totalUpdated}");

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        } else {
            $this->info('✅ GBRO expiration dates initialized!');
        }

        return Command::SUCCESS;
    }
}
