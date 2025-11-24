<?php

namespace App\Policies;

use App\Models\BiometricRecord;
use App\Models\User;
use App\Services\PermissionService;

class BiometricRecordPolicy
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
        return $this->permissionService->userHasPermission($user, 'biometric.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, BiometricRecord $biometricRecord): bool
    {
        return $this->permissionService->userHasPermission($user, 'biometric.view');
    }

    /**
     * Determine whether the user can reprocess biometric data.
     */
    public function reprocess(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'biometric.reprocess');
    }

    /**
     * Determine whether the user can view anomalies.
     */
    public function anomalies(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'biometric.anomalies');
    }

    /**
     * Determine whether the user can export biometric data.
     */
    public function export(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'biometric.export');
    }

    /**
     * Determine whether the user can manage retention policies.
     */
    public function retention(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'biometric.retention');
    }
}
