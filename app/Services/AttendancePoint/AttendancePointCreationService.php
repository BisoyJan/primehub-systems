<?php

namespace App\Services\AttendancePoint;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Services\NotificationService;
use Carbon\Carbon;

/**
 * Service responsible for creating and updating attendance points.
 */
class AttendancePointCreationService
{
    public function __construct(
        protected GbroCalculationService $gbroService,
        protected NotificationService $notificationService
    ) {}

    /**
     * Create a manual attendance point.
     */
    public function createManualPoint(array $data, int $createdById): AttendancePoint
    {
        $pointType = $data['point_type'];
        $isAdvised = $data['is_advised'] ?? false;
        $shiftDate = Carbon::parse($data['shift_date']);
        $userId = $data['user_id'];

        // Delete any existing points for the same user and date
        AttendancePoint::where('user_id', $userId)
            ->where('shift_date', $data['shift_date'])
            ->delete();

        $isNcnsOrFtn = $pointType === 'whole_day_absence' && ! $isAdvised;
        $expiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);
        $isGbroEligible = ! $isNcnsOrFtn;

        $violationDetails = $data['violation_details'] ?? null;
        if (empty($violationDetails)) {
            $violationDetails = $this->generateManualViolationDetails(
                $pointType,
                $isAdvised,
                $data['tardy_minutes'] ?? null,
                $data['undertime_minutes'] ?? null
            );
        }

        $point = AttendancePoint::create([
            'user_id' => $userId,
            'attendance_id' => null,
            'shift_date' => $data['shift_date'],
            'point_type' => $pointType,
            'points' => AttendancePoint::POINT_VALUES[$pointType] ?? 0,
            'status' => null,
            'is_advised' => $isAdvised,
            'is_manual' => true,
            'created_by' => $createdById,
            'notes' => $data['notes'] ?? null,
            'violation_details' => $violationDetails,
            'tardy_minutes' => $data['tardy_minutes'] ?? null,
            'undertime_minutes' => $data['undertime_minutes'] ?? null,
            'expires_at' => $expiresAt,
            'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
            'eligible_for_gbro' => $isGbroEligible,
            'gbro_expires_at' => null,
        ]);

        // Send notification
        $this->notificationService->notifyManualAttendancePoint(
            $userId,
            $pointType,
            Carbon::parse($data['shift_date'])->format('M d, Y'),
            $point->points ?? 0
        );

        // Cascade recalculate GBRO
        $this->gbroService->cascadeRecalculateGbro($userId);

        return $point;
    }

    /**
     * Update a manual attendance point.
     */
    public function updateManualPoint(AttendancePoint $point, array $data): AttendancePoint
    {
        $pointType = $data['point_type'];
        $isAdvised = $data['is_advised'] ?? false;
        $shiftDate = Carbon::parse($data['shift_date']);
        $userId = $data['user_id'];

        $isNcnsOrFtn = $pointType === 'whole_day_absence' && ! $isAdvised;
        $expiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);

        $violationDetails = $data['violation_details'] ?? null;
        if (empty($violationDetails)) {
            $violationDetails = $this->generateManualViolationDetails(
                $pointType,
                $isAdvised,
                $data['tardy_minutes'] ?? null,
                $data['undertime_minutes'] ?? null
            );
        }

        $point->update([
            'user_id' => $userId,
            'shift_date' => $data['shift_date'],
            'point_type' => $pointType,
            'points' => AttendancePoint::POINT_VALUES[$pointType] ?? 0,
            'is_advised' => $isAdvised,
            'notes' => $data['notes'] ?? null,
            'violation_details' => $violationDetails,
            'tardy_minutes' => $data['tardy_minutes'] ?? null,
            'undertime_minutes' => $data['undertime_minutes'] ?? null,
            'expires_at' => $expiresAt,
            'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
            'eligible_for_gbro' => ! $isNcnsOrFtn,
        ]);

        // Send notification
        $this->notificationService->notifyManualAttendancePoint(
            $userId,
            $pointType,
            Carbon::parse($data['shift_date'])->format('M d, Y'),
            $point->fresh()->points ?? 0
        );

        // Recalculate GBRO
        $this->gbroService->cascadeRecalculateGbro($userId);

        return $point->fresh();
    }

    /**
     * Delete a manual attendance point.
     */
    public function deleteManualPoint(AttendancePoint $point): void
    {
        $userId = $point->user_id;
        $point->delete();

        // Cascade recalculate GBRO
        $this->gbroService->cascadeRecalculateGbro($userId);
    }

    /**
     * Create attendance points from an attendance record.
     */
    public function createPointsFromAttendance(Attendance $attendance): int
    {
        $pointsCreated = 0;
        $shiftDate = Carbon::parse($attendance->shift_date);

        // Handle absences
        if ($attendance->is_absent) {
            $pointsCreated += $this->createAbsencePoint($attendance, $shiftDate);
        }

        // Handle tardy
        if ($attendance->is_tardy && $attendance->tardy_minutes > 0) {
            $pointsCreated += $this->createTardyPoint($attendance, $shiftDate);
        }

        // Handle undertime
        if ($attendance->is_undertime && $attendance->undertime_minutes > 0) {
            $pointsCreated += $this->createUndertimePoint($attendance, $shiftDate);
        }

        return $pointsCreated;
    }

    /**
     * Create an absence point from attendance.
     */
    private function createAbsencePoint(Attendance $attendance, Carbon $shiftDate): int
    {
        $isNcnsOrFtn = ! $attendance->is_advised;
        $expiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);
        $gbroExpiresAt = $isNcnsOrFtn ? null : $shiftDate->copy()->addDays(60)->format('Y-m-d');

        $isHalfDay = $attendance->remarks && str_contains(strtolower($attendance->remarks), 'half');
        $pointType = $isHalfDay ? 'half_day_absence' : 'whole_day_absence';
        $points = $isHalfDay ? 0.50 : 1.00;

        AttendancePoint::create([
            'user_id' => $attendance->user_id,
            'attendance_id' => $attendance->id,
            'shift_date' => $attendance->shift_date,
            'point_type' => $pointType,
            'points' => $points,
            'is_advised' => $attendance->is_advised,
            'is_manual' => false,
            'expires_at' => $expiresAt,
            'gbro_expires_at' => $gbroExpiresAt,
            'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
            'eligible_for_gbro' => ! $isNcnsOrFtn,
        ]);

        if (! $isNcnsOrFtn) {
            $this->gbroService->updateUserGbroExpirationDates($attendance->user_id, $shiftDate);
        }

        return 1;
    }

    /**
     * Create a tardy point from attendance.
     */
    private function createTardyPoint(Attendance $attendance, Carbon $shiftDate): int
    {
        $gbroExpiresAt = $shiftDate->copy()->addDays(60)->format('Y-m-d');

        AttendancePoint::create([
            'user_id' => $attendance->user_id,
            'attendance_id' => $attendance->id,
            'shift_date' => $attendance->shift_date,
            'point_type' => 'tardy',
            'points' => 0.25,
            'tardy_minutes' => $attendance->tardy_minutes,
            'is_manual' => false,
            'expires_at' => $shiftDate->copy()->addMonths(6),
            'gbro_expires_at' => $gbroExpiresAt,
            'expiration_type' => 'sro',
            'eligible_for_gbro' => true,
        ]);

        $this->gbroService->updateUserGbroExpirationDates($attendance->user_id, $shiftDate);

        return 1;
    }

    /**
     * Create an undertime point from attendance.
     */
    private function createUndertimePoint(Attendance $attendance, Carbon $shiftDate): int
    {
        $isMoreThanHour = $attendance->undertime_minutes > 60;
        $gbroExpiresAt = $shiftDate->copy()->addDays(60)->format('Y-m-d');

        AttendancePoint::create([
            'user_id' => $attendance->user_id,
            'attendance_id' => $attendance->id,
            'shift_date' => $attendance->shift_date,
            'point_type' => $isMoreThanHour ? 'undertime_more_than_hour' : 'undertime',
            'points' => $isMoreThanHour ? 0.50 : 0.25,
            'undertime_minutes' => $attendance->undertime_minutes,
            'is_manual' => false,
            'expires_at' => $shiftDate->copy()->addMonths(6),
            'gbro_expires_at' => $gbroExpiresAt,
            'expiration_type' => 'sro',
            'eligible_for_gbro' => true,
        ]);

        $this->gbroService->updateUserGbroExpirationDates($attendance->user_id, $shiftDate);

        return 1;
    }

    /**
     * Generate violation details for manual entries.
     */
    public function generateManualViolationDetails(
        string $pointType,
        bool $isAdvised,
        ?int $tardyMinutes,
        ?int $undertimeMinutes
    ): string {
        return match ($pointType) {
            'whole_day_absence' => $isAdvised
                ? 'Manual Entry: Advised absence (Failed to Notify - FTN)'
                : 'Manual Entry: No Call, No Show (NCNS) - Did not report for work without prior notice',
            'half_day_absence' => 'Manual Entry: Half-day absence recorded',
            'tardy' => sprintf('Manual Entry: Late arrival by %d minutes', $tardyMinutes ?? 0),
            'undertime' => sprintf('Manual Entry: Early departure by %d minutes (up to 1 hour)', $undertimeMinutes ?? 0),
            'undertime_more_than_hour' => sprintf('Manual Entry: Early departure by %d minutes (more than 1 hour)', $undertimeMinutes ?? 0),
            default => 'Manual Entry: Attendance violation',
        };
    }

    /**
     * Generate detailed violation description from attendance record.
     */
    public function generateViolationDetails(Attendance $attendance): string
    {
        $scheduledIn = $attendance->scheduled_time_in ? Carbon::parse($attendance->scheduled_time_in)->format('H:i') : 'N/A';
        $scheduledOut = $attendance->scheduled_time_out ? Carbon::parse($attendance->scheduled_time_out)->format('H:i') : 'N/A';
        $actualIn = $attendance->actual_time_in ? $attendance->actual_time_in->format('H:i') : 'No scan';
        $actualOut = $attendance->actual_time_out ? $attendance->actual_time_out->format('H:i') : 'No scan';
        $gracePeriod = $attendance->employeeSchedule?->grace_period_minutes ?? 15;

        return match ($attendance->status) {
            'ncns' => $attendance->is_advised
                ? "Failed to Notify (FTN): Employee did not report for work despite being advised. Scheduled: {$scheduledIn} - {$scheduledOut}. No biometric scans recorded."
                : "No Call, No Show (NCNS): Employee did not report for work and did not provide prior notice. Scheduled: {$scheduledIn} - {$scheduledOut}. No biometric scans recorded.",

            'half_day_absence' => sprintf(
                'Half-Day Absence: Arrived %d minutes late (more than %d minutes grace period). Scheduled: %s, Actual: %s.',
                $attendance->tardy_minutes ?? 0,
                $gracePeriod,
                $scheduledIn,
                $actualIn
            ),

            'tardy' => sprintf(
                'Tardy: Arrived %d minutes late. Scheduled time in: %s, Actual time in: %s.',
                $attendance->tardy_minutes ?? 0,
                $scheduledIn,
                $actualIn
            ),

            'undertime' => sprintf(
                'Undertime: Left %d minutes early (up to 1 hour before scheduled end). Scheduled: %s, Actual: %s.',
                $attendance->undertime_minutes ?? 0,
                $scheduledOut,
                $actualOut
            ),

            'undertime_more_than_hour' => sprintf(
                'Undertime (>1 Hour): Left %d minutes early (more than 1 hour before scheduled end). Scheduled: %s, Actual: %s.',
                $attendance->undertime_minutes ?? 0,
                $scheduledOut,
                $actualOut
            ),

            default => sprintf('Attendance violation on %s', Carbon::parse($attendance->shift_date)->format('Y-m-d')),
        };
    }

    /**
     * Determine point type and value based on attendance status.
     */
    public function determinePointType(Attendance $attendance): ?array
    {
        $type = match ($attendance->status) {
            'ncns', 'advised_absence' => 'whole_day_absence',
            'half_day_absence' => 'half_day_absence',
            'undertime' => 'undertime',
            'undertime_more_than_hour' => 'undertime_more_than_hour',
            'tardy' => 'tardy',
            default => null,
        };

        if (! $type) {
            return null;
        }

        return [
            'type' => $type,
            'points' => AttendancePoint::POINT_VALUES[$type] ?? 0,
        ];
    }
}
