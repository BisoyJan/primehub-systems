<?php

namespace App\Console\Commands;

use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\LeaveRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixLeaveCreditMismatch extends Command
{
    protected $signature = 'leave:fix-credit-mismatch {--dry-run : Only show issues without fixing}';

    protected $description = 'Find and fix leave credit mismatches where credits_deducted does not match LeaveCredit records';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $this->info('=== Scanning for leave credit mismatches ===');
        $this->newLine();

        // Get all approved leave requests with credits deducted
        $approvedLeaves = LeaveRequest::where('status', 'approved')
            ->whereNotNull('credits_deducted')
            ->where('credits_deducted', '>', 0)
            ->whereNotNull('credits_year')
            ->with('user:id,first_name,last_name')
            ->get();

        $issues = [];

        foreach ($approvedLeaves as $leave) {
            $userId = $leave->user_id;
            $year = $leave->credits_year;
            $key = $userId . '-' . $year;

            // Skip if already processed this user-year combo
            if (isset($issues[$key])) {
                continue;
            }

            // Get total used from LeaveCredit for this user/year
            $totalUsed = LeaveCredit::where('user_id', $userId)
                ->where('year', $year)
                ->sum('credits_used');

            // Get total credits_deducted from all approved leaves for this user/year
            $totalDeducted = LeaveRequest::where('user_id', $userId)
                ->where('status', 'approved')
                ->where('credits_year', $year)
                ->where('credits_deducted', '>', 0)
                ->sum('credits_deducted');

            // Check if there's a mismatch
            if (abs($totalUsed - $totalDeducted) > 0.01) {
                $issues[$key] = [
                    'user_id' => $userId,
                    'user_name' => $leave->user ? $leave->user->first_name . ' ' . $leave->user->last_name : 'Unknown',
                    'year' => $year,
                    'total_deducted' => (float) $totalDeducted,
                    'total_used' => (float) $totalUsed,
                    'difference' => (float) $totalDeducted - (float) $totalUsed,
                ];
            }
        }

        if (empty($issues)) {
            $this->info('✅ No mismatches found! All leave credit deductions are properly recorded.');
            return Command::SUCCESS;
        }

        $this->warn('Found ' . count($issues) . ' user-year combinations with mismatches:');
        $this->newLine();

        $tableData = [];
        foreach ($issues as $issue) {
            $tableData[] = [
                $issue['user_id'],
                $issue['user_name'],
                $issue['year'],
                number_format($issue['total_deducted'], 2),
                number_format($issue['total_used'], 2),
                number_format($issue['difference'], 2),
            ];
        }

        $this->table(
            ['User ID', 'Name', 'Year', 'Deducted (Leave)', 'Used (Credit)', 'Missing'],
            $tableData
        );

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run mode - no changes made. Run without --dry-run to fix issues.');
            return Command::SUCCESS;
        }

        $this->newLine();
        if (!$this->confirm('Do you want to fix these mismatches?')) {
            $this->info('Aborted.');
            return Command::SUCCESS;
        }

        $fixed = 0;
        foreach ($issues as $key => $issue) {
            $this->info("Fixing user {$issue['user_id']} ({$issue['user_name']}) for year {$issue['year']}...");

            DB::beginTransaction();
            try {
                $this->fixUserYearCredits($issue['user_id'], $issue['year']);
                DB::commit();
                $fixed++;
                $this->info("  ✅ Fixed!");
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("  ❌ Failed: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Fixed {$fixed} out of " . count($issues) . " issues.");

        return Command::SUCCESS;
    }

    /**
     * Fix the LeaveCredit records for a user/year to match their approved leave requests.
     */
    protected function fixUserYearCredits(int $userId, int $year): void
    {
        // Get all approved leave requests for this user/year
        $leaveRequests = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->where('credits_year', $year)
            ->where('credits_deducted', '>', 0)
            ->orderBy('start_date')
            ->get();

        $totalToDeduct = $leaveRequests->sum('credits_deducted');

        // First, ensure carryover credit record exists if there's a carryover
        $carryover = LeaveCreditCarryover::where('user_id', $userId)
            ->where('to_year', $year)
            ->first();

        if ($carryover && $carryover->carryover_credits > 0) {
            // Check if month 0 record exists
            $carryoverCredit = LeaveCredit::where('user_id', $userId)
                ->where('year', $year)
                ->where('month', 0)
                ->first();

            if (!$carryoverCredit) {
                // Create carryover credit record
                LeaveCredit::create([
                    'user_id' => $userId,
                    'year' => $year,
                    'month' => 0,
                    'credits_earned' => $carryover->carryover_credits,
                    'credits_used' => 0,
                    'credits_balance' => $carryover->carryover_credits,
                    'accrued_at' => "{$year}-01-01",
                ]);
                $this->line("    Created carryover credit record (month 0) with {$carryover->carryover_credits} credits");
            }
        }

        // Reset all credits_used to 0 first
        LeaveCredit::where('user_id', $userId)
            ->where('year', $year)
            ->update(['credits_used' => 0]);

        // Recalculate credits_balance based on credits_earned
        $credits = LeaveCredit::where('user_id', $userId)
            ->where('year', $year)
            ->orderBy('month')
            ->get();

        foreach ($credits as $credit) {
            $credit->credits_balance = $credit->credits_earned;
            $credit->save();
        }

        // Now apply deductions using FIFO (same as normal deduction)
        $remainingToDeduct = $totalToDeduct;

        foreach ($credits as $credit) {
            if ($remainingToDeduct <= 0) {
                break;
            }

            $availableInThisMonth = $credit->credits_balance;
            $deductFromThisMonth = min($remainingToDeduct, $availableInThisMonth);

            if ($deductFromThisMonth > 0) {
                $credit->credits_used += $deductFromThisMonth;
                $credit->credits_balance -= $deductFromThisMonth;
                $credit->save();

                $monthName = $credit->month == 0 ? 'Carryover' : "Month {$credit->month}";
                $this->line("    Deducted {$deductFromThisMonth} from {$monthName}");

                $remainingToDeduct -= $deductFromThisMonth;
            }
        }

        if ($remainingToDeduct > 0) {
            $this->warn("    ⚠️  Could not deduct all credits - {$remainingToDeduct} remaining (insufficient credits)");
        }
    }
}
