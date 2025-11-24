<?php

namespace App\Policies;

use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\PermissionService;

class EmployeeSchedulePolicy
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
        return $this->permissionService->userHasPermission($user, 'schedules.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, EmployeeSchedule $employeeSchedule): bool
    {
        return $this->permissionService->userHasPermission($user, 'schedules.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'schedules.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, EmployeeSchedule $employeeSchedule): bool
    {
        return $this->permissionService->userHasPermission($user, 'schedules.edit');
    }

    /**
     * Determine whether the user can toggle schedule status.
     */
    public function toggle(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'schedules.toggle');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, EmployeeSchedule $employeeSchedule): bool
    {
        return $this->permissionService->userHasPermission($user, 'schedules.delete');
    }
}
