<?php

namespace App\Policies;

use App\Models\AttendancePoint;
use App\Models\User;
use App\Services\PermissionService;

class AttendancePointPolicy
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
        return $this->permissionService->userHasPermission($user, 'attendance_points.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AttendancePoint $attendancePoint): bool
    {
        // Restricted roles can only view their own points
        $restrictedRoles = ['Agent', 'IT', 'Utility'];
        if (in_array($user->role, $restrictedRoles)) {
            return $attendancePoint->user_id === $user->id;
        }

        return $this->permissionService->userHasPermission($user, 'attendance_points.view');
    }

    /**
     * Determine whether the user can create manual attendance points.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance_points.create');
    }

    /**
     * Determine whether the user can update manual attendance points.
     */
    public function update(User $user, AttendancePoint $attendancePoint): bool
    {
        // Can only edit manual entries
        if (!$attendancePoint->is_manual) {
            return false;
        }

        return $this->permissionService->userHasPermission($user, 'attendance_points.edit');
    }

    /**
     * Determine whether the user can delete manual attendance points.
     */
    public function delete(User $user, AttendancePoint $attendancePoint): bool
    {
        // Can only delete manual entries
        if (!$attendancePoint->is_manual) {
            return false;
        }

        return $this->permissionService->userHasPermission($user, 'attendance_points.delete');
    }

    /**
     * Determine whether the user can excuse attendance points.
     */
    public function excuse(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance_points.excuse');
    }

    /**
     * Determine whether the user can export attendance points.
     */
    public function export(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance_points.export');
    }

    /**
     * Determine whether the user can rescan attendance points.
     */
    public function rescan(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'attendance_points.rescan');
    }
}
