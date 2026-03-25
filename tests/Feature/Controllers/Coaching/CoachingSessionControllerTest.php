<?php

namespace Tests\Feature\Controllers\Coaching;

use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\EmployeeSchedule;
use App\Models\Notification;
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
                ->andReturn(\Mockery::mock(Notification::class));
            $mock->shouldReceive('notifyCoachingAcknowledged')
                ->andReturn(\Mockery::mock(Notification::class));
            $mock->shouldReceive('notifyAdminsCoachingReadyForReview');
            $mock->shouldReceive('notifyCoachingReviewed')
                ->andReturn(\Mockery::mock(Notification::class));
            $mock->shouldReceive('notifyCoacheeCoachingReviewed')
                ->andReturn(\Mockery::mock(Notification::class));
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
    protected function validSessionData(int $coacheeId): array
    {
        return [
            'coachee_id' => $coacheeId,
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
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
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
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
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
                ->where('isAdmin', false)
                ->has('teamLeads', 0)
            );
    }

    #[Test]
    public function admin_can_view_create_form_with_team_leads(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($admin)->get(route('coaching.sessions.create'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Create')
                ->has('agents')
                ->has('teamLeads')
                ->where('isAdmin', true)
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
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
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
            'coachee_id', 'session_date', 'purpose',
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

    #[Test]
    public function admin_can_create_session_in_assign_mode(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        $data = $this->validSessionData($team['agent']->id);
        $data['coaching_mode'] = 'assign';
        $data['coach_id'] = $team['teamLead']->id;

        $response = $this->actingAs($admin)
            ->post(route('coaching.sessions.store'), $data);

        $response->assertRedirect(route('coaching.sessions.index'));

        $this->assertDatabaseHas('coaching_sessions', [
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'ack_status' => 'Pending',
        ]);
    }

    #[Test]
    public function admin_store_requires_coach_id_in_assign_mode(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        $data = $this->validSessionData($team['agent']->id);
        $data['coaching_mode'] = 'assign';
        // Do not include coach_id

        $response = $this->actingAs($admin)
            ->post(route('coaching.sessions.store'), $data);

        $response->assertSessionHasErrors('coach_id');
    }

    #[Test]
    public function store_rejects_agent_not_in_team_lead_campaign(): void
    {
        $team = $this->createTeamWithCampaign();

        // Create agent in a different campaign
        $otherCampaign = Campaign::factory()->create();
        $otherAgent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $otherAgent->id,
            'campaign_id' => $otherCampaign->id,
            'is_active' => true,
        ]);

        $data = $this->validSessionData($otherAgent->id);

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), $data);

        $response->assertSessionHasErrors('coachee_id');
    }

    // ─── Show Tests ─────────────────────────────────────────────────

    #[Test]
    public function agent_can_view_own_coaching_session_detail(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
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
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
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
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
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
            'coach_id' => $team['teamLead']->id,
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
            'coachee_id' => $team['agent']->id,
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
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'ack_status' => 'Pending',
            'compliance_status' => 'Awaiting_Agent_Ack',
        ]);

        $response = $this->actingAs($team['agent'])
            ->patch(route('coaching.sessions.acknowledge', $session), [
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
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['agent'])
            ->patch(route('coaching.sessions.acknowledge', $session));

        $response->assertStatus(403);
    }

    #[Test]
    public function team_lead_cannot_acknowledge_on_behalf_of_agent(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'ack_status' => 'Pending',
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->patch(route('coaching.sessions.acknowledge', $session));

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

    // ─── Notification Tests ─────────────────────────────────────────

    #[Test]
    public function acknowledge_notifies_admins_for_review(): void
    {
        $notificationMock = $this->mock(NotificationService::class);
        $notificationMock->shouldReceive('notifyCoachingAcknowledged')
            ->once()
            ->andReturn(\Mockery::mock(Notification::class));
        $notificationMock->shouldReceive('notifyAdminsCoachingReadyForReview')
            ->once();

        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'ack_status' => 'Pending',
            'compliance_status' => 'Awaiting_Agent_Ack',
        ]);

        $this->actingAs($team['agent'])
            ->patch(route('coaching.sessions.acknowledge', $session), [
                'ack_comment' => 'Noted.',
            ]);
    }

    #[Test]
    public function review_verify_notifies_coach_and_coachee(): void
    {
        $notificationMock = $this->mock(NotificationService::class);
        $notificationMock->shouldReceive('notifyCoachingReviewed')
            ->once()
            ->andReturn(\Mockery::mock(Notification::class));
        $notificationMock->shouldReceive('notifyCoacheeCoachingReviewed')
            ->once()
            ->andReturn(\Mockery::mock(Notification::class));

        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        // Factory default creates TL as coach — TL should be notified
        $session = CoachingSession::factory()->acknowledged()->create();

        $this->actingAs($hr)
            ->patch(route('coaching.sessions.review', $session), [
                'compliance_status' => 'Verified',
            ]);
    }

    #[Test]
    public function review_reject_notifies_coach_and_coachee(): void
    {
        $notificationMock = $this->mock(NotificationService::class);
        $notificationMock->shouldReceive('notifyCoachingReviewed')
            ->once()
            ->andReturn(\Mockery::mock(Notification::class));
        $notificationMock->shouldReceive('notifyCoacheeCoachingReviewed')
            ->once()
            ->andReturn(\Mockery::mock(Notification::class));

        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $session = CoachingSession::factory()->acknowledged()->create();

        $this->actingAs($hr)
            ->patch(route('coaching.sessions.review', $session), [
                'compliance_status' => 'Rejected',
                'compliance_notes' => 'Needs revision.',
            ]);
    }

    #[Test]
    public function review_does_not_notify_admin_coach_only_coachee(): void
    {
        $notificationMock = $this->mock(NotificationService::class);
        // Coach is admin — should NOT receive notifyCoachingReviewed
        $notificationMock->shouldReceive('notifyCoachingReviewed')
            ->never();
        $notificationMock->shouldReceive('notifyCoacheeCoachingReviewed')
            ->once()
            ->andReturn(\Mockery::mock(Notification::class));

        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);

        $session = CoachingSession::factory()->acknowledged()->create([
            'coach_id' => $admin->id,
            'coachee_id' => $tl->id,
        ]);

        $this->actingAs($hr)
            ->patch(route('coaching.sessions.review', $session), [
                'compliance_status' => 'Verified',
            ]);
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

    // ─── Admin Direct Coaching (TL as Coachee) Tests ────────────────

    #[Test]
    public function admin_create_form_passes_coachable_team_leads_and_coaching_mode(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $this->createTeamWithCampaign();

        $response = $this->actingAs($admin)
            ->get(route('coaching.sessions.create', ['coaching_mode' => 'direct']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Create')
                ->has('coachableTeamLeads')
                ->where('coachingMode', 'direct')
                ->where('isAdmin', true)
            );
    }

    #[Test]
    public function admin_can_create_direct_coaching_session_for_team_lead(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        $data = $this->validSessionData($team['teamLead']->id);
        $data['coaching_mode'] = 'direct';

        $response = $this->actingAs($admin)
            ->post(route('coaching.sessions.store'), $data);

        $response->assertRedirect(route('coaching.sessions.index'));

        $this->assertDatabaseHas('coaching_sessions', [
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
            'ack_status' => 'Pending',
        ]);
    }

    #[Test]
    public function admin_direct_mode_sets_self_as_coach(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        $data = $this->validSessionData($team['teamLead']->id);
        $data['coaching_mode'] = 'direct';

        $this->actingAs($admin)
            ->post(route('coaching.sessions.store'), $data);

        $session = CoachingSession::latest('id')->first();
        $this->assertEquals($admin->id, $session->coach_id);
        $this->assertEquals($team['teamLead']->id, $session->coachee_id);
    }

    #[Test]
    public function team_lead_cannot_create_direct_coaching_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $otherTl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);

        $data = $this->validSessionData($otherTl->id);
        $data['coaching_mode'] = 'direct';

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), $data);

        // TL should not be able to use direct mode — coaching_mode is nullable for non-admins
        // The TL store flow uses assign mode; coachee must be in their campaign
        $this->assertDatabaseMissing('coaching_sessions', [
            'coachee_id' => $otherTl->id,
        ]);
    }

    // ─── Coachee Role Filter Tests ──────────────────────────────────

    #[Test]
    public function admin_can_filter_sessions_by_coachee_role_agent(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        // Session with agent coachee
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        // Session with TL coachee (admin coaching a TL)
        $otherTl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $otherTl->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('coaching.sessions.index', ['coachee_role' => 'Agent']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 1)
                ->where('sessions.data.0.coachee_id', $team['agent']->id)
                ->where('filters.coachee_role', 'Agent')
            );
    }

    #[Test]
    public function admin_can_filter_sessions_by_coachee_role_team_lead(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        // Session with agent coachee
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        // Session with TL coachee
        $otherTl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $otherTl->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('coaching.sessions.index', ['coachee_role' => 'Team Lead']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 1)
                ->where('sessions.data.0.coachee_id', $otherTl->id)
                ->where('filters.coachee_role', 'Team Lead')
            );
    }

    #[Test]
    public function admin_without_coachee_role_filter_returns_all_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $otherTl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $otherTl->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 2)
            );
    }

    #[Test]
    public function non_admin_coachee_role_filter_is_ignored(): void
    {
        $team = $this->createTeamWithCampaign();

        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        // TL tries to use coachee_role filter — should be ignored
        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.index', ['coachee_role' => 'Agent']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 1)
            );
    }

    // ─── Team Lead as Coachee Tests ─────────────────────────────────

    #[Test]
    public function team_lead_can_view_session_where_they_are_coachee(): void
    {
        $team = $this->createTeamWithCampaign();
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        // Admin coaches the Team Lead directly
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.show', $session));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Show')
                ->has('session')
            );
    }

    #[Test]
    public function team_lead_can_acknowledge_session_where_they_are_coachee(): void
    {
        $team = $this->createTeamWithCampaign();
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
            'ack_status' => 'Pending',
            'compliance_status' => 'Awaiting_Agent_Ack',
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->patch(route('coaching.sessions.acknowledge', $session), [
                'ack_status' => 'Acknowledged',
            ]);

        $response->assertRedirect();
        $session->refresh();
        $this->assertEquals('Acknowledged', $session->ack_status);
    }

    #[Test]
    public function team_lead_sees_team_sessions_on_default_tab(): void
    {
        $team = $this->createTeamWithCampaign();
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        // Session where TL coaches agent — should appear on team tab
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        // Session where admin coaches TL — should NOT appear on team tab
        CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 1)
                ->where('activeTab', 'team')
                ->has('pendingAckCount')
            );
    }

    #[Test]
    public function team_lead_sees_own_sessions_on_my_tab(): void
    {
        $team = $this->createTeamWithCampaign();
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        // Session where TL coaches agent — should NOT appear on my tab
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        // Session where admin coaches TL — should appear on my tab
        CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.index', ['tab' => 'my']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 1)
                ->where('activeTab', 'my')
                ->where('pendingAckCount', 1)
                ->has('agentSummary')
            );
    }

    #[Test]
    public function team_lead_pending_ack_count_reflects_unacknowledged(): void
    {
        $team = $this->createTeamWithCampaign();
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
            'ack_status' => 'Pending',
        ]);
        CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
            'ack_status' => 'Acknowledged',
        ]);
        CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
            'ack_status' => 'Pending',
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingAckCount', 2)
            );
    }

    #[Test]
    public function admin_sees_all_sessions_on_default_tab(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        CoachingSession::factory()->count(3)->create();

        $response = $this->actingAs($admin)
            ->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 3)
                ->where('activeTab', 'all')
                ->has('pendingReviewCount')
            );
    }

    #[Test]
    public function admin_sees_only_for_review_sessions_on_needs_review_tab(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        CoachingSession::factory()->create(['compliance_status' => 'For_Review']);
        CoachingSession::factory()->create(['compliance_status' => 'Verified']);
        CoachingSession::factory()->create(['compliance_status' => 'For_Review']);

        $response = $this->actingAs($admin)
            ->get(route('coaching.sessions.index', ['tab' => 'needs_review']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 2)
                ->where('activeTab', 'needs_review')
                ->where('pendingReviewCount', 2)
            );
    }

    #[Test]
    public function admin_pending_review_count_reflects_for_review_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        CoachingSession::factory()->create(['compliance_status' => 'For_Review']);
        CoachingSession::factory()->create(['compliance_status' => 'Awaiting_Agent_Ack']);
        CoachingSession::factory()->create(['compliance_status' => 'For_Review']);

        $response = $this->actingAs($admin)
            ->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingReviewCount', 2)
            );
    }
}
