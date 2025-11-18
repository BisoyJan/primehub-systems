<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateMissingAttendancePoints extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:generate-points
                            {--from= : Start date (YYYY-MM-DD)}
                            {--to= : End date (YYYY-MM-DD)}
                            {--all : Generate for all attendance records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate missing attendance points for attendance records with violations';

    protected AttendanceProcessor $processor;

    public function __construct(AttendanceProcessor $processor)
    {
        parent::__construct();
        $this->processor = $processor;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating Missing Attendance Points');
        $this->newLine();

        // Build query for attendance records that need points
        $query = Attendance::whereIn('status', ['ncns', 'half_day_absence', 'tardy', 'undertime']);

        // Apply date filters
        if ($this->option('all')) {
            $this->info('Processing ALL attendance records...');
        } else {
            $from = $this->option('from');
            $to = $this->option('to');

            if (!$from || !$to) {
                $this->error('Please provide --from and --to dates, or use --all flag');
                return 1;
            }

            try {
                $fromDate = Carbon::parse($from);
                $toDate = Carbon::parse($to);

                if ($toDate->lessThan($fromDate)) {
                    $this->error('End date must be after start date');
                    return 1;
                }

                $query->whereBetween('shift_date', [$fromDate, $toDate]);
                $this->info("Processing attendance records from {$fromDate->format('Y-m-d')} to {$toDate->format('Y-m-d')}");
            } catch (\Exception $e) {
                $this->error('Invalid date format. Please use YYYY-MM-DD format.');
                return 1;
            }
        }

        $attendances = $query->orderBy('shift_date')->get();

        if ($attendances->isEmpty()) {
            $this->info('No attendance records found that require points.');
            return 0;
        }

        $this->info("Found {$attendances->count()} attendance records with violations");
        $this->newLine();

        $pointsCreated = 0;
        $pointsSkipped = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar($attendances->count());
        $progressBar->start();

        foreach ($attendances as $attendance) {
            try {
                // Check if point already exists for this attendance
                $existingPoint = AttendancePoint::where('attendance_id', $attendance->id)->first();

                if ($existingPoint) {
                    $pointsSkipped++;
                    $progressBar->advance();
                    continue;
                }

                // Use the processor's method to regenerate points
                $this->processor->regeneratePointsForAttendance($attendance);
                $pointsCreated++;

            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error processing attendance {$attendance->id}: {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Attendance Records', $attendances->count()],
                ['Points Created', $pointsCreated],
                ['Points Skipped (already exist)', $pointsSkipped],
                ['Errors', $errors],
            ]
        );

        if ($pointsCreated > 0) {
            $this->info("✓ Successfully created {$pointsCreated} attendance points");
        }

        if ($errors > 0) {
            $this->warn("⚠ {$errors} errors occurred during processing");
        }

        return 0;
    }
}
