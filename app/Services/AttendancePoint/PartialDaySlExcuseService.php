<?php

namespace App\Services\AttendancePoint;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;

class PartialDaySlExcuseService
{
    public function __construct(protected GbroCalculationService $gbroService) {}

    /**
     * Auto-excuse any un-excused attendance points on a Partial-day SL day.
     * Requires the linked SL leave request to have a medical certificate on file.
     *
     * Returns the number of points excused.
     */
    public function excuseForAttendance(Attendance $attendance, ?int $excusedBy = null): int
    {
        if (! $attendance->leave_request_id || ! $attendance->shift_date) {
            return 0;
        }

        $dateStr = $attendance->shift_date->toDateString();

        $isPartialDaySl = LeaveRequestDay::where('leave_request_id', $attendance->leave_request_id)
            ->whereDate('date', $dateStr)
            ->where('day_status', LeaveRequestDay::STATUS_PARTIAL_DAY_ABSENCE)
            ->exists();

        if (! $isPartialDaySl) {
            return 0;
        }

        $leaveRequest = LeaveRequest::find($attendance->leave_request_id);
        if (! $leaveRequest || ! $leaveRequest->medical_cert_submitted) {
            return 0;
        }

        $points = AttendancePoint::where('user_id', $attendance->user_id)
            ->whereDate('shift_date', $dateStr)
            ->where('is_excused', false)
            ->get();

        if ($points->isEmpty()) {
            return 0;
        }

        $reason = "Auto-excused: Partial-day Absence (SL with Undertime) - Leave Request #{$leaveRequest->id}";
        $now = now();

        foreach ($points as $point) {
            $point->update([
                'is_excused' => true,
                'excused_by' => $excusedBy,
                'excused_at' => $now,
                'excuse_reason' => $reason,
                'is_expired' => false,
                'expired_at' => null,
                'expiration_type' => method_exists($point, 'isNcns') && $point->isNcns() ? 'none' : 'sro',
                'gbro_applied_at' => null,
                'gbro_batch_id' => null,
            ]);
        }

        // Recalculate GBRO since excusing changes the ledger.
        try {
            $this->gbroService->cascadeRecalculateGbro((int) $attendance->user_id);
        } catch (\Throwable $e) {
            \Log::warning('PartialDaySlExcuseService: GBRO recalc failed', [
                'user_id' => $attendance->user_id,
                'message' => $e->getMessage(),
            ]);
        }

        return $points->count();
    }
}
