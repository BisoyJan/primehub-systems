<?php

namespace App\Policies;

use App\Models\SocialGroup;
use App\Models\SocialGroupMember;
use App\Models\User;
use App\Services\PermissionService;

class SocialGroupPolicy
{
    public function __construct(protected PermissionService $permissionService) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'social_space.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SocialGroup $socialGroup): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($socialGroup->is_archived) {
            return $this->permissionService->userHasPermission($user, 'social_space.moderate');
        }

        if ($socialGroup->visibility === SocialGroup::VISIBILITY_PUBLIC) {
            return true;
        }

        if ($socialGroup->visibility === SocialGroup::VISIBILITY_CAMPAIGN && $socialGroup->campaign_id) {
            return $user->belongsToCampaign((int) $socialGroup->campaign_id);
        }

        return $socialGroup->isMember($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'social_space.create_group');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SocialGroup $socialGroup): bool
    {
        if ($this->permissionService->userHasPermission($user, 'social_space.moderate')) {
            return true;
        }

        return $socialGroup->memberRecords()
            ->where('user_id', $user->id)
            ->whereIn('role', [SocialGroupMember::ROLE_OWNER, SocialGroupMember::ROLE_ADMIN])
            ->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SocialGroup $socialGroup): bool
    {
        if ($this->permissionService->userHasPermission($user, 'social_space.moderate')) {
            return true;
        }

        return $socialGroup->memberRecords()
            ->where('user_id', $user->id)
            ->where('role', SocialGroupMember::ROLE_OWNER)
            ->exists();
    }

    public function sendMessage(User $user, SocialGroup $socialGroup): bool
    {
        if (! $this->permissionService->userHasPermission($user, 'social_space.message')) {
            return false;
        }

        return $this->view($user, $socialGroup);
    }

    public function manageInvites(User $user, SocialGroup $socialGroup): bool
    {
        if (! $this->permissionService->userHasPermission($user, 'social_space.manage_group')) {
            return false;
        }

        return $this->update($user, $socialGroup);
    }

    public function manageMembers(User $user, SocialGroup $socialGroup): bool
    {
        if (! $this->permissionService->userHasPermission($user, 'social_space.manage_group')) {
            return false;
        }

        return $this->update($user, $socialGroup);
    }

    public function transferOwnership(User $user, SocialGroup $socialGroup): bool
    {
        if (! $this->permissionService->userHasPermission($user, 'social_space.manage_group')) {
            return false;
        }

        if ($this->permissionService->userHasPermission($user, 'social_space.moderate')) {
            return true;
        }

        return $socialGroup->memberRecords()
            ->where('user_id', $user->id)
            ->where('role', SocialGroupMember::ROLE_OWNER)
            ->exists();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SocialGroup $socialGroup): bool
    {
        return $this->permissionService->userHasPermission($user, 'social_space.moderate');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SocialGroup $socialGroup): bool
    {
        return $this->permissionService->userHasPermission($user, 'social_space.moderate');
    }
}
