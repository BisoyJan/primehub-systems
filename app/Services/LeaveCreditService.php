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
use Spatie\Activitylog\Facades\Activity;

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
     * Balance = Monthly credits earned + Carryover received - Credits used
     *
     * Note: For users still in probation who were hired in a previous year,
     * do NOT include carryover credits - their credits are "pending transfer"
     * until they are regularized.
     *
     * Regular carryover credits (max 4, not first regularization) can be used for leave
     * until end of March, after which they expire.
     */
    public function getBalance(User $user, ?int $year = null): float
    {
        $year = $year ?? now()->year;

        // Get monthly credits balance
        $monthlyBalance = LeaveCredit::getTotalBalance($user->id, $year);

        // Add carryover received INTO this year (from previous year)
        // But only if user is regularized OR the carryover is from a year they were already regularized
        $carryoverReceived = 0;
        $carryovers = LeaveCreditCarryover::forUser($user->id)
            ->toYear($year)
            ->get();

        $hireDate = $user->hired_date ? Carbon::parse($user->hired_date) : null;
        $hireYear = $hireDate ? $hireDate->year : null;

        // Check if we're past March for the carryover year (carryover expires after March)
        $now = now();
        $isPastMarch = ($now->year > $year) || ($now->year === $year && $now->month > 3);

        foreach ($carryovers as $carryover) {
            // If user was hired in the year the carryover is FROM, they need to be regularized first
            if ($hireYear && $carryover->from_year === $hireYear) {
                // First regularization transfer - only include if NOW regularized
                // These don't expire after February (full credit transfer for leave use)
                if ($this->isRegularized($user)) {
                    $carryoverReceived += $carryover->carryover_credits;
                }
                // If not regularized, credits are "pending" - don't include
            } else {
                // Regular carryover (max 4 credits) - can use for leave until end of March
                // After March, these credits expire and cannot be used for leave
                if ($carryover->is_first_regularization) {
                    // First regularization transfers don't expire
                    $carryoverReceived += $carryover->carryover_credits;
                } elseif (!$isPastMarch && !$carryover->cash_converted) {
                    // Regular carryover - only include if before end of March AND not yet converted
                    $carryoverReceived += $carryover->carryover_credits;
                }
                // After March or if converted, regular carryover credits are not included in balance
            }
        }

        return $monthlyBalance + $carryoverReceived;
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

        // Get monthly credits earned
        $monthlyEarned = LeaveCredit::getTotalEarned($user->id, $year);

        // Get carryover received INTO this year
        // But only include if user is regularized OR carryover is from a year they were already regularized
        $carryoverReceived = 0;
        $carryovers = LeaveCreditCarryover::forUser($user->id)
            ->toYear($year)
            ->get();

        $hireDate = $user->hired_date ? Carbon::parse($user->hired_date) : null;
        $hireYear = $hireDate ? $hireDate->year : null;

        foreach ($carryovers as $carryover) {
            // If user was hired in the year the carryover is FROM, they need to be regularized first
            if ($hireYear && $carryover->from_year === $hireYear) {
                // User was hired in the carryover source year - only include if NOW regularized
                if ($this->isRegularized($user)) {
                    $carryoverReceived += $carryover->carryover_credits;
                }
                // If not regularized, credits are "pending" - don't include
            } else {
                // User was already regularized before the carryover year - always include
                $carryoverReceived += $carryover->carryover_credits;
            }
        }

        // Total earned = monthly + carryover (if applicable)
        $totalEarned = $monthlyEarned + $carryoverReceived;

        return [
            'year' => $year,
            'is_eligible' => $this->isEligible($user),
            'eligibility_date' => $this->getEligibilityDate($user),
            'monthly_rate' => $monthlyRate,
            'total_earned' => $totalEarned,
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
     *
     * Accrual rules:
     * - Probationary period (first 6 months): Credits accrue on hire date anniversary
     *   (e.g., hired July 11 → Aug 11 +1.25, Sep 11 +1.25, etc.)
     * - Post-regularization: Credits accrue at end of month
     *   (first post-reg accrual is at end of the month AFTER regularization month)
     * - Hire month does NOT get credit (first accrual is 1 month after hire)
     *
     * Example (hired July 11, 2025, regularized Jan 11, 2026):
     * - Aug 11, 2025: +1.25 (probation month 1)
     * - Sep 11, 2025: +1.25 (probation month 2)
     * - Oct 11, 2025: +1.25 (probation month 3)
     * - Nov 11, 2025: +1.25 (probation month 4)
     * - Dec 11, 2025: +1.25 (probation month 5)
     * - Jan 11, 2026: +1.25 (probation month 6 = regularization)
     * - Feb 28, 2026: +1.25 (first post-reg at end of month)
     */
    public function accrueMonthly(User $user, ?int $year = null, ?int $month = null): ?LeaveCredit
    {
        $year = $year ?? now()->year;
        $month = $month ?? now()->month;

        // Don't accrue if user doesn't have a hire date
        if (!$user->hired_date) {
            return null;
        }

        $hireDate = Carbon::parse($user->hired_date);
        $hireDay = $hireDate->day;
        $regularizationDate = $this->getRegularizationDate($user);
        $isRegularized = $this->isRegularized($user);

        // The target month we're trying to accrue
        $targetMonthStart = Carbon::create($year, $month, 1);
        $targetMonthEnd = $targetMonthStart->copy()->endOfMonth();

        // Calculate the hire anniversary date for this month
        // Handle months with fewer days (e.g., hired on 31st, February only has 28)
        $anniversaryDay = min($hireDay, $targetMonthStart->daysInMonth);
        $anniversaryDate = Carbon::create($year, $month, $anniversaryDay);

        // Don't accrue for the hire month - first accrual is 1 month after hire
        if ($year == $hireDate->year && $month == $hireDate->month) {
            return null;
        }

        // Don't accrue before hire date
        if ($targetMonthEnd->lt($hireDate)) {
            return null;
        }

        // Determine when this month's credit should accrue
        // If regularization happens IN this month or hasn't happened yet, use anniversary date
        // If already regularized BEFORE this month started, use end of month
        $regularizedBeforeThisMonth = $regularizationDate && $regularizationDate->lt($targetMonthStart);

        if ($regularizedBeforeThisMonth) {
            // Regular employee: accrue at end of month
            $accrualDate = $targetMonthEnd;
        } else {
            // Probationary or regularizing this month: accrue on anniversary
            $accrualDate = $anniversaryDate;
        }

        // Check if accrual date has passed
        if (now()->lt($accrualDate)) {
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
        $credit = LeaveCredit::create([
            'user_id' => $user->id,
            'credits_earned' => $rate,
            'credits_used' => 0,
            'credits_balance' => $rate,
            'year' => $year,
            'month' => $month,
            'accrued_at' => $accrualDate,
        ]);

        // Log the activity
        $this->logActivity('credit_accrued', $user, $credit, [
            'credits_earned' => $rate,
            'year' => $year,
            'month' => $month,
            'accrual_type' => $regularizedBeforeThisMonth ? 'end_of_month' : 'anniversary',
            'accrued_at' => $accrualDate->format('Y-m-d'),
        ]);

        return $credit;
    }

    /**
     * Backfill missing leave credits for the current year only.
     * Only creates credits from January of current year to avoid database bloat
     * and confusion about expired credits from previous years.
     *
     * Note: Hire month does NOT get credit - first accrual is 1 month after hire.
     * Example: hired July 11 → first credit is August, not July.
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

        // Start from January of current year or the month AFTER hire month (whichever is later)
        // Hire month doesn't get credit - first accrual is 1 month after hire
        $currentDate = Carbon::create($currentYear, 1, 1)->startOfMonth();

        // If hired this year, start from the month AFTER hire month
        if ($hireDate->year === $currentYear) {
            $currentDate = $hireDate->copy()->addMonth()->startOfMonth();
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
     * Backfill ALL missing leave credits for a user from hire date to now.
     * Unlike backfillCredits which only does current year, this does all years.
     *
     * @param User $user
     * @return int Number of credit records created
     */
    public function backfillAllCredits(User $user): int
    {
        if (!$user->hired_date) {
            return 0;
        }

        $hireDate = Carbon::parse($user->hired_date);
        $today = now();
        $accrued = 0;

        // Start from the month AFTER hire (hire month doesn't get credit)
        $currentDate = $hireDate->copy()->addMonth()->startOfMonth();

        // Don't backfill if not yet eligible
        if ($currentDate->gt($today)) {
            return 0;
        }

        // Loop through each month from hire to now
        while ($currentDate->copy()->endOfMonth()->lte($today)) {
            $year = $currentDate->year;
            $month = $currentDate->month;

            // Check if already exists
            $exists = LeaveCredit::forUser($user->id)
                ->forMonth($year, $month)
                ->exists();

            if (!$exists) {
                $credit = $this->accrueMonthly($user, $year, $month);
                if ($credit) {
                    $accrued++;
                }
            }

            $currentDate->addMonth();
        }

        return $accrued;
    }

    /**
     * Process monthly accruals for all eligible users.
     * This backfills all missing credits from hire date to now for each user.
     *
     * @return array{processed: int, skipped: int, total_credits: float}
     */
    public function accrueCreditsForAllUsers(): array
    {
        $users = User::whereNotNull('hired_date')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->get();

        $processed = 0;
        $skipped = 0;
        $totalCredits = 0;

        foreach ($users as $user) {
            $creditsAdded = $this->backfillAllCredits($user);

            if ($creditsAdded > 0) {
                $processed++;
                $totalCredits += $creditsAdded * $this->getMonthlyRate($user);
            } else {
                $skipped++;
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'total_credits' => $totalCredits,
        ];
    }

    /**
     * Deduct leave credits when a leave request is approved.
     */
    public function deductCredits(LeaveRequest $leaveRequest, ?int $year = null): bool
    {
        if (!$leaveRequest->requiresCredits()) {
            return true; // Non-credited leave types don't need deduction
        }

        $year = $year ?? $leaveRequest->start_date->year;
        $daysToDeduct = $leaveRequest->days_requested;
        $userId = $leaveRequest->user_id;

        // Determine if carryover credits can be used
        // Carryover credits expire at the end of March of the current year
        $leaveStartDate = $leaveRequest->start_date;
        $carryoverExpirationDate = Carbon::create($year, 3, 31)->endOfDay();
        $canUseCarryover = $leaveStartDate->lte($carryoverExpirationDate);

        // Ensure carryover credit record exists for this year (month 0)
        // Only create if carryover is still valid
        if ($canUseCarryover) {
            $this->ensureCarryoverCreditRecord($userId, $year);
        }

        // Get all credit records for this year ordered by month (0 = carryover first)
        // This ensures carryover is deducted first before monthly accruals (FIFO)
        $creditsQuery = LeaveCredit::forUser($userId)
            ->forYear($year);

        // Exclude carryover (month 0) if leave is after March 31
        if (!$canUseCarryover) {
            $creditsQuery->where('month', '>', 0);
        }

        $credits = $creditsQuery->orderBy('month')->get();

        // Calculate total available credits
        $totalAvailableCredits = $credits->sum('credits_balance');

        if ($totalAvailableCredits <= 0) {
            // No credits available at all
            \Log::warning('No leave credits available for deduction', [
                'user_id' => $userId,
                'leave_request_id' => $leaveRequest->id,
                'year' => $year,
                'days_requested' => $daysToDeduct,
                'carryover_expired' => !$canUseCarryover,
            ]);

            $leaveRequest->update([
                'credits_deducted' => 0,
                'credits_year' => $year,
            ]);

            return false;
        }

        $remainingToDeduct = $daysToDeduct;
        $actuallyDeducted = 0;

        // Note: No DB::beginTransaction() here - the caller (controller) handles the transaction
        // This allows proper nesting when called from within an existing transaction
        try {
            // Deduct from carryover first (month 0), then monthly accruals (1, 2, 3...)
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
                    $actuallyDeducted += $deductFromThisMonth;
                }
            }

            // Update leave request with actual deduction info
            $leaveRequest->update([
                'credits_deducted' => $actuallyDeducted,
                'credits_year' => $year,
            ]);

            // Log the activity
            $this->logActivity('credits_deducted', $leaveRequest->user, $leaveRequest, [
                'credits_deducted' => $actuallyDeducted,
                'leave_request_id' => $leaveRequest->id,
                'year' => $year,
                'leave_type' => $leaveRequest->leave_type,
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to deduct leave credits', [
                'user_id' => $leaveRequest->user_id,
                'leave_request_id' => $leaveRequest->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw to let the caller handle the transaction rollback
        }
    }

    /**
     * Ensure a LeaveCredit record exists for carryover credits (month 0) for the given year.
     * This creates a LeaveCredit record with the carryover amount if one doesn't exist.
     */
    protected function ensureCarryoverCreditRecord(int $userId, int $year): void
    {
        // Check if carryover credit record already exists for this year
        $existingCarryoverCredit = LeaveCredit::forUser($userId)
            ->forYear($year)
            ->where('month', 0)
            ->first();

        if ($existingCarryoverCredit) {
            return; // Already exists
        }

        // Get the carryover amount from LeaveCreditCarryover table
        $carryover = LeaveCreditCarryover::forUser($userId)
            ->toYear($year)
            ->first();

        if (!$carryover || $carryover->carryover_credits <= 0) {
            return; // No carryover to create
        }

        // Create LeaveCredit record for month 0 (carryover)
        LeaveCredit::create([
            'user_id' => $userId,
            'year' => $year,
            'month' => 0, // 0 represents carryover
            'credits_earned' => $carryover->carryover_credits,
            'credits_used' => 0,
            'credits_balance' => $carryover->carryover_credits,
            'accrued_at' => "{$year}-01-01", // Carryover is available from start of year
        ]);

        \Log::info('Created carryover credit record', [
            'user_id' => $userId,
            'year' => $year,
            'carryover_credits' => $carryover->carryover_credits,
        ]);
    }

    /**
     * Restore leave credits when a leave request is cancelled.
     * Uses LIFO (Last-In-First-Out) order - restores to most recent months first,
     * which is the reverse of the deduction order (FIFO).
     */
    public function restoreCredits(LeaveRequest $leaveRequest): bool
    {
        if (!$leaveRequest->credits_deducted || !$leaveRequest->credits_year) {
            return true; // Nothing to restore
        }

        $year = $leaveRequest->credits_year;
        $daysToRestore = $leaveRequest->credits_deducted;

        // Get all credit records for this year ordered by month (descending - LIFO)
        // This reverses the deduction order, restoring to most recent months first
        $credits = LeaveCredit::forUser($leaveRequest->user_id)
            ->forYear($year)
            ->orderBy('month', 'desc')
            ->get();

        $remainingToRestore = $daysToRestore;

        // Note: No DB::beginTransaction() here - the caller (controller) handles the transaction
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

            // Log the activity
            $this->logActivity('credits_restored', $leaveRequest->user, $leaveRequest, [
                'credits_restored' => $daysToRestore,
                'leave_request_id' => $leaveRequest->id,
                'year' => $year,
                'leave_type' => $leaveRequest->type,
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to restore leave credits', [
                'user_id' => $leaveRequest->user_id,
                'leave_request_id' => $leaveRequest->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw to let the caller handle the transaction rollback
        }
    }

    /**
     * Restore partial leave credits when leave is shortened (e.g., employee reported on last day).
     * Uses LIFO (Last-In-First-Out) order - restores to most recent months first,
     * which is the reverse of the deduction order (FIFO).
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

        // Get all credit records for this year ordered by month (descending - LIFO)
        // This reverses the deduction order, restoring to most recent months first
        $credits = LeaveCredit::forUser($leaveRequest->user_id)
            ->forYear($year)
            ->orderBy('month', 'desc')
            ->get();

        $remainingToRestore = $daysToRestore;

        // Note: No DB::beginTransaction() here - the caller (controller) handles the transaction
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

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to restore partial credits', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
            ]);
            throw $e; // Re-throw to let the caller handle the transaction rollback
        }
    }

    /**
     * Get total attendance points for a user.
     * Only counts active points (not excused, not expired).
     */
    public function getAttendancePoints(User $user): float
    {
        return AttendancePoint::where('user_id', $user->id)
            ->where('is_expired', false)
            ->where('is_excused', false)
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
     * These can be used for leave requests or conversion until end of March.
     * After March, unused carryover credits expire.
     */
    const MAX_CARRYOVER_CREDITS = 4;

    /**
     * Process year-end carryover for a single user.
     * Unused credits from the previous year (up to max 4) are carried over.
     * These credits can be used for leave requests or conversion until end of March.
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

        $toYear = $fromYear + 1;

        // Check if carryover is expired (past March of the to_year)
        $now = now();
        $isExpired = ($now->year > $toYear) || ($now->year === $toYear && $now->month > 3);

        if (!$carryover) {
            // Calculate potential carryover if not processed yet
            $balance = $this->getBalance($user, $fromYear);
            $potentialCarryover = min($balance, self::MAX_CARRYOVER_CREDITS);
            $potentialForfeited = max(0, $balance - self::MAX_CARRYOVER_CREDITS);

            return [
                'has_carryover' => false,
                'is_processed' => false,
                'is_first_regularization' => false,
                'is_expired' => false, // Not processed yet, can't be expired
                'carryover_credits' => $potentialCarryover,
                'credits_from_year' => $balance,
                'forfeited_credits' => $potentialForfeited,
                'cash_converted' => false,
                'cash_converted_at' => null,
                'from_year' => $fromYear,
                'to_year' => $toYear,
            ];
        }

        // First regularization carryovers don't expire
        $carryoverExpired = $isExpired && !$carryover->is_first_regularization && !$carryover->cash_converted;

        return [
            'has_carryover' => true,
            'is_processed' => true,
            'is_first_regularization' => (bool) $carryover->is_first_regularization,
            'is_expired' => $carryoverExpired,
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

    // ============================================
    // REGULARIZATION CREDIT TRANSFER METHODS
    // ============================================

    /**
     * Get the regularization date for a user (6 months after hire date).
     *
     * @param User $user
     * @return Carbon|null
     */
    public function getRegularizationDate(User $user): ?Carbon
    {
        if (!$user->hired_date) {
            return null;
        }

        return Carbon::parse($user->hired_date)->addMonths(6);
    }

    /**
     * Check if user is regularized (6 months after hire date has passed).
     *
     * @param User $user
     * @return bool
     */
    public function isRegularized(User $user): bool
    {
        $regularizationDate = $this->getRegularizationDate($user);
        if (!$regularizationDate) {
            return false;
        }

        return now()->greaterThanOrEqualTo($regularizationDate);
    }

    /**
     * Check if user needs first-time regularization credit transfer.
     * This is for users hired in a previous year who have now been regularized (6 months after hire).
     * The transfer happens when they become regularized, regardless of which day of the year.
     *
     * @param User $user
     * @param int|null $toYear The year to transfer credits to (defaults to current year)
     * @return bool
     */
    public function needsFirstRegularizationTransfer(User $user, ?int $toYear = null): bool
    {
        $toYear = $toYear ?? now()->year;

        if (!$user->hired_date) {
            return false;
        }

        $hireDate = Carbon::parse($user->hired_date);
        $regularizationDate = $this->getRegularizationDate($user);

        // Must be hired in a previous year (credits to transfer from)
        $hiredInPreviousYear = $hireDate->year < $toYear;

        // Must be regularized (6 months have passed since hire)
        $isRegularized = $this->isRegularized($user);

        // Has NOT already had first regularization carryover processed
        $hasNotHadFirstRegularization = !LeaveCreditCarryover::hasFirstRegularization($user->id);

        return $hiredInPreviousYear && $isRegularized && $hasNotHadFirstRegularization;
    }

    /**
     * Get accrued credits from hire year that are pending transfer upon regularization.
     * This is for users who:
     * 1. Were hired in the PREVIOUS year
     * 2. Will regularize (or just regularized) in the CURRENT year
     * 3. Haven't had their first regularization transfer processed yet
     *
     * For example, in 2026:
     * - User hired Jul 15, 2025 → Regularizes Jan 15, 2026 → HAS pending transfer
     * - User hired Jan 15, 2025 → Regularized Jul 15, 2025 → NO pending transfer (already regularized in 2025)
     *
     * @param User $user
     * @return array{year: int, credits: float, months_accrued: int, regularization_date: string|null, is_pending: bool}
     */
    public function getPendingRegularizationCredits(User $user): array
    {
        $defaultResult = [
            'year' => 0,
            'credits' => 0,
            'months_accrued' => 0,
            'regularization_date' => null,
            'is_pending' => false,
        ];

        if (!$user->hired_date) {
            return $defaultResult;
        }

        $hireDate = Carbon::parse($user->hired_date);
        $regularizationDate = $this->getRegularizationDate($user);
        $currentYear = now()->year;
        $hireYear = $hireDate->year;
        $previousYear = $currentYear - 1;
        $regularizationYear = $regularizationDate?->year;

        // Only applicable if:
        // 1. Hired in the PREVIOUS year (e.g., 2025 for current year 2026)
        // 2. Regularization happens in the CURRENT year (e.g., 2026)
        // Users who regularized in their hire year already had their transfer done
        if ($hireYear != $previousYear || $regularizationYear != $currentYear) {
            return array_merge($defaultResult, [
                'year' => $hireYear,
                'regularization_date' => $regularizationDate?->format('Y-m-d'),
            ]);
        }

        // Check if first regularization transfer already happened
        if (LeaveCreditCarryover::hasFirstRegularization($user->id)) {
            return array_merge($defaultResult, [
                'year' => $hireYear,
                'regularization_date' => $regularizationDate?->format('Y-m-d'),
            ]);
        }

        // Calculate credits accrued in hire year (probation period)
        $creditsFromHireYear = LeaveCredit::forUser($user->id)
            ->forYear($hireYear)
            ->sum('credits_balance'); // Use balance (earned - used)

        $monthsAccrued = LeaveCredit::forUser($user->id)
            ->forYear($hireYear)
            ->count();

        // Pending if they have credits from hire year and regularization is this year
        $isPending = $creditsFromHireYear > 0;

        return [
            'year' => $hireYear,
            'credits' => (float) $creditsFromHireYear,
            'months_accrued' => $monthsAccrued,
            'regularization_date' => $regularizationDate?->format('Y-m-d'),
            'is_pending' => $isPending,
        ];
    }

    /**
     * Process first-time regularization credit transfer.
     * Transfers ALL accrued credits from hire year to regularization year (no cap).
     * If a carryover already exists (from year-end processing), update it to be first regularization.
     *
     * @param User $user
     * @param int|null $processedBy User ID of the admin processing this (null for automatic)
     * @return LeaveCreditCarryover|null
     */
    public function processFirstRegularizationTransfer(User $user, ?int $processedBy = null): ?LeaveCreditCarryover
    {
        if (!$this->needsFirstRegularizationTransfer($user)) {
            return null;
        }

        $hireDate = Carbon::parse($user->hired_date);
        $regularizationDate = $this->getRegularizationDate($user);
        $hireYear = $hireDate->year;
        $regularizationYear = $regularizationDate->year;

        // Get total balance from hire year (earned - used)
        $creditsFromHireYear = LeaveCredit::forUser($user->id)
            ->forYear($hireYear)
            ->sum('credits_balance');

        if ($creditsFromHireYear <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $hireYear, $regularizationYear, $creditsFromHireYear, $regularizationDate, $processedBy) {
            // Check if carryover already exists (from year-end processing)
            $existingCarryover = LeaveCreditCarryover::forUser($user->id)
                ->fromYear($hireYear)
                ->toYear($regularizationYear)
                ->first();

            if ($existingCarryover) {
                // Update existing carryover to be first regularization (no cap)
                $previousCarryoverCredits = $existingCarryover->carryover_credits;

                $existingCarryover->update([
                    'credits_from_previous_year' => $creditsFromHireYear,
                    'carryover_credits' => $creditsFromHireYear, // ALL credits now (no cap)
                    'forfeited_credits' => 0, // Remove forfeiture for first regularization
                    'is_first_regularization' => true,
                    'regularization_date' => $regularizationDate,
                    'processed_by' => $processedBy,
                    'notes' => "First-time regularization credit transfer (updated). Hired {$hireYear}, regularized {$regularizationYear}. All {$creditsFromHireYear} credits transferred (was {$previousCarryoverCredits}).",
                ]);

                // Note: We do NOT create Month 0 LeaveCredit records anymore.
                // Carryover is tracked in LeaveCreditCarryover table and
                // getBalance() includes carryover_credits automatically.

                \Log::info('First regularization credit transfer updated existing carryover', [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'hire_year' => $hireYear,
                    'regularization_year' => $regularizationYear,
                    'credits_transferred' => $creditsFromHireYear,
                    'previous_credits' => $previousCarryoverCredits,
                    'processed_by' => $processedBy,
                ]);

                // Log the activity
                $this->logActivity('first_regularization_transfer_updated', $user, $existingCarryover, [
                    'hire_year' => $hireYear,
                    'regularization_year' => $regularizationYear,
                    'regularization_date' => $regularizationDate->toDateString(),
                    'credits_transferred' => $creditsFromHireYear,
                    'previous_credits' => $previousCarryoverCredits,
                    'processed_by' => $processedBy,
                ], $processedBy ? User::find($processedBy) : null);

                return $existingCarryover;
            }

            // Create new first regularization carryover record
            // Note: For first regularization, ALL credits transfer (no forfeiture)
            $carryover = LeaveCreditCarryover::create([
                'user_id' => $user->id,
                'credits_from_previous_year' => $creditsFromHireYear,
                'carryover_credits' => $creditsFromHireYear, // ALL credits transfer
                'forfeited_credits' => 0, // No forfeiture for first regularization
                'from_year' => $hireYear,
                'to_year' => $regularizationYear,
                'is_first_regularization' => true,
                'regularization_date' => $regularizationDate,
                'cash_converted' => false,
                'processed_by' => $processedBy,
                'notes' => "First-time regularization credit transfer. Hired {$hireYear}, regularized {$regularizationYear}. All {$creditsFromHireYear} credits transferred.",
            ]);

            // Note: We do NOT create a Month 0 LeaveCredit record anymore.
            // Carryover is tracked in LeaveCreditCarryover table and
            // getBalance() includes carryover_credits automatically.

            \Log::info('First regularization credit transfer processed', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'hire_year' => $hireYear,
                'regularization_year' => $regularizationYear,
                'credits_transferred' => $creditsFromHireYear,
                'processed_by' => $processedBy,
            ]);

            // Log the activity
            $this->logActivity('first_regularization_transfer', $user, $carryover, [
                'hire_year' => $hireYear,
                'regularization_year' => $regularizationYear,
                'regularization_date' => $regularizationDate->toDateString(),
                'credits_transferred' => $creditsFromHireYear,
                'processed_by' => $processedBy,
            ], $processedBy ? User::find($processedBy) : null);

            return $carryover;
        });
    }

    /**
     * Process carryover credits at year end.
     * For first-time regularization: ALL credits transfer (no cap).
     * For subsequent years: Capped at 4 credits.
     *
     * NOTE: This method will return null and skip processing for probationary employees
     * who will regularize in the next year. They should wait for their first regularization
     * transfer instead of getting a year-end carryover.
     *
     * @param User $user
     * @param int $fromYear
     * @param int|null $processedBy
     * @return LeaveCreditCarryover|null
     */
    public function processYearEndCarryover(User $user, int $fromYear, ?int $processedBy = null): ?LeaveCreditCarryover
    {
        $toYear = $fromYear + 1;

        // Check if already processed
        $existing = LeaveCreditCarryover::forUser($user->id)
            ->fromYear($fromYear)
            ->toYear($toYear)
            ->first();

        if ($existing) {
            return $existing;
        }

        // IMPORTANT: Skip probationary employees who will regularize in the next year
        // They should wait for their first regularization transfer (which has no cap)
        // instead of getting a year-end carryover (which has max 4 cap)
        if ($this->shouldSkipYearEndCarryover($user, $fromYear)) {
            \Log::info('Skipping year-end carryover for probationary employee', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'from_year' => $fromYear,
                'reason' => 'Will regularize in ' . $toYear . ', should wait for first regularization transfer',
            ]);
            return null;
        }

        // Get balance from the year being closed
        $balanceFromYear = $this->getBalance($user, $fromYear);

        if ($balanceFromYear <= 0) {
            return null;
        }

        // Regular year-end carryover: Capped at MAX_CARRYOVER_CREDITS (4)
        // Note: First regularization transfers are handled by processFirstRegularizationTransfer()
        $carryoverCredits = min($balanceFromYear, LeaveCreditCarryover::MAX_CARRYOVER_CREDITS);
        $forfeitedCredits = max(0, $balanceFromYear - LeaveCreditCarryover::MAX_CARRYOVER_CREDITS);

        return DB::transaction(function () use ($user, $fromYear, $toYear, $balanceFromYear, $carryoverCredits, $forfeitedCredits, $processedBy) {
            $carryover = LeaveCreditCarryover::create([
                'user_id' => $user->id,
                'credits_from_previous_year' => $balanceFromYear,
                'carryover_credits' => $carryoverCredits,
                'forfeited_credits' => $forfeitedCredits,
                'from_year' => $fromYear,
                'to_year' => $toYear,
                'is_first_regularization' => false,
                'regularization_date' => null,
                'cash_converted' => false,
                'processed_by' => $processedBy,
                'notes' => $forfeitedCredits > 0
                    ? "Regular carryover. {$carryoverCredits} credits carried over, {$forfeitedCredits} forfeited (max 4)."
                    : "Regular carryover. All {$carryoverCredits} credits carried over.",
            ]);

            // Log the activity
            $this->logActivity('year_end_carryover', $user, $carryover, [
                'from_year' => $fromYear,
                'to_year' => $toYear,
                'credits_from_previous_year' => $balanceFromYear,
                'carryover_credits' => $carryoverCredits,
                'forfeited_credits' => $forfeitedCredits,
                'processed_by' => $processedBy,
            ], $processedBy ? User::find($processedBy) : null);

            // Note: We do NOT create Month 0 LeaveCredit records anymore.
            // Carryover is tracked in LeaveCreditCarryover table and
            // getBalance() includes carryover_credits automatically.

            return $carryover;
        });
    }

    /**
     * Check if a user should be skipped for year-end carryover.
     * Returns true for probationary employees who were hired in the current year
     * but will regularize in the next year (different year regularization).
     *
     * These users should wait for their first regularization transfer which
     * transfers ALL credits without any cap, instead of getting the year-end
     * carryover which caps at 4 credits.
     *
     * @param User $user
     * @param int $fromYear The year being closed
     * @return bool
     */
    public function shouldSkipYearEndCarryover(User $user, int $fromYear): bool
    {
        if (!$user->hired_date) {
            return false;
        }

        $hireDate = Carbon::parse($user->hired_date);
        $hireYear = $hireDate->year;
        $regularizationDate = $this->getRegularizationDate($user);
        $regularizationYear = $regularizationDate?->year;
        $toYear = $fromYear + 1;

        // Skip if:
        // 1. Hired in the year being closed (fromYear)
        // 2. Regularization happens in the next year (toYear)
        // This means they should get first regularization transfer, not year-end carryover
        return $hireYear === $fromYear && $regularizationYear === $toYear;
    }

    /**
     * Get users who need first-time regularization credit transfer.
     * These are users who:
     * 1. Were hired in the PREVIOUS year (e.g., 2025 for current year 2026)
     * 2. Regularize in the CURRENT year (hire + 6 months falls in current year)
     * 3. Have reached their regularization date (6 months passed)
     * 4. Haven't had first regularization processed yet
     *
     * Note: Users who hired and regularized in the SAME year get regular year-end carryover (max 4),
     * not first regularization transfer.
     *
     * @param int|null $toYear The year to transfer credits to (defaults to current year)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUsersNeedingFirstRegularization(?int $toYear = null): \Illuminate\Database\Eloquent\Collection
    {
        $toYear = $toYear ?? now()->year;
        $previousYear = $toYear - 1;
        $today = now();

        return User::whereNotNull('hired_date')
            // Must be active (not terminated)
            ->where('is_active', true)
            // Hired in the PREVIOUS year specifically (e.g., 2025 for 2026)
            ->whereRaw('YEAR(hired_date) = ?', [$previousYear])
            // Regularization falls in the CURRENT year (hire + 6 months is in $toYear)
            ->whereRaw('YEAR(DATE_ADD(hired_date, INTERVAL 6 MONTH)) = ?', [$toYear])
            // Has reached regularization date (6 months have passed since hire)
            ->whereRaw('DATE_ADD(hired_date, INTERVAL 6 MONTH) <= ?', [$today->format('Y-m-d')])
            // Hasn't had first regularization processed yet
            ->whereNotIn('id', function ($query) {
                $query->select('user_id')
                    ->from('leave_credit_carryovers')
                    ->where('is_first_regularization', true);
            })
            ->get();
    }

    /**
     * Get detailed regularization info for a user (for display).
     *
     * @param User $user
     * @param int|null $year
     * @return array
     */
    public function getRegularizationInfo(User $user, ?int $year = null): array
    {
        $year = $year ?? now()->year;
        $regularizationDate = $this->getRegularizationDate($user);
        $isRegularized = $this->isRegularized($user);
        $hireDate = $user->hired_date ? Carbon::parse($user->hired_date) : null;

        // Check for existing first regularization carryover
        $firstRegularizationCarryover = LeaveCreditCarryover::forUser($user->id)
            ->where('is_first_regularization', true)
            ->first();

        // Get pending credits if applicable
        $pendingCredits = $this->getPendingRegularizationCredits($user);

        return [
            'hired_date' => $hireDate?->format('Y-m-d'),
            'hire_year' => $hireDate?->year,
            'regularization_date' => $regularizationDate?->format('Y-m-d'),
            'regularization_year' => $regularizationDate?->year,
            'is_regularized' => $isRegularized,
            'days_until_regularization' => $regularizationDate && !$isRegularized
                ? now()->diffInDays($regularizationDate, false)
                : 0,
            'needs_first_transfer' => $this->needsFirstRegularizationTransfer($user, $year),
            'has_first_regularization' => (bool) $firstRegularizationCarryover,
            'first_regularization_credits' => $firstRegularizationCarryover?->carryover_credits ?? 0,
            'pending_credits' => $pendingCredits,
        ];
    }

    /**
     * Handle hire date change for a user.
     * This validates the change and returns warnings about potential impacts.
     *
     * @param User $user The user whose hire date is being changed
     * @param Carbon $newHireDate The new hire date
     * @return array{valid: bool, warnings: array, impacts: array}
     */
    public function validateHireDateChange(User $user, Carbon $newHireDate): array
    {
        $warnings = [];
        $impacts = [];

        $oldHireDate = $user->hired_date ? Carbon::parse($user->hired_date) : null;

        if (!$oldHireDate) {
            // No previous hire date, nothing to validate
            return ['valid' => true, 'warnings' => [], 'impacts' => []];
        }

        // Check for existing leave credits
        $existingCredits = LeaveCredit::forUser($user->id)->count();
        if ($existingCredits > 0) {
            $warnings[] = "User has {$existingCredits} existing leave credit records that may become inconsistent with new hire date.";
            $impacts[] = [
                'type' => 'existing_credits',
                'count' => $existingCredits,
                'description' => 'May need to recalculate credits if hire year changed',
            ];
        }

        // Check for existing carryovers
        $existingCarryovers = LeaveCreditCarryover::forUser($user->id)->count();
        if ($existingCarryovers > 0) {
            $warnings[] = "User has {$existingCarryovers} existing carryover record(s) that may need review.";
            $impacts[] = [
                'type' => 'existing_carryovers',
                'count' => $existingCarryovers,
                'description' => 'Carryover records may reference incorrect years',
            ];
        }

        // Check if regularization status would change
        $oldRegularizationDate = $oldHireDate->copy()->addMonths(6);
        $newRegularizationDate = $newHireDate->copy()->addMonths(6);

        if ($oldRegularizationDate->year !== $newRegularizationDate->year) {
            $warnings[] = "Regularization year would change from {$oldRegularizationDate->year} to {$newRegularizationDate->year}.";

            // Check for first regularization carryover
            $firstRegCarryover = LeaveCreditCarryover::forUser($user->id)
                ->where('is_first_regularization', true)
                ->first();

            if ($firstRegCarryover) {
                $warnings[] = "IMPORTANT: User already has a first regularization carryover. Year change may invalidate this record.";
                $impacts[] = [
                    'type' => 'first_regularization_affected',
                    'carryover_id' => $firstRegCarryover->id,
                    'from_year' => $firstRegCarryover->from_year,
                    'to_year' => $firstRegCarryover->to_year,
                    'description' => 'First regularization carryover may need to be recalculated or deleted',
                ];
            }
        }

        // Check if year of hire would change
        if ($oldHireDate->year !== $newHireDate->year) {
            $warnings[] = "Hire year would change from {$oldHireDate->year} to {$newHireDate->year}. This significantly impacts credit calculations.";
        }

        return [
            'valid' => true,
            'warnings' => $warnings,
            'impacts' => $impacts,
        ];
    }

    /**
     * Recalculate credits after a hire date change.
     * WARNING: This should only be called after admin confirmation.
     *
     * @param User $user The user to recalculate
     * @param bool $deleteExisting Whether to delete existing credits and start fresh
     * @return array{success: bool, message: string, details: array}
     */
    public function recalculateCreditsForUser(User $user, bool $deleteExisting = false): array
    {
        if (!$user->hired_date) {
            return [
                'success' => false,
                'message' => 'User has no hire date',
                'details' => [],
            ];
        }

        $details = [];

        if ($deleteExisting) {
            // Delete all credits and carryovers for user (fresh start)
            $deletedCredits = LeaveCredit::forUser($user->id)->delete();
            $deletedCarryovers = LeaveCreditCarryover::forUser($user->id)->delete();

            $details['deleted_credits'] = $deletedCredits;
            $details['deleted_carryovers'] = $deletedCarryovers;

            // Log the deletion
            $this->logActivity('credits_recalculated', $user, null, [
                'action' => 'full_reset',
                'deleted_credits' => $deletedCredits,
                'deleted_carryovers' => $deletedCarryovers,
                'new_hire_date' => Carbon::parse($user->hired_date)->format('Y-m-d'),
            ]);
        }

        // Backfill credits from hire date
        $creditsAdded = $this->backfillAllCredits($user);
        $details['credits_added'] = $creditsAdded;

        return [
            'success' => true,
            'message' => $deleteExisting
                ? "Recalculated credits from scratch. Added {$creditsAdded} credit records."
                : "Backfilled missing credits. Added {$creditsAdded} credit records.",
            'details' => $details,
        ];
    }

    /**
     * Log leave credit activity with standardized format.
     *
     * @param string $action The action being performed
     * @param User $user The user affected
     * @param mixed $subject The model being acted upon (LeaveCredit, LeaveCreditCarryover, etc.)
     * @param array $properties Additional properties to log
     * @param User|null $performedBy The user performing the action (null for system)
     */
    protected function logActivity(
        string $action,
        User $user,
        $subject = null,
        array $properties = [],
        ?User $performedBy = null
    ): void {
        $activity = Activity::causedBy($performedBy)
            ->performedOn($subject ?? $user)
            ->useLog('leave-credits')
            ->event($action)
            ->withProperties(array_merge([
                'user_id' => $user->id,
                'user_name' => $user->name,
            ], $properties));

        $activity->log($this->getActivityDescription($action, $user, $properties));
    }

    /**
     * Generate human-readable activity description.
     */
    protected function getActivityDescription(string $action, User $user, array $properties): string
    {
        return match ($action) {
            'credit_accrued' => sprintf(
                'Leave credit accrued for %s: %.2f credits for %d-%02d',
                $user->name,
                $properties['credits_earned'] ?? 0,
                $properties['year'] ?? 0,
                $properties['month'] ?? 0
            ),
            'credits_deducted' => sprintf(
                'Leave credits deducted for %s: %.2f credits for leave request #%d',
                $user->name,
                $properties['credits_deducted'] ?? 0,
                $properties['leave_request_id'] ?? 0
            ),
            'credits_restored' => sprintf(
                'Leave credits restored for %s: %.2f credits from cancelled leave request #%d',
                $user->name,
                $properties['credits_restored'] ?? 0,
                $properties['leave_request_id'] ?? 0
            ),
            'year_end_carryover' => sprintf(
                'Year-end carryover for %s: %.2f credits from %d to %d (%.2f forfeited)',
                $user->name,
                $properties['carryover_credits'] ?? 0,
                $properties['from_year'] ?? 0,
                $properties['to_year'] ?? 0,
                $properties['forfeited_credits'] ?? 0
            ),
            'first_regularization_transfer' => sprintf(
                'First regularization transfer for %s: %.2f credits from %d to %d (no cap)',
                $user->name,
                $properties['credits_transferred'] ?? 0,
                $properties['hire_year'] ?? 0,
                $properties['regularization_year'] ?? 0
            ),
            'first_regularization_transfer_updated' => sprintf(
                'First regularization transfer updated for %s: %.2f credits from %d to %d (was %.2f)',
                $user->name,
                $properties['credits_transferred'] ?? 0,
                $properties['hire_year'] ?? 0,
                $properties['regularization_year'] ?? 0,
                $properties['previous_credits'] ?? 0
            ),
            'bulk_accrual' => sprintf(
                'Bulk credit accrual: %d users processed, %d credits created',
                $properties['users_processed'] ?? 0,
                $properties['credits_created'] ?? 0
            ),
            'credits_backfilled' => sprintf(
                'Credits backfilled for %s: %d credit records created',
                $user->name,
                $properties['records_created'] ?? 0
            ),
            'credits_recalculated' => sprintf(
                'Leave credits recalculated for %s: %s (deleted %d credits, %d carryovers)',
                $user->name,
                $properties['action'] ?? 'unknown',
                $properties['deleted_credits'] ?? 0,
                $properties['deleted_carryovers'] ?? 0
            ),
            default => "Leave credit action: {$action} for {$user->name}",
        };
    }
}
