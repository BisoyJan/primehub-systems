<?php

namespace App\Policies;

use App\Models\Stock;
use App\Models\User;
use App\Services\PermissionService;

class StockPolicy
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
        return $this->permissionService->userHasPermission($user, 'stocks.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Stock $stock): bool
    {
        return $this->permissionService->userHasPermission($user, 'stocks.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'stocks.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Stock $stock): bool
    {
        return $this->permissionService->userHasPermission($user, 'stocks.edit');
    }

    /**
     * Determine whether the user can adjust stock levels.
     */
    public function adjust(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'stocks.adjust');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Stock $stock): bool
    {
        return $this->permissionService->userHasPermission($user, 'stocks.delete');
    }
}
