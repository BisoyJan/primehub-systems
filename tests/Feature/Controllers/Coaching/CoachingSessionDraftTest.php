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

class CoachingSessionDraftTest extends TestCase
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

    protected function createTeamWithCampaign(): array
    {
        $campaign = Campaign::factory()->create();

        $teamLead = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $teamLead->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);
        $teamLead->campaigns()->sync([$campaign->id]);

        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return compact('campaign', 'teamLead', 'agent');
    }

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

    // ─── Store Draft Tests ──────────────────────────────────────────

    #[Test]
    public function team_lead_can_save_coaching_session_as_draft(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.draft'), [
                'coachee_id' => $team['agent']->id,
                'session_date' => now()->format('Y-m-d'),
            ]);

        $session = CoachingSession::latest('id')->first();
        $response->assertRedirect(route('coaching.sessions.show', $session));

        $this->assertDatabaseHas('coaching_sessions', [
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'is_draft' => true,
            'submitted_at' => null,
        ]);
    }

    #[Test]
    public function draft_can_be_saved_with_minimal_data(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.draft'), [
                'coachee_id' => $team['agent']->id,
            ]);

        $session = CoachingSession::latest('id')->first();
        $response->assertRedirect(route('coaching.sessions.show', $session));

        $this->assertDatabaseHas('coaching_sessions', [
            'id' => $session->id,
            'is_draft' => true,
            'purpose' => null,
            'performance_description' => null,
            'smart_action_plan' => null,
        ]);
    }

    #[Test]
    public function draft_can_be_saved_with_full_data(): void
    {
        $team = $this->createTeamWithCampaign();
        $data = $this->validSessionData($team['agent']->id);

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.draft'), $data);

        $session = CoachingSession::latest('id')->first();
        $response->assertRedirect(route('coaching.sessions.show', $session));

        $this->assertDatabaseHas('coaching_sessions', [
            'id' => $session->id,
            'is_draft' => true,
            'submitted_at' => null,
            'purpose' => 'performance_behavior_issue',
        ]);
    }

    #[Test]
    public function draft_requires_coachee_id(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.draft'), []);

        $response->assertSessionHasErrors('coachee_id');
    }

    #[Test]
    public function agent_cannot_save_draft(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['agent'])
            ->post(route('coaching.sessions.draft'), [
                'coachee_id' => $team['agent']->id,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function admin_can_save_draft(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($admin)
            ->post(route('coaching.sessions.draft'), [
                'coachee_id' => $team['agent']->id,
                'coaching_mode' => 'assign',
                'coach_id' => $team['teamLead']->id,
            ]);

        $session = CoachingSession::latest('id')->first();
        $response->assertRedirect(route('coaching.sessions.show', $session));

        $this->assertDatabaseHas('coaching_sessions', [
            'id' => $session->id,
            'is_draft' => true,
            'coach_id' => $team['teamLead']->id,
        ]);
    }

    #[Test]
    public function admin_can_save_direct_draft(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($admin)
            ->post(route('coaching.sessions.draft'), [
                'coachee_id' => $team['teamLead']->id,
                'coaching_mode' => 'direct',
            ]);

        $session = CoachingSession::latest('id')->first();
        $response->assertRedirect(route('coaching.sessions.show', $session));

        $this->assertDatabaseHas('coaching_sessions', [
            'id' => $session->id,
            'is_draft' => true,
            'coach_id' => $admin->id,
            'coachee_id' => $team['teamLead']->id,
        ]);
    }

    #[Test]
    public function store_draft_does_not_send_notification(): void
    {
        $notificationMock = $this->mock(NotificationService::class);
        $notificationMock->shouldReceive('notifyCoachingSessionCreated')->never();

        $team = $this->createTeamWithCampaign();

        $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.draft'), [
                'coachee_id' => $team['agent']->id,
            ]);
    }

    // ─── Submit Draft Tests ─────────────────────────────────────────

    #[Test]
    public function team_lead_can_submit_draft_with_full_data(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $data = $this->validSessionData($team['agent']->id);

        $response = $this->actingAs($team['teamLead'])
            ->patch(route('coaching.sessions.submit', $session), $data);

        $response->assertRedirect(route('coaching.sessions.show', $session));

        $session->refresh();
        $this->assertFalse($session->is_draft);
        $this->assertNotNull($session->submitted_at);
        $this->assertEquals('performance_behavior_issue', $session->purpose);
    }

    #[Test]
    public function submit_draft_validates_required_fields(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->patch(route('coaching.sessions.submit', $session), [
                'coachee_id' => $team['agent']->id,
            ]);

        $response->assertSessionHasErrors([
            'purpose', 'performance_description', 'smart_action_plan',
        ]);
    }

    #[Test]
    public function cannot_submit_already_submitted_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'is_draft' => false,
            'submitted_at' => now(),
        ]);

        $data = $this->validSessionData($team['agent']->id);

        $response = $this->actingAs($team['teamLead'])
            ->patch(route('coaching.sessions.submit', $session), $data);

        $response->assertSessionHas('type', 'error');
    }

    #[Test]
    public function agent_cannot_submit_draft(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $data = $this->validSessionData($team['agent']->id);

        $response = $this->actingAs($team['agent'])
            ->patch(route('coaching.sessions.submit', $session), $data);

        $response->assertStatus(403);
    }

    #[Test]
    public function other_team_lead_cannot_submit_another_coaches_draft(): void
    {
        $team = $this->createTeamWithCampaign();

        $session = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        // Create another TL on the same campaign so coachee_id validation passes
        $otherTl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $otherTl->id,
            'campaign_id' => $team['campaign']->id,
            'is_active' => true,
        ]);
        $otherTl->campaigns()->sync([$team['campaign']->id]);

        $data = $this->validSessionData($team['agent']->id);

        $response = $this->actingAs($otherTl)
            ->patch(route('coaching.sessions.submit', $session), $data);

        $response->assertStatus(403);
    }

    #[Test]
    public function submit_draft_sends_notification_to_coachee(): void
    {
        $notificationMock = $this->mock(NotificationService::class);
        $notificationMock->shouldReceive('notifyCoachingSessionCreated')
            ->once()
            ->andReturn(\Mockery::mock(Notification::class));

        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $data = $this->validSessionData($team['agent']->id);

        $this->actingAs($team['teamLead'])
            ->patch(route('coaching.sessions.submit', $session), $data);
    }

    // ─── Draft Visibility Tests ─────────────────────────────────────

    #[Test]
    public function drafts_are_not_visible_in_default_index_listing(): void
    {
        $team = $this->createTeamWithCampaign();

        // Submitted session
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        // Draft session
        CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 1)
            );
    }

    #[Test]
    public function team_lead_can_view_own_drafts_on_drafts_tab(): void
    {
        $team = $this->createTeamWithCampaign();

        CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        // Another TL's draft — should not appear
        $otherTeam = $this->createTeamWithCampaign();
        CoachingSession::factory()->draft()->create([
            'coachee_id' => $otherTeam['agent']->id,
            'coach_id' => $otherTeam['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.index', ['tab' => 'drafts']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 1)
            );
    }

    #[Test]
    public function draft_count_is_passed_to_index(): void
    {
        $team = $this->createTeamWithCampaign();

        CoachingSession::factory()->draft()->count(3)->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('draftCount', 3)
            );
    }

    #[Test]
    public function agent_does_not_see_draft_sessions(): void
    {
        $team = $this->createTeamWithCampaign();

        $draft = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['agent'])
            ->get(route('coaching.sessions.show', $draft));

        $response->assertStatus(403);
    }

    #[Test]
    public function coach_can_view_own_draft_detail(): void
    {
        $team = $this->createTeamWithCampaign();

        $draft = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.show', $draft));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Show')
                ->where('session.is_draft', true)
                ->where('canSubmitDraft', true)
            );
    }

    #[Test]
    public function admin_can_view_any_draft(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        $draft = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('coaching.sessions.show', $draft));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Show')
                ->where('session.is_draft', true)
            );
    }

    // ─── Draft Policy Guard Tests ───────────────────────────────────

    #[Test]
    public function agent_cannot_acknowledge_draft_session(): void
    {
        $team = $this->createTeamWithCampaign();

        $draft = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'ack_status' => 'Pending',
            'compliance_status' => 'Awaiting_Agent_Ack',
        ]);

        $response = $this->actingAs($team['agent'])
            ->patch(route('coaching.sessions.acknowledge', $draft), [
                'ack_comment' => 'Trying to ack a draft.',
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function hr_cannot_review_draft_session(): void
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $draft = CoachingSession::factory()->draft()->create();

        $response = $this->actingAs($hr)
            ->patch(route('coaching.sessions.review', $draft), [
                'compliance_status' => 'Verified',
            ]);

        $response->assertStatus(403);
    }

    // ─── Edit Draft Tests ───────────────────────────────────────────

    #[Test]
    public function team_lead_can_edit_own_draft(): void
    {
        $team = $this->createTeamWithCampaign();
        $draft = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.edit', $draft));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Edit')
                ->where('session.is_draft', true)
            );
    }

    #[Test]
    public function team_lead_can_update_draft_and_keep_as_draft(): void
    {
        $team = $this->createTeamWithCampaign();
        $draft = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'purpose' => null,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->put(route('coaching.sessions.update', $draft), [
                'session_date' => now()->format('Y-m-d'),
                'purpose' => 'recognition_appreciation',
                'performance_description' => 'Updated description for the agent.',
                'smart_action_plan' => 'Updated plan: keep up the great work.',
                'profile_tenured' => true,
                'focus_productivity' => true,
                'root_cause_lack_of_skills' => true,
            ]);

        $response->assertRedirect(route('coaching.sessions.show', $draft));

        $draft->refresh();
        $this->assertTrue($draft->is_draft);
        $this->assertEquals('recognition_appreciation', $draft->purpose);
    }

    // ─── Delete Draft Tests ─────────────────────────────────────────

    #[Test]
    public function team_lead_can_delete_own_draft(): void
    {
        $team = $this->createTeamWithCampaign();
        $draft = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->delete(route('coaching.sessions.destroy', $draft));

        $response->assertRedirect(route('coaching.sessions.index'));
        $this->assertDatabaseMissing('coaching_sessions', ['id' => $draft->id]);
    }

    #[Test]
    public function admin_can_delete_any_draft(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        $draft = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($admin)
            ->delete(route('coaching.sessions.destroy', $draft));

        $response->assertRedirect(route('coaching.sessions.index'));
        $this->assertDatabaseMissing('coaching_sessions', ['id' => $draft->id]);
    }

    // ─── Auto-Save Draft Tests ──────────────────────────────────────

    #[Test]
    public function auto_save_creates_new_draft_and_returns_json(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['teamLead'])
            ->postJson(route('coaching.sessions.auto-save-draft'), [
                'coachee_id' => $team['agent']->id,
                'session_date' => now()->format('Y-m-d'),
                'purpose' => 'performance_behavior_issue',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['draft_id', 'saved_at']);

        $draftId = $response->json('draft_id');
        $this->assertDatabaseHas('coaching_sessions', [
            'id' => $draftId,
            'is_draft' => true,
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);
    }

    #[Test]
    public function auto_save_updates_existing_draft(): void
    {
        $team = $this->createTeamWithCampaign();
        $draft = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'purpose' => 'performance_behavior_issue',
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->postJson(route('coaching.sessions.auto-save-draft'), [
                'coachee_id' => $team['agent']->id,
                'draft_id' => $draft->id,
                'purpose' => 'recognition_appreciation',
                'performance_description' => 'Updated via auto-save.',
            ]);

        $response->assertStatus(200)
            ->assertJson(['draft_id' => $draft->id]);

        $draft->refresh();
        $this->assertEquals('recognition_appreciation', $draft->purpose);
        $this->assertEquals('Updated via auto-save.', $draft->performance_description);
    }

    #[Test]
    public function auto_save_requires_coachee_id(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['teamLead'])
            ->postJson(route('coaching.sessions.auto-save-draft'), []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('coachee_id');
    }

    #[Test]
    public function auto_save_cannot_update_other_coaches_draft(): void
    {
        $team = $this->createTeamWithCampaign();
        $otherTeam = $this->createTeamWithCampaign();

        $draft = CoachingSession::factory()->draft()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($otherTeam['teamLead'])
            ->postJson(route('coaching.sessions.auto-save-draft'), [
                'coachee_id' => $otherTeam['agent']->id,
                'draft_id' => $draft->id,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function auto_save_cannot_update_non_draft_session(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'is_draft' => false,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->postJson(route('coaching.sessions.auto-save-draft'), [
                'coachee_id' => $team['agent']->id,
                'draft_id' => $session->id,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function agent_cannot_auto_save_draft(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['agent'])
            ->postJson(route('coaching.sessions.auto-save-draft'), [
                'coachee_id' => $team['agent']->id,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function auto_save_with_minimal_data_creates_draft(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['teamLead'])
            ->postJson(route('coaching.sessions.auto-save-draft'), [
                'coachee_id' => $team['agent']->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['draft_id', 'saved_at']);

        $this->assertDatabaseHas('coaching_sessions', [
            'id' => $response->json('draft_id'),
            'is_draft' => true,
            'purpose' => null,
            'performance_description' => null,
        ]);
    }
}
