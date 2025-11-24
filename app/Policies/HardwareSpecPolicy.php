<?php

namespace App\Policies;

use App\Models\User;
use App\Services\PermissionService;

class HardwareSpecPolicy
{
    protected PermissionService $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Determine whether the user can view any hardware specs.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'hardware.view');
    }

    /**
     * Determine whether the user can view hardware specs.
     */
    public function view(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'hardware.view');
    }

    /**
     * Determine whether the user can create hardware specs.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'hardware.create');
    }

    /**
     * Determine whether the user can update hardware specs.
     */
    public function update(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'hardware.edit');
    }

    /**
     * Determine whether the user can delete hardware specs.
     */
    public function delete(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'hardware.delete');
    }
}
