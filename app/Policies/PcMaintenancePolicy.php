<?php

namespace App\Policies;

use App\Models\PcMaintenance;
use App\Models\User;
use App\Services\PermissionService;

class PcMaintenancePolicy
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
        return $this->permissionService->userHasPermission($user, 'pc_maintenance.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PcMaintenance $pcMaintenance): bool
    {
        return $this->permissionService->userHasPermission($user, 'pc_maintenance.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'pc_maintenance.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PcMaintenance $pcMaintenance): bool
    {
        return $this->permissionService->userHasPermission($user, 'pc_maintenance.edit');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PcMaintenance $pcMaintenance): bool
    {
        return $this->permissionService->userHasPermission($user, 'pc_maintenance.delete');
    }
}
