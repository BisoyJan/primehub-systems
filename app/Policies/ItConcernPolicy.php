<?php

namespace App\Policies;

use App\Models\ItConcern;
use App\Models\User;
use App\Services\PermissionService;

class ItConcernPolicy
{
    protected PermissionService $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Determine whether the user can view any models.
     * Users with create permission can view the list (they'll see only their own concerns)
     * Users with view permission can see all concerns
     */
    public function viewAny(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'it_concerns.view')
            || $this->permissionService->userHasPermission($user, 'it_concerns.create');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ItConcern $itConcern): bool
    {
        // Users can always view their own concerns
        if ($itConcern->user_id === $user->id) {
            return true;
        }

        // Assigned users can view concerns assigned to them
        if ($itConcern->assigned_to === $user->id) {
            return true;
        }

        // Others need view permission
        return $this->permissionService->userHasPermission($user, 'it_concerns.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'it_concerns.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ItConcern $itConcern): bool
    {
        // Users can edit their own concerns if status is pending or in_progress
        if ($itConcern->user_id === $user->id) {
            return in_array($itConcern->status, ['pending', 'in_progress']);
        }

        // Others need edit permission
        return $this->permissionService->userHasPermission($user, 'it_concerns.edit');
    }

    /**
     * Determine whether the user can assign concerns.
     */
    public function assign(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'it_concerns.assign');
    }

    /**
     * Determine whether the user can resolve concerns.
     */
    public function resolve(User $user, ItConcern $itConcern): bool
    {
        return $this->permissionService->userHasPermission($user, 'it_concerns.resolve');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ItConcern $itConcern): bool
    {
        // Users can delete their own concerns if status is pending
        if ($itConcern->user_id === $user->id) {
            return $itConcern->status === 'pending';
        }

        return $this->permissionService->userHasPermission($user, 'it_concerns.delete');
    }
}
