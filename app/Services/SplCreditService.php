<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\SplCredit;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SplCreditService
{
    /**
     * Ensure SPL credit record exists for a user in a given year.
     */
    public function ensureCreditsExist(User $user, ?int $year = null): SplCredit
    {
        return SplCredit::ensureCreditsExist($user->id, $year);
    }

    /**
     * Get the SPL credit balance for a user in a given year.
     */
    public function getBalance(User $user, ?int $year = null): float
    {
        return SplCredit::getBalance($user->id, $year);
    }

    /**
     * Get a full summary of SPL credits for a user in a given year.
     *
     * @return array{total: float, used: float, balance: float, year: int}
     */
    public function getSummary(User $user, ?int $year = null): array
    {
        $year = $year ?? now()->year;
        $record = SplCredit::forUser($user->id)->forYear($year)->first();

        if (! $record) {
            return [
                'total' => SplCredit::YEARLY_CREDITS,
                'used' => 0,
                'balance' => SplCredit::YEARLY_CREDITS,
                'year' => $year,
            ];
        }

        return [
            'total' => (float) $record->total_credits,
            'used' => (float) $record->credits_used,
            'balance' => (float) $record->credits_balance,
            'year' => $year,
        ];
    }

    /**
     * Check if SPL credit deduction is possible for a leave request.
     *
     * @return array{should_deduct: bool, credits_to_deduct: float, available: float, insufficient: bool}
     */
    public function checkSplCreditDeduction(User $user, float $creditsNeeded, ?int $year = null): array
    {
        $year = $year ?? now()->year;
        $balance = $this->getBalance($user, $year);

        if ($balance <= 0) {
            return [
                'should_deduct' => false,
                'credits_to_deduct' => 0,
                'available' => $balance,
                'insufficient' => true,
            ];
        }

        if ($balance < $creditsNeeded) {
            return [
                'should_deduct' => true,
                'credits_to_deduct' => $balance,
                'available' => $balance,
                'insufficient' => true,
            ];
        }

        return [
            'should_deduct' => true,
            'credits_to_deduct' => $creditsNeeded,
            'available' => $balance,
            'insufficient' => false,
        ];
    }

    /**
     * Deduct SPL credits for an approved leave request.
     */
    public function deductCredits(LeaveRequest $leaveRequest, float $amount, ?int $year = null): bool
    {
        $year = $year ?? $leaveRequest->start_date->year;
        $record = $this->ensureCreditsExist($leaveRequest->user, $year);

        if ($record->credits_balance < $amount) {
            Log::warning("SPL credit deduction: insufficient balance for user {$leaveRequest->user_id}. Needed: {$amount}, Available: {$record->credits_balance}");

            $amount = $record->credits_balance;
        }

        if ($amount <= 0) {
            $leaveRequest->update(['credits_deducted' => 0, 'credits_year' => $year]);

            return false;
        }

        $record->credits_used += $amount;
        $record->credits_balance -= $amount;
        $record->save();

        $leaveRequest->update([
            'credits_deducted' => $amount,
            'credits_year' => $year,
        ]);

        return true;
    }

    /**
     * Restore SPL credits when a leave request is cancelled.
     */
    public function restoreCredits(LeaveRequest $leaveRequest): bool
    {
        if (! $leaveRequest->credits_deducted || ! $leaveRequest->credits_year) {
            return true;
        }

        $record = SplCredit::forUser($leaveRequest->user_id)
            ->forYear($leaveRequest->credits_year)
            ->first();

        if (! $record) {
            Log::warning("SPL credit restore: no record found for user {$leaveRequest->user_id}, year {$leaveRequest->credits_year}");

            return false;
        }

        $restoreAmount = (float) $leaveRequest->credits_deducted;

        $record->credits_used -= $restoreAmount;
        $record->credits_balance += $restoreAmount;

        // Prevent balance exceeding total
        if ($record->credits_balance > $record->total_credits) {
            $record->credits_balance = $record->total_credits;
        }
        if ($record->credits_used < 0) {
            $record->credits_used = 0;
        }

        $record->save();

        return true;
    }

    /**
     * Validate that a user can apply for SPL.
     *
     * @return array<string> Array of error messages (empty if valid)
     */
    public function validateSplRequest(User $user): array
    {
        $errors = [];

        if (! $user->is_solo_parent) {
            $errors[] = 'Only users with Solo Parent status can apply for Solo Parent Leave.';
        }

        return $errors;
    }
}
