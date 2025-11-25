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
            ['status' => $status, 'type' => $leaveType, 'request_id' => $requestId]
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
}
