<?php

namespace App\Console\Commands;

use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Services\LeaveCreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLeaveCreditsDeduction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave:backfill-credits-deduction
                            {--dry-run : Show what would be deducted without making changes}
                            {--leave-id= : Backfill specific leave request ID only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill missing leave credit deductions for approved VL/SL leave requests';

    /**
     * Execute the console command.
     */
    public function handle(LeaveCreditService $leaveCreditService): int
    {
        $dryRun = $this->option('dry-run');
        $specificLeaveId = $this->option('leave-id');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Find approved VL leaves where credits weren't actually deducted from LeaveCredit records
        // This includes cases where credits_deducted is set but the LeaveCredit.credits_used is 0
        $query = LeaveRequest::where('status', 'approved')
            ->where('leave_type', 'VL') // VL always requires credits
            ->where('days_requested', '>', 0)
            ->with('user');

        if ($specificLeaveId) {
            $query->where('id', $specificLeaveId);
        }

        $allVLLeaves = $query->get();

        // Filter to only leaves where LeaveCredit wasn't actually updated
        $affectedLeaves = $allVLLeaves->filter(function ($leave) {
            // Check if the credits were actually deducted from LeaveCredit table
            $creditsYear = $leave->credits_year ?? $leave->start_date->year;

            // Check credits for the specified year
            $yearCredits = LeaveCredit::forUser($leave->user_id)
                ->forYear($creditsYear)
                ->sum('credits_used');

            // Also check previous year if current year has no records
            if ($yearCredits == 0) {
                $previousYear = $creditsYear - 1;
                $previousYearUsed = LeaveCredit::forUser($leave->user_id)
                    ->forYear($previousYear)
                    ->sum('credits_used');

                // If no usage in either year, this leave wasn't properly deducted
                if ($previousYearUsed == 0 && $leave->days_requested > 0) {
                    return true;
                }
            }

            return false;
        });

        if ($affectedLeaves->isEmpty()) {
            $this->info('No approved VL leave requests found with missing credit deductions.');
            return Command::SUCCESS;
        }

        $this->info("Found {$affectedLeaves->count()} approved VL leave requests with missing credit deductions:");
        $this->newLine();

        $successCount = 0;
        $failCount = 0;

        foreach ($affectedLeaves as $leave) {
            $this->line("Processing Leave #{$leave->id}:");
            $this->line("  - User: {$leave->user->name} (ID: {$leave->user_id})");
            $this->line("  - Type: {$leave->leave_type}");
            $this->line("  - Dates: {$leave->start_date->format('Y-m-d')} to {$leave->end_date->format('Y-m-d')}");
            $this->line("  - Days Requested: {$leave->days_requested}");

            // Find available credits
            $leaveYear = $leave->start_date->year;
            $credits = LeaveCredit::forUser($leave->user_id)
                ->forYear($leaveYear)
                ->get();

            $availableCredits = $credits->sum('credits_balance');

            // If no credits for leave year, check previous year
            if ($availableCredits <= 0) {
                $previousYear = $leaveYear - 1;
                $previousCredits = LeaveCredit::forUser($leave->user_id)
                    ->forYear($previousYear)
                    ->get();
                $previousYearBalance = $previousCredits->sum('credits_balance');

                if ($previousYearBalance > 0) {
                    $this->line("  - No credits in {$leaveYear}, using {$previousYear} (Balance: {$previousYearBalance})");
                    $availableCredits = $previousYearBalance;
                    $leaveYear = $previousYear;
                }
            }

            $this->line("  - Available Credits ({$leaveYear}): {$availableCredits}");

            if ($availableCredits <= 0) {
                $this->error("  - SKIPPED: No credits available to deduct");
                $failCount++;
                $this->newLine();
                continue;
            }

            if (!$dryRun) {
                // Temporarily set credits_year to the year we want to deduct from
                $originalCreditsYear = $leave->credits_year;

                $result = $leaveCreditService->deductCredits($leave, $leaveYear);

                if ($result) {
                    $leave->refresh();
                    $this->info("  - SUCCESS: Deducted {$leave->credits_deducted} credits from {$leave->credits_year}");
                    $successCount++;
                } else {
                    $this->error("  - FAILED: Could not deduct credits");
                    $failCount++;
                }
            } else {
                $toDeduct = min($leave->days_requested, $availableCredits);
                $this->info("  - WOULD DEDUCT: {$toDeduct} credits from {$leaveYear}");
                $successCount++;
            }

            $this->newLine();
        }

        $this->newLine();
        if ($dryRun) {
            $this->warn("DRY RUN COMPLETE:");
            $this->info("  Would process: {$successCount} leave requests");
            $this->info("  Would skip: {$failCount} leave requests (no credits available)");
        } else {
            $this->info("BACKFILL COMPLETE:");
            $this->info("  Successfully deducted: {$successCount} leave requests");
            $this->info("  Failed/Skipped: {$failCount} leave requests");
        }

        return Command::SUCCESS;
    }
}
