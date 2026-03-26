<?php

namespace App\Policies;

use App\Models\CoachingSession;
use App\Models\User;
use App\Services\PermissionService;

class CoachingSessionPolicy
{
    protected PermissionService $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Determine whether the user can view any coaching sessions.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissionService->userHasAnyPermission($user, [
            'coaching.view_own',
            'coaching.view_team',
            'coaching.view_all',
        ]);
    }

    /**
     * Determine whether the user can view the coaching session.
     */
    public function view(User $user, CoachingSession $coachingSession): bool
    {
        // Coachee can view their own sessions
        if ($coachingSession->coachee_id === $user->id) {
            return $this->permissionService->userHasPermission($user, 'coaching.view_own');
        }

        // Coach can view sessions they created
        if ($coachingSession->coach_id === $user->id) {
            return $this->permissionService->userHasPermission($user, 'coaching.view_team');
        }

        // Team Lead can view sessions for coachees on their campaigns
        if ($user->role === 'Team Lead') {
            $teamLeadCampaignIds = $user->getCampaignIds();
            if (! empty($teamLeadCampaignIds)) {
                $coacheeCampaignId = $coachingSession->coachee?->activeSchedule?->campaign_id;
                if ($coacheeCampaignId && in_array($coacheeCampaignId, $teamLeadCampaignIds)) {
                    return $this->permissionService->userHasPermission($user, 'coaching.view_team');
                }
            }
        }

        // Admin/HR/Super Admin can view all
        return $this->permissionService->userHasPermission($user, 'coaching.view_all');
    }

    /**
     * Determine whether the user can create coaching sessions.
     */
    public function create(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'coaching.create');
    }

    /**
     * Determine whether the user can update the coaching session.
     */
    public function update(User $user, CoachingSession $coachingSession): bool
    {
        // Only the coach who created the session can edit it
        if ($coachingSession->coach_id !== $user->id) {
            // Unless they have admin-level access
            if (! in_array($user->role, ['Super Admin', 'Admin'])) {
                return false;
            }
        }

        return $this->permissionService->userHasPermission($user, 'coaching.edit');
    }

    /**
     * Determine whether the user can delete the coaching session.
     */
    public function delete(User $user, CoachingSession $coachingSession): bool
    {
        return $this->permissionService->userHasPermission($user, 'coaching.delete');
    }

    /**
     * Determine whether the user can acknowledge the coaching session.
     */
    public function acknowledge(User $user, CoachingSession $coachingSession): bool
    {
        // Only the coachee of this session can acknowledge
        if ($coachingSession->coachee_id !== $user->id) {
            return false;
        }

        // Must be in Pending status
        if ($coachingSession->ack_status !== 'Pending') {
            return false;
        }

        return $this->permissionService->userHasPermission($user, 'coaching.acknowledge');
    }

    /**
     * Determine whether the user can review (verify/reject) the coaching session.
     */
    public function review(User $user, CoachingSession $coachingSession): bool
    {
        // Session must be in For_Review status
        if ($coachingSession->compliance_status !== 'For_Review') {
            return false;
        }

        return $this->permissionService->userHasPermission($user, 'coaching.review');
    }

    /**
     * Determine whether the user can export coaching logs.
     */
    public function export(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'coaching.export');
    }

    /**
     * Determine whether the user can manage coaching settings.
     */
    public function manageSettings(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'coaching.settings');
    }
}
