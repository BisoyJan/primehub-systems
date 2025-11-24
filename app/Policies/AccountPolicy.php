<?php

namespace App\Policies;

use App\Models\User;
use App\Services\PermissionService;

class AccountPolicy
{
    protected PermissionService $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Determine whether the user can view any accounts.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'accounts.view');
    }

    /**
     * Determine whether the user can view an account.
     */
    public function view(User $user, User $account): bool
    {
        // Users can always view their own account
        if ($user->id === $account->id) {
            return true;
        }

        return $this->permissionService->userHasPermission($user, 'accounts.view');
    }

    /**
     * Determine whether the user can create accounts.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'accounts.create');
    }

    /**
     * Determine whether the user can update an account.
     */
    public function update(User $user, User $account): bool
    {
        return $this->permissionService->userHasPermission($user, 'accounts.edit');
    }

    /**
     * Determine whether the user can delete an account.
     */
    public function delete(User $user, User $account): bool
    {
        // Cannot delete yourself
        if ($user->id === $account->id) {
            return false;
        }

        return $this->permissionService->userHasPermission($user, 'accounts.delete');
    }
}
