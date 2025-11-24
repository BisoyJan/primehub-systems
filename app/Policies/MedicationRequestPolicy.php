<?php

namespace App\Policies;

use App\Models\MedicationRequest;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Auth\Access\Response;

class MedicationRequestPolicy
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
        return $this->permissionService->userHasPermission($user, 'medication_requests.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, MedicationRequest $medicationRequest): bool
    {
        // Users can view their own requests
        if ($medicationRequest->user_id === $user->id) {
            return true;
        }

        // Admins and users with update permission can view all requests
        return $this->permissionService->userHasPermission($user, 'medication_requests.update');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'medication_requests.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, MedicationRequest $medicationRequest): bool
    {
        // Only users with update permission can change status
        return $this->permissionService->userHasPermission($user, 'medication_requests.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MedicationRequest $medicationRequest): bool
    {
        // Users can delete their own pending requests
        if ($medicationRequest->user_id === $user->id && $medicationRequest->status === 'pending') {
            return true;
        }

        // Admins with delete permission can delete any request
        return $this->permissionService->userHasPermission($user, 'medication_requests.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, MedicationRequest $medicationRequest): bool
    {
        return $this->permissionService->userHasPermission($user, 'medication_requests.delete');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, MedicationRequest $medicationRequest): bool
    {
        return $this->permissionService->userHasPermission($user, 'medication_requests.delete');
    }
}
