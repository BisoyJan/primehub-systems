<?php

namespace App\Policies;

use App\Models\PcTransfer;
use App\Models\User;
use App\Services\PermissionService;

class PcTransferPolicy
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
        return $this->permissionService->userHasPermission($user, 'pc_transfers.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PcTransfer $pcTransfer): bool
    {
        return $this->permissionService->userHasPermission($user, 'pc_transfers.view');
    }

    /**
     * Determine whether the user can create models (transfer PCs).
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'pc_transfers.create');
    }

    /**
     * Determine whether the user can remove PCs from stations.
     */
    public function remove(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'pc_transfers.remove');
    }

    /**
     * Determine whether the user can view transfer history.
     */
    public function history(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'pc_transfers.history');
    }
}
