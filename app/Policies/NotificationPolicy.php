<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;
use App\Services\PermissionService;

class NotificationPolicy
{
    public function __construct(protected PermissionService $permissionService)
    {
    }

    /**
     * Determine whether the user can send notifications.
     */
    public function send(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'notifications.send');
    }

    /**
     * Determine whether the user can send notifications to all users.
     */
    public function sendAll(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'notifications.send_all');
    }
}
