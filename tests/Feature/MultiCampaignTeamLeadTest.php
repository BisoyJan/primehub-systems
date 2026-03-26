<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\EmployeeSchedule;
use App\Models\Notification;
use App\Models\Site;
use App\Models\User;
use App\Services\CoachingDashboardService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MultiCampaignTeamLeadTest extends TestCase
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
     * Create a TL assigned to multiple campaigns with agents in each.
     */
    protected function createMultiCampaignTeam(): array
    {
        $campaignA = Campaign::factory()->create(['name' => 'Campaign A']);
        $campaignB = Campaign::factory()->create(['name' => 'Campaign B']);

        $teamLead = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true, 'is_active' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $teamLead->id,
            'campaign_id' => $campaignA->id,
            'is_active' => true,
        ]);
        $teamLead->campaigns()->sync([$campaignA->id, $campaignB->id]);

        $agentA = User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'is_active' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agentA->id,
            'campaign_id' => $campaignA->id,
            'is_active' => true,
        ]);

        $agentB = User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'is_active' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agentB->id,
            'campaign_id' => $campaignB->id,
            'is_active' => true,
        ]);

        return compact('campaignA', 'campaignB', 'teamLead', 'agentA', 'agentB');
    }

    // ─── User Model Tests ────────────────────────────────────────

    #[Test]
    public function user_get_campaign_ids_returns_pivot_campaigns_for_team_lead(): void
    {
        $team = $this->createMultiCampaignTeam();
        $ids = $team['teamLead']->getCampaignIds();

        $this->assertCount(2, $ids);
        $this->assertContains($team['campaignA']->id, $ids);
        $this->assertContains($team['campaignB']->id, $ids);
    }

    #[Test]
    public function user_get_campaign_ids_returns_active_schedule_campaign_for_agent(): void
    {
        $team = $this->createMultiCampaignTeam();
        $ids = $team['agentA']->getCampaignIds();

        $this->assertCount(1, $ids);
        $this->assertEquals($team['campaignA']->id, $ids[0]);
    }

    #[Test]
    public function user_belongs_to_campaign_checks_pivot_for_team_lead(): void
    {
        $team = $this->createMultiCampaignTeam();
        $otherCampaign = Campaign::factory()->create();

        $this->assertTrue($team['teamLead']->belongsToCampaign($team['campaignA']->id));
        $this->assertTrue($team['teamLead']->belongsToCampaign($team['campaignB']->id));
        $this->assertFalse($team['teamLead']->belongsToCampaign($otherCampaign->id));
    }

    #[Test]
    public function campaign_team_leads_relationship_returns_associated_tls(): void
    {
        $team = $this->createMultiCampaignTeam();

        $this->assertTrue($team['campaignA']->teamLeads->contains($team['teamLead']));
        $this->assertTrue($team['campaignB']->teamLeads->contains($team['teamLead']));
    }

    // ─── Employee Schedule Tests ─────────────────────────────────

    #[Test]
    public function store_schedule_syncs_campaign_pivot_for_team_lead(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $teamLead = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->post(route('employee-schedules.store'), [
            'user_id' => $teamLead->id,
            'campaign_id' => $campaignA->id,
            'campaign_ids' => [$campaignA->id, $campaignB->id],
            'site_id' => $site->id,
            'shift_type' => 'night_shift',
            'scheduled_time_in' => '22:00',
            'scheduled_time_out' => '07:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'grace_period_minutes' => 15,
            'effective_date' => now()->format('Y-m-d'),
        ]);

        $response->assertRedirect(route('employee-schedules.index'));
        $this->assertCount(2, $teamLead->fresh()->campaigns);
        $this->assertTrue($teamLead->fresh()->campaigns->contains($campaignA));
        $this->assertTrue($teamLead->fresh()->campaigns->contains($campaignB));
    }

    #[Test]
    public function update_schedule_syncs_campaign_pivot_for_team_lead(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createMultiCampaignTeam();
        $schedule = EmployeeSchedule::where('user_id', $team['teamLead']->id)->first();
        $campaignC = Campaign::factory()->create();

        $response = $this->actingAs($admin)->put(route('employee-schedules.update', $schedule), [
            'campaign_id' => $schedule->campaign_id,
            'campaign_ids' => [$team['campaignA']->id, $campaignC->id],
            'site_id' => $schedule->site_id,
            'shift_type' => $schedule->shift_type,
            'scheduled_time_in' => substr($schedule->scheduled_time_in, 0, 5),
            'scheduled_time_out' => substr($schedule->scheduled_time_out, 0, 5),
            'work_days' => $schedule->work_days,
            'grace_period_minutes' => $schedule->grace_period_minutes,
            'effective_date' => $schedule->effective_date->format('Y-m-d'),
        ]);

        $response->assertRedirect(route('employee-schedules.index'));
        $tl = $team['teamLead']->fresh();
        $this->assertCount(2, $tl->campaigns);
        $this->assertTrue($tl->campaigns->contains($team['campaignA']));
        $this->assertTrue($tl->campaigns->contains($campaignC));
        // Campaign B should be removed since sync replaces
        $this->assertFalse($tl->campaigns->contains($team['campaignB']));
    }

    #[Test]
    public function edit_schedule_passes_user_campaign_ids_for_team_lead(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createMultiCampaignTeam();
        $schedule = EmployeeSchedule::where('user_id', $team['teamLead']->id)->first();

        $response = $this->actingAs($admin)->get(route('employee-schedules.edit', $schedule));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/EmployeeSchedules/Edit')
            ->has('userCampaignIds', 2)
        );
    }

    // ─── Coaching Session Controller Tests ───────────────────────

    #[Test]
    public function tl_index_shows_sessions_across_multiple_campaigns(): void
    {
        $team = $this->createMultiCampaignTeam();

        // Create sessions for agents in both campaigns
        $sessionA = CoachingSession::factory()->create([
            'coach_id' => $team['teamLead']->id,
            'coachee_id' => $team['agentA']->id,
        ]);
        $sessionB = CoachingSession::factory()->create([
            'coach_id' => $team['teamLead']->id,
            'coachee_id' => $team['agentB']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.index', ['tab' => 'team']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 2)
            );
    }

    #[Test]
    public function tl_create_shows_agents_from_all_managed_campaigns(): void
    {
        $team = $this->createMultiCampaignTeam();

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.create'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Create')
                ->has('agents', 2)
            );
    }

    #[Test]
    public function tl_can_store_coaching_session_for_agent_in_second_campaign(): void
    {
        $team = $this->createMultiCampaignTeam();

        $sessionData = [
            'coachee_id' => $team['agentB']->id,
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
            'root_cause_lack_of_clarity' => true,
            'root_cause_personal_issues' => false,
            'root_cause_motivation_engagement' => false,
            'root_cause_health_fatigue' => false,
            'root_cause_workload_process' => false,
            'root_cause_peer_conflict' => false,
            'root_cause_others' => false,
        ];

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), $sessionData);

        $response->assertRedirect();
        $this->assertDatabaseHas('coaching_sessions', [
            'coach_id' => $team['teamLead']->id,
            'coachee_id' => $team['agentB']->id,
        ]);
    }

    // ─── Coaching Dashboard Service Tests ────────────────────────

    #[Test]
    public function dashboard_service_returns_agents_from_all_tl_campaigns(): void
    {
        $team = $this->createMultiCampaignTeam();

        $service = app(CoachingDashboardService::class);
        $data = $service->getTeamLeadDashboardData($team['teamLead']);

        $this->assertEquals(2, $data['total_agents']);
    }

    // ─── Coaching Session Policy Tests ───────────────────────────

    #[Test]
    public function tl_can_view_coaching_session_for_agent_in_second_campaign(): void
    {
        $team = $this->createMultiCampaignTeam();

        $session = CoachingSession::factory()->create([
            'coach_id' => $team['teamLead']->id,
            'coachee_id' => $team['agentB']->id,
        ]);

        $this->assertTrue($team['teamLead']->can('view', $session));
    }

    #[Test]
    public function tl_cannot_view_session_for_agent_in_unassigned_campaign(): void
    {
        $team = $this->createMultiCampaignTeam();
        $otherCampaign = Campaign::factory()->create();
        $otherAgent = User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'is_active' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $otherAgent->id,
            'campaign_id' => $otherCampaign->id,
            'is_active' => true,
        ]);

        // Another TL created this session
        $otherTl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        $session = CoachingSession::factory()->create([
            'coach_id' => $otherTl->id,
            'coachee_id' => $otherAgent->id,
        ]);

        $this->assertFalse($team['teamLead']->can('view', $session));
    }

    // ─── Coaching Dashboard Controller Tests ─────────────────────

    #[Test]
    public function tl_coaching_dashboard_shows_combined_campaign_names(): void
    {
        $team = $this->createMultiCampaignTeam();

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Dashboard/Index')
                ->where('campaignName', fn ($value) => str_contains($value, 'Campaign A') && str_contains($value, 'Campaign B'))
            );
    }
}
