<?php

namespace App\Policies;

use App\Models\BreakSession;
use App\Models\User;
use App\Services\PermissionService;

class BreakSessionPolicy
{
    public function __construct(protected PermissionService $permissionService) {}

    public function viewAny(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'break_timer.view');
    }

    public function view(User $user, BreakSession $breakSession): bool
    {
        return $this->permissionService->userHasPermission($user, 'break_timer.view');
    }

    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'break_timer.use');
    }

    public function dashboard(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'break_timer.dashboard');
    }

    public function reports(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'break_timer.reports');
    }

    public function managePolicy(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'break_timer.manage_policy');
    }

    public function reset(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'break_timer.reset');
    }
}
