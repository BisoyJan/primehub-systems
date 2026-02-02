<?php

namespace App\Services\AttendancePoint;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for maintenance operations on attendance points.
 * Includes: duplicate removal, batch expiration, cleanup, reset, and regeneration.
 */
class AttendancePointMaintenanceService
{
    public function __construct(
        protected GbroCalculationService $gbroService
    ) {}

    /**
     * Get management statistics (duplicates, pending expirations, etc.)
     */
    public function getManagementStats(): array
    {
        $duplicatesCount = DB::table('attendance_points')
            ->select('user_id', 'shift_date', 'point_type', DB::raw('COUNT(*) as count'))
            ->groupBy('user_id', 'shift_date', 'point_type')
            ->having('count', '>', 1)
            ->get()
            ->sum(fn ($row) => $row->count - 1);

        $pendingExpirationsCount = AttendancePoint::where('is_expired', false)
            ->where('is_excused', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();

        $expiredCount = AttendancePoint::where('is_expired', true)
            ->where('is_excused', false)
            ->count();

        $missingPointsCount = Attendance::where('admin_verified', true)
            ->whereIn('status', ['ncns', 'advised_absence', 'half_day_absence', 'tardy', 'undertime', 'undertime_more_than_hour'])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('attendance_points')
                    ->whereColumn('attendance_points.attendance_id', 'attendances.id');
            })
            ->count();

        return [
            'duplicates_count' => $duplicatesCount,
            'pending_expirations_count' => $pendingExpirationsCount,
            'expired_count' => $expiredCount,
            'missing_points_count' => $missingPointsCount,
        ];
    }

    /**
     * Remove duplicate attendance points (same user, date, type).
     * Prioritizes keeping excused points.
     */
    public function removeDuplicates(): array
    {
        $duplicates = DB::table('attendance_points')
            ->select('user_id', 'shift_date', 'point_type', DB::raw('COUNT(*) as count'))
            ->groupBy('user_id', 'shift_date', 'point_type')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            return [
                'success' => true,
                'message' => 'No duplicate attendance points found.',
                'removed' => 0,
            ];
        }

        $totalRemoved = 0;

        foreach ($duplicates as $dup) {
            $points = AttendancePoint::where('user_id', $dup->user_id)
                ->where('shift_date', $dup->shift_date)
                ->where('point_type', $dup->point_type)
                ->orderByDesc('is_excused')
                ->orderBy('id')
                ->get();

            $keepId = $points->first()->id;

            $deleted = AttendancePoint::where('user_id', $dup->user_id)
                ->where('shift_date', $dup->shift_date)
                ->where('point_type', $dup->point_type)
                ->where('id', '!=', $keepId)
                ->delete();

            $totalRemoved += $deleted;
        }

        return [
            'success' => true,
            'message' => "Removed {$totalRemoved} duplicate attendance points (excused points preserved).",
            'removed' => $totalRemoved,
        ];
    }

    /**
     * Expire all pending attendance points (SRO, GBRO, or both).
     */
    public function expireAllPending(string $expirationType = 'both'): array
    {
        $sroExpired = 0;
        $gbroExpired = 0;

        // Process SRO (Standard Roll Off)
        if ($expirationType === 'sro' || $expirationType === 'both') {
            $sroPoints = AttendancePoint::where('is_expired', false)
                ->where('is_excused', false)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->get();

            foreach ($sroPoints as $point) {
                $point->markAsExpired('sro');
                $sroExpired++;
            }
        }

        // Process GBRO (Good Behavior Roll Off)
        if ($expirationType === 'gbro' || $expirationType === 'both') {
            $gbroExpired = $this->processGbroExpirations();
        }

        $totalExpired = $sroExpired + $gbroExpired;
        $typeLabel = match ($expirationType) {
            'sro' => 'SRO only',
            'gbro' => 'GBRO only',
            default => 'SRO + GBRO',
        };

        if ($totalExpired === 0) {
            return [
                'success' => true,
                'message' => "No pending expirations found ({$typeLabel}).",
                'expired' => 0,
                'sro_expired' => 0,
                'gbro_expired' => 0,
            ];
        }

        return [
            'success' => true,
            'message' => "Expired {$totalExpired} attendance points ({$typeLabel}: SRO={$sroExpired}, GBRO={$gbroExpired}).",
            'expired' => $totalExpired,
            'sro_expired' => $sroExpired,
            'gbro_expired' => $gbroExpired,
        ];
    }

    /**
     * Process GBRO expirations for all eligible users.
     */
    private function processGbroExpirations(): int
    {
        $batchId = now()->format('YmdHis');
        $gbroExpired = 0;

        $usersWithPoints = User::whereHas('attendancePoints', function ($query) {
            $query->where('is_expired', false)
                ->where('is_excused', false)
                ->where('eligible_for_gbro', true);
        })->get();

        foreach ($usersWithPoints as $user) {
            $activeGbroEligiblePoints = $user->attendancePoints()
                ->where('is_expired', false)
                ->where('is_excused', false)
                ->where('eligible_for_gbro', true)
                ->whereNull('gbro_applied_at')
                ->orderBy('shift_date', 'desc')
                ->get();

            if ($activeGbroEligiblePoints->isEmpty()) {
                continue;
            }

            $gbroReferenceDate = $this->gbroService->calculateGbroReferenceDate($user, $activeGbroEligiblePoints);
            $gbroPredictionDate = $gbroReferenceDate->copy()->addDays(60);

            $this->gbroService->updateGbroExpiresAt($activeGbroEligiblePoints, $gbroPredictionDate);

            $daysSinceReference = $gbroReferenceDate->diffInDays(now());

            if ($daysSinceReference >= 60) {
                $pointsToExpire = $activeGbroEligiblePoints->take(2);

                foreach ($pointsToExpire as $point) {
                    $point->update([
                        'is_expired' => true,
                        'expired_at' => now(),
                        'expiration_type' => 'gbro',
                        'gbro_applied_at' => now(),
                        'gbro_batch_id' => $batchId,
                    ]);
                    $gbroExpired++;
                }

                // Update remaining points
                $remainingPoints = $user->attendancePoints()
                    ->where('is_expired', false)
                    ->where('is_excused', false)
                    ->where('eligible_for_gbro', true)
                    ->whereNull('gbro_applied_at')
                    ->orderBy('shift_date', 'desc')
                    ->get();

                if ($remainingPoints->isNotEmpty()) {
                    $newGbroPrediction = $gbroPredictionDate->copy()->addDays(60);
                    $this->gbroService->updateGbroExpiresAt($remainingPoints, $newGbroPrediction);
                }
            }
        }

        return $gbroExpired;
    }

    /**
     * Reset expired attendance points back to active.
     * Excused points are NOT reset.
     */
    public function resetExpired(?array $userIds = null, ?int $userId = null): array
    {
        $query = AttendancePoint::where('is_expired', true)
            ->where('is_excused', false);

        if (! empty($userIds)) {
            $query->whereIn('user_id', $userIds);
        } elseif ($userId) {
            $query->where('user_id', $userId);
        }

        $expiredPoints = $query->get();

        if ($expiredPoints->isEmpty()) {
            return [
                'success' => true,
                'message' => 'No expired attendance points found (excused points are excluded).',
                'reset' => 0,
            ];
        }

        $resetCount = 0;

        foreach ($expiredPoints as $point) {
            $shiftDate = Carbon::parse($point->shift_date);
            $isNcnsOrFtn = $point->point_type === 'whole_day_absence' && ! $point->is_advised;
            $newExpiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);

            $point->update([
                'is_expired' => false,
                'expired_at' => null,
                'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                'gbro_applied_at' => null,
                'gbro_batch_id' => null,
                'expires_at' => $newExpiresAt,
            ]);

            $resetCount++;
        }

        return [
            'success' => true,
            'message' => "Reset {$resetCount} expired attendance points to active (excused points excluded).",
            'reset' => $resetCount,
        ];
    }

    /**
     * Initialize GBRO expiration dates for existing active points.
     */
    public function initializeGbroDates(): array
    {
        $totalUpdated = 0;

        $usersWithPoints = User::whereHas('attendancePoints', function ($query) {
            $query->where('is_expired', false)
                ->where('eligible_for_gbro', true)
                ->whereNull('gbro_applied_at');
        })->get();

        foreach ($usersWithPoints as $user) {
            $allActivePoints = $user->attendancePoints()
                ->where('is_expired', false)
                ->where('eligible_for_gbro', true)
                ->whereNull('gbro_applied_at')
                ->orderBy('shift_date', 'desc')
                ->get();

            $activePoints = $allActivePoints->where('is_excused', false)->values();

            if ($activePoints->isEmpty()) {
                continue;
            }

            $lastViolationDate = Carbon::parse($allActivePoints->first()->shift_date);

            $lastGbroDate = $user->attendancePoints()
                ->whereNotNull('gbro_applied_at')
                ->max('gbro_applied_at');

            $gbroReferenceDate = $lastViolationDate;
            if ($lastGbroDate) {
                $lastGbroCarbon = Carbon::parse($lastGbroDate);
                if ($lastGbroCarbon->greaterThan($lastViolationDate)) {
                    $gbroReferenceDate = $lastGbroCarbon;
                }
            }

            $gbroPredictionDate = $gbroReferenceDate->copy()->addDays(60);

            foreach ($activePoints as $index => $point) {
                if ($index < 2) {
                    $pointGbroDate = $gbroPredictionDate->format('Y-m-d');
                    if (! $point->gbro_expires_at || $point->gbro_expires_at !== $pointGbroDate) {
                        $point->update(['gbro_expires_at' => $pointGbroDate]);
                        $totalUpdated++;
                    }
                } else {
                    if ($point->gbro_expires_at !== null) {
                        $point->update(['gbro_expires_at' => null]);
                        $totalUpdated++;
                    }
                }
            }
        }

        return [
            'success' => true,
            'message' => $totalUpdated > 0
                ? "Initialized GBRO dates for {$totalUpdated} points."
                : 'All GBRO dates are already initialized.',
            'updated' => $totalUpdated,
        ];
    }

    /**
     * Fix GBRO expiration dates for points with wrong reference.
     */
    public function fixGbroDates(): array
    {
        $totalUpdated = 0;

        $usersWithGbroApplied = AttendancePoint::whereNotNull('gbro_applied_at')
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        foreach ($usersWithGbroApplied as $userId) {
            $expiredPoint = AttendancePoint::where('user_id', $userId)
                ->whereNotNull('gbro_applied_at')
                ->whereNotNull('gbro_expires_at')
                ->orderBy('gbro_applied_at', 'desc')
                ->first();

            if (! $expiredPoint || ! $expiredPoint->gbro_expires_at) {
                continue;
            }

            $scheduledGbroDate = Carbon::parse($expiredPoint->gbro_expires_at);
            $newGbroPrediction = $scheduledGbroDate->copy()->addDays(60);

            $allActivePoints = AttendancePoint::where('user_id', $userId)
                ->where('is_expired', false)
                ->where('eligible_for_gbro', true)
                ->whereNull('gbro_applied_at')
                ->orderBy('shift_date', 'desc')
                ->get();

            $activePoints = $allActivePoints->where('is_excused', false)->values();

            foreach ($activePoints as $index => $point) {
                if ($index < 2) {
                    $point->update(['gbro_expires_at' => $newGbroPrediction->format('Y-m-d')]);
                    $totalUpdated++;
                } else {
                    if ($point->gbro_expires_at !== null) {
                        $point->update(['gbro_expires_at' => null]);
                        $totalUpdated++;
                    }
                }
            }
        }

        return [
            'success' => true,
            'message' => $totalUpdated > 0
                ? "Fixed GBRO dates for {$totalUpdated} points."
                : 'No GBRO dates needed fixing.',
            'updated' => $totalUpdated,
        ];
    }

    /**
     * Full cleanup: remove duplicates + expire all pending (SRO + GBRO).
     */
    public function cleanup(): array
    {
        $results = [
            'duplicates_removed' => 0,
            'sro_expired' => 0,
            'gbro_expired' => 0,
        ];

        // Step 1: Remove duplicates
        $duplicateResult = $this->removeDuplicates();
        $results['duplicates_removed'] = $duplicateResult['removed'];

        // Step 2: Expire all pending SRO
        $sroPoints = AttendancePoint::where('is_expired', false)
            ->where('is_excused', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($sroPoints as $point) {
            $point->markAsExpired('sro');
            $results['sro_expired']++;
        }

        // Step 3: Expire all pending GBRO
        $results['gbro_expired'] = $this->processGbroExpirations();

        $totalExpired = $results['sro_expired'] + $results['gbro_expired'];
        $totalActions = $results['duplicates_removed'] + $totalExpired;

        return [
            'success' => true,
            'message' => $totalActions > 0
                ? "Cleanup complete: removed {$results['duplicates_removed']} duplicates, expired {$totalExpired} points (SRO: {$results['sro_expired']}, GBRO: {$results['gbro_expired']}). Excused points preserved."
                : 'No cleanup actions needed. Database is already clean.',
            'duplicates_removed' => $results['duplicates_removed'],
            'points_expired' => $totalExpired,
            'sro_expired' => $results['sro_expired'],
            'gbro_expired' => $results['gbro_expired'],
        ];
    }

    /**
     * Regenerate attendance points from verified attendance records.
     */
    public function regeneratePoints(
        AttendancePointCreationService $creationService,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $userId = null
    ): array {
        $query = Attendance::where('status', 'verified')
            ->where(function ($q) {
                $q->where('is_absent', true)
                    ->orWhere('is_tardy', true)
                    ->orWhere('is_undertime', true);
            })
            ->whereDoesntHave('attendancePoints');

        if ($dateFrom) {
            $query->whereDate('shift_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('shift_date', '<=', $dateTo);
        }
        if ($userId) {
            $query->where('user_id', $userId);
        }

        $attendances = $query->with('user')->get();

        if ($attendances->isEmpty()) {
            return [
                'success' => true,
                'message' => 'No attendance records found that need points regeneration.',
                'regenerated' => 0,
            ];
        }

        $regeneratedCount = 0;

        foreach ($attendances as $attendance) {
            $pointsCreated = $creationService->createPointsFromAttendance($attendance);
            $regeneratedCount += $pointsCreated;
        }

        return [
            'success' => true,
            'message' => "Regenerated {$regeneratedCount} attendance points from {$attendances->count()} attendance records.",
            'regenerated' => $regeneratedCount,
            'records_processed' => $attendances->count(),
        ];
    }
}
