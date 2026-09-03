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

/**
 * Row-level scoping: TLs only see sessions they personally coached
 * (+ sessions where they are the coachee). Super Admin/Admin see all.
 */
class CoachingSessionScopingTest extends TestCase
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
     * Create a campaign with a TL (pivot-synced) and an agent (schedule-linked).
     *
     * @return array{campaign: Campaign, teamLead: User, agent: User}
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
        $teamLead->campaigns()->sync([$campaign->id]);

        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return compact('campaign', 'teamLead', 'agent');
    }

    #[Test]
    public function team_lead_only_sees_sessions_they_coached_on_team_tab(): void
    {
        $teamA = $this->createTeamWithCampaign();
        $teamB = $this->createTeamWithCampaign();

        // Same campaign for both TLs (shared campaign scenario)
        $teamB['teamLead']->campaigns()->sync([$teamA['campaign']->id]);

        $ownSession = CoachingSession::factory()->create([
            'coachee_id' => $teamA['agent']->id,
            'coach_id' => $teamA['teamLead']->id,
        ]);

        // Session coached by another TL on the SAME campaign's agent
        $otherTlSession = CoachingSession::factory()->create([
            'coachee_id' => $teamA['agent']->id,
            'coach_id' => $teamB['teamLead']->id,
        ]);

        // Session coached by an admin on TL A's own agent
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $adminSession = CoachingSession::factory()->create([
            'coachee_id' => $teamA['agent']->id,
            'coach_id' => $admin->id,
        ]);

        $response = $this->actingAs($teamA['teamLead'])->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Index')
                ->has('sessions.data', 1)
                ->where('sessions.data.0.id', $ownSession->id)
            );
    }

    #[Test]
    public function team_lead_my_tab_shows_sessions_where_they_are_coachee(): void
    {
        $team = $this->createTeamWithCampaign();
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $tlAsCoachee = CoachingSession::factory()->create([
            'coachee_id' => $team['teamLead']->id,
            'coach_id' => $admin->id,
        ]);

        // Session they coached — should not appear on 'my' tab
        CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.index', ['tab' => 'my']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions.data', 1)
                ->where('sessions.data.0.id', $tlAsCoachee->id)
            );
    }

    #[Test]
    public function team_lead_cannot_view_session_coached_by_another_tl_on_own_agent(): void
    {
        $teamA = $this->createTeamWithCampaign();
        $teamB = $this->createTeamWithCampaign();
        $teamB['teamLead']->campaigns()->sync([$teamA['campaign']->id]);

        $session = CoachingSession::factory()->create([
            'coachee_id' => $teamA['agent']->id,
            'coach_id' => $teamB['teamLead']->id,
        ]);

        $this->actingAs($teamA['teamLead'])
            ->get(route('coaching.sessions.show', $session))
            ->assertForbidden();
    }

    #[Test]
    public function team_lead_coaching_history_only_includes_own_coached_sessions(): void
    {
        $teamA = $this->createTeamWithCampaign();
        $teamB = $this->createTeamWithCampaign();
        $teamB['teamLead']->campaigns()->sync([$teamA['campaign']->id]);

        $ownFirst = CoachingSession::factory()->create([
            'coachee_id' => $teamA['agent']->id,
            'coach_id' => $teamA['teamLead']->id,
        ]);
        CoachingSession::factory()->create([
            'coachee_id' => $teamA['agent']->id,
            'coach_id' => $teamB['teamLead']->id,
        ]);

        $response = $this->actingAs($teamA['teamLead'])
            ->get(route('coaching.sessions.show', $ownFirst));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coaching/Sessions/Show')
                ->has('coaching_history', 0)
            );
    }

    #[Test]
    public function admin_sees_all_sessions(): void
    {
        $teamA = $this->createTeamWithCampaign();
        $teamB = $this->createTeamWithCampaign();
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        CoachingSession::factory()->create([
            'coachee_id' => $teamA['agent']->id,
            'coach_id' => $teamA['teamLead']->id,
        ]);
        CoachingSession::factory()->create([
            'coachee_id' => $teamB['agent']->id,
            'coach_id' => $teamB['teamLead']->id,
        ]);

        $response = $this->actingAs($admin)->get(route('coaching.sessions.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions.data', 2)
            );
    }

    #[Test]
    public function hr_cannot_view_sessions_index(): void
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $this->actingAs($hr)
            ->get(route('coaching.sessions.index'))
            ->assertForbidden();
    }

    #[Test]
    public function hr_cannot_view_coaching_dashboard(): void
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $this->actingAs($hr)
            ->get(route('coaching.dashboard'))
            ->assertForbidden();
    }

    #[Test]
    public function team_lead_drafts_tab_still_shows_only_own_drafts(): void
    {
        $teamA = $this->createTeamWithCampaign();
        $teamB = $this->createTeamWithCampaign();

        CoachingSession::factory()->create([
            'coachee_id' => $teamA['agent']->id,
            'coach_id' => $teamA['teamLead']->id,
            'is_draft' => true,
        ]);
        CoachingSession::factory()->create([
            'coachee_id' => $teamB['agent']->id,
            'coach_id' => $teamB['teamLead']->id,
            'is_draft' => true,
        ]);

        $response = $this->actingAs($teamA['teamLead'])
            ->get(route('coaching.sessions.index', ['tab' => 'drafts']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions.data', 1)
            );
    }
}
