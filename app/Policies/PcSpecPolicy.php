<?php

namespace App\Policies;

use App\Models\PcSpec;
use App\Models\User;
use App\Services\PermissionService;

class PcSpecPolicy
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
        return $this->permissionService->userHasPermission($user, 'pcspecs.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PcSpec $pcSpec): bool
    {
        return $this->permissionService->userHasPermission($user, 'pcspecs.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'pcspecs.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PcSpec $pcSpec): bool
    {
        return $this->permissionService->userHasPermission($user, 'pcspecs.edit');
    }

    /**
     * Determine whether the user can update PC issues.
     */
    public function updateIssue(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'pcspecs.update_issue');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PcSpec $pcSpec): bool
    {
        return $this->permissionService->userHasPermission($user, 'pcspecs.delete');
    }

    /**
     * Determine whether the user can generate QR codes.
     */
    public function qrcode(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'pcspecs.qrcode');
    }
}
