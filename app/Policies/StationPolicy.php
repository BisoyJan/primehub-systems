<?php

namespace App\Policies;

use App\Models\Station;
use App\Models\User;
use App\Services\PermissionService;

class StationPolicy
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
        return $this->permissionService->userHasPermission($user, 'stations.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Station $station): bool
    {
        return $this->permissionService->userHasPermission($user, 'stations.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'stations.create');
    }

    /**
     * Determine whether the user can bulk create stations.
     */
    public function bulk(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'stations.bulk');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Station $station): bool
    {
        return $this->permissionService->userHasPermission($user, 'stations.edit');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Station $station): bool
    {
        return $this->permissionService->userHasPermission($user, 'stations.delete');
    }

    /**
     * Determine whether the user can generate QR codes.
     */
    public function qrcode(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'stations.qrcode');
    }
}
