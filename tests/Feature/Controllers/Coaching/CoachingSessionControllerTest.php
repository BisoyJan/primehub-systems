<?php

namespace Tests\Feature\Controllers\Coaching;

use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CoachingSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyCoachingSessionCreated')
                ->andReturn(\Mockery::mock(\App\Models\Notification::class));
            $mock->shouldReceive('notifyCoachingAcknowledged')
                ->andReturn(\Mockery::mock(\App\Models\Notification::class));
            $mock->shouldReceive('notifyCoachingReviewed')
                ->andReturn(\Mockery::mock(\App\Models\Notification::class));
        });
    }

    /**
     * Helper to create a TL + Agent on the same campaign.
     */
    protected function createTeamWithCampaign(): array
    {
        $campaign = Campaign::factory()->create();

        $teamLead = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $teamLead->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return compact('campaign', 'teamLead', 'agent');
    }

    /**
     * Helper to build valid session data for store requests.
     */
    protected function validSessionData(int $agentId): array
    {
        return [
            'agent_id' => $agentId,
            'session_date' => now()->format('Y-m-d'),
            'purpose' => 'performance_behavior_issue',
            'performance_description' => 'The agent has been consistently late for the past week.',
            'smart_action_plan' => 'Agent will set alarms and arrive 10 minutes early for the next 2 weeks.',
            'profile_new_hire' => false,
            'profile_tenured' => true,
            'profile_returning' => false,
            'profile_previously_coached_same_issue' => false,
            'focus_attendance_tardiness' => true,
            'focus_productivity' => false,
            'focus_compliance' => false,
            'focus_callouts' => false,
            'focus_recognition_milestones' => false,
            'focus_growth_development' => false,
            'focus_other' => false,
            'root_cause_lack_of_skills' => false,
            'root_cause_lack_of_clarity' => false,
            'root_cause_personal_issues' => true,
            'root_cause_motivation_engagement' => false,
            'root_cause_health_fatigue' => false,
            'root_cause_workload_process' => false,
            'root_cause_peer_conflict' => false,
            'root_cause_others' => false,
        ];
    }

    // ─── Index Tests ────────────────────────────────────────────────

    #[Test]
    public function agent_can_view_own_coaching_sessions(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
        ]);

        // Create another session for a different agent — should not appear
        CoachingSession::factory()->create();

        $response = $this->actingAs($team['agent'])->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 1)
                ->where('sessions.data.0.id', $session->id)
                ->where('isAgent', true)
            );
    }

    #[Test]
    public function team_lead_can_view_team_coaching_sessions(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 1)
                ->where('isTeamLead', true)
            );
    }

    #[Test]
    public function admin_can_view_all_coaching_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        CoachingSession::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 3)
                ->where('isAdmin', true)
            );
    }

    // ─── Create Tests ───────────────────────────────────────────────

    #[Test]
    public function team_lead_can_view_create_form(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['teamLead'])->get(route('coaching.sessions.create'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Create')
                ->has('agents')
                ->has('purposes')
            );
    }

    #[Test]
    public function agent_cannot_view_create_form(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['agent'])->get(route('coaching.sessions.create'));

        $response->assertStatus(403);
    }

    // ─── Store Tests ────────────────────────────────────────────────

    #[Test]
    public function team_lead_can_create_coaching_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $data = $this->validSessionData($team['agent']->id);

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), $data);

        $response->assertRedirect(route('coaching.sessions.index'));

        $this->assertDatabaseHas('coaching_sessions', [
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
            'ack_status' => 'Pending',
            'compliance_status' => 'Awaiting_Agent_Ack',
            'purpose' => 'performance_behavior_issue',
        ]);
    }

    #[Test]
    public function agent_cannot_create_coaching_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $data = $this->validSessionData($team['agent']->id);

        $response = $this->actingAs($team['agent'])
            ->post(route('coaching.sessions.store'), $data);

        $response->assertStatus(403);
    }

    #[Test]
    public function store_validates_required_fields(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), []);

        $response->assertSessionHasErrors([
            'agent_id', 'session_date', 'purpose',
            'performance_description', 'smart_action_plan',
        ]);
    }

    #[Test]
    public function store_rejects_future_session_date(): void
    {
        $team = $this->createTeamWithCampaign();
        $data = $this->validSessionData($team['agent']->id);
        $data['session_date'] = now()->addDays(5)->format('Y-m-d');

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), $data);

        $response->assertSessionHasErrors('session_date');
    }

    // ─── Show Tests ─────────────────────────────────────────────────

    #[Test]
    public function agent_can_view_own_coaching_session_detail(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['agent'])
            ->get(route('coaching.sessions.show', $session));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Show')
                ->where('session.id', $session->id)
            );
    }

    #[Test]
    public function agent_cannot_view_other_agents_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create();

        $response = $this->actingAs($team['agent'])
            ->get(route('coaching.sessions.show', $session));

        $response->assertStatus(403);
    }

    // ─── Update Tests ───────────────────────────────────────────────

    #[Test]
    public function team_lead_can_update_own_coaching_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
        ]);

        $data = [
            'session_date' => now()->format('Y-m-d'),
            'purpose' => 'recognition_appreciation',
            'performance_description' => 'Updated description for the agent performance review.',
            'smart_action_plan' => 'Updated plan: Continue doing great work and mentor others.',
        ];

        $response = $this->actingAs($team['teamLead'])
            ->put(route('coaching.sessions.update', $session), $data);

        $response->assertRedirect(route('coaching.sessions.show', $session));

        $this->assertDatabaseHas('coaching_sessions', [
            'id' => $session->id,
            'purpose' => 'recognition_appreciation',
        ]);
    }

    #[Test]
    public function other_team_lead_cannot_update_session(): void
    {
        $session = CoachingSession::factory()->create();
        $otherTeam = $this->createTeamWithCampaign();

        $data = [
            'session_date' => now()->format('Y-m-d'),
            'purpose' => 'recognition_appreciation',
            'performance_description' => 'Attempt to update someone else session.',
            'smart_action_plan' => 'Should not be allowed to update this session.',
        ];

        $response = $this->actingAs($otherTeam['teamLead'])
            ->put(route('coaching.sessions.update', $session), $data);

        $response->assertStatus(403);
    }

    #[Test]
    public function agent_cannot_update_coaching_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['agent'])
            ->put(route('coaching.sessions.update', $session), [
                'session_date' => now()->format('Y-m-d'),
                'purpose' => 'recognition_appreciation',
                'performance_description' => 'Agent should not edit.',
                'smart_action_plan' => 'This should be forbidden.',
            ]);

        $response->assertStatus(403);
    }

    // ─── Delete Tests ───────────────────────────────────────────────

    #[Test]
    public function admin_can_delete_coaching_session(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $session = CoachingSession::factory()->create();

        $response = $this->actingAs($admin)
            ->delete(route('coaching.sessions.destroy', $session));

        $response->assertRedirect(route('coaching.sessions.index'));
        $this->assertDatabaseMissing('coaching_sessions', ['id' => $session->id]);
    }

    #[Test]
    public function team_lead_cannot_delete_coaching_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'team_lead_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->delete(route('coaching.sessions.destroy', $session));

        $response->assertStatus(403);
        $this->assertDatabaseHas('coaching_sessions', ['id' => $session->id]);
    }

    #[Test]
    public function agent_cannot_delete_coaching_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'agent_id' => $team['agent']->id,
        ]);

        $response = $this->actingAs($team['agent'])
            ->delete(route('coaching.sessions.destroy', $session));

        $response->assertStatus(403);
    }

    // ─── Acknowledge Tests ──────────────────────────────────────────

    #[Test]
    public function agent_can_acknowledge_pending_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
            'ack_status' => 'Pending',
            'compliance_status' => 'Awaiting_Agent_Ack',
        ]);

        $response = $this->actingAs($team['agent'])
            ->patch(route('coaching.sessions.acknowledge', $session), [
                'acknowledged' => true,
                'ack_comment' => 'I understand the action plan.',
            ]);

        $response->assertRedirect(route('coaching.sessions.show', $session));

        $this->assertDatabaseHas('coaching_sessions', [
            'id' => $session->id,
            'ack_status' => 'Acknowledged',
            'compliance_status' => 'For_Review',
            'ack_comment' => 'I understand the action plan.',
        ]);
    }

    #[Test]
    public function agent_cannot_acknowledge_already_acknowledged_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->acknowledged()->create([
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['agent'])
            ->patch(route('coaching.sessions.acknowledge', $session), [
                'acknowledged' => true,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function team_lead_cannot_acknowledge_on_behalf_of_agent(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
            'ack_status' => 'Pending',
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->patch(route('coaching.sessions.acknowledge', $session), [
                'acknowledged' => true,
            ]);

        $response->assertStatus(403);
    }

    // ─── Review Tests ───────────────────────────────────────────────

    #[Test]
    public function hr_can_verify_session_in_for_review_status(): void
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $session = CoachingSession::factory()->acknowledged()->create();

        $response = $this->actingAs($hr)
            ->patch(route('coaching.sessions.review', $session), [
                'compliance_status' => 'Verified',
            ]);

        $response->assertRedirect(route('coaching.sessions.index'));

        $this->assertDatabaseHas('coaching_sessions', [
            'id' => $session->id,
            'compliance_status' => 'Verified',
            'compliance_reviewer_id' => $hr->id,
        ]);
    }

    #[Test]
    public function hr_can_reject_session_with_notes(): void
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $session = CoachingSession::factory()->acknowledged()->create();

        $response = $this->actingAs($hr)
            ->patch(route('coaching.sessions.review', $session), [
                'compliance_status' => 'Rejected',
                'compliance_notes' => 'Incomplete action plan. Please revise.',
            ]);

        $response->assertRedirect(route('coaching.sessions.index'));

        $this->assertDatabaseHas('coaching_sessions', [
            'id' => $session->id,
            'compliance_status' => 'Rejected',
            'compliance_notes' => 'Incomplete action plan. Please revise.',
        ]);
    }

    #[Test]
    public function review_rejects_when_no_notes_provided_for_rejection(): void
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $session = CoachingSession::factory()->acknowledged()->create();

        $response = $this->actingAs($hr)
            ->patch(route('coaching.sessions.review', $session), [
                'compliance_status' => 'Rejected',
                // no compliance_notes
            ]);

        $response->assertSessionHasErrors('compliance_notes');
    }

    #[Test]
    public function cannot_review_session_not_in_for_review_status(): void
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $session = CoachingSession::factory()->create([
            'ack_status' => 'Pending',
            'compliance_status' => 'Awaiting_Agent_Ack',
        ]);

        $response = $this->actingAs($hr)
            ->patch(route('coaching.sessions.review', $session), [
                'compliance_status' => 'Verified',
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function agent_cannot_review_coaching_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->acknowledged()->create();

        $response = $this->actingAs($team['agent'])
            ->patch(route('coaching.sessions.review', $session), [
                'compliance_status' => 'Verified',
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function team_lead_cannot_review_coaching_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->acknowledged()->create();

        $response = $this->actingAs($team['teamLead'])
            ->patch(route('coaching.sessions.review', $session), [
                'compliance_status' => 'Verified',
            ]);

        $response->assertStatus(403);
    }

    // ─── Filter Tests ───────────────────────────────────────────────

    #[Test]
    public function sessions_can_be_filtered_by_ack_status(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        CoachingSession::factory()->create(['ack_status' => 'Pending']);
        CoachingSession::factory()->acknowledged()->create();

        $response = $this->actingAs($admin)
            ->get(route('coaching.sessions.index', ['ack_status' => 'Pending']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions.data', 1)
                ->where('sessions.data.0.ack_status', 'Pending')
            );
    }
}
