<?php

namespace App\Services\AttendancePoint;

use App\Models\AttendancePoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Service responsible for all GBRO (Good Behavior Roll Off) calculations.
 *
 * GBRO Rules:
 * - Points are eligible for GBRO expiration after 60 days of clean behavior
 * - Only non-excused, non-expired points can be expired via GBRO
 * - First 2 newest points get GBRO dates; remaining have NULL
 * - New violations reset the GBRO clock
 * - Excused points' dates count for reference calculations but aren't expired
 */
class GbroCalculationService
{
    /**
     * Cascade recalculate all GBRO expirations for a user.
     * Performs a full GBRO simulation from scratch:
     * 1. Resets all GBRO-expired points to active
     * 2. Simulates GBRO expirations chronologically
     * 3. After each GBRO, next trigger = previous GBRO date + 60 (unless new violation resets it)
     * 4. Updates all states accordingly
     */
    public function cascadeRecalculateGbro(int $userId): void
    {
        $batchId = 'cascade_'.now()->format('YmdHis');

        // Step 1: Get ALL GBRO-eligible points (including those expired via GBRO)
        $allPoints = AttendancePoint::where('user_id', $userId)
            ->where('eligible_for_gbro', true)
            ->orderBy('shift_date', 'asc')
            ->get();

        if ($allPoints->isEmpty()) {
            return;
        }

        // Step 2: Reset all GBRO-expired points to active
        $this->resetGbroExpiredPoints($allPoints);

        // Step 3: Simulate GBRO expirations chronologically
        $allActivePoints = AttendancePoint::where('user_id', $userId)
            ->where('eligible_for_gbro', true)
            ->where('is_expired', false)
            ->orderBy('shift_date', 'asc')
            ->get();

        $activePoints = $allActivePoints->where('is_excused', false)->values();
        $lastGbroDate = null;

        while ($activePoints->count() >= 1) {
            $gbroDate = $this->calculateNextGbroDate($allActivePoints, $lastGbroDate);

            if (! $gbroDate) {
                break;
            }

            $pointsBeforeGbro = $activePoints->filter(function ($p) use ($gbroDate) {
                return Carbon::parse($p->shift_date)->lessThan($gbroDate);
            })->sortByDesc('shift_date')->values();

            $toExpire = $pointsBeforeGbro->take(2);

            if ($toExpire->isEmpty()) {
                break;
            }

            foreach ($toExpire as $point) {
                $point->update([
                    'is_expired' => true,
                    'expiration_type' => 'gbro',
                    'expired_at' => $gbroDate->format('Y-m-d'),
                    'gbro_expires_at' => $gbroDate->format('Y-m-d'),
                    'gbro_applied_at' => $gbroDate->format('Y-m-d'),
                    'gbro_batch_id' => $batchId,
                ]);
            }

            $lastGbroDate = $gbroDate;

            $expiredIds = $toExpire->pluck('id')->toArray();
            $allActivePoints = $allActivePoints->reject(fn ($p) => in_array($p->id, $expiredIds))->values();
            $activePoints = $activePoints->reject(fn ($p) => in_array($p->id, $expiredIds))->values();
        }

        // Step 4: Set GBRO dates for remaining active points
        $this->updateUserGbroExpirationDates($userId, $lastGbroDate);
    }

    /**
     * Reset all GBRO-expired points to active for recalculation.
     */
    private function resetGbroExpiredPoints(Collection $allPoints): void
    {
        foreach ($allPoints->where('is_excused', false) as $point) {
            if ($point->is_expired && $point->expiration_type === 'gbro') {
                $point->update([
                    'is_expired' => false,
                    'expiration_type' => 'sro',
                    'expired_at' => null,
                    'gbro_applied_at' => null,
                    'gbro_batch_id' => null,
                ]);
            }
            $point->update(['gbro_expires_at' => null]);
        }
    }

    /**
     * Calculate the next GBRO date based on current active points and last GBRO.
     *
     * Rules:
     * 1. If no previous GBRO: Find gap > 60 days, GBRO = (newest point before gap) + 60
     * 2. If previous GBRO exists: Next GBRO = lastGbroDate + 60
     *    - BUT if a violation occurred between lastGbroDate and (lastGbroDate+60), RESET
     */
    public function calculateNextGbroDate(Collection $activePoints, ?Carbon $lastGbroDate): ?Carbon
    {
        if ($activePoints->isEmpty()) {
            return null;
        }

        $sortedPoints = $activePoints->sortBy('shift_date')->values();
        $newestPoint = $sortedPoints->last();
        $newestDate = Carbon::parse($newestPoint->shift_date)->startOfDay();
        $today = now()->startOfDay();

        if ($lastGbroDate) {
            return $this->calculateGbroDateWithPreviousGbro($sortedPoints, $lastGbroDate, $newestDate, $today);
        }

        return $this->calculateInitialGbroDate($sortedPoints, $newestDate, $today);
    }

    /**
     * Calculate GBRO date when there's a previous GBRO.
     */
    private function calculateGbroDateWithPreviousGbro(
        Collection $sortedPoints,
        Carbon $lastGbroDate,
        Carbon $newestDate,
        Carbon $today
    ): ?Carbon {
        $nextScheduledGbro = $lastGbroDate->copy()->startOfDay()->addDays(60);

        $violationAfterGbro = $sortedPoints->first(function ($p) use ($lastGbroDate) {
            return Carbon::parse($p->shift_date)->startOfDay()->greaterThan($lastGbroDate->startOfDay());
        });

        if ($violationAfterGbro) {
            $violationDate = Carbon::parse($violationAfterGbro->shift_date)->startOfDay();

            if ($violationDate->lessThan($nextScheduledGbro)) {
                $daysFromNewest = $newestDate->diffInDays($today);

                if ($daysFromNewest > 60) {
                    $newGbro = $newestDate->copy()->addDays(60);
                    if ($newGbro->lessThanOrEqualTo($today)) {
                        return $newGbro;
                    }
                }

                return null;
            }
        }

        if ($nextScheduledGbro->lessThanOrEqualTo($today)) {
            return $nextScheduledGbro;
        }

        return null;
    }

    /**
     * Calculate initial GBRO date (no previous GBRO).
     */
    private function calculateInitialGbroDate(Collection $sortedPoints, Carbon $newestDate, Carbon $today): ?Carbon
    {
        for ($i = 0; $i < $sortedPoints->count() - 1; $i++) {
            $current = $sortedPoints[$i];
            $next = $sortedPoints[$i + 1];

            $currentDate = Carbon::parse($current->shift_date)->startOfDay();
            $nextDate = Carbon::parse($next->shift_date)->startOfDay();
            $gap = $currentDate->diffInDays($nextDate);

            if ($gap > 60) {
                $pointsBeforeGap = $sortedPoints->filter(function ($p) use ($nextDate) {
                    return Carbon::parse($p->shift_date)->startOfDay()->lessThan($nextDate);
                })->sortByDesc('shift_date')->first();

                if ($pointsBeforeGap) {
                    $newestBeforeGap = Carbon::parse($pointsBeforeGap->shift_date)->startOfDay();
                    $gbroDate = $newestBeforeGap->copy()->addDays(60);

                    if ($gbroDate->lessThanOrEqualTo($today)) {
                        return $gbroDate;
                    }
                }
            }
        }

        $daysFromNewest = $newestDate->diffInDays($today);
        if ($daysFromNewest > 60) {
            $gbroDate = $newestDate->copy()->addDays(60);
            if ($gbroDate->lessThanOrEqualTo($today)) {
                return $gbroDate;
            }
        }

        return null;
    }

    /**
     * Update GBRO expiration dates for all active GBRO-eligible points of a user.
     */
    public function updateUserGbroExpirationDates(int $userId, ?Carbon $referenceDate = null): void
    {
        $allActivePoints = AttendancePoint::where('user_id', $userId)
            ->where('is_expired', false)
            ->where('eligible_for_gbro', true)
            ->whereNull('gbro_applied_at')
            ->orderBy('shift_date', 'desc')
            ->get();

        $activePoints = $allActivePoints->where('is_excused', false)->values();

        if ($activePoints->isEmpty()) {
            return;
        }

        $baseDate = $referenceDate ?? Carbon::parse($allActivePoints->first()->shift_date);
        $gbroExpiresAt = $baseDate->copy()->addDays(60)->format('Y-m-d');

        foreach ($activePoints as $index => $point) {
            if ($index < 2) {
                $point->update(['gbro_expires_at' => $gbroExpiresAt]);
            } else {
                $point->update(['gbro_expires_at' => null]);
            }
        }
    }

    /**
     * Calculate the GBRO reference date for a user.
     * Returns the MORE RECENT of: last GBRO application date OR last violation date.
     */
    public function calculateGbroReferenceDate(User $user, Collection $activePoints): Carbon
    {
        $lastViolationDate = Carbon::parse($activePoints->first()->shift_date);

        $lastGbroDate = $user->attendancePoints()
            ->whereNotNull('gbro_applied_at')
            ->max('gbro_applied_at');

        if ($lastGbroDate) {
            $lastGbroCarbon = Carbon::parse($lastGbroDate);
            if ($lastGbroCarbon->greaterThan($lastViolationDate)) {
                return $lastGbroCarbon;
            }
        }

        return $lastViolationDate;
    }

    /**
     * Update gbro_expires_at for a collection of points.
     * Only the first 2 newest points get the GBRO date.
     */
    public function updateGbroExpiresAt(Collection $points, Carbon $basePredictionDate): void
    {
        $gbroExpiresAt = $basePredictionDate->format('Y-m-d');

        foreach ($points as $index => $point) {
            if ($index < 2) {
                if (! $point->gbro_expires_at || $point->gbro_expires_at !== $gbroExpiresAt) {
                    $point->update(['gbro_expires_at' => $gbroExpiresAt]);
                }
            } else {
                if ($point->gbro_expires_at !== null) {
                    $point->update(['gbro_expires_at' => null]);
                }
            }
        }
    }

    /**
     * Calculate GBRO statistics for a user's show page.
     */
    public function calculateGbroStats(User $user): array
    {
        $lastViolationDate = AttendancePoint::where('user_id', $user->id)
            ->where('is_expired', false)
            ->max('shift_date');

        $lastGbroDate = AttendancePoint::where('user_id', $user->id)
            ->whereNotNull('gbro_applied_at')
            ->max('gbro_applied_at');

        $daysClean = 0;
        $daysUntilGbro = 60;
        $eligiblePointsCount = 0;
        $eligiblePointsSum = 0;
        $gbroReferenceDate = null;
        $gbroReferenceType = null;

        if ($lastViolationDate) {
            $lastViolationCarbon = Carbon::parse($lastViolationDate);
            $gbroReferenceDate = $lastViolationDate;
            $gbroReferenceType = 'violation';

            if ($lastGbroDate) {
                $lastGbroCarbon = Carbon::parse($lastGbroDate);
                if ($lastGbroCarbon->greaterThan($lastViolationCarbon)) {
                    $gbroReferenceDate = $lastGbroDate;
                    $gbroReferenceType = 'gbro';
                }
            }

            $daysClean = Carbon::parse($gbroReferenceDate)->diffInDays(Carbon::now());
            $daysUntilGbro = max(0, 60 - $daysClean);

            $eligiblePoints = AttendancePoint::where('user_id', $user->id)
                ->where('is_excused', false)
                ->where('is_expired', false)
                ->where('eligible_for_gbro', true)
                ->orderBy('shift_date', 'desc')
                ->limit(2)
                ->get();

            $eligiblePointsCount = $eligiblePoints->count();
            $eligiblePointsSum = $eligiblePoints->sum('points');
        }

        return [
            'days_clean' => $daysClean,
            'days_until_gbro' => $daysUntilGbro,
            'eligible_points_count' => $eligiblePointsCount,
            'eligible_points_sum' => (float) $eligiblePointsSum,
            'last_violation_date' => $lastViolationDate,
            'last_gbro_date' => $lastGbroDate,
            'gbro_reference_date' => $gbroReferenceDate,
            'gbro_reference_type' => $gbroReferenceType,
            'is_gbro_ready' => $daysClean >= 60 && $eligiblePointsCount > 0,
        ];
    }
}
