<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Notification;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests for the Agent -> Team Lead notification routing on leave
 * request submission.
 *
 * Bug: When a TL handled multiple campaigns, the previous schedule-based
 * lookup only saw the TL's *single* active schedule campaign, so agents in
 * the other campaigns this TL handled were treated as having no TL and
 * the request was forwarded directly to HR/Admin instead of the TL.
 */
class LeaveRequestTeamLeadRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function createAgentInCampaign(Campaign $campaign): User
    {
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        EmployeeSchedule::factory()->create([
            'user_id' => $agent->id,
            'site_id' => Site::factory()->create()->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return $agent;
    }

    private function createTeamLead(array $campaigns, ?Campaign $activeScheduleCampaign = null): User
    {
        $tl = User::factory()->create([
            'role' => 'Team Lead',
            'is_approved' => true,
        ]);

        // Assign campaigns through the campaign_user pivot (source of truth)
        $tl->campaigns()->sync(collect($campaigns)->pluck('id')->all());

        // Personal employee_schedules row only stores ONE campaign id
        if ($activeScheduleCampaign) {
            EmployeeSchedule::factory()->create([
                'user_id' => $tl->id,
                'site_id' => Site::factory()->create()->id,
                'campaign_id' => $activeScheduleCampaign->id,
                'is_active' => true,
            ]);
        }

        return $tl;
    }

    private function payload(): array
    {
        // LOA: simplest leave type — no credit/eligibility blocking validations.
        $start = now()->addDays(3)->toDateString();
        $end = now()->addDays(4)->toDateString();

        return [
            'leave_type' => 'LOA',
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Family matters that need immediate attention.',
            'campaign_department' => 'Sales',
        ];
    }

    #[Test]
    public function agent_request_notifies_tl_when_tl_handles_multiple_campaigns_via_pivot(): void
    {
        Mail::fake();

        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();

        // TL manages BOTH A & B (pivot), but TL's own active schedule sits in A.
        // Agent is in B — the previously-buggy edge case.
        $tl = $this->createTeamLead([$campaignA, $campaignB], activeScheduleCampaign: $campaignA);
        $agent = $this->createAgentInCampaign($campaignB);

        // Add an HR user we DON'T expect to be notified at submission time.
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $response = $this->actingAs($agent)
            ->post(route('leave-requests.store'), $this->payload());

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $agent->id,
            'requires_tl_approval' => true,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tl->id,
            'type' => 'leave_request',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $hr->id,
            'type' => 'leave_request',
        ]);
    }

    #[Test]
    public function agent_request_falls_back_to_hr_when_no_tl_assigned_to_campaign(): void
    {
        Mail::fake();

        $campaign = Campaign::factory()->create();
        $agent = $this->createAgentInCampaign($campaign);

        // HR exists; no Team Lead assigned to the agent's campaign at all.
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $response = $this->actingAs($agent)
            ->post(route('leave-requests.store'), $this->payload());

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $agent->id,
            'requires_tl_approval' => false,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $hr->id,
            'type' => 'leave_request',
        ]);
    }

    #[Test]
    public function only_tls_assigned_to_agents_campaign_are_notified(): void
    {
        Mail::fake();

        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();

        // TL #1 handles A only. TL #2 handles A & B. Agent is in B.
        $tlOnlyA = $this->createTeamLead([$campaignA], activeScheduleCampaign: $campaignA);
        $tlBoth = $this->createTeamLead([$campaignA, $campaignB], activeScheduleCampaign: $campaignA);

        $agent = $this->createAgentInCampaign($campaignB);

        $this->actingAs($agent)
            ->post(route('leave-requests.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tlBoth->id,
            'type' => 'leave_request',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $tlOnlyA->id,
            'type' => 'leave_request',
        ]);

        $this->assertSame(
            1,
            Notification::where('type', 'leave_request')->count(),
            'Exactly one TL (the one assigned to the agent\'s campaign) should be notified.'
        );
    }
}
