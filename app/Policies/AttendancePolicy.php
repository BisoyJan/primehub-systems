<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Services\PermissionService;

class AttendancePolicy
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
        return $this->permissionService->userHasPermission($user, 'attendance.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Attendance $attendance): bool
    {
        // Restricted roles can only view their own attendance
        $restrictedRoles = ['Agent', 'IT', 'Utility'];
        if (in_array($user->role, $restrictedRoles)) {
            return $attendance->user_id === $user->id;
        }

        return $this->permissionService->userHasPermission($user, 'attendance.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance.create');
    }

    /**
     * Determine whether the user can import attendance data.
     */
    public function import(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance.import');
    }

    /**
     * Determine whether the user can review attendance.
     */
    public function review(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance.review');
    }

    /**
     * Determine whether the user can verify attendance.
     */
    public function verify(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance.verify');
    }

    /**
     * Determine whether the user can approve attendance.
     */
    public function approve(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance.approve');
    }

    /**
     * Determine whether the user can view statistics.
     */
    public function statistics(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance.statistics');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Attendance $attendance): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance.delete');
    }

    /**
     * Determine whether the user can request undertime approval.
     */
    public function requestUndertimeApproval(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance.request_undertime_approval');
    }

    /**
     * Determine whether the user can approve/reject undertime requests.
     */
    public function approveUndertime(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance.approve_undertime');
    }
}
