<?php

namespace Tests\Unit\Services;

use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\CoachingStatusSetting;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\CoachingDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CoachingDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CoachingDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CoachingDashboardService::class);
        $this->seed(\Database\Seeders\CoachingStatusSettingSeeder::class);
    }

    // ─── Status Calculation Tests (Threshold-Based) ──────────────

    #[Test]
    public function returns_coaching_done_when_session_within_15_days(): void
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(5),
            'severity_flag' => 'Normal',
        ]);

        $status = $this->service->getCoachingStatus($agent->id);

        $this->assertEquals(CoachingDashboardService::STATUS_COACHING_DONE, $status);
    }

    #[Test]
    public function returns_needs_coaching_when_session_between_16_and_30_days(): void
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(20),
            'severity_flag' => 'Normal',
        ]);

        $status = $this->service->getCoachingStatus($agent->id);

        $this->assertEquals(CoachingDashboardService::STATUS_NEEDS_COACHING, $status);
    }

    #[Test]
    public function returns_badly_needs_coaching_when_session_between_31_and_45_days(): void
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(35),
            'severity_flag' => 'Normal',
        ]);

        $status = $this->service->getCoachingStatus($agent->id);

        $this->assertEquals(CoachingDashboardService::STATUS_BADLY_NEEDS_COACHING, $status);
    }

    #[Test]
    public function returns_please_coach_asap_when_session_older_than_45_days(): void
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(50),
            'severity_flag' => 'Normal',
        ]);

        $status = $this->service->getCoachingStatus($agent->id);

        $this->assertEquals(CoachingDashboardService::STATUS_PLEASE_COACH_ASAP, $status);
    }

    #[Test]
    public function returns_no_record_when_no_sessions_exist(): void
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        $status = $this->service->getCoachingStatus($agent->id);

        $this->assertEquals(CoachingDashboardService::STATUS_NO_RECORD, $status);
    }

    #[Test]
    public function returns_no_record_when_session_older_than_60_days(): void
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(65),
            'severity_flag' => 'Normal',
        ]);

        $status = $this->service->getCoachingStatus($agent->id);

        $this->assertEquals(CoachingDashboardService::STATUS_NO_RECORD, $status);
    }

    #[Test]
    public function returns_coaching_done_even_with_critical_severity(): void
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(5),
            'severity_flag' => 'Critical',
            'compliance_status' => 'Awaiting_Agent_Ack',
        ]);

        $status = $this->service->getCoachingStatus($agent->id);

        // Critical severity no longer overrides — status is purely time-based
        $this->assertEquals(CoachingDashboardService::STATUS_COACHING_DONE, $status);
    }

    #[Test]
    public function status_respects_custom_thresholds(): void
    {
        // Change coaching_done_max_days to 5
        CoachingStatusSetting::where('key', 'coaching_done_max_days')->update(['value' => 5]);
        CoachingStatusSetting::clearCache();

        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(7),
            'severity_flag' => 'Normal',
        ]);

        $status = $this->service->getCoachingStatus($agent->id);

        // With threshold at 5 days, 7 days ago = needs coaching
        $this->assertEquals(CoachingDashboardService::STATUS_NEEDS_COACHING, $status);
    }

    // ─── Agent Summary Tests ────────────────────────────────────────

    #[Test]
    public function agent_summary_returns_correct_structure(): void
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(3),
            'ack_status' => 'Pending',
            'severity_flag' => 'Normal',
        ]);

        $summary = $this->service->getCoacheeSummary($agent->id);

        $this->assertArrayHasKey('coaching_status', $summary);
        $this->assertArrayHasKey('status_color', $summary);
        $this->assertArrayHasKey('last_coached_date', $summary);
        $this->assertArrayHasKey('previous_coached_date', $summary);
        $this->assertArrayHasKey('older_coached_date', $summary);
        $this->assertArrayHasKey('pending_acknowledgements', $summary);
        $this->assertArrayHasKey('total_sessions', $summary);
        $this->assertEquals(1, $summary['total_sessions']);
        $this->assertEquals(1, $summary['pending_acknowledgements']);
    }

    #[Test]
    public function agent_summary_returns_last_three_session_dates(): void
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(3),
            'severity_flag' => 'Normal',
        ]);
        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(10),
            'severity_flag' => 'Normal',
        ]);
        CoachingSession::factory()->create([
            'coachee_id' => $agent->id,
            'session_date' => now()->subDays(20),
            'severity_flag' => 'Normal',
        ]);

        $summary = $this->service->getCoacheeSummary($agent->id);

        $this->assertNotNull($summary['last_coached_date']);
        $this->assertNotNull($summary['previous_coached_date']);
        $this->assertNotNull($summary['older_coached_date']);
        $this->assertEquals(3, $summary['total_sessions']);
    }

    // ─── Team Lead Dashboard Data Tests ─────────────────────────────

    #[Test]
    public function team_lead_dashboard_returns_agents_in_campaign(): void
    {
        $campaign = Campaign::factory()->create();

        $teamLead = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        $teamLead->campaigns()->attach($campaign->id);
        EmployeeSchedule::factory()->create([
            'user_id' => $teamLead->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $agent1 = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agent1->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $agent2 = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agent2->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $data = $this->service->getTeamLeadDashboardData($teamLead);

        $this->assertEquals(2, $data['total_agents']);
        $this->assertCount(2, $data['agents']);
        $this->assertArrayHasKey('status_counts', $data);
    }

    #[Test]
    public function team_lead_dashboard_returns_empty_when_no_campaign(): void
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);

        $data = $this->service->getTeamLeadDashboardData($teamLead);

        $this->assertEquals(0, $data['total_agents']);
        $this->assertCount(0, $data['agents']);
    }

    // ─── Compliance Dashboard Data Tests ────────────────────────────

    #[Test]
    public function compliance_dashboard_returns_all_agents(): void
    {
        User::factory()->count(3)->create(['role' => 'Agent', 'is_approved' => true]);

        $data = $this->service->getComplianceDashboardData();

        $this->assertEquals(3, $data['total_agents']);
    }

    #[Test]
    public function compliance_queue_returns_unacknowledged_and_for_review(): void
    {
        CoachingSession::factory()->create([
            'ack_status' => 'Pending',
            'compliance_status' => 'Awaiting_Agent_Ack',
        ]);
        CoachingSession::factory()->acknowledged()->create();

        $queueData = $this->service->getComplianceQueueData();

        $this->assertCount(1, $queueData['unacknowledged']);
        $this->assertCount(1, $queueData['for_review']);
    }

    // ─── Team Lead Coaching Data Tests ──────────────────────────────

    #[Test]
    public function coaching_status_works_for_team_lead_coachee(): void
    {
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        CoachingSession::factory()->forTeamLead()->create([
            'coachee_id' => $tl->id,
            'session_date' => now()->subDays(5),
            'severity_flag' => 'Normal',
        ]);

        $status = $this->service->getCoachingStatus($tl->id);

        $this->assertEquals(CoachingDashboardService::STATUS_COACHING_DONE, $status);
    }

    #[Test]
    public function coachee_summary_works_for_team_lead(): void
    {
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        CoachingSession::factory()->forTeamLead()->create([
            'coachee_id' => $tl->id,
            'session_date' => now()->subDays(3),
            'ack_status' => 'Pending',
            'severity_flag' => 'Normal',
        ]);

        $summary = $this->service->getCoacheeSummary($tl->id);

        $this->assertEquals(CoachingDashboardService::STATUS_COACHING_DONE, $summary['coaching_status']);
        $this->assertEquals(1, $summary['total_sessions']);
        $this->assertEquals(1, $summary['pending_acknowledgements']);
    }

    #[Test]
    public function team_lead_coaching_data_returns_correct_structure(): void
    {
        $campaign = Campaign::factory()->create();

        $tl1 = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tl1->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $tl2 = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tl2->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        CoachingSession::factory()->forTeamLead()->create([
            'coachee_id' => $tl1->id,
            'session_date' => now()->subDays(5),
        ]);

        $data = $this->service->getTeamLeadCoachingData();

        $this->assertGreaterThanOrEqual(2, $data['total_agents']);
        $this->assertArrayHasKey('status_counts', $data);
        $this->assertArrayHasKey('agents', $data);
    }

    #[Test]
    public function team_lead_coaching_data_filters_by_campaign(): void
    {
        $campaign = Campaign::factory()->create();

        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tl->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $otherCampaign = Campaign::factory()->create();
        $otherTl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $otherTl->id,
            'campaign_id' => $otherCampaign->id,
            'is_active' => true,
        ]);

        $data = $this->service->getTeamLeadCoachingData(['campaign_id' => $campaign->id]);

        $this->assertEquals(1, $data['total_agents']);
    }
}
