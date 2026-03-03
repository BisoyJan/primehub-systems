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

class CoachingDashboardControllerTest extends TestCase
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

        // Seed default coaching status settings
        $this->seed(\Database\Seeders\CoachingStatusSettingSeeder::class);
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
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
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
            'agent_id' => $team['agent']->id,
            'team_lead_id' => $team['teamLead']->id,
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
}
