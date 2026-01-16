<?php

namespace App\Console\Commands;

use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\LeaveRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixLeaveCreditsDeduction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave:fix-credits-deduction
                            {--dry-run : Show what would be fixed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix leave credit deductions that were incorrectly applied to previous year instead of current year carryover';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Find approved VL/SL leaves where:
        // 1. Leave was taken in year X (e.g., 2026)
        // 2. But credits_year is year X-1 (e.g., 2025)
        // This indicates the deduction was done from wrong year
        $affectedLeaves = LeaveRequest::where('status', 'approved')
            ->whereIn('leave_type', ['VL', 'SL'])
            ->where('credits_deducted', '>', 0)
            ->whereColumn(DB::raw('YEAR(start_date)'), '!=', 'credits_year')
            ->with('user')
            ->get();

        if ($affectedLeaves->isEmpty()) {
            $this->info('No leave requests found with incorrect year deductions.');
            return Command::SUCCESS;
        }

        $this->info("Found {$affectedLeaves->count()} leave requests with incorrect year deductions:");
        $this->newLine();

        $successCount = 0;
        $failCount = 0;

        foreach ($affectedLeaves as $leave) {
            $leaveYear = $leave->start_date->year;
            $deductedYear = $leave->credits_year;

            $this->line("Processing Leave #{$leave->id}:");
            $this->line("  - User: {$leave->user->name} (ID: {$leave->user_id})");
            $this->line("  - Leave Type: {$leave->leave_type}");
            $this->line("  - Leave Dates: {$leave->start_date->format('Y-m-d')} to {$leave->end_date->format('Y-m-d')}");
            $this->line("  - Credits Deducted: {$leave->credits_deducted}");
            $this->line("  - Wrong Year: {$deductedYear} → Should be: {$leaveYear}");

            // Check if carryover exists for the correct year
            $carryover = LeaveCreditCarryover::forUser($leave->user_id)
                ->toYear($leaveYear)
                ->first();

            if (!$carryover) {
                $this->error("  - SKIPPED: No carryover record found for {$leaveYear}");
                $failCount++;
                $this->newLine();
                continue;
            }

            $this->line("  - Carryover to {$leaveYear}: {$carryover->carryover_credits}");

            if (!$dryRun) {
                DB::beginTransaction();
                try {
                    // Step 1: Restore credits to the wrong year (deductedYear)
                    $this->restoreCreditsToYear($leave->user_id, $deductedYear, $leave->credits_deducted);
                    $this->line("  - Restored {$leave->credits_deducted} credits to {$deductedYear}");

                    // Step 2: Create or get carryover credit record for correct year
                    $carryoverCredit = $this->ensureCarryoverCreditRecord($leave->user_id, $leaveYear, $carryover->carryover_credits);

                    // Step 3: Deduct from the correct year's carryover
                    $deducted = $this->deductFromCarryover($carryoverCredit, $leave->credits_deducted);
                    $this->line("  - Deducted {$deducted} credits from {$leaveYear} carryover");

                    // Step 4: Update leave request to correct year
                    $leave->update([
                        'credits_year' => $leaveYear,
                        'credits_deducted' => $deducted,
                    ]);

                    DB::commit();
                    $this->info("  - SUCCESS: Fixed deduction year");
                    $successCount++;
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("  - FAILED: {$e->getMessage()}");
                    $failCount++;
                }
            } else {
                $this->info("  - WOULD FIX: Restore to {$deductedYear}, deduct from {$leaveYear} carryover");
                $successCount++;
            }

            $this->newLine();
        }

        $this->newLine();
        if ($dryRun) {
            $this->warn("DRY RUN COMPLETE:");
            $this->info("  Would fix: {$successCount} leave requests");
            $this->info("  Would skip: {$failCount} leave requests");
        } else {
            $this->info("FIX COMPLETE:");
            $this->info("  Successfully fixed: {$successCount} leave requests");
            $this->info("  Failed/Skipped: {$failCount} leave requests");
        }

        return Command::SUCCESS;
    }

    /**
     * Restore credits to a specific year's LeaveCredit records.
     */
    protected function restoreCreditsToYear(int $userId, int $year, float $amount): void
    {
        $credits = LeaveCredit::forUser($userId)
            ->forYear($year)
            ->orderBy('month')
            ->get();

        $remaining = $amount;

        foreach ($credits as $credit) {
            if ($remaining <= 0) break;

            $toRestore = min($remaining, $credit->credits_used);
            if ($toRestore > 0) {
                $credit->credits_used -= $toRestore;
                $credit->credits_balance += $toRestore;
                $credit->save();
                $remaining -= $toRestore;
            }
        }
    }

    /**
     * Ensure carryover credit record exists for month 0.
     */
    protected function ensureCarryoverCreditRecord(int $userId, int $year, float $carryoverAmount): LeaveCredit
    {
        $existing = LeaveCredit::forUser($userId)
            ->forYear($year)
            ->where('month', 0)
            ->first();

        if ($existing) {
            return $existing;
        }

        return LeaveCredit::create([
            'user_id' => $userId,
            'year' => $year,
            'month' => 0,
            'credits_earned' => $carryoverAmount,
            'credits_used' => 0,
            'credits_balance' => $carryoverAmount,
            'accrued_at' => "{$year}-01-01", // Carryover is available from start of year
        ]);
    }

    /**
     * Deduct credits from carryover record.
     */
    protected function deductFromCarryover(LeaveCredit $carryoverCredit, float $amount): float
    {
        $toDeduct = min($amount, $carryoverCredit->credits_balance);

        $carryoverCredit->credits_used += $toDeduct;
        $carryoverCredit->credits_balance -= $toDeduct;
        $carryoverCredit->save();

        return $toDeduct;
    }
}
