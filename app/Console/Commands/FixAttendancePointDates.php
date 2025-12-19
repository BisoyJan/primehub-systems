<?php

namespace App\Console\Commands;

use App\Models\AttendancePoint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixAttendancePointDates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance-points:fix-dates
                            {--days=1 : Number of days to add back}
                            {--dry-run : Preview changes without applying them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix attendance point dates by adding days back (to correct accidental date shifts)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        $this->info("Starting attendance point date fix...");
        $this->info("Days to add: {$days}");

        if ($dryRun) {
            $this->warn("DRY RUN MODE - No changes will be made");
        }

        // Get count of records to be updated
        $totalPoints = AttendancePoint::count();
        $this->info("Total attendance points: {$totalPoints}");

        if ($totalPoints === 0) {
            $this->warn("No attendance points found to update.");
            return Command::SUCCESS;
        }

        // Show sample of current dates before fix
        $this->info("\nSample of current dates (first 10 records):");
        $sampleBefore = AttendancePoint::orderBy('shift_date', 'desc')
            ->take(10)
            ->get(['id', 'user_id', 'shift_date', 'point_type', 'expires_at']);

        $this->table(
            ['ID', 'User ID', 'Shift Date', 'Point Type', 'Expires At'],
            $sampleBefore->map(fn($p) => [
                $p->id,
                $p->user_id,
                $p->shift_date?->format('Y-m-d'),
                $p->point_type,
                $p->expires_at?->format('Y-m-d'),
            ])
        );

        if (!$dryRun) {
            if (!$this->confirm("Are you sure you want to add {$days} day(s) to ALL {$totalPoints} attendance point dates?")) {
                $this->info("Operation cancelled.");
                return Command::SUCCESS;
            }

            try {
                DB::beginTransaction();

                // Update shift_date by adding days
                $updatedShiftDate = DB::table('attendance_points')
                    ->update([
                        'shift_date' => DB::raw("DATE_ADD(shift_date, INTERVAL {$days} DAY)")
                    ]);

                $this->info("Updated shift_date for {$updatedShiftDate} records.");

                // Update expires_at by adding days (only where not null)
                $updatedExpiresAt = DB::table('attendance_points')
                    ->whereNotNull('expires_at')
                    ->update([
                        'expires_at' => DB::raw("DATE_ADD(expires_at, INTERVAL {$days} DAY)")
                    ]);

                $this->info("Updated expires_at for {$updatedExpiresAt} records.");

                // Update expired_at by adding days (only where not null)
                $updatedExpiredAt = DB::table('attendance_points')
                    ->whereNotNull('expired_at')
                    ->update([
                        'expired_at' => DB::raw("DATE_ADD(expired_at, INTERVAL {$days} DAY)")
                    ]);

                $this->info("Updated expired_at for {$updatedExpiredAt} records.");

                // Update gbro_applied_at by adding days (only where not null)
                $updatedGbroAppliedAt = DB::table('attendance_points')
                    ->whereNotNull('gbro_applied_at')
                    ->update([
                        'gbro_applied_at' => DB::raw("DATE_ADD(gbro_applied_at, INTERVAL {$days} DAY)")
                    ]);

                $this->info("Updated gbro_applied_at for {$updatedGbroAppliedAt} records.");

                DB::commit();

                // Show sample of dates after fix
                $this->info("\nSample of dates after fix (first 10 records):");
                $sampleAfter = AttendancePoint::orderBy('shift_date', 'desc')
                    ->take(10)
                    ->get(['id', 'user_id', 'shift_date', 'point_type', 'expires_at']);

                $this->table(
                    ['ID', 'User ID', 'Shift Date', 'Point Type', 'Expires At'],
                    $sampleAfter->map(fn($p) => [
                        $p->id,
                        $p->user_id,
                        $p->shift_date?->format('Y-m-d'),
                        $p->point_type,
                        $p->expires_at?->format('Y-m-d'),
                    ])
                );

                Log::info("FixAttendancePointDates: Added {$days} day(s) to {$totalPoints} attendance point dates");
                $this->info("\n✅ Successfully fixed dates for {$totalPoints} attendance points!");

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("FixAttendancePointDates Error: " . $e->getMessage());
                $this->error("Failed to fix dates: " . $e->getMessage());
                return Command::FAILURE;
            }
        } else {
            // Preview what dates would look like after fix
            $this->info("\nPreview of dates after fix would be applied:");
            $samplePreview = AttendancePoint::orderBy('shift_date', 'desc')
                ->take(10)
                ->get(['id', 'user_id', 'shift_date', 'point_type', 'expires_at']);

            $this->table(
                ['ID', 'User ID', 'Current Shift Date', 'New Shift Date', 'Point Type'],
                $samplePreview->map(fn($p) => [
                    $p->id,
                    $p->user_id,
                    $p->shift_date?->format('Y-m-d'),
                    $p->shift_date?->copy()->addDays($days)->format('Y-m-d'),
                    $p->point_type,
                ])
            );

            $this->info("\nTo apply these changes, run without --dry-run flag.");
        }

        return Command::SUCCESS;
    }
}
