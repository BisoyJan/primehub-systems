<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\LeaveCredit;
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
    public function getBalance(User $user, int $year = null): float
    {
        $year = $year ?? now()->year;
        return LeaveCredit::getTotalBalance($user->id, $year);
    }

    /**
     * Get detailed leave credits summary for a user.
     */
    public function getSummary(User $user, int $year = null): array
    {
        $year = $year ?? now()->year;

        return [
            'year' => $year,
            'is_eligible' => $this->isEligible($user),
            'eligibility_date' => $this->getEligibilityDate($user),
            'monthly_rate' => $this->getMonthlyRate($user),
            'total_earned' => LeaveCredit::getTotalEarned($user->id, $year),
            'total_used' => LeaveCredit::getTotalUsed($user->id, $year),
            'balance' => $this->getBalance($user, $year),
            'credits_by_month' => LeaveCredit::forUser($user->id)
                ->forYear($year)
                ->orderBy('month')
                ->get(),
        ];
    }

    /**
     * Accrue monthly leave credits for a user.
     */
    public function accrueMonthly(User $user, int $year = null, int $month = null): ?LeaveCredit
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
     * Backfill missing leave credits from hire date to present.
     * Useful when adding existing employees or fixing missing accruals.
     */
    public function backfillCredits(User $user): int
    {
        if (!$user->hired_date) {
            return 0;
        }

        $hireDate = Carbon::parse($user->hired_date);
        $today = now();
        $accrued = 0;

        // Start from hire month
        $currentDate = $hireDate->copy()->startOfMonth();

        // Loop through each month from hire date to last completed month
        while ($currentDate->endOfMonth()->lt($today)) {
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
    public function deductCredits(LeaveRequest $leaveRequest, int $year = null): bool
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
     * Get total attendance points for a user.
     */
    public function getAttendancePoints(User $user): float
    {
        return AttendancePoint::where('user_id', $user->id)
            ->where('is_expired', false)
            ->sum('points');
    }

    /**
     * Check if user had any absences in the last 30 days.
     */
    public function hasRecentAbsence(User $user, Carbon $fromDate = null): bool
    {
        $fromDate = $fromDate ?? now();
        $thirtyDaysAgo = $fromDate->copy()->subDays(30);

        return Attendance::where('user_id', $user->id)
            ->where('shift_date', '>=', $thirtyDaysAgo)
            ->where('shift_date', '<=', $fromDate)
            ->where('status', 'absent')
            ->exists();
    }

    /**
     * Get date when user can apply for leave (30 days after last absence).
     */
    public function getNextEligibleLeaveDate(User $user): ?Carbon
    {
        $lastAbsence = Attendance::where('user_id', $user->id)
            ->where('status', 'absent')
            ->orderBy('shift_date', 'desc')
            ->first();

        if (!$lastAbsence) {
            return now(); // No absences, can apply anytime
        }

        return Carbon::parse($lastAbsence->shift_date)->addDays(30);
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
     */
    public function validateLeaveRequest(User $user, array $data): array
    {
        $errors = [];
        $leaveType = $data['leave_type'];
        $startDate = Carbon::parse($data['start_date']);
        $endDate = Carbon::parse($data['end_date']);
        $year = $data['credits_year'] ?? now()->year;

        // Check eligibility (6 months rule) for credited leave types
        if (in_array($leaveType, LeaveRequest::CREDITED_LEAVE_TYPES)) {
            if (!$this->isEligible($user)) {
                $eligibilityDate = $this->getEligibilityDate($user);
                $errors[] = "You are not eligible to use leave credits yet. You will be eligible on {$eligibilityDate->format('F j, Y')}.";
            }
        }

        // Check 2-week advance notice for VL and BL only (SL is unpredictable)
        if (in_array($leaveType, ['VL', 'BL'])) {
            $twoWeeksFromNow = now()->addWeeks(2)->startOfDay();
            if ($startDate->startOfDay()->lt($twoWeeksFromNow)) {
                $errors[] = "Leave requests must be submitted at least 2 weeks in advance. Earliest date you can apply for is {$twoWeeksFromNow->format('F j, Y')}.";
            }
        }

        // Check attendance points for VL and BL (must be ≤6)
        if (in_array($leaveType, ['VL', 'BL'])) {
            $points = $this->getAttendancePoints($user);
            if ($points > 6) {
                $errors[] = "You cannot apply for Vacation Leave because you have {$points} attendance points (must be 6 or below).";
            }
        }

        // Check 30-day absence rule for VL and BL
        if (in_array($leaveType, ['VL', 'BL'])) {
            if ($this->hasRecentAbsence($user, $startDate)) {
                $nextEligibleDate = $this->getNextEligibleLeaveDate($user);
                $errors[] = "You had an absence in the last 30 days. You can apply for Vacation Leave starting {$nextEligibleDate->format('F j, Y')}.";
            }
        }

        // Check leave credits balance for credited leave types
        if (in_array($leaveType, LeaveRequest::CREDITED_LEAVE_TYPES)) {
            $balance = $this->getBalance($user, $year);
            $daysRequested = $this->calculateDays($startDate, $endDate);

            if ($balance < $daysRequested) {
                $errors[] = "Insufficient leave credits. You have {$balance} days available, but requested {$daysRequested} days.";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
