<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\PermissionService;

class LeaveRequestPolicy
{
    protected PermissionService $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'leave.view');
    }

    /**
     * Determine whether the user can view all leave requests.
     */
    public function viewAll(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'leave.view_all');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        // Users can always view their own requests
        if ($leaveRequest->user_id === $user->id) {
            return true;
        }

        // Team Leads can view leave requests from agents in their campaign
        if ($user->role === 'Team Lead') {
            $teamLeadSchedule = $user->activeSchedule;
            $agentSchedule = $leaveRequest->user->activeSchedule;

            if ($teamLeadSchedule && $agentSchedule && $teamLeadSchedule->campaign_id === $agentSchedule->campaign_id) {
                return true;
            }
        }

        // Others need view_all permission
        return $this->permissionService->userHasPermission($user, 'leave.view_all');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'leave.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        // Only pending requests can be updated
        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        // Users can update their own pending requests
        if ($leaveRequest->user_id === $user->id) {
            return true;
        }

        // Admins/HR with edit permission can update any pending request
        if ($this->permissionService->userHasPermission($user, 'leave.edit') &&
            in_array($user->role, ['Super Admin', 'Admin', 'HR'])) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can approve leave requests.
     */
    public function approve(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'leave.approve');
    }

    /**
     * Determine whether the user can deny leave requests.
     */
    public function deny(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'leave.deny');
    }

    /**
     * Determine whether the Team Lead can approve/deny a leave request from their campaign agent.
     */
    public function tlApprove(User $user, LeaveRequest $leaveRequest): bool
    {
        // Only Team Leads can use this method
        if ($user->role !== 'Team Lead') {
            return false;
        }

        // The leave request must require TL approval
        if (!$leaveRequest->requiresTlApproval()) {
            return false;
        }

        // The leave request must not already be processed by TL
        if ($leaveRequest->isTlApproved() || $leaveRequest->isTlRejected()) {
            return false;
        }

        // The leave request must be pending
        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        // Check if Team Lead is in the same campaign as the agent
        $teamLeadSchedule = $user->activeSchedule;
        $agentSchedule = $leaveRequest->user->activeSchedule;

        if (!$teamLeadSchedule || !$agentSchedule) {
            return false;
        }

        // Team Lead must be in the same campaign as the agent
        return $teamLeadSchedule->campaign_id === $agentSchedule->campaign_id;
    }

    /**
     * Determine whether the user can cancel the leave request.
     */
    public function cancel(User $user, LeaveRequest $leaveRequest): bool
    {
        // Only pending requests can be cancelled
        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        // Users can cancel their own pending requests if they have permission
        if ($leaveRequest->user_id === $user->id) {
            return $this->permissionService->userHasPermission($user, 'leave.cancel');
        }

        // Admins/HR with cancel permission can cancel any pending request
        if ($this->permissionService->userHasPermission($user, 'leave.cancel') &&
            in_array($user->role, ['Super Admin', 'Admin', 'HR'])) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the leave request.
     */
    public function delete(User $user, LeaveRequest $leaveRequest): bool
    {
        // Only admins/HR with delete permission can delete leave requests
        if (!$this->permissionService->userHasPermission($user, 'leave.delete')) {
            return false;
        }

        // Only Super Admin, Admin, and HR can delete
        return in_array($user->role, ['Super Admin', 'Admin', 'HR']);
    }
}
