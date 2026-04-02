<?php

namespace Tests\Feature\BreakTimer;

use App\Models\BreakPolicy;
use App\Models\BreakSession;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BreakDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected BreakPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time to 10:00 AM so getShiftDate() returns today's calendar date
        Carbon::setTestNow(Carbon::today()->setTime(10, 0, 0));

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_approved' => true,
        ]);

        $this->policy = BreakPolicy::factory()->create(['is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_displays_dashboard_page(): void
    {
        BreakSession::factory()->count(3)->create([
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('break-timer.dashboard'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Dashboard')
                ->has('sessions.data', 3)
                ->has('stats')
                ->has('filters')
                ->has('users')
            );
    }

    #[Test]
    public function it_filters_dashboard_by_status(): void
    {
        BreakSession::factory()->create([
            'break_policy_id' => $this->policy->id,
            'status' => 'completed',
            'shift_date' => now()->toDateString(),
        ]);
        BreakSession::factory()->active()->create([
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('break-timer.dashboard', ['status' => 'active']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Dashboard')
                ->has('sessions.data', 1)
            );
    }

    #[Test]
    public function it_displays_reports_page(): void
    {
        BreakSession::factory()->count(5)->create([
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('break-timer.reports'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Reports')
                ->has('sessions.data', 5)
                ->has('summary')
                ->has('filters')
                ->has('users')
            );
    }

    #[Test]
    public function it_filters_reports_by_type(): void
    {
        BreakSession::factory()->create([
            'break_policy_id' => $this->policy->id,
            'type' => 'lunch',
            'shift_date' => now()->toDateString(),
        ]);
        BreakSession::factory()->create([
            'break_policy_id' => $this->policy->id,
            'type' => '1st_break',
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('break-timer.reports', ['type' => 'lunch']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Reports')
                ->has('sessions.data', 1)
            );
    }

    #[Test]
    public function it_filters_reports_by_date_range(): void
    {
        BreakSession::factory()->create([
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->subDays(5)->toDateString(),
        ]);
        BreakSession::factory()->create([
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->subMonths(2)->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('break-timer.reports', [
                'start_date' => now()->subDays(7)->toDateString(),
                'end_date' => now()->toDateString(),
            ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Reports')
                ->has('sessions.data', 1)
            );
    }

    #[Test]
    public function it_starts_export_job(): void
    {
        BreakSession::factory()->count(3)->create([
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('break-timer.reports.export.start'), [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->toDateString(),
            ]);

        $response->assertOk();
        $response->assertDownload();
    }

    #[Test]
    public function it_returns_error_when_no_records_for_export(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('break-timer.reports.export.start'), [
                'start_date' => now()->subYear()->toDateString(),
                'end_date' => now()->subYear()->addDay()->toDateString(),
            ]);

        $response->assertUnprocessable()
            ->assertJson(['error' => true]);
    }

    #[Test]
    public function it_checks_export_progress(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('break-timer.reports.export.progress', ['jobId' => 'test-job-id']));

        $response->assertOk()
            ->assertJsonStructure(['percent', 'status', 'finished', 'downloadUrl']);
    }

    #[Test]
    public function agent_cannot_access_dashboard(): void
    {
        $agent = User::factory()->create([
            'role' => 'agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($agent)
            ->get(route('break-timer.dashboard'));

        $response->assertForbidden();
    }

    #[Test]
    public function team_lead_only_sees_their_campaign_sessions_on_dashboard(): void
    {
        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();

        $teamLead = User::factory()->create([
            'role' => 'Team Lead',
            'is_approved' => true,
        ]);
        $teamLead->campaigns()->attach($campaignA);
        EmployeeSchedule::factory()->create([
            'user_id' => $teamLead->id,
            'campaign_id' => $campaignA->id,
        ]);

        $agentA = User::factory()->create(['role' => 'agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agentA->id,
            'campaign_id' => $campaignA->id,
        ]);

        $agentB = User::factory()->create(['role' => 'agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agentB->id,
            'campaign_id' => $campaignB->id,
        ]);

        BreakSession::factory()->create([
            'user_id' => $agentA->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);
        BreakSession::factory()->create([
            'user_id' => $agentB->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($teamLead)
            ->get(route('break-timer.dashboard'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Dashboard')
                ->has('sessions.data', 1)
                ->where('sessions.data.0.user.id', $agentA->id)
            );
    }

    #[Test]
    public function team_lead_only_sees_their_campaign_sessions_on_reports(): void
    {
        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();

        $teamLead = User::factory()->create([
            'role' => 'Team Lead',
            'is_approved' => true,
        ]);
        $teamLead->campaigns()->attach($campaignA);
        EmployeeSchedule::factory()->create([
            'user_id' => $teamLead->id,
            'campaign_id' => $campaignA->id,
        ]);

        $agentA = User::factory()->create(['role' => 'agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agentA->id,
            'campaign_id' => $campaignA->id,
        ]);

        $agentB = User::factory()->create(['role' => 'agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agentB->id,
            'campaign_id' => $campaignB->id,
        ]);

        BreakSession::factory()->create([
            'user_id' => $agentA->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);
        BreakSession::factory()->create([
            'user_id' => $agentB->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($teamLead)
            ->get(route('break-timer.reports'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Reports')
                ->has('sessions.data', 1)
                ->where('sessions.data.0.user.id', $agentA->id)
            );
    }

    #[Test]
    public function team_lead_users_dropdown_is_scoped_to_their_campaigns(): void
    {
        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();

        $teamLead = User::factory()->create([
            'role' => 'Team Lead',
            'is_approved' => true,
        ]);
        $teamLead->campaigns()->attach($campaignA);
        EmployeeSchedule::factory()->create([
            'user_id' => $teamLead->id,
            'campaign_id' => $campaignA->id,
        ]);

        $agentA = User::factory()->create(['role' => 'agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agentA->id,
            'campaign_id' => $campaignA->id,
        ]);

        $agentB = User::factory()->create(['role' => 'agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agentB->id,
            'campaign_id' => $campaignB->id,
        ]);

        $response = $this->actingAs($teamLead)
            ->get(route('break-timer.dashboard'));

        $response->assertOk()
            ->assertInertia(function (Assert $page) use ($agentA, $agentB, $teamLead) {
                $page->component('BreakTimer/Dashboard')
                    ->has('users', 2);

                // The users should include agentA and teamLead (both in campaignA), but not agentB
                $users = $page->toArray()['props']['users'];
                $userIds = array_column($users, 'id');
                $this->assertContains($agentA->id, $userIds);
                $this->assertContains($teamLead->id, $userIds);
                $this->assertNotContains($agentB->id, $userIds);
            });
    }

    #[Test]
    public function admin_sees_all_sessions_regardless_of_campaign(): void
    {
        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();

        $agentA = User::factory()->create(['role' => 'agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agentA->id,
            'campaign_id' => $campaignA->id,
        ]);

        $agentB = User::factory()->create(['role' => 'agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agentB->id,
            'campaign_id' => $campaignB->id,
        ]);

        BreakSession::factory()->create([
            'user_id' => $agentA->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);
        BreakSession::factory()->create([
            'user_id' => $agentB->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('break-timer.dashboard'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Dashboard')
                ->has('sessions.data', 2)
            );
    }
}
