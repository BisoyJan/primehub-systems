<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveCreditService
{
    /**
     * Manager roles that get 1.5 credits per month.
     */
    const MANAGER_ROLES = ['Super Admin', 'Admin', 'Team Lead', 'HR'];

    /**
     * Employee roles that get 1.25 credits per month.
     */
    const EMPLOYEE_ROLES = ['Agent', 'IT', 'Utility'];

    /**
     * Get monthly credit rate based on user role.
     */
    public function getMonthlyRate(User $user): float
    {
        return in_array($user->role, self::MANAGER_ROLES) ? 1.5 : 1.25;
    }

    /**
     * Check if user is eligible to use leave credits (6 months after hire date).
     */
    public function isEligible(User $user): bool
    {
        if (!$user->hired_date) {
            return false;
        }

        $sixMonthsAfterHire = Carbon::parse($user->hired_date)->addMonths(6);
        return now()->greaterThanOrEqualTo($sixMonthsAfterHire);
    }

    /**
     * Get eligibility date (6 months after hire).
     */
    public function getEligibilityDate(User $user): ?Carbon
    {
        if (!$user->hired_date) {
            return null;
        }

        return Carbon::parse($user->hired_date)->addMonths(6);
    }

    /**
     * Get current leave credits balance for a user in a specific year.
     *
     * NOTE: Credits are year-specific and do NOT carry over to the next year.
     * Each year starts with 0 balance and accrues monthly.
     */
    public function getBalance(User $user, ?int $year = null): float
    {
        $year = $year ?? now()->year;
        return LeaveCredit::getTotalBalance($user->id, $year);
    }

    /**
     * Get projected leave credits balance for a user at a future date.
     * This calculates the current balance + future accruals up to the target date.
     *
     * Credits accrue at end of each month. So for a leave in Feb 15:
     * - If today is Jan 7, January's credits (accrued Jan 31) should be included
     * - Current balance only has credits up to the last completed month
     *
     * @param User $user
     * @param Carbon $targetDate The date to project credits to
     * @param int|null $year The credit year to use
     * @return array{current: float, projected: float, months_until: int, future_accrual: float}
     */
    public function getProjectedBalance(User $user, Carbon $targetDate, ?int $year = null): array
    {
        $year = $year ?? now()->year;
        $currentBalance = $this->getBalance($user, $year);

        // If target date is in the past or current month before month end, no projection needed
        $today = now();
        $currentMonthEnd = $today->copy()->endOfMonth();

        // If target date is within current month and before month end, no future accrual
        if ($targetDate->lte($currentMonthEnd)) {
            return [
                'current' => $currentBalance,
                'projected' => $currentBalance,
                'months_until' => 0,
                'future_accrual' => 0,
            ];
        }

        // Count months that will accrue before the target date
        // Start from current month (credits accrue at end of month)
        $monthlyRate = $this->getMonthlyRate($user);
        $checkMonth = $today->month;
        $checkYear = $today->year;

        $monthsUntil = 0;

        while (true) {
            // Get the end of this month
            $monthEnd = Carbon::create($checkYear, $checkMonth, 1)->endOfMonth();

            // Only count if:
            // 1. Month end is before target date (credits will be available)
            // 2. Month is in the same credit year
            if ($monthEnd->lt($targetDate) && $checkYear == $year) {
                $monthsUntil++;
            }

            // Move to next month
            $checkMonth++;
            if ($checkMonth > 12) {
                $checkMonth = 1;
                $checkYear++;
            }

            // Stop if we've passed the credit year or reached the target month
            if ($checkYear > $year) {
                break;
            }
            if ($checkYear == $targetDate->year && $checkMonth > $targetDate->month) {
                break;
            }
        }

        $futureAccrual = $monthsUntil * $monthlyRate;
        $projectedBalance = $currentBalance + $futureAccrual;

        return [
            'current' => $currentBalance,
            'projected' => $projectedBalance,
            'months_until' => $monthsUntil,
            'future_accrual' => $futureAccrual,
        ];
    }

    /**
     * Get total days from pending leave requests that require credits.
     * Note: We count ALL pending requests regardless of year, since they represent
     * credits that will be deducted from the user's balance when approved.
     */
    public function getPendingCredits(User $user, ?int $year = null): float
    {
        return LeaveRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->whereIn('leave_type', ['VL', 'SL', 'BL']) // Only credit-requiring leave types
            ->sum('days_requested');
    }

    /**
     * Get detailed leave credits summary for a user.
     */
    public function getSummary(User $user, ?int $year = null): array
    {
        $year = $year ?? now()->year;
        $monthlyRate = $this->getMonthlyRate($user);

        return [
            'year' => $year,
            'is_eligible' => $this->isEligible($user),
            'eligibility_date' => $this->getEligibilityDate($user),
            'monthly_rate' => $monthlyRate,
            'total_earned' => LeaveCredit::getTotalEarned($user->id, $year),
            'total_used' => LeaveCredit::getTotalUsed($user->id, $year),
            'balance' => $this->getBalance($user, $year),
            'pending_credits' => $this->getPendingCredits($user, $year),
            'credits_by_month' => LeaveCredit::forUser($user->id)
                ->forYear($year)
                ->orderBy('month')
                ->get(),
        ];
    }

    /**
     * Accrue monthly leave credits for a user.
     */
    public function accrueMonthly(User $user, ?int $year = null, ?int $month = null): ?LeaveCredit
    {
        $year = $year ?? now()->year;
        $month = $month ?? now()->month;

        // Don't accrue if user doesn't have a hire date
        if (!$user->hired_date) {
            return null;
        }

        // Don't accrue if the month hasn't ended yet (run on last day of month)
        $targetDate = Carbon::create($year, $month, 1)->endOfMonth();
        if (now()->lt($targetDate)) {
            return null;
        }

        // Don't accrue before hire date
        $hireDate = Carbon::parse($user->hired_date);
        if ($targetDate->lt($hireDate)) {
            return null;
        }

        // Check if already accrued for this month
        $existing = LeaveCredit::forUser($user->id)
            ->forMonth($year, $month)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Calculate credits to add
        $rate = $this->getMonthlyRate($user);

        // Create new credit record
        return LeaveCredit::create([
            'user_id' => $user->id,
            'credits_earned' => $rate,
            'credits_used' => 0,
            'credits_balance' => $rate,
            'year' => $year,
            'month' => $month,
            'accrued_at' => $targetDate,
        ]);
    }

    /**
     * Backfill missing leave credits for the current year only.
     * Only creates credits from January of current year to avoid database bloat
     * and confusion about expired credits from previous years.
     *
     * Useful when adding existing employees or fixing missing accruals.
     */
    public function backfillCredits(User $user): int
    {
        if (!$user->hired_date) {
            return 0;
        }

        $hireDate = Carbon::parse($user->hired_date);
        $today = now();
        $currentYear = $today->year;
        $accrued = 0;

        // Start from January of current year or hire month (whichever is later)
        $currentDate = Carbon::create($currentYear, 1, 1)->startOfMonth();

        // If hired this year, start from hire month instead
        if ($hireDate->year === $currentYear) {
            $currentDate = $hireDate->copy()->startOfMonth();
        }

        // Don't backfill if hired after current date
        if ($currentDate->gt($today)) {
            return 0;
        }

        // Loop through each month from start date to last completed month (current year only)
        while ($currentDate->copy()->endOfMonth()->lt($today) && $currentDate->year === $currentYear) {
            $year = $currentDate->year;
            $month = $currentDate->month;

            // Try to accrue for this month
            $credit = $this->accrueMonthly($user, $year, $month);
            if ($credit && $credit->wasRecentlyCreated) {
                $accrued++;
            }

            // Move to next month
            $currentDate->addMonth()->startOfMonth();
        }

        return $accrued;
    }

    /**
     * Deduct leave credits when a leave request is approved.
     */
    public function deductCredits(LeaveRequest $leaveRequest, ?int $year = null): bool
    {
        if (!$leaveRequest->requiresCredits()) {
            return true; // Non-credited leave types don't need deduction
        }

        $year = $year ?? now()->year;
        $daysToDeduct = $leaveRequest->days_requested;

        // Get all credit records for this year ordered by month
        $credits = LeaveCredit::forUser($leaveRequest->user_id)
            ->forYear($year)
            ->orderBy('month')
            ->get();

        $remainingToDeduct = $daysToDeduct;

        DB::beginTransaction();
        try {
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

                    $remainingToDeduct -= $deductFromThisMonth;
                }
            }

            // Update leave request with deduction info
            $leaveRequest->update([
                'credits_deducted' => $daysToDeduct,
                'credits_year' => $year,
            ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    /**
     * Restore leave credits when a leave request is cancelled.
     */
    public function restoreCredits(LeaveRequest $leaveRequest): bool
    {
        if (!$leaveRequest->credits_deducted || !$leaveRequest->credits_year) {
            return true; // Nothing to restore
        }

        $year = $leaveRequest->credits_year;
        $daysToRestore = $leaveRequest->credits_deducted;

        // Get all credit records for this year ordered by month (ascending)
        $credits = LeaveCredit::forUser($leaveRequest->user_id)
            ->forYear($year)
            ->orderBy('month')
            ->get();

        $remainingToRestore = $daysToRestore;

        DB::beginTransaction();
        try {
            foreach ($credits as $credit) {
                if ($remainingToRestore <= 0) {
                    break;
                }

                $usedInThisMonth = $credit->credits_used;
                $restoreToThisMonth = min($remainingToRestore, $usedInThisMonth);

                if ($restoreToThisMonth > 0) {
                    $credit->credits_used -= $restoreToThisMonth;
                    $credit->credits_balance += $restoreToThisMonth;
                    $credit->save();

                    $remainingToRestore -= $restoreToThisMonth;
                }
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    /**
     * Restore partial leave credits when leave is shortened (e.g., employee reported on last day).
     *
     * @param LeaveRequest $leaveRequest The leave request being adjusted
     * @param float $daysToRestore Number of days to restore
     * @param string $reason Reason for the restoration
     * @return bool
     */
    public function restorePartialCredits(LeaveRequest $leaveRequest, float $daysToRestore, string $reason = ''): bool
    {
        if (!$leaveRequest->credits_deducted || !$leaveRequest->credits_year || $daysToRestore <= 0) {
            return true; // Nothing to restore
        }

        // Don't restore more than was deducted
        $daysToRestore = min($daysToRestore, $leaveRequest->credits_deducted);

        $year = $leaveRequest->credits_year;

        // Get all credit records for this year ordered by month (ascending)
        $credits = LeaveCredit::forUser($leaveRequest->user_id)
            ->forYear($year)
            ->orderBy('month')
            ->get();

        $remainingToRestore = $daysToRestore;

        DB::beginTransaction();
        try {
            foreach ($credits as $credit) {
                if ($remainingToRestore <= 0) {
                    break;
                }

                $usedInThisMonth = $credit->credits_used;
                $restoreToThisMonth = min($remainingToRestore, $usedInThisMonth);

                if ($restoreToThisMonth > 0) {
                    $credit->credits_used -= $restoreToThisMonth;
                    $credit->credits_balance += $restoreToThisMonth;
                    $credit->save();

                    $remainingToRestore -= $restoreToThisMonth;

                    \Log::info('Partial credits restored', [
                        'leave_request_id' => $leaveRequest->id,
                        'user_id' => $leaveRequest->user_id,
                        'month' => $credit->month,
                        'year' => $credit->year,
                        'restored' => $restoreToThisMonth,
                        'reason' => $reason,
                    ]);
                }
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to restore partial credits', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
            ]);
            return false;
        }
    }

    /**
     * Get total attendance points for a user.
     */
    public function getAttendancePoints(User $user): float
    {
        return AttendancePoint::where('user_id', $user->id)
            ->where('is_expired', false)
            ->sum('points');
    }

    /**
     * Absence statuses that count for the 30-day rule.
     * Includes: absent, ncns (No Call No Show), half_day_absence, advised_absence
     */
    const ABSENCE_STATUSES = ['absent', 'ncns', 'half_day_absence', 'advised_absence'];

    /**
     * Check if user had any absences in the last 30 days.
     */
    public function hasRecentAbsence(User $user, ?Carbon $fromDate = null): bool
    {
        $fromDate = $fromDate ?? now();
        $thirtyDaysAgo = $fromDate->copy()->subDays(30);

        return Attendance::where('user_id', $user->id)
            ->where('shift_date', '>=', $thirtyDaysAgo)
            ->where('shift_date', '<=', $fromDate)
            ->whereIn('status', self::ABSENCE_STATUSES)
            ->exists();
    }

    /**
     * Get date when user can apply for leave (30 days after last absence).
     */
    public function getNextEligibleLeaveDate(User $user): ?Carbon
    {
        $lastAbsence = Attendance::where('user_id', $user->id)
            ->whereIn('status', self::ABSENCE_STATUSES)
            ->orderBy('shift_date', 'desc')
            ->first();

        if (!$lastAbsence) {
            return now(); // No absences, can apply anytime
        }

        return Carbon::parse($lastAbsence->shift_date)->addDays(30);
    }

    /**
     * Get the last absence date for a user.
     */
    public function getLastAbsenceDate(User $user): ?Carbon
    {
        $lastAbsence = Attendance::where('user_id', $user->id)
            ->whereIn('status', self::ABSENCE_STATUSES)
            ->orderBy('shift_date', 'desc')
            ->first();

        if (!$lastAbsence) {
            return null;
        }

        return Carbon::parse($lastAbsence->shift_date);
    }

    /**
     * Check if given date falls within 30-day absence window.
     * Returns info about the restriction if applicable.
     *
     * @param User $user
     * @param Carbon $checkDate The date to check
     * @return array{within_window: bool, last_absence_date: string|null, window_end_date: string|null}
     */
    public function checkAbsenceWindowForDate(User $user, Carbon $checkDate): array
    {
        $lastAbsenceDate = $this->getLastAbsenceDate($user);

        if (!$lastAbsenceDate) {
            return [
                'within_window' => false,
                'last_absence_date' => null,
                'window_end_date' => null,
            ];
        }

        $windowEndDate = $lastAbsenceDate->copy()->addDays(30);

        return [
            'within_window' => $checkDate->lte($windowEndDate),
            'last_absence_date' => $lastAbsenceDate->format('Y-m-d'),
            'window_end_date' => $windowEndDate->format('Y-m-d'),
        ];
    }

    /**
     * Calculate number of working days between two dates (excluding weekends).
     * Leave credits are only deducted for working days (Monday-Friday).
     */
    public function calculateDays(Carbon $startDate, Carbon $endDate): float
    {
        $workingDays = 0;
        $currentDate = $startDate->copy();

        // Loop through each day in the range
        while ($currentDate->lte($endDate)) {
            // Count only weekdays (Monday = 1 to Friday = 5)
            // Saturday = 6, Sunday = 7 are excluded
            if ($currentDate->dayOfWeek >= Carbon::MONDAY && $currentDate->dayOfWeek <= Carbon::FRIDAY) {
                $workingDays++;
            }
            $currentDate->addDay();
        }

        return $workingDays;
    }

    /**
     * Validate if a leave request can be submitted based on all business rules.
     * Note: Sick Leave (SL) can be submitted without credits - credits are only deducted
     * if employee is eligible, has sufficient balance, AND submits a medical certificate.
     *
     * @param User $user
     * @param array $data Request data including 'short_notice_override' flag
     * @return array
     */
    public function validateLeaveRequest(User $user, array $data): array
    {
        $errors = [];
        $leaveType = $data['leave_type'];
        $startDate = Carbon::parse($data['start_date']);
        $endDate = Carbon::parse($data['end_date']);
        $year = $data['credits_year'] ?? now()->year;

        // Check if short notice override is enabled (Admin/Super Admin bypassing 2-week rule)
        $shortNoticeOverride = $data['short_notice_override'] ?? false;

        // Check if leave dates are within valid credit usage period
        // Credits from year X can only be used until March of year X+1
        // For example: 2025 credits can be used until March 31, 2026
        if (in_array($leaveType, ['VL', 'BL'])) {
            $creditYear = $year;
            $nextYear = $creditYear + 1;
            $maxLeaveDate = Carbon::create($nextYear, 3, 31)->endOfDay(); // March 31 of next year

            if ($startDate->gt($maxLeaveDate) || $endDate->gt($maxLeaveDate)) {
                $errors[] = "Leave dates cannot be beyond March {$nextYear}. Credits from {$creditYear} can only be used until March 31, {$nextYear}.";
            }
        }

        // Check eligibility (6 months rule) for VL and BL only (SL can proceed without eligibility)
        if (in_array($leaveType, ['VL', 'BL'])) {
            if (!$this->isEligible($user)) {
                $eligibilityDate = $this->getEligibilityDate($user);
                $errors[] = "You are not eligible to use leave credits yet. You will be eligible on {$eligibilityDate->format('F j, Y')}.";
            }
        }

        // Check 2-week advance notice for VL and BL only (SL is unpredictable)
        // Skip this check if short notice override is enabled
        if (in_array($leaveType, ['VL', 'BL']) && !$shortNoticeOverride) {
            $twoWeeksFromNow = now()->addWeeks(2)->startOfDay();
            if ($startDate->startOfDay()->lt($twoWeeksFromNow)) {
                $errors[] = "Leave requests must be submitted at least 2 weeks in advance. Earliest date you can apply for is {$twoWeeksFromNow->format('F j, Y')}.";
            }
        }

        // Validate SL date constraints (3 weeks back to 1 month ahead)
        if ($leaveType === 'SL') {
            $threeWeeksAgo = now()->subWeeks(3)->startOfDay();
            $oneMonthAhead = now()->addMonth()->endOfDay();

            if ($startDate->lt($threeWeeksAgo)) {
                $errors[] = "Sick Leave start date must be within the last 3 weeks.";
            }
            if ($endDate->gt($oneMonthAhead)) {
                $errors[] = "Sick Leave end date cannot exceed 1 month from today.";
            }
        }

        // NOTE: Attendance points > 6 is now informational only (shown in Create page)
        // Reviewers can see the attendance points on the Show page to make approval decisions
        // The rule is NOT enforced as a blocking validation anymore

        // NOTE: 30-day absence rule is now informational only (shown as warning on Create page)
        // Reviewers can see the absence info on the Show page to make approval decisions
        // The rule is NOT enforced as a blocking validation anymore

        // NOTE: Insufficient credits is now informational only
        // Users can submit leave requests even without sufficient credits
        // Reviewers can see the credit balance on the Show page to make approval decisions
        // Credits will only be deducted if approved AND user has sufficient balance

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check if a leave request requires short notice (less than 2 weeks advance).
     * Used to determine if override button should be shown to Admin/Super Admin.
     */
    public function requiresShortNoticeOverride(string $leaveType, Carbon $startDate): bool
    {
        // Only VL and BL require 2-week notice
        if (!in_array($leaveType, ['VL', 'BL'])) {
            return false;
        }

        $twoWeeksFromNow = now()->addWeeks(2)->startOfDay();
        return $startDate->startOfDay()->lt($twoWeeksFromNow);
    }

    /**
     * Determine if credits should be deducted for a Sick Leave request.
     * Credits are deducted only if:
     * 1. Employee is eligible (6+ months)
     * 2. Has sufficient credits
     * 3. Medical certificate is submitted
     */
    public function shouldDeductSlCredits(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->checkSlCreditDeduction($user, $leaveRequest)['should_deduct'];
    }

    /**
     * Check if SL credits should be deducted and return detailed reason if not.
     *
     * @param User $user
     * @param LeaveRequest $leaveRequest
     * @return array{should_deduct: bool, reason: string|null}
     */
    public function checkSlCreditDeduction(User $user, LeaveRequest $leaveRequest): array
    {
        if ($leaveRequest->leave_type !== 'SL') {
            return ['should_deduct' => true, 'reason' => null];
        }

        // Must have medical certificate
        if (!$leaveRequest->medical_cert_submitted) {
            return [
                'should_deduct' => false,
                'reason' => 'No medical certificate submitted',
                'convert_to_upto' => false,
            ];
        }

        // Must be eligible (6+ months)
        if (!$this->isEligible($user)) {
            $hireDate = $user->hired_date ? Carbon::parse($user->hired_date)->format('M d, Y') : 'unknown';
            return [
                'should_deduct' => false,
                'reason' => "Not eligible for SL credits (less than 6 months of employment, hired: {$hireDate})",
                'convert_to_upto' => false,
            ];
        }

        // Must have sufficient credits
        $year = Carbon::parse($leaveRequest->start_date)->year;
        $balance = $this->getBalance($user, $year);
        if ($balance < $leaveRequest->days_requested) {
            // Has medical cert but no credits - convert to UPTO
            return [
                'should_deduct' => false,
                'reason' => "Insufficient SL credits (balance: {$balance} days, requested: {$leaveRequest->days_requested} days) - Converted to UPTO",
                'convert_to_upto' => true,
            ];
        }

        return ['should_deduct' => true, 'reason' => null, 'convert_to_upto' => false];
    }

    /**
     * Maximum credits that can be carried over to the next year.
     * These are for cash conversion only, NOT for applying leaves.
     */
    const MAX_CARRYOVER_CREDITS = 4;

    /**
     * Process year-end carryover for a single user.
     * Unused credits from the previous year (up to max 4) are carried over for cash conversion.
     *
     * @param User $user
     * @param int $fromYear The year to carry over from
     * @param int|null $processedBy User ID who processed this carryover
     * @param string|null $notes Optional notes
     * @return LeaveCreditCarryover|null
     */
    public function processCarryover(User $user, int $fromYear, ?int $processedBy = null, ?string $notes = null): ?LeaveCreditCarryover
    {
        $toYear = $fromYear + 1;

        // Check if already processed for this year transition
        $existing = LeaveCreditCarryover::forUser($user->id)
            ->fromYear($fromYear)
            ->first();

        if ($existing) {
            return $existing; // Already processed
        }

        // Get unused balance from the previous year
        $unusedCredits = $this->getBalance($user, $fromYear);

        if ($unusedCredits <= 0) {
            return null; // No credits to carry over
        }

        // Calculate carryover (max 4 credits)
        $carryoverCredits = min($unusedCredits, self::MAX_CARRYOVER_CREDITS);
        $forfeitedCredits = max(0, $unusedCredits - self::MAX_CARRYOVER_CREDITS);

        return LeaveCreditCarryover::create([
            'user_id' => $user->id,
            'credits_from_previous_year' => $unusedCredits,
            'carryover_credits' => $carryoverCredits,
            'forfeited_credits' => $forfeitedCredits,
            'from_year' => $fromYear,
            'to_year' => $toYear,
            'cash_converted' => false,
            'processed_by' => $processedBy,
            'notes' => $notes,
        ]);
    }

    /**
     * Process year-end carryover for all eligible users.
     *
     * @param int $fromYear The year to carry over from
     * @param int|null $processedBy User ID who triggered this process
     * @return array{processed: int, skipped: int, total_carryover: float, total_forfeited: float}
     */
    public function processAllCarryovers(int $fromYear, ?int $processedBy = null): array
    {
        $users = User::whereNotNull('hired_date')->get();

        $processed = 0;
        $skipped = 0;
        $totalCarryover = 0;
        $totalForfeited = 0;

        foreach ($users as $user) {
            $carryover = $this->processCarryover($user, $fromYear, $processedBy);

            if ($carryover && $carryover->wasRecentlyCreated) {
                $processed++;
                $totalCarryover += $carryover->carryover_credits;
                $totalForfeited += $carryover->forfeited_credits;
            } else {
                $skipped++;
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'total_carryover' => $totalCarryover,
            'total_forfeited' => $totalForfeited,
        ];
    }

    /**
     * Mark carryover credits as cash converted.
     *
     * @param LeaveCreditCarryover $carryover
     * @param int|null $processedBy User ID who processed the conversion
     * @return bool
     */
    public function markAsCashConverted(LeaveCreditCarryover $carryover, ?int $processedBy = null): bool
    {
        return $carryover->update([
            'cash_converted' => true,
            'cash_converted_at' => now(),
            'processed_by' => $processedBy,
        ]);
    }

    /**
     * Get carryover summary for a user (carryover TO a specific year).
     * Use this when you want to see what was carried INTO a year.
     *
     * @param User $user
     * @param int|null $toYear The year to check carryovers for
     * @return array
     */
    public function getCarryoverSummary(User $user, ?int $toYear = null): array
    {
        $toYear = $toYear ?? now()->year;

        $carryover = LeaveCreditCarryover::forUser($user->id)
            ->toYear($toYear)
            ->first();

        if (!$carryover) {
            return [
                'has_carryover' => false,
                'carryover_credits' => 0,
                'forfeited_credits' => 0,
                'cash_converted' => false,
                'cash_converted_at' => null,
                'from_year' => $toYear - 1,
                'to_year' => $toYear,
            ];
        }

        return [
            'has_carryover' => true,
            'carryover_credits' => (float) $carryover->carryover_credits,
            'credits_from_previous_year' => (float) $carryover->credits_from_previous_year,
            'forfeited_credits' => (float) $carryover->forfeited_credits,
            'cash_converted' => $carryover->cash_converted,
            'cash_converted_at' => $carryover->cash_converted_at?->format('Y-m-d'),
            'from_year' => $carryover->from_year,
            'to_year' => $carryover->to_year,
        ];
    }

    /**
     * Get carryover summary for credits FROM a specific year.
     * Use this when viewing a year and want to see what will be/was carried over from that year.
     *
     * @param User $user
     * @param int $fromYear The year to check carryovers from
     * @return array
     */
    public function getCarryoverFromYearSummary(User $user, int $fromYear): array
    {
        $carryover = LeaveCreditCarryover::forUser($user->id)
            ->fromYear($fromYear)
            ->first();

        if (!$carryover) {
            // Calculate potential carryover if not processed yet
            $balance = $this->getBalance($user, $fromYear);
            $potentialCarryover = min($balance, self::MAX_CARRYOVER_CREDITS);
            $potentialForfeited = max(0, $balance - self::MAX_CARRYOVER_CREDITS);

            return [
                'has_carryover' => false,
                'is_processed' => false,
                'carryover_credits' => $potentialCarryover,
                'credits_from_year' => $balance,
                'forfeited_credits' => $potentialForfeited,
                'cash_converted' => false,
                'cash_converted_at' => null,
                'from_year' => $fromYear,
                'to_year' => $fromYear + 1,
            ];
        }

        return [
            'has_carryover' => true,
            'is_processed' => true,
            'carryover_credits' => (float) $carryover->carryover_credits,
            'credits_from_year' => (float) $carryover->credits_from_previous_year,
            'forfeited_credits' => (float) $carryover->forfeited_credits,
            'cash_converted' => $carryover->cash_converted,
            'cash_converted_at' => $carryover->cash_converted_at?->format('Y-m-d'),
            'from_year' => $carryover->from_year,
            'to_year' => $carryover->to_year,
        ];
    }

    /**
     * Get all pending cash conversions for a specific year.
     *
     * @param int $toYear
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPendingCashConversions(int $toYear)
    {
        return LeaveCreditCarryover::with(['user', 'processedBy'])
            ->toYear($toYear)
            ->pendingCashConversion()
            ->get();
    }

    /**
     * Get carryover report for all users for a specific year transition.
     *
     * @param int $fromYear
     * @return array
     */
    public function getCarryoverReport(int $fromYear): array
    {
        $toYear = $fromYear + 1;

        $carryovers = LeaveCreditCarryover::with(['user', 'processedBy'])
            ->fromYear($fromYear)
            ->get();

        $summary = [
            'from_year' => $fromYear,
            'to_year' => $toYear,
            'total_users' => $carryovers->count(),
            'total_carryover_credits' => $carryovers->sum('carryover_credits'),
            'total_forfeited_credits' => $carryovers->sum('forfeited_credits'),
            'pending_cash_conversion' => $carryovers->where('cash_converted', false)->count(),
            'completed_cash_conversion' => $carryovers->where('cash_converted', true)->count(),
        ];

        return [
            'summary' => $summary,
            'carryovers' => $carryovers,
        ];
    }
}
