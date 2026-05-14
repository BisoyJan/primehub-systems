<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Centralizes leave-conflict detection for any attendance write surface
 * (Manual create, Daily Roster generate, Spreadsheet cell create/update).
 *
 * Behavior preserved from the original inline logic in
 * AttendanceController::store():
 *
 *  - Approved leave on the same date → flagged as conflict; the resulting
 *    attendance row should be created with admin_verified=false and
 *    descriptive verification_notes / notes so HR can review.
 *  - Pending leave on the same date with actual time recorded:
 *      * single-day leave  → auto-cancelled and HR/SuperAdmin/TeamLead notified
 *      * multi-day leave   → flagged for HR review (no auto-cancel)
 */
class LeaveConflictResolver
{
    public function __construct(protected NotificationService $notificationService) {}

    /**
     * Resolve leave conflicts for an attendance write at the given user/date.
     *
     * @return array{
     *   approvedLeave: ?LeaveRequest,
     *   pendingLeave: ?LeaveRequest,
     *   hasApprovedConflict: bool,
     *   autoCancelledPending: bool,
     *   verificationNote: ?string,
     *   conflictNote: ?string,
     * }
     */
    public function resolveOnAttendanceWrite(
        int $userId,
        string $shiftDate,
        ?Carbon $actualTimeIn,
        ?Carbon $actualTimeOut,
        ?string $surfaceLabel = null,
    ): array {
        $approvedLeave = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->where('start_date', '<=', $shiftDate)
            ->where('end_date', '>=', $shiftDate)
            ->first();

        $pendingLeave = LeaveRequest::where('user_id', $userId)
            ->where('status', 'pending')
            ->where('start_date', '<=', $shiftDate)
            ->where('end_date', '>=', $shiftDate)
            ->first();

        $hasActualTime = $actualTimeIn || $actualTimeOut;
        $hasApprovedConflict = (bool) $approvedLeave && $hasActualTime;
        $autoCancelledPending = false;
        $verificationNote = null;
        $conflictNote = null;

        $author = auth()->user()?->name ?? 'system';
        $surfaceText = $surfaceLabel ? ' via '.$surfaceLabel : '';

        if ($hasApprovedConflict) {
            $verificationNote = "Manual entry during approved leave - requires HR review. Created{$surfaceText} by {$author}";
            $conflictNote = 'Leave conflict: Employee on approved leave but has attendance entry. Pending HR review.';

            $employee = User::find($userId);
            if ($employee && $approvedLeave) {
                $workDuration = ($actualTimeIn && $actualTimeOut)
                    ? round($actualTimeIn->diffInMinutes($actualTimeOut) / 60, 2)
                    : 0;
                $scanTimes = $actualTimeIn
                    ? $actualTimeIn->format('H:i').($actualTimeOut ? ', '.$actualTimeOut->format('H:i') : '')
                    : 'N/A';

                $this->notificationService->notifyLeaveAttendanceConflict(
                    $employee,
                    $approvedLeave,
                    Carbon::parse($shiftDate),
                    $actualTimeIn && $actualTimeOut ? 2 : 1,
                    $scanTimes,
                    $workDuration,
                    $approvedLeave->start_date != $approvedLeave->end_date,
                );
            }
        }

        if ($pendingLeave && $hasActualTime) {
            $autoCancelledPending = $this->handlePendingLeave(
                $pendingLeave,
                $userId,
                $shiftDate,
                $surfaceLabel,
            );
        }

        return [
            'approvedLeave' => $approvedLeave,
            'pendingLeave' => $pendingLeave,
            'hasApprovedConflict' => $hasApprovedConflict,
            'autoCancelledPending' => $autoCancelledPending,
            'verificationNote' => $verificationNote,
            'conflictNote' => $conflictNote,
        ];
    }

    /**
     * Apply the pending-leave rules: auto-cancel single-day, flag multi-day for HR.
     */
    protected function handlePendingLeave(
        LeaveRequest $pendingLeave,
        int $userId,
        string $shiftDate,
        ?string $surfaceLabel,
    ): bool {
        $employee = User::find($userId);
        if (! $employee) {
            return false;
        }

        $leaveType = str_replace('_', ' ', ucfirst($pendingLeave->leave_type));
        $startDate = Carbon::parse($pendingLeave->start_date)->format('M d, Y');
        $endDate = Carbon::parse($pendingLeave->end_date)->format('M d, Y');
        $isMultiDay = Carbon::parse($pendingLeave->start_date)
            ->diffInDays(Carbon::parse($pendingLeave->end_date)) > 0;
        $surfaceText = $surfaceLabel ? " ({$surfaceLabel})" : '';

        if ($isMultiDay) {
            $title = 'Pending Leave Conflict - Review Required';
            $message = "{$employee->name} has manual attendance{$surfaceText} during pending {$leaveType} leave.\n\n"
                ."Leave Period: {$startDate} to {$endDate}\n"
                .'Attendance Date: '.Carbon::parse($shiftDate)->format('M d, Y')."\n\n"
                ."Please review and decide:\n"
                ."• Cancel the pending leave if employee is no longer taking leave\n"
                .'• No action needed if employee only worked this one day';
            $data = [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'leave_request_id' => $pendingLeave->id,
                'leave_type' => $leaveType,
                'link' => route('leave-requests.show', $pendingLeave->id),
            ];

            $this->notificationService->notifyUsersByRole('HR', 'leave_request', $title, $message, $data);
            $this->notificationService->notifyUsersByRole('Super Admin', 'leave_request', $title, $message, $data);
            $this->notificationService->notifyUsersByRole('Team Lead', 'leave_request', $title, $message, $data);

            Log::info('Multi-day pending leave conflict flagged for HR review (manual attendance)', [
                'leave_request_id' => $pendingLeave->id,
                'user_id' => $userId,
                'shift_date' => $shiftDate,
                'surface' => $surfaceLabel,
            ]);

            return false;
        }

        // Single-day pending leave: auto-cancel
        $pendingLeave->update([
            'status' => 'cancelled',
            'auto_cancelled' => true,
            'auto_cancelled_reason' => 'Manual attendance was created for '
                .Carbon::parse($shiftDate)->format('M d, Y')
                .'. Pending leave request was automatically cancelled.',
            'auto_cancelled_at' => now(),
        ]);

        $this->notificationService->notifyLeaveRequestAutoCancelled(
            $employee,
            $leaveType,
            $startDate,
            $endDate,
            'Manual attendance was created for the leave period',
            $pendingLeave->id,
        );

        $autoCancelTitle = 'Pending Leave Auto-Cancelled';
        $autoCancelMessage = "{$employee->name}'s pending {$leaveType} leave ({$startDate} to {$endDate}) was automatically cancelled because manual attendance was created{$surfaceText} for "
            .Carbon::parse($shiftDate)->format('M d, Y').'.';
        $autoCancelData = [
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'leave_request_id' => $pendingLeave->id,
            'leave_type' => $leaveType,
            'link' => route('leave-requests.show', $pendingLeave->id),
        ];

        $this->notificationService->notifyUsersByRole('HR', 'leave_request', $autoCancelTitle, $autoCancelMessage, $autoCancelData);
        $this->notificationService->notifyUsersByRole('Super Admin', 'leave_request', $autoCancelTitle, $autoCancelMessage, $autoCancelData);
        $this->notificationService->notifyUsersByRole('Team Lead', 'leave_request', $autoCancelTitle, $autoCancelMessage, $autoCancelData);

        Log::info('Pending leave auto-cancelled due to manual attendance creation', [
            'leave_request_id' => $pendingLeave->id,
            'user_id' => $userId,
            'shift_date' => $shiftDate,
            'surface' => $surfaceLabel,
        ]);

        return true;
    }
}
