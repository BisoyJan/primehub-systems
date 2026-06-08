<?php

namespace App\Observers;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Services\AttendancePoint\StreakService;

class AttendancePointObserver
{
    public function __construct(protected StreakService $streakService) {}

    public function created(AttendancePoint $point): void
    {
        $this->streakService->clearUserCache($point->user_id);

        // Auto-excuse this point if it lands on an approved Partial-day SL
        // (SL with Undertime) day backed by a medical certificate. Covers any
        // creation path — biometric reprocessing, manual entry, daily roster.
        $this->autoExcuseIfPartialDaySl($point);
    }

    public function updated(AttendancePoint $point): void
    {
        // Excused / unexcused / type changes all affect the streak calculation.
        $this->streakService->clearUserCache($point->user_id);
    }

    public function deleted(AttendancePoint $point): void
    {
        $this->streakService->clearUserCache($point->user_id);
    }

    /**
     * Mirror the Partial-day SL auto-excuse rule whenever a new point lands on
     * a date covered by an approved SL-with-Undertime leave + medcert.
     */
    protected function autoExcuseIfPartialDaySl(AttendancePoint $point): void
    {
        if ($point->is_excused || ! $point->shift_date) {
            return;
        }

        $dateStr = $point->shift_date->toDateString();

        // Find the linked SL leave request via the attendance row OR via the
        // leave_request_days table directly (covers manual point creation that
        // isn't tied to a single attendance row).
        $leaveRequestId = null;

        if ($point->attendance_id) {
            $attendance = Attendance::find($point->attendance_id);
            $leaveRequestId = $attendance?->leave_request_id;
        }

        if (! $leaveRequestId) {
            $leaveRequestId = LeaveRequestDay::whereDate('date', $dateStr)
                ->where('day_status', LeaveRequestDay::STATUS_PARTIAL_DAY_ABSENCE)
                ->whereHas('leaveRequest', fn ($q) => $q->where('user_id', $point->user_id)
                    ->where('status', 'approved')
                )
                ->value('leave_request_id');
        }

        if (! $leaveRequestId) {
            return;
        }

        $isPartialDay = LeaveRequestDay::where('leave_request_id', $leaveRequestId)
            ->whereDate('date', $dateStr)
            ->where('day_status', LeaveRequestDay::STATUS_PARTIAL_DAY_ABSENCE)
            ->exists();

        if (! $isPartialDay) {
            return;
        }

        $leaveRequest = LeaveRequest::find($leaveRequestId);
        if (! $leaveRequest || ! $leaveRequest->medical_cert_submitted) {
            return;
        }

        // Use saveQuietly so we don't recursively trigger ::updated on this same row.
        $point->is_excused = true;
        $point->excused_by = auth()->id();
        $point->excused_at = now();
        $point->excuse_reason = "Auto-excused: Partial-day Absence (SL with Undertime) - Leave Request #{$leaveRequest->id}";
        $point->is_expired = false;
        $point->expired_at = null;
        $point->expiration_type = method_exists($point, 'isNcns') && $point->isNcns() ? 'none' : 'sro';
        $point->gbro_applied_at = null;
        $point->gbro_batch_id = null;
        $point->saveQuietly();
    }
}
