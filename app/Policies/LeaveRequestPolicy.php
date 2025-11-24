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
}
