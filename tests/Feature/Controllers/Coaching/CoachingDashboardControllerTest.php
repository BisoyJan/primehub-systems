<?php

namespace Tests\Feature\Controllers\Coaching;

use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\EmployeeSchedule;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\CoachingStatusSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CoachingDashboardControllerTest extends TestCase
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
            $mock->shouldReceive('notifyCoachingReviewed')
                ->andReturn(\Mockery::mock(Notification::class));
        });

        // Seed default coaching status settings
        $this->seed(CoachingStatusSettingSeeder::class);
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

        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return compact('campaign', 'teamLead', 'agent');
    }

    // ─── Agent Dashboard ────────────────────────────────────────────

    #[Test]
    public function agent_sees_own_coaching_dashboard(): void
    {
        $team = $this->createTeamWithCampaign();
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['agent'])
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/MyCoachingLogs/Index')
                ->has('summary')
                ->has('sessions.data', 1)
                ->has('pendingSessions')
            );
    }

    // ─── Team Lead Dashboard ────────────────────────────────────────

    #[Test]
    public function team_lead_sees_team_coaching_dashboard(): void
    {
        $team = $this->createTeamWithCampaign();
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Dashboard/Index')
                ->has('dashboardData')
                ->has('recentSessions')
                ->has('campaignName')
                ->has('statusColors')
            );
    }

    #[Test]
    public function team_lead_dashboard_no_longer_includes_personal_coaching_data(): void
    {
        $team = $this->createTeamWithCampaign();
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Dashboard/Index')
                ->missing('myCoachingData')
                ->missing('myCoachingSessions')
            );
    }

    #[Test]
    public function team_lead_dashboard_includes_upcoming_follow_ups(): void
    {
        $team = $this->createTeamWithCampaign();

        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'session_date' => now()->subDays(30),
            'follow_up_date' => now()->addDays(2),
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Dashboard/Index')
                ->has('upcomingFollowUps')
                ->has('overdueFollowUps')
                ->has('upcomingFollowUps.0', fn (Assert $item) => $item
                    ->has('id')
                    ->has('agent_name')
                    ->has('team_lead_name')
                    ->has('follow_up_date')
                    ->has('purpose_label')
                    ->has('session_date')
                )
            );
    }

    #[Test]
    public function team_lead_dashboard_includes_overdue_follow_ups(): void
    {
        $team = $this->createTeamWithCampaign();

        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'session_date' => now()->subDays(30),
            'follow_up_date' => now()->subDays(3),
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Dashboard/Index')
                ->has('overdueFollowUps', 1)
                ->has('overdueFollowUps.0', fn (Assert $item) => $item
                    ->has('id')
                    ->has('agent_name')
                    ->has('team_lead_name')
                    ->has('follow_up_date')
                    ->has('purpose_label')
                    ->has('session_date')
                )
            );
    }

    // ─── Compliance/Admin Dashboard ─────────────────────────────────

    #[Test]
    public function admin_sees_compliance_coaching_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        CoachingSession::factory()->count(2)->create();

        $response = $this->actingAs($admin)
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Admin/Index')
                ->has('dashboardData')
                ->has('queueData')
                ->has('campaigns')
                ->has('teamLeads')
            );
    }

    #[Test]
    public function admin_dashboard_includes_upcoming_follow_ups(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $team = $this->createTeamWithCampaign();

        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
            'session_date' => now()->subDays(30),
            'follow_up_date' => now()->addDays(3),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Admin/Index')
                ->has('upcomingFollowUps')
                ->has('overdueFollowUps')
                ->has('upcomingFollowUps.0', fn (Assert $item) => $item
                    ->has('id')
                    ->has('agent_name')
                    ->has('team_lead_name')
                    ->has('follow_up_date')
                    ->has('purpose_label')
                    ->has('session_date')
                )
            );
    }

    #[Test]
    public function hr_sees_compliance_coaching_dashboard(): void
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $response = $this->actingAs($hr)
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Admin/Index')
            );
    }

    // ─── Settings ───────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_coaching_settings(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($admin)
            ->get(route('coaching.settings'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Settings/Index')
                ->has('settings')
                ->has('defaults')
            );
    }

    #[Test]
    public function admin_can_update_coaching_settings(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($admin)
            ->put(route('coaching.settings.update'), [
                'settings' => [
                    ['key' => 'coaching_done_max_days', 'value' => 20],
                    ['key' => 'needs_coaching_max_days', 'value' => 35],
                ],
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('coaching_status_settings', [
            'key' => 'coaching_done_max_days',
            'value' => 20,
        ]);
    }

    #[Test]
    public function agent_cannot_view_coaching_settings(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['agent'])
            ->get(route('coaching.settings'));

        $response->assertStatus(403);
    }

    #[Test]
    public function team_lead_cannot_update_coaching_settings(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['teamLead'])
            ->put(route('coaching.settings.update'), [
                'settings' => [
                    ['key' => 'coaching_done_max_days', 'value' => 10],
                ],
            ]);

        $response->assertStatus(403);
    }

    // ─── Export ─────────────────────────────────────────────────────

    #[Test]
    public function admin_can_start_coaching_export(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        CoachingSession::factory()->count(3)->create();

        $response = $this->actingAs($admin)
            ->postJson(route('coaching.export.start'), [
                'date_from' => now()->subMonth()->toDateString(),
                'date_to' => now()->toDateString(),
            ]);

        $response->assertOk()
            ->assertJsonStructure(['jobId']);
    }

    #[Test]
    public function admin_can_check_export_progress(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($admin)
            ->getJson(route('coaching.export.progress', ['jobId' => 'test-job-id']));

        $response->assertOk()
            ->assertJsonStructure(['percent', 'status', 'finished', 'downloadUrl']);
    }

    #[Test]
    public function agent_cannot_start_coaching_export(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['agent'])
            ->postJson(route('coaching.export.start'));

        $response->assertStatus(403);
    }

    #[Test]
    public function team_lead_cannot_start_coaching_export(): void
    {
        $team = $this->createTeamWithCampaign();

        $response = $this->actingAs($team['teamLead'])
            ->postJson(route('coaching.export.start'));

        $response->assertStatus(403);
    }

    // ─── TL Personal Coaching Moved to Sessions ─────────────────────

    #[Test]
    public function team_lead_dashboard_does_not_include_personal_coaching_data(): void
    {
        $team = $this->createTeamWithCampaign();

        // Create a session where TL is the coachee (coached by admin)
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Dashboard/Index')
                ->missing('myCoachingData')
                ->missing('myCoachingSessions')
            );
    }

    // ─── Admin TL Coaching Dashboard ────────────────────────────────

    #[Test]
    public function admin_dashboard_includes_team_lead_coaching_data(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        // Create TLs and coaching sessions for them
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $tl->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Admin/Index')
                ->has('teamLeadCoachingData')
                ->has('teamLeadCoachingData.total_agents')
                ->has('teamLeadCoachingData.status_counts')
                ->has('teamLeadCoachingData.agents')
            );
    }

    // ─── Admin Coachee Role Filter ──────────────────────────────────

    #[Test]
    public function admin_can_filter_dashboard_by_coachee_role_agent(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $team = $this->createTeamWithCampaign();
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);
        CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('coaching.dashboard', ['coachee_role' => 'Agent']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Admin/Index')
                ->has('dashboardData.agents')
                ->where('teamLeadCoachingData.total_agents', 0)
                ->where('filters.coachee_role', 'Agent')
            );
    }

    #[Test]
    public function admin_can_filter_dashboard_by_coachee_role_team_lead(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $team = $this->createTeamWithCampaign();
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);
        CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('coaching.dashboard', ['coachee_role' => 'Team Lead']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Admin/Index')
                ->where('dashboardData.total_agents', 0)
                ->has('teamLeadCoachingData.agents')
                ->where('filters.coachee_role', 'Team Lead')
            );
    }

    #[Test]
    public function admin_without_coachee_role_filter_returns_both_roles(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $team = $this->createTeamWithCampaign();
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);
        CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('coaching.dashboard'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Admin/Index')
                ->has('dashboardData.agents')
                ->has('teamLeadCoachingData.agents')
            );
    }
}
