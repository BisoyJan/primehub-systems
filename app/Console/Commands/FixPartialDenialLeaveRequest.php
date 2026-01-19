<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixPartialDenialLeaveRequest extends Command
{
    protected $signature = 'leave:fix-partial-denial {id : The leave request ID to fix}';

    protected $description = 'Fix attendance records, credits, and dates for a partial denial leave request';

    public function handle(): int
    {
        $leaveRequestId = $this->argument('id');

        $lr = LeaveRequest::find($leaveRequestId);

        if (!$lr) {
            $this->error("Leave request #{$leaveRequestId} not found.");
            return 1;
        }

        if (!$lr->has_partial_denial) {
            $this->error("Leave request #{$leaveRequestId} does not have a partial denial.");
            return 1;
        }

        $this->info("Fixing Leave Request #{$leaveRequestId}");
        $this->info("Status: {$lr->status}");
        $this->info("Days Requested: {$lr->days_requested}");
        $this->info("Approved Days: {$lr->approved_days}");
        $this->info("Current Credits Deducted: {$lr->credits_deducted}");
        $this->info("Start Date: {$lr->start_date}");
        $this->info("End Date: {$lr->end_date}");

        // Get denied dates
        $deniedDates = $lr->deniedDates()
            ->pluck('denied_date')
            ->map(fn($d) => Carbon::parse($d)->format('Y-m-d'))
            ->toArray();

        $this->info("Denied Dates: " . implode(', ', $deniedDates));

        if (empty($deniedDates)) {
            $this->error("No denied dates found for this leave request.");
            return 1;
        }

        // Calculate approved dates
        $startDate = Carbon::parse($lr->start_date);
        $endDate = Carbon::parse($lr->end_date);
        $approvedDates = [];
        $current = $startDate->copy();

        while ($current->lte($endDate)) {
            $dateStr = $current->format('Y-m-d');
            if (!in_array($dateStr, $deniedDates)) {
                $approvedDates[] = $dateStr;
            }
            $current->addDay();
        }

        $this->info("Approved Dates: " . implode(', ', $approvedDates));

        // Show current attendance records
        $attendances = Attendance::where('leave_request_id', $leaveRequestId)->get();
        $this->info("\nCurrent Attendance Records:");
        foreach ($attendances as $att) {
            $dateStr = Carbon::parse($att->shift_date)->format('Y-m-d');
            $isDenied = in_array($dateStr, $deniedDates) ? ' (DENIED - should be removed)' : ' (OK)';
            $this->info("  - {$dateStr}: {$att->status}{$isDenied}");
        }

        // Calculate credits to restore
        $creditsToRestore = $lr->credits_deducted - $lr->approved_days;
        $this->info("\nCredits to restore: {$creditsToRestore}");

        // Calculate new dates
        $newStartDate = count($approvedDates) > 0 ? min($approvedDates) : null;
        $newEndDate = count($approvedDates) > 0 ? max($approvedDates) : null;
        $this->info("New Start Date: {$newStartDate}");
        $this->info("New End Date: {$newEndDate}");

        if (!$this->confirm('Do you want to proceed with the fix?')) {
            $this->info('Aborted.');
            return 0;
        }

        DB::beginTransaction();
        try {
            // Delete attendance records for denied dates
            $deleted = Attendance::where('leave_request_id', $leaveRequestId)
                ->whereIn('shift_date', $deniedDates)
                ->delete();
            $this->info("Deleted {$deleted} attendance records for denied dates.");

            // Restore credits
            if ($creditsToRestore > 0) {
                $year = Carbon::parse($lr->start_date)->year;
                $creditsRecords = LeaveCredit::where('user_id', $lr->user_id)
                    ->where('year', $year)
                    ->orderBy('month')
                    ->get();

                $remaining = $creditsToRestore;
                foreach ($creditsRecords as $credit) {
                    if ($remaining <= 0) break;

                    $canRestore = min($remaining, $credit->credits_used);
                    if ($canRestore > 0) {
                        $credit->credits_used -= $canRestore;
                        $credit->credits_balance += $canRestore;
                        $credit->save();
                        $remaining -= $canRestore;
                        $this->info("Restored {$canRestore} credits to month {$credit->month}");
                    }
                }
            }

            // Update leave request
            // Store original dates if not already stored
            if (!$lr->original_start_date) {
                $lr->original_start_date = $lr->start_date;
            }
            if (!$lr->original_end_date) {
                $lr->original_end_date = $lr->end_date;
            }

            // Update dates to reflect approved period
            if ($newStartDate && $newEndDate) {
                $lr->start_date = $newStartDate;
                $lr->end_date = $newEndDate;
            }

            $lr->credits_deducted = $lr->approved_days;
            $lr->save();

            $this->info("Updated leave request:");
            $this->info("  - credits_deducted: {$lr->credits_deducted}");
            $this->info("  - start_date: {$lr->start_date}");
            $this->info("  - end_date: {$lr->end_date}");
            $this->info("  - original_start_date: {$lr->original_start_date}");
            $this->info("  - original_end_date: {$lr->original_end_date}");

            DB::commit();
            $this->info("\n✅ Fix completed successfully!");

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }
}
