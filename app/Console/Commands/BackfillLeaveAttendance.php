<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLeaveAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave:backfill-attendance
                            {--dry-run : Show what would be created without making changes}
                            {--leave-id= : Backfill specific leave request ID only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill missing attendance records for approved leave requests';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $specificLeaveId = $this->option('leave-id');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Get approved leave requests without attendance records
        $query = LeaveRequest::where('status', 'approved')
            ->whereNotIn('leave_type', ['SL']) // SL has special handling (advised_absence)
            ->whereDoesntHave('attendances');

        if ($specificLeaveId) {
            $query->where('id', $specificLeaveId);
        }

        $leavesWithoutAttendance = $query->with('user')->get();

        if ($leavesWithoutAttendance->isEmpty()) {
            $this->info('No approved leave requests found without attendance records.');
            return Command::SUCCESS;
        }

        $this->info("Found {$leavesWithoutAttendance->count()} approved leave requests without attendance records:");
        $this->newLine();

        $totalCreated = 0;
        $totalUpdated = 0;

        foreach ($leavesWithoutAttendance as $leave) {
            $this->line("Processing Leave #{$leave->id}:");
            $this->line("  - User: {$leave->user->name} (ID: {$leave->user_id})");
            $this->line("  - Type: {$leave->leave_type}");
            $this->line("  - Dates: {$leave->start_date->format('Y-m-d')} to {$leave->end_date->format('Y-m-d')}");

            $startDate = Carbon::parse($leave->start_date);
            $endDate = Carbon::parse($leave->end_date);
            $leaveNote = "On approved {$leave->leave_type}" . ($leave->reason ? " - {$leave->reason}" : '');

            $currentDate = $startDate->copy();
            $createdCount = 0;
            $updatedCount = 0;

            while ($currentDate->lte($endDate)) {
                $dateStr = $currentDate->format('Y-m-d');

                // Check if attendance already exists for this date
                $existingAttendance = Attendance::where('user_id', $leave->user_id)
                    ->where('shift_date', $dateStr)
                    ->first();

                if ($existingAttendance) {
                    // Update existing record if not already on_leave and not verified
                    if ($existingAttendance->status !== 'on_leave' && !$existingAttendance->admin_verified) {
                        if (!$dryRun) {
                            $existingAttendance->update([
                                'status' => 'on_leave',
                                'leave_request_id' => $leave->id,
                                'notes' => $leaveNote,
                                'admin_verified' => true,
                            ]);
                        }
                        $updatedCount++;
                        $this->line("    Updated: {$dateStr} (was: {$existingAttendance->status})");
                    } else {
                        $this->line("    Skipped: {$dateStr} (already on_leave or verified)");
                    }
                } else {
                    // Create new attendance record
                    $schedule = $this->getActiveScheduleForDate($leave->user_id, $dateStr);

                    if (!$dryRun) {
                        Attendance::create([
                            'user_id' => $leave->user_id,
                            'employee_schedule_id' => $schedule?->id,
                            'shift_date' => $dateStr,
                            'scheduled_time_in' => $schedule?->scheduled_time_in,
                            'scheduled_time_out' => $schedule?->scheduled_time_out,
                            'status' => 'on_leave',
                            'leave_request_id' => $leave->id,
                            'notes' => $leaveNote,
                            'admin_verified' => true,
                        ]);
                    }
                    $createdCount++;
                    $this->line("    Created: {$dateStr}");
                }

                $currentDate->addDay();
            }

            $totalCreated += $createdCount;
            $totalUpdated += $updatedCount;
            $this->newLine();
        }

        $this->newLine();
        if ($dryRun) {
            $this->warn("DRY RUN COMPLETE:");
            $this->info("  Would create: {$totalCreated} attendance records");
            $this->info("  Would update: {$totalUpdated} attendance records");
        } else {
            $this->info("BACKFILL COMPLETE:");
            $this->info("  Created: {$totalCreated} attendance records");
            $this->info("  Updated: {$totalUpdated} attendance records");
        }

        return Command::SUCCESS;
    }

    /**
     * Get user's active schedule for a specific date.
     */
    protected function getActiveScheduleForDate(int $userId, string $date): ?EmployeeSchedule
    {
        return EmployeeSchedule::where('user_id', $userId)
            ->where('is_active', true)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_date')
                    ->orWhere('effective_date', '<=', $date);
            })
            ->orderBy('effective_date', 'desc')
            ->first();
    }
}
