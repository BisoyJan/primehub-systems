<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    /**
     * Create a notification for a user.
     *
     * @param User|int $user
     * @param string $type
     * @param string $title
     * @param string $message
     * @param array|null $data
     * @return Notification
     */
    public function create(User|int $user, string $type, string $title, string $message, ?array $data = null): Notification
    {
        $userId = $user instanceof User ? $user->id : $user;

        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Create notifications for multiple users.
     *
     * @param array $userIds
     * @param string $type
     * @param string $title
     * @param string $message
     * @param array|null $data
     * @return void
     */
    public function createForMultipleUsers(array $userIds, string $type, string $title, string $message, ?array $data = null): void
    {
        foreach ($userIds as $userId) {
            $this->create($userId, $type, $title, $message, $data);
        }
    }

    /**
     * Notify about maintenance due.
     */
    public function notifyMaintenanceDue(User|int $user, string $stationName, string $dueDate): Notification
    {
        return $this->create(
            $user,
            'maintenance_due',
            'Maintenance Due',
            "Station {$stationName} is due for maintenance on {$dueDate}.",
            ['station' => $stationName, 'due_date' => $dueDate]
        );
    }

    /**
     * Notify about new leave request.
     */
    public function notifyLeaveRequest(User|int $user, string $requesterName, string $leaveType, int $requestId): Notification
    {
        return $this->create(
            $user,
            'leave_request',
            'New Leave Request',
            "{$requesterName} has submitted a {$leaveType} request for your review.",
            ['requester' => $requesterName, 'type' => $leaveType, 'request_id' => $requestId]
        );
    }

    /**
     * Notify about leave request status change.
     */
    public function notifyLeaveRequestStatusChange(User|int $user, string $status, string $leaveType, int $requestId): Notification
    {
        $statusText = ucfirst($status);
        return $this->create(
            $user,
            'leave_request',
            "Leave Request {$statusText}",
            "Your {$leaveType} request has been {$status}.",
            [
                'status' => $status,
                'type' => $leaveType,
                'request_id' => $requestId,
                'link' => route('leave-requests.show', $requestId)
            ]
        );
    }

    /**
     * Notify about new IT concern.
     */
    public function notifyItConcern(User|int $user, string $stationName, string $category, int $concernId): Notification
    {
        return $this->create(
            $user,
            'it_concern',
            'New IT Concern',
            "A new {$category} issue has been reported for {$stationName}.",
            ['station' => $stationName, 'category' => $category, 'concern_id' => $concernId]
        );
    }

    /**
     * Notify about IT concern status change.
     */
    public function notifyItConcernStatusChange(User|int $user, string $status, string $stationName, int $concernId): Notification
    {
        $statusText = ucfirst(str_replace('_', ' ', $status));
        return $this->create(
            $user,
            'it_concern',
            "IT Concern {$statusText}",
            "Your IT concern for {$stationName} has been marked as {$statusText}.",
            ['status' => $status, 'station' => $stationName, 'concern_id' => $concernId]
        );
    }

    /**
     * Notify about new medication request.
     */
    public function notifyMedicationRequest(User|int $user, string $requesterName, int $requestId): Notification
    {
        return $this->create(
            $user,
            'medication_request',
            'New Medication Request',
            "{$requesterName} has submitted a medication request for review.",
            ['requester' => $requesterName, 'request_id' => $requestId]
        );
    }

    /**
     * Notify about PC assignment.
     */
    public function notifyPcAssignment(User|int $user, string $pcNumber, string $stationName): Notification
    {
        return $this->create(
            $user,
            'pc_assignment',
            'PC Assignment',
            "PC {$pcNumber} has been assigned to station {$stationName}.",
            ['pc' => $pcNumber, 'station' => $stationName]
        );
    }

    /**
     * Notify about system message.
     */
    public function notifySystemMessage(User|int $user, string $title, string $message, ?array $data = null): Notification
    {
        return $this->create(
            $user,
            'system',
            $title,
            $message,
            $data
        );
    }

    /**
     * Send system message to all users.
     */
    public function notifyAllUsers(string $title, string $message, ?array $data = null): void
    {
        $userIds = User::where('is_approved', true)->pluck('id')->toArray();
        $this->createForMultipleUsers($userIds, 'system', $title, $message, $data);
    }

    /**
     * Send notification to users with specific role.
     */
    public function notifyUsersByRole(string $role, string $type, string $title, string $message, ?array $data = null): void
    {
        $userIds = User::where('role', $role)->where('is_approved', true)->pluck('id')->toArray();
        $this->createForMultipleUsers($userIds, $type, $title, $message, $data);
    }

    /**
     * Notify about attendance status.
     */
    public function notifyAttendanceStatus(User|int $user, string $status, string $date, ?float $points = null): Notification
    {
        $statusText = ucfirst(str_replace('_', ' ', $status));
        $message = "Your attendance for {$date} has been verified as {$statusText}.";

        if ($points !== null && $points > 0) {
            $message .= " You have incurred {$points} attendance point(s).";
        }

        return $this->create(
            $user,
            'attendance_status',
            'Attendance Verified',
            $message,
            [
                'status' => $status,
                'date' => $date,
                'points' => $points,
                'link' => route('attendance-points.show', $user instanceof User ? $user->id : $user)
            ]
        );
    }

    /**
     * Notify about manual attendance point entry.
     */
    public function notifyManualAttendancePoint(User|int $user, string $pointType, string $date, float $points): Notification
    {
        // Format point type for display
        $typeText = match($pointType) {
            'whole_day_absence' => 'Whole Day Absence',
            'half_day_absence' => 'Half-Day Absence',
            'tardy' => 'Tardy',
            'undertime' => 'Undertime',
            default => ucfirst(str_replace('_', ' ', $pointType))
        };

        $message = "A manual attendance point has been recorded for {$date}.";
        $message .= " Violation Type: {$typeText}.";
        $message .= " Points: {$points}.";

        return $this->create(
            $user,
            'attendance_status',
            'Manual Attendance Point Added',
            $message,
            [
                'point_type' => $pointType,
                'date' => $date,
                'points' => $points,
                'is_manual' => true,
                'link' => route('attendance-points.show', $user instanceof User ? $user->id : $user)
            ]
        );
    }

    /**
     * Notify IT roles about a new IT concern.
     */
    public function notifyItRolesAboutNewConcern(string $stationNumber, string $siteName, string $agentName, string $category, string $priority, string $description, int $concernId): void
    {
        $title = 'New IT Concern Reported';
        $message = "{$agentName} reported a {$priority} priority {$category} issue at {$siteName} - Station {$stationNumber}.";

        $data = [
            'station_number' => $stationNumber,
            'site_name' => $siteName,
            'agent_name' => $agentName,
            'category' => $category,
            'priority' => $priority,
            'description' => $description,
            'concern_id' => $concernId,
            'link' => route('it-concerns.show', $concernId)
        ];

        $this->notifyUsersByRole('IT', 'it_concern', $title, $message, $data);
        // Also notify Super Admin
        $this->notifyUsersByRole('Super Admin', 'it_concern', $title, $message, $data);
    }

    /**
     * Notify IT roles about an IT concern update by agent.
     */
    public function notifyItRolesAboutConcernUpdate(string $stationNumber, string $siteName, string $agentName, int $concernId): void
    {
        $title = 'IT Concern Updated';
        $message = "{$agentName} updated the IT concern for Station {$stationNumber} at {$siteName}.";

        $data = [
            'station_number' => $stationNumber,
            'site_name' => $siteName,
            'agent_name' => $agentName,
            'concern_id' => $concernId,
            'link' => route('it-concerns.show', $concernId)
        ];

        $this->notifyUsersByRole('IT', 'it_concern', $title, $message, $data);
        $this->notifyUsersByRole('Super Admin', 'it_concern', $title, $message, $data);
    }

    /**
     * Notify IT roles about an IT concern cancellation by agent.
     */
    public function notifyItRolesAboutConcernCancellation(string $stationNumber, string $siteName, string $agentName, int $concernId): void
    {
        $title = 'IT Concern Cancelled';
        $message = "{$agentName} cancelled their IT concern for Station {$stationNumber} at {$siteName}.";

        $data = [
            'station_number' => $stationNumber,
            'site_name' => $siteName,
            'agent_name' => $agentName,
            'concern_id' => $concernId,
            'link' => route('it-concerns.show', $concernId)
        ];

        $this->notifyUsersByRole('IT', 'it_concern', $title, $message, $data);
        $this->notifyUsersByRole('Super Admin', 'it_concern', $title, $message, $data);
    }

    /**
     * Notify HR roles about a new leave request.
     */
    public function notifyHrRolesAboutNewLeaveRequest(string $requesterName, string $leaveType, string $startDate, string $endDate, int $requestId): void
    {
        $title = 'New Leave Request';
        $message = "{$requesterName} has submitted a {$leaveType} request from {$startDate} to {$endDate}.";

        $data = [
            'requester' => $requesterName,
            'type' => $leaveType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'request_id' => $requestId,
            'link' => route('leave-requests.show', $requestId)
        ];

        $this->notifyUsersByRole('HR', 'leave_request', $title, $message, $data);
        $this->notifyUsersByRole('Admin', 'leave_request', $title, $message, $data);
        $this->notifyUsersByRole('Super Admin', 'leave_request', $title, $message, $data);
    }

    /**
     * Notify HR roles about a leave request cancellation.
     */
    public function notifyHrRolesAboutLeaveCancellation(string $requesterName, string $leaveType, string $startDate, string $endDate): void
    {
        $title = 'Leave Request Cancelled';
        $message = "{$requesterName} has cancelled their {$leaveType} request from {$startDate} to {$endDate}.";

        $data = [
            'requester' => $requesterName,
            'type' => $leaveType,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        $this->notifyUsersByRole('HR', 'leave_request', $title, $message, $data);
        $this->notifyUsersByRole('Admin', 'leave_request', $title, $message, $data);
        $this->notifyUsersByRole('Super Admin', 'leave_request', $title, $message, $data);
    }

    /**
     * Notify Admin roles that HR has approved a leave request (pending Admin approval).
     */
    public function notifyAdminAboutHrApproval(string $requesterName, string $leaveType, string $hrApproverName, int $requestId): void
    {
        $title = 'Leave Request - HR Approved';
        $message = "{$hrApproverName} (HR) has approved {$requesterName}'s {$leaveType} request. Awaiting your approval.";

        $data = [
            'requester' => $requesterName,
            'type' => $leaveType,
            'hr_approver' => $hrApproverName,
            'request_id' => $requestId,
            'link' => route('leave-requests.show', $requestId)
        ];

        $this->notifyUsersByRole('Admin', 'leave_request', $title, $message, $data);
        $this->notifyUsersByRole('Super Admin', 'leave_request', $title, $message, $data);
    }

    /**
     * Notify HR roles that Admin has approved a leave request (pending HR approval).
     */
    public function notifyHrAboutAdminApproval(string $requesterName, string $leaveType, string $adminApproverName, int $requestId): void
    {
        $title = 'Leave Request - Admin Approved';
        $message = "{$adminApproverName} (Admin) has approved {$requesterName}'s {$leaveType} request. Awaiting your approval.";

        $data = [
            'requester' => $requesterName,
            'type' => $leaveType,
            'admin_approver' => $adminApproverName,
            'request_id' => $requestId,
            'link' => route('leave-requests.show', $requestId)
        ];

        $this->notifyUsersByRole('HR', 'leave_request', $title, $message, $data);
    }

    /**
     * Notify the employee that their leave request has been fully approved (by both Admin and HR).
     */
    public function notifyLeaveRequestFullyApproved(int $userId, string $leaveType, int $requestId): Notification
    {
        return $this->create(
            $userId,
            'leave_request',
            'Leave Request Fully Approved',
            "Your {$leaveType} request has been approved by both Admin and HR.",
            [
                'status' => 'approved',
                'type' => $leaveType,
                'request_id' => $requestId,
                'link' => route('leave-requests.show', $requestId)
            ]
        );
    }

    /**
     * Notify HR roles about a new medication request.
     */
    public function notifyHrRolesAboutNewMedicationRequest(string $requesterName, string $medicationType, int $requestId): void
    {
        $title = 'New Medication Request';
        $message = "{$requesterName} has requested {$medicationType}.";

        $data = [
            'requester' => $requesterName,
            'medication_type' => $medicationType,
            'request_id' => $requestId,
            'link' => route('medication-requests.show', $requestId)
        ];

        $this->notifyUsersByRole('HR', 'medication_request', $title, $message, $data);
        $this->notifyUsersByRole('Admin', 'medication_request', $title, $message, $data);
        $this->notifyUsersByRole('Super Admin', 'medication_request', $title, $message, $data);
    }

    /**
     * Notify about medication request status change.
     */
    public function notifyMedicationRequestStatusChange(User|int $user, string $status, string $medicationType, int $requestId): Notification
    {
        $statusText = ucfirst($status);
        return $this->create(
            $user,
            'medication_request',
            "Medication Request {$statusText}",
            "Your request for {$medicationType} has been {$status}.",
            [
                'status' => $status,
                'medication_type' => $medicationType,
                'request_id' => $requestId,
                'link' => route('medication-requests.show', $requestId)
            ]
        );
    }

    /**
     * Notify HR roles about a medication request cancellation.
     */
    public function notifyHrRolesAboutMedicationRequestCancellation(string $requesterName, string $medicationType): void
    {
        $title = 'Medication Request Cancelled';
        $message = "{$requesterName} has cancelled their request for {$medicationType}.";

        $data = [
            'requester' => $requesterName,
            'medication_type' => $medicationType,
        ];

        $this->notifyUsersByRole('HR', 'medication_request', $title, $message, $data);
        $this->notifyUsersByRole('Admin', 'medication_request', $title, $message, $data);
        $this->notifyUsersByRole('Super Admin', 'medication_request', $title, $message, $data);
    }

    /**
     * Notify admin roles about an account deletion request.
     */
    public function notifyAccountDeletionRequest(string $accountName, string $deletedByName, int $accountId): void
    {
        $title = 'Account Deletion Request';
        $message = "{$deletedByName} has marked the account '{$accountName}' for deletion. Please review and confirm.";

        $data = [
            'account_name' => $accountName,
            'deleted_by' => $deletedByName,
            'account_id' => $accountId,
            'link' => route('accounts.index', ['status' => 'pending_deletion'])
        ];

        $this->notifyUsersByRole('Super Admin', 'account_deletion', $title, $message, $data);
        $this->notifyUsersByRole('Admin', 'account_deletion', $title, $message, $data);
        $this->notifyUsersByRole('IT', 'account_deletion', $title, $message, $data);
    }

    /**
     * Notify admin roles about an account reactivation.
     */
    public function notifyAccountReactivated(string $accountName, int $accountId): void
    {
        $title = 'Account Reactivated';
        $message = "The account '{$accountName}' has been reactivated by the user.";

        $data = [
            'account_name' => $accountName,
            'account_id' => $accountId,
            'link' => route('accounts.index')
        ];

        $this->notifyUsersByRole('Super Admin', 'account_reactivation', $title, $message, $data);
        $this->notifyUsersByRole('Admin', 'account_reactivation', $title, $message, $data);
        $this->notifyUsersByRole('IT', 'account_reactivation', $title, $message, $data);
    }

    /**
     * Notify a user that their account has been restored by an admin.
     */
    public function notifyAccountRestored(User|int $user): Notification
    {
        return $this->create(
            $user,
            'account_restored',
            'Account Restored',
            'Your account has been restored by an administrator. You can now continue using the system.',
            null
        );
    }
}
