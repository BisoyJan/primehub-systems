<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MedicationNotificationCampaignScopeTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService;
    }

    // ==================== notifyUsersByRoleExcluding ====================

    #[Test]
    public function it_notifies_users_by_role_excluding_a_specific_user(): void
    {
        $hr1 = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $hr2 = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $count = $this->service->notifyUsersByRoleExcluding(
            'HR', 'test_type', 'Title', 'Message', null, $hr1->id
        );

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('notifications', ['user_id' => $hr1->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $hr2->id, 'type' => 'test_type']);
    }

    #[Test]
    public function it_notifies_all_users_of_role_when_no_exclusion(): void
    {
        $hr1 = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $hr2 = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $count = $this->service->notifyUsersByRoleExcluding(
            'HR', 'test_type', 'Title', 'Message'
        );

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('notifications', ['user_id' => $hr1->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $hr2->id]);
    }

    // ==================== notifyUsersByRoleAndCampaign ====================

    #[Test]
    public function it_notifies_only_team_leads_in_the_specified_campaign(): void
    {
        $campaign1 = Campaign::factory()->create();
        $campaign2 = Campaign::factory()->create();

        $tl1 = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tl1->id,
            'campaign_id' => $campaign1->id,
            'is_active' => true,
            'effective_date' => now()->subMonth(),
            'end_date' => null,
        ]);

        $tl2 = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tl2->id,
            'campaign_id' => $campaign2->id,
            'is_active' => true,
            'effective_date' => now()->subMonth(),
            'end_date' => null,
        ]);

        $count = $this->service->notifyUsersByRoleAndCampaign(
            'Team Lead', $campaign1->id, 'medication_request', 'Title', 'Message'
        );

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('notifications', ['user_id' => $tl1->id, 'type' => 'medication_request']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $tl2->id]);
    }

    #[Test]
    public function it_excludes_user_from_campaign_scoped_notification(): void
    {
        $campaign = Campaign::factory()->create();

        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tl->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
            'effective_date' => now()->subMonth(),
            'end_date' => null,
        ]);

        $count = $this->service->notifyUsersByRoleAndCampaign(
            'Team Lead', $campaign->id, 'medication_request', 'Title', 'Message', null, $tl->id
        );

        $this->assertEquals(0, $count);
        $this->assertDatabaseMissing('notifications', ['user_id' => $tl->id]);
    }

    #[Test]
    public function it_returns_zero_when_no_team_lead_exists_in_campaign(): void
    {
        $campaign = Campaign::factory()->create();

        $count = $this->service->notifyUsersByRoleAndCampaign(
            'Team Lead', $campaign->id, 'medication_request', 'Title', 'Message'
        );

        $this->assertEquals(0, $count);
        $this->assertDatabaseCount('notifications', 0);
    }

    // ==================== notifyHrRolesAboutNewMedicationRequest ====================

    #[Test]
    public function agent_with_campaign_notifies_only_campaign_team_lead(): void
    {
        $campaign = Campaign::factory()->create();
        $otherCampaign = Campaign::factory()->create();

        // Agent in campaign
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
            'effective_date' => now()->subMonth(),
            'end_date' => null,
        ]);

        // TL in same campaign
        $tlInCampaign = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tlInCampaign->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
            'effective_date' => now()->subMonth(),
            'end_date' => null,
        ]);

        // TL in different campaign
        $tlOtherCampaign = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tlOtherCampaign->id,
            'campaign_id' => $otherCampaign->id,
            'is_active' => true,
            'effective_date' => now()->subMonth(),
            'end_date' => null,
        ]);

        // HR + Super Admin to receive global notifications
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutNewMedicationRequest(
            $agent->name, 'Biogesic', 1, $agent->id, 'Agent', $campaign->id
        );

        // Campaign TL notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $tlInCampaign->id,
            'type' => 'medication_request',
        ]);

        // Other campaign TL NOT notified
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $tlOtherCampaign->id,
        ]);

        // HR and Super Admin still notified globally
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'medication_request']);
    }

    #[Test]
    public function agent_without_campaign_notifies_all_team_leads(): void
    {
        $campaign = Campaign::factory()->create();

        // Agent with no schedule
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        // Two TLs
        $tl1 = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tl1->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
            'effective_date' => now()->subMonth(),
            'end_date' => null,
        ]);

        $tl2 = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);

        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutNewMedicationRequest(
            $agent->name, 'Biogesic', 1, $agent->id, 'Agent', null
        );

        // Both TLs notified (fallback)
        $this->assertDatabaseHas('notifications', ['user_id' => $tl1->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $tl2->id, 'type' => 'medication_request']);

        // HR notified
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'medication_request']);
    }

    #[Test]
    public function agent_with_campaign_but_no_team_lead_still_notifies_super_admin(): void
    {
        $campaign = Campaign::factory()->create();

        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
            'effective_date' => now()->subMonth(),
            'end_date' => null,
        ]);

        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutNewMedicationRequest(
            $agent->name, 'Biogesic', 1, $agent->id, 'Agent', $campaign->id
        );

        // No TL to notify, but Super Admin and HR still get global notifications
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'medication_request']);
    }

    #[Test]
    public function team_lead_requesting_skips_team_lead_notification(): void
    {
        $campaign = Campaign::factory()->create();

        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tl->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
            'effective_date' => now()->subMonth(),
            'end_date' => null,
        ]);

        $anotherTl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutNewMedicationRequest(
            $tl->name, 'Biogesic', 1, $tl->id, 'Team Lead', $campaign->id
        );

        // No Team Lead should be notified (skipped for non-agent roles)
        $this->assertDatabaseMissing('notifications', ['user_id' => $tl->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $anotherTl->id]);

        // HR and Super Admin notified
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'medication_request']);
    }

    #[Test]
    public function hr_requesting_skips_team_lead_and_excludes_self(): void
    {
        $hrRequester = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $hrOther = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutNewMedicationRequest(
            $hrRequester->name, 'Biogesic', 1, $hrRequester->id, 'HR', null
        );

        // TL not notified (non-agent role)
        $this->assertDatabaseMissing('notifications', ['user_id' => $tl->id]);

        // Requester HR excluded
        $this->assertDatabaseMissing('notifications', ['user_id' => $hrRequester->id]);

        // Other HR, Admin, Super Admin notified
        $this->assertDatabaseHas('notifications', ['user_id' => $hrOther->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'medication_request']);
    }

    #[Test]
    public function admin_requesting_excludes_self_from_admin_notifications(): void
    {
        $adminRequester = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $adminOther = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutNewMedicationRequest(
            $adminRequester->name, 'Biogesic', 1, $adminRequester->id, 'Admin', null
        );

        // Requester excluded
        $this->assertDatabaseMissing('notifications', ['user_id' => $adminRequester->id]);

        // Other Admin, HR, Super Admin notified
        $this->assertDatabaseHas('notifications', ['user_id' => $adminOther->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'medication_request']);
    }

    #[Test]
    public function super_admin_requesting_excludes_self(): void
    {
        $saRequester = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $saOther = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutNewMedicationRequest(
            $saRequester->name, 'Biogesic', 1, $saRequester->id, 'Super Admin', null
        );

        // Requester excluded
        $this->assertDatabaseMissing('notifications', ['user_id' => $saRequester->id]);

        // Other Super Admin + HR notified
        $this->assertDatabaseHas('notifications', ['user_id' => $saOther->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'medication_request']);
    }

    #[Test]
    public function it_user_requesting_notifies_hr_admin_superadmin_not_team_lead(): void
    {
        $itUser = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutNewMedicationRequest(
            $itUser->name, 'Biogesic', 1, $itUser->id, 'IT', null
        );

        // TL not notified (non-agent role)
        $this->assertDatabaseMissing('notifications', ['user_id' => $tl->id]);

        // IT requester not excluded (not in HR/Admin/Super Admin role)
        // HR, Admin, Super Admin notified
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'medication_request']);
    }

    #[Test]
    public function backward_compatible_with_no_extra_parameters(): void
    {
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);

        // Call with only the original 3 parameters (backward compat)
        $this->service->notifyHrRolesAboutNewMedicationRequest(
            'John Doe', 'Biogesic', 1
        );

        // Default is Agent with no campaign: all TLs + HR + Admin + Super Admin
        $this->assertDatabaseHas('notifications', ['user_id' => $tl->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'medication_request']);
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'medication_request']);
    }
}
