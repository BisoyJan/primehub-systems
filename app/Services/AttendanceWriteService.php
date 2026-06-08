<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Services\AttendancePoint\GbroAnomalyService;
use App\Services\AttendancePoint\GbroCalculationService;
use App\Services\AttendancePoint\PartialDaySlExcuseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Centralizes the shared post-create pipeline used by every manual-entry
 * attendance write surface (Manual create, Bulk create, Daily Roster /
 * generateAttendance, Spreadsheet cell):
 *
 *  1. Recalculate total_minutes_worked with the canonical processor logic
 *     (lunch deduction, scheduled-time cap, overtime approval).
 *  2. Delete & regenerate AttendancePoint rows when the record is
 *     admin_verified — leave-conflict records (admin_verified=false) await
 *     HR review and don't generate points yet.
 *  3. Notify the employee of their attendance status when it's non-on_time
 *     and the record is admin_verified.
 *
 * Edit / verify / update flows continue to use
 * AttendanceController::regeneratePointsIfNeeded() because they need the
 * old-vs-new status comparison that lives there.
 */
class AttendanceWriteService
{
    /**
     * Statuses that trigger AttendancePoint generation.
     */
    protected const POINTABLE_STATUSES = [
        'ncns',
        'half_day_absence',
        'tardy',
        'undertime',
        'undertime_more_than_hour',
        'advised_absence',
    ];

    public function __construct(
        protected AttendanceProcessor $processor,
        protected NotificationService $notifications,
        protected GbroCalculationService $gbroService,
        protected GbroAnomalyService $anomalyService,
        protected PartialDaySlExcuseService $partialDaySlExcuseService,
    ) {}

    /**
     * Run the shared finalize pipeline for a freshly created attendance row.
     */
    public function finalizeManualWrite(Attendance $attendance, string $shiftDate): void
    {
        // Recalculate total_minutes_worked with proper lunch deduction,
        // scheduled-time-in cap, and overtime approval logic. Safe to run
        // even when one of in/out is missing.
        $this->processor->recalculateTotalMinutesWorked($attendance);

        $attendance->refresh();

        if ($attendance->admin_verified) {
            $this->regeneratePoints($attendance);

            // Auto-excuse newly-generated points if this date is a Partial-day SL
            // day with a medical certificate on file.
            if ($attendance->leave_request_id) {
                $this->partialDaySlExcuseService->excuseForAttendance($attendance, auth()->id());
            }
        }

        if ($attendance->admin_verified && $attendance->status !== 'on_time') {
            $pointRecord = AttendancePoint::where('attendance_id', $attendance->id)->first();
            $points = $pointRecord?->points;

            $this->notifications->notifyAttendanceStatus(
                $attendance->user_id,
                $attendance->status,
                Carbon::parse($shiftDate)->format('M d, Y'),
                $points,
            );
        }
    }

    /**
     * Delete any existing AttendancePoint rows for this attendance and
     * regenerate them when the status is pointable. Also recalculates the
     * GBRO milestone for the user.
     */
    protected function regeneratePoints(Attendance $attendance): void
    {
        AttendancePoint::where('attendance_id', $attendance->id)->delete();

        if (in_array($attendance->status, self::POINTABLE_STATUSES, true) ||
            in_array($attendance->secondary_status, self::POINTABLE_STATUSES, true)) {
            $this->processor->regeneratePointsForAttendance($attendance);
        }

        try {
            $this->gbroService->cascadeRecalculateGbro($attendance->user_id);
        } catch (\Exception $e) {
            Log::error('AttendanceWriteService GBRO recalc Error: '.$e->getMessage());
        }

        // Audit for drift introduced by the new/regenerated points. Failures here
        // are logged but never propagate — the user-facing write must succeed.
        try {
            $this->anomalyService->repair($attendance->user_id, 'manual_write');
        } catch (\Throwable $e) {
            Log::error('AttendanceWriteService anomaly audit failed', [
                'user_id' => $attendance->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
