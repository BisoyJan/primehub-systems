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

        // Team Leads can view any agent leave requests that require TL approval
        if ($user->role === 'Team Lead' && $leaveRequest->requiresTlApproval()) {
            return true;
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
        // Super Admin and Admin can update approved leaves (for date changes)
        if (in_array($user->role, ['Super Admin', 'Admin'])) {
            // Can update pending or approved leaves
            if (in_array($leaveRequest->status, ['pending', 'approved'])) {
                return $this->permissionService->userHasPermission($user, 'leave.edit');
            }
        }

        // For non-admins, only pending requests can be updated
        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        // Users can update their own pending requests
        if ($leaveRequest->user_id === $user->id) {
            return true;
        }

        // HR with edit permission can update any pending request
        if ($this->permissionService->userHasPermission($user, 'leave.edit') &&
            $user->role === 'HR') {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can update approved leave dates.
     * Only Super Admin and Admin can do this.
     */
    public function updateApproved(User $user, LeaveRequest $leaveRequest): bool
    {
        // Only approved leaves can have their dates changed
        if ($leaveRequest->status !== 'approved') {
            return false;
        }

        // Cannot update if the leave end date has already passed
        if ($leaveRequest->end_date && $leaveRequest->end_date->endOfDay()->isPast()) {
            return false;
        }

        // Only Super Admin and Admin can update approved leaves
        return in_array($user->role, ['Super Admin', 'Admin']) &&
               $this->permissionService->userHasPermission($user, 'leave.edit');
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
     * Determine whether the Team Lead can approve/deny a leave request from any agent.
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

        // Any Team Lead can approve agent leave requests
        return true;
    }

    /**
     * Determine whether the user can cancel the leave request.
     */
    public function cancel(User $user, LeaveRequest $leaveRequest): bool
    {
        // Cannot cancel if the leave end date has already passed
        if ($leaveRequest->end_date && $leaveRequest->end_date->endOfDay()->isPast()) {
            return false;
        }

        // Super Admin and Admin can cancel ANY pending or approved request
        if (in_array($user->role, ['Super Admin', 'Admin'])) {
            if (in_array($leaveRequest->status, ['pending', 'approved'])) {
                return $this->permissionService->userHasPermission($user, 'leave.cancel');
            }
        }

        // For employees: Only pending requests can be cancelled directly
        // For approved requests, only if start date is in the future
        if ($leaveRequest->status === 'pending') {
            // Users can cancel their own pending requests if they have permission
            if ($leaveRequest->user_id === $user->id) {
                return $this->permissionService->userHasPermission($user, 'leave.cancel');
            }
        }

        // HR with cancel permission can cancel any pending request
        if ($leaveRequest->status === 'pending' &&
            $this->permissionService->userHasPermission($user, 'leave.cancel') &&
            $user->role === 'HR') {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can cancel an approved leave request.
     * Only Super Admin and Admin can cancel approved leaves.
     */
    public function cancelApproved(User $user, LeaveRequest $leaveRequest): bool
    {
        // Only approved leaves can be cancelled with this method
        if ($leaveRequest->status !== 'approved') {
            return false;
        }

        // Cannot cancel if the leave end date has already passed
        if ($leaveRequest->end_date && $leaveRequest->end_date->endOfDay()->isPast()) {
            return false;
        }

        // Only Super Admin and Admin can cancel approved leaves
        return in_array($user->role, ['Super Admin', 'Admin']) &&
               $this->permissionService->userHasPermission($user, 'leave.cancel');
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
