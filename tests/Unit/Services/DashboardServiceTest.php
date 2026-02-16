<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\ItConcern;
use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Models\PcMaintenance;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardService;
    }

    #[Test]
    public function it_gets_total_stations_count(): void
    {
        $site = Site::factory()->create();
        Station::factory()->count(5)->create(['site_id' => $site->id]);

        $result = $this->service->getTotalStations();

        $this->assertEquals(5, $result['total']);
        $this->assertIsArray($result['bysite']);
    }

    #[Test]
    public function it_gets_stations_by_site_breakdown(): void
    {
        $site1 = Site::factory()->create(['name' => 'Site A']);
        $site2 = Site::factory()->create(['name' => 'Site B']);
        Station::factory()->count(3)->create(['site_id' => $site1->id]);
        Station::factory()->count(2)->create(['site_id' => $site2->id]);

        $result = $this->service->getTotalStations();

        $this->assertCount(2, $result['bysite']);
        $this->assertEquals(3, collect($result['bysite'])->firstWhere('site', 'Site A')['count']);
        $this->assertEquals(2, collect($result['bysite'])->firstWhere('site', 'Site B')['count']);
    }

    #[Test]
    public function it_gets_stations_without_pcs(): void
    {
        $site = Site::factory()->create();
        Station::factory()->count(3)->create(['site_id' => $site->id, 'pc_spec_id' => null]);

        $pcSpec = PcSpec::factory()->create();
        Station::factory()->create(['site_id' => $site->id, 'pc_spec_id' => $pcSpec->id]);

        $result = $this->service->getStationsWithoutPcs();

        $this->assertEquals(3, $result['total']);
        $this->assertCount(3, $result['stations']);
    }

    #[Test]
    public function it_gets_vacant_stations(): void
    {
        $site = Site::factory()->create();
        Station::factory()->count(2)->create(['site_id' => $site->id, 'status' => 'Vacant']);
        Station::factory()->count(3)->create(['site_id' => $site->id, 'status' => 'Occupied']);

        $result = $this->service->getVacantStations();

        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['stations']);
        $this->assertIsArray($result['bysite']);
    }

    #[Test]
    public function it_gets_dual_monitor_stations(): void
    {
        $site = Site::factory()->create();
        Station::factory()->count(3)->create(['site_id' => $site->id, 'monitor_type' => 'dual']);
        Station::factory()->count(2)->create(['site_id' => $site->id, 'monitor_type' => 'single']);

        $result = $this->service->getDualMonitorStations();

        $this->assertEquals(3, $result['total']);
        $this->assertIsArray($result['bysite']);
    }

    #[Test]
    public function it_gets_maintenance_due(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        PcMaintenance::factory()->create([

            'status' => 'overdue',
            'next_due_date' => Carbon::now()->subDays(5),
        ]);

        $result = $this->service->getMaintenanceDue();

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['stations']);
    }

    #[Test]
    public function it_gets_maintenance_due_including_pending(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        PcMaintenance::factory()->create([

            'status' => 'pending',
            'next_due_date' => Carbon::now()->subDay(),
        ]);

        $result = $this->service->getMaintenanceDue();

        $this->assertEquals(1, $result['total']);
    }

    #[Test]
    public function it_gets_unassigned_pc_specs(): void
    {
        // PC with no stations
        $unassignedPc = PcSpec::factory()->create();

        // PC assigned to station
        $assignedPc = PcSpec::factory()->create();
        $site = Site::factory()->create();
        Station::factory()->create(['site_id' => $site->id, 'pc_spec_id' => $assignedPc->id]);

        $result = $this->service->getUnassignedPcSpecs();

        $this->assertCount(1, $result);
        $this->assertEquals($unassignedPc->pc_number, $result[0]['pc_number']);
    }

    #[Test]
    public function it_gets_all_dashboard_stats(): void
    {
        $result = $this->service->getAllStats('Super Admin');

        $this->assertArrayHasKey('totalStations', $result);
        $this->assertArrayHasKey('noPcs', $result);
        $this->assertArrayHasKey('vacantStations', $result);
        $this->assertArrayHasKey('dualMonitor', $result);
        $this->assertArrayHasKey('maintenanceDue', $result);
        $this->assertArrayHasKey('unassignedPcSpecs', $result);
        $this->assertArrayHasKey('itConcernStats', $result);
        $this->assertArrayHasKey('itConcernTrends', $result);
        $this->assertArrayHasKey('stockSummary', $result);
        $this->assertArrayHasKey('userAccountStats', $result);
        $this->assertArrayHasKey('recentActivityLogs', $result);
        $this->assertArrayHasKey('biometricAnomalies', $result);
    }

    #[Test]
    public function it_gets_it_concern_stats(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        ItConcern::factory()->create(['site_id' => $site->id, 'status' => 'pending']);
        ItConcern::factory()->create(['site_id' => $site->id, 'status' => 'in_progress']);
        ItConcern::factory()->create(['site_id' => $site->id, 'status' => 'resolved']);

        $result = $this->service->getItConcernStats();

        $this->assertEquals(1, $result['pending']);
        $this->assertEquals(1, $result['in_progress']);
        $this->assertEquals(1, $result['resolved']);
        $this->assertIsArray($result['bySite']);
    }

    #[Test]
    public function it_gets_it_concern_stats_by_site(): void
    {
        $site1 = Site::factory()->create(['name' => 'Site A']);
        $site2 = Site::factory()->create(['name' => 'Site B']);
        $station1 = Station::factory()->create(['site_id' => $site1->id]);
        $station2 = Station::factory()->create(['site_id' => $site2->id]);

        ItConcern::factory()->count(2)->create(['site_id' => $site1->id, 'status' => 'pending']);
        ItConcern::factory()->create(['site_id' => $site2->id, 'status' => 'pending']);

        $result = $this->service->getItConcernStats();

        $this->assertCount(2, $result['bySite']);
    }

    #[Test]
    public function it_gets_it_concern_trends(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        ItConcern::factory()->create([

            'site_id' => $site->id,
            'status' => 'pending',
            'created_at' => Carbon::now()->subMonth(),
        ]);

        $result = $this->service->getItConcernTrends();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function it_handles_empty_data_gracefully(): void
    {
        $result = $this->service->getAllStats('Super Admin');

        $this->assertEquals(0, $result['totalStations']['total']);
        $this->assertEquals(0, $result['noPcs']['total']);
        $this->assertEquals(0, $result['vacantStations']['total']);
    }

    #[Test]
    public function it_returns_restricted_data_for_agent_role(): void
    {
        $result = $this->service->getAllStats('Agent');

        // Agent should NOT get infrastructure, IT concerns, stock, or admin widgets
        $this->assertArrayNotHasKey('totalStations', $result);
        $this->assertArrayNotHasKey('itConcernStats', $result);
        $this->assertArrayNotHasKey('stockSummary', $result);
        $this->assertArrayNotHasKey('userAccountStats', $result);
        $this->assertArrayNotHasKey('recentActivityLogs', $result);
        $this->assertArrayNotHasKey('biometricAnomalies', $result);

        // Agent SHOULD get presence insights
        $this->assertArrayHasKey('presenceInsights', $result);
    }

    #[Test]
    public function it_returns_it_focused_data_for_it_role(): void
    {
        $result = $this->service->getAllStats('IT');

        // IT should get infrastructure, IT concerns, and stock
        $this->assertArrayHasKey('totalStations', $result);
        $this->assertArrayHasKey('itConcernStats', $result);
        $this->assertArrayHasKey('stockSummary', $result);

        // IT should NOT get admin-only widgets
        $this->assertArrayNotHasKey('userAccountStats', $result);
        $this->assertArrayNotHasKey('recentActivityLogs', $result);
        $this->assertArrayNotHasKey('biometricAnomalies', $result);
    }

    #[Test]
    public function it_formats_days_overdue_correctly(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        PcMaintenance::factory()->create([

            'status' => 'overdue',
            'next_due_date' => Carbon::now()->subDays(1),
        ]);

        $result = $this->service->getMaintenanceDue();

        $this->assertStringContainsString('overdue', $result['stations'][0]['daysOverdue']);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Phase 4: Enhanced Analytics Tests
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_gets_points_escalation_for_employees_nearing_threshold(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        // Create 5 active points totalling 5.0 (4.00-5.99 range)
        AttendancePoint::factory()->count(5)->create([
            'user_id' => $user->id,
            'point_type' => 'whole_day_absence',
            'points' => 1.00,
            'is_excused' => false,
            'is_expired' => false,
        ]);

        $result = $this->service->getPointsEscalation();

        $this->assertEquals(1, $result['count']);
        $this->assertCount(1, $result['employees']);
        $this->assertEquals(5.0, $result['employees'][0]['total_points']);
        $this->assertEquals(1.0, $result['employees'][0]['remaining_before_threshold']);
    }

    #[Test]
    public function it_excludes_employees_below_escalation_range(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        // Create 3 points = 3.0 (below 4.0)
        AttendancePoint::factory()->count(3)->create([
            'user_id' => $user->id,
            'point_type' => 'whole_day_absence',
            'points' => 1.00,
            'is_excused' => false,
            'is_expired' => false,
        ]);

        $result = $this->service->getPointsEscalation();

        $this->assertEquals(0, $result['count']);
        $this->assertEmpty($result['employees']);
    }

    #[Test]
    public function it_gets_ncns_trend(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        // Create NCNS attendance for 2 different months
        Attendance::factory()->ncns()->create([
            'user_id' => $user->id,
            'shift_date' => now()->startOfMonth()->format('Y-m-d'),
            'admin_verified' => true,
        ]);
        Attendance::factory()->ncns()->create([
            'user_id' => $user->id,
            'shift_date' => now()->subMonth()->startOfMonth()->format('Y-m-d'),
            'admin_verified' => true,
        ]);

        $result = $this->service->getNcnsTrend();

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertArrayHasKey('month', $result[0]);
        $this->assertArrayHasKey('ncns_count', $result[0]);
        $this->assertArrayHasKey('change', $result[0]);
    }

    #[Test]
    public function it_gets_leave_utilization_data(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0.50,
            'credits_balance' => 0.75,
        ]);

        $result = $this->service->getLeaveUtilizationData();

        $this->assertArrayHasKey('months', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertGreaterThanOrEqual(1, count($result['months']));
        $this->assertEquals(1.25, $result['totals']['total_earned']);
        $this->assertEquals(0.50, $result['totals']['total_used']);
        $this->assertArrayHasKey('utilization_rate', $result['totals']);
    }

    #[Test]
    public function it_gets_campaign_presence_comparison(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create(['name' => 'Test Campaign']);
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'campaign_id' => $campaign->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        // Create attendance for today
        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'shift_date' => now()->format('Y-m-d'),
            'admin_verified' => true,
        ]);

        $result = $this->service->getCampaignPresenceComparison(now()->format('Y-m-d'));

        $this->assertIsArray($result);
        $campaignResult = collect($result)->firstWhere('campaign_name', 'Test Campaign');
        $this->assertNotNull($campaignResult);
        $this->assertEquals(1, $campaignResult['total_scheduled']);
        $this->assertEquals(1, $campaignResult['present']);
        $this->assertEquals(100.0, $campaignResult['presence_rate']);
    }

    #[Test]
    public function it_gets_points_by_campaign(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create(['name' => 'Points Campaign']);
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'campaign_id' => $campaign->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        AttendancePoint::factory()->count(2)->create([
            'user_id' => $user->id,
            'point_type' => 'tardy',
            'points' => 0.25,
            'is_excused' => false,
            'is_expired' => false,
        ]);

        $result = $this->service->getPointsByCampaign();

        $this->assertIsArray($result);
        $campaignResult = collect($result)->firstWhere('campaign_name', 'Points Campaign');
        $this->assertNotNull($campaignResult);
        $this->assertEquals(0.50, $campaignResult['total_points']);
        $this->assertEquals(2, $campaignResult['violations_count']);
        $this->assertEquals(1, $campaignResult['employees_with_points']);
    }

    #[Test]
    public function it_includes_phase4_data_for_super_admin(): void
    {
        $result = $this->service->getAllStats('Super Admin');

        $this->assertArrayHasKey('pointsEscalation', $result);
        $this->assertArrayHasKey('ncnsTrend', $result);
        $this->assertArrayHasKey('leaveUtilization', $result);
        $this->assertArrayHasKey('campaignPresence', $result);
        $this->assertArrayHasKey('pointsByCampaign', $result);
    }

    #[Test]
    public function it_excludes_phase4_campaign_data_for_hr_role(): void
    {
        $result = $this->service->getAllStats('HR');

        // HR gets escalation, NCNS trend, leave utilization — but NOT campaign data
        $this->assertArrayHasKey('pointsEscalation', $result);
        $this->assertArrayHasKey('ncnsTrend', $result);
        $this->assertArrayHasKey('leaveUtilization', $result);
        $this->assertArrayNotHasKey('campaignPresence', $result);
        $this->assertArrayNotHasKey('pointsByCampaign', $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Phase 7: Previously Untested Service Methods
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_gets_stock_summary(): void
    {
        $disk = \App\Models\DiskSpec::factory()->create();
        \App\Models\Stock::factory()->create([
            'stockable_type' => \App\Models\DiskSpec::class,
            'stockable_id' => $disk->id,
            'quantity' => 50,
            'reserved' => 10,
        ]);

        $result = $this->service->getStockSummary();

        $this->assertArrayHasKey('Disk', $result);
        $this->assertEquals(50, $result['Disk']['total']);
        $this->assertEquals(10, $result['Disk']['reserved']);
        $this->assertEquals(40, $result['Disk']['available']);
        $this->assertEquals(1, $result['Disk']['items']);
    }

    #[Test]
    public function it_gets_stock_summary_empty(): void
    {
        $result = $this->service->getStockSummary();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function it_gets_user_account_stats(): void
    {
        User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'is_active' => true]);
        User::factory()->create(['role' => 'Agent', 'is_approved' => false, 'is_active' => true]);
        User::factory()->create(['role' => 'HR', 'is_approved' => true, 'is_active' => true]);
        User::factory()->create(['role' => 'Agent', 'is_active' => false, 'hired_date' => now()->subYear()]);

        $result = $this->service->getUserAccountStats();

        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('by_role', $result);
        $this->assertArrayHasKey('pending_approvals', $result);
        $this->assertArrayHasKey('recently_deactivated', $result);
        $this->assertArrayHasKey('resigned', $result);
        $this->assertGreaterThanOrEqual(2, $result['total']); // at least 2 active
        $this->assertGreaterThanOrEqual(1, $result['pending_approvals']);
        $this->assertGreaterThanOrEqual(1, $result['resigned']);
    }

    #[Test]
    public function it_gets_recent_activity_logs(): void
    {
        // Activity logs are created by Spatie - create some manually
        activity()
            ->causedBy(User::factory()->create(['is_approved' => true]))
            ->log('Test activity');

        $result = $this->service->getRecentActivityLogs();

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('description', $result[0]);
        $this->assertArrayHasKey('causer_name', $result[0]);
        $this->assertArrayHasKey('created_at', $result[0]);
    }

    #[Test]
    public function it_gets_recent_activity_logs_empty(): void
    {
        $result = $this->service->getRecentActivityLogs();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function it_gets_notification_summary(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        \App\Models\Notification::factory()->count(3)->create([
            'user_id' => $user->id,
            'read_at' => null,
        ]);
        \App\Models\Notification::factory()->read()->create([
            'user_id' => $user->id,
        ]);

        $result = $this->service->getNotificationSummary($user->id);

        $this->assertEquals(3, $result['unread_count']);
        $this->assertCount(3, $result['recent']);
        $this->assertArrayHasKey('id', $result['recent'][0]);
        $this->assertArrayHasKey('type', $result['recent'][0]);
        $this->assertArrayHasKey('title', $result['recent'][0]);
    }

    #[Test]
    public function it_gets_notification_summary_empty(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $result = $this->service->getNotificationSummary($user->id);

        $this->assertEquals(0, $result['unread_count']);
        $this->assertEmpty($result['recent']);
    }

    #[Test]
    public function it_gets_personal_schedule(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'work_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        ]);

        $result = $this->service->getPersonalSchedule($user->id);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('campaign', $result);
        $this->assertArrayHasKey('site', $result);
        $this->assertArrayHasKey('shift_type', $result);
        $this->assertArrayHasKey('time_in', $result);
        $this->assertArrayHasKey('time_out', $result);
        $this->assertArrayHasKey('work_days', $result);
        $this->assertArrayHasKey('next_shifts', $result);
        $this->assertEquals('09:00:00', $result['time_in']);
    }

    #[Test]
    public function it_returns_null_for_user_without_schedule(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        $result = $this->service->getPersonalSchedule($user->id);

        $this->assertNull($result);
    }

    #[Test]
    public function it_gets_personal_requests_summary(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        \App\Models\LeaveRequest::factory()->count(2)->create(['user_id' => $user->id]);
        \App\Models\ItConcern::factory()->create(['user_id' => $user->id]);
        \App\Models\MedicationRequest::factory()->create(['user_id' => $user->id]);

        $result = $this->service->getPersonalRequestsSummary($user->id);

        $this->assertArrayHasKey('leaves', $result);
        $this->assertArrayHasKey('it_concerns', $result);
        $this->assertArrayHasKey('medication_requests', $result);
        $this->assertCount(2, $result['leaves']);
        $this->assertCount(1, $result['it_concerns']);
        $this->assertCount(1, $result['medication_requests']);
    }

    #[Test]
    public function it_gets_personal_requests_summary_empty(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        $result = $this->service->getPersonalRequestsSummary($user->id);

        $this->assertEmpty($result['leaves']);
        $this->assertEmpty($result['it_concerns']);
        $this->assertEmpty($result['medication_requests']);
    }

    #[Test]
    public function it_gets_personal_attendance_summary(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);
        Attendance::factory()->tardy()->create([
            'user_id' => $user->id,
            'shift_date' => now()->subDay()->format('Y-m-d'),
        ]);

        $result = $this->service->getPersonalAttendanceSummary($user->id);

        $this->assertArrayHasKey('month', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('on_time', $result);
        $this->assertArrayHasKey('tardy', $result);
        $this->assertArrayHasKey('total_points', $result);
        $this->assertArrayHasKey('points_by_type', $result);
        $this->assertArrayHasKey('points_threshold', $result);
        $this->assertArrayHasKey('upcoming_expirations', $result);
        $this->assertEquals(6, $result['points_threshold']);
        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    #[Test]
    public function it_gets_personal_attendance_summary_with_points(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        AttendancePoint::factory()->create([
            'user_id' => $user->id,
            'point_type' => 'tardy',
            'points' => 0.25,
            'is_excused' => false,
            'is_expired' => false,
        ]);

        $result = $this->service->getPersonalAttendanceSummary($user->id);

        $this->assertEquals(0.25, $result['total_points']);
        $this->assertEquals(0.25, $result['points_by_type']['tardy']);
    }

    #[Test]
    public function it_returns_utility_role_restricted_data(): void
    {
        $result = $this->service->getAllStats('Utility');

        // Utility should NOT get infrastructure, IT concerns, or any admin data
        $this->assertArrayNotHasKey('totalStations', $result);
        $this->assertArrayNotHasKey('itConcernStats', $result);
        $this->assertArrayNotHasKey('stockSummary', $result);
        $this->assertArrayNotHasKey('userAccountStats', $result);
        $this->assertArrayNotHasKey('recentActivityLogs', $result);
        $this->assertArrayNotHasKey('biometricAnomalies', $result);
        $this->assertArrayNotHasKey('presenceInsights', $result);
        $this->assertArrayNotHasKey('pointsEscalation', $result);
    }

    #[Test]
    public function it_returns_team_lead_data(): void
    {
        $result = $this->service->getAllStats('Team Lead');

        // Team Lead should get presence insights
        $this->assertArrayHasKey('presenceInsights', $result);

        // Team Lead should NOT get infra, IT, stock, admin widgets
        $this->assertArrayNotHasKey('totalStations', $result);
        $this->assertArrayNotHasKey('itConcernStats', $result);
        $this->assertArrayNotHasKey('stockSummary', $result);
        $this->assertArrayNotHasKey('userAccountStats', $result);
        $this->assertArrayNotHasKey('recentActivityLogs', $result);
    }

    #[Test]
    public function it_does_not_include_deprecated_ssd_hdd_in_all_stats(): void
    {
        $result = $this->service->getAllStats('Super Admin');

        $this->assertArrayNotHasKey('ssdPcs', $result);
        $this->assertArrayNotHasKey('hddPcs', $result);
    }

    // ─── Pending Leave Approvals ─────────────────────────────────────────

    #[Test]
    public function it_returns_pending_leave_approvals_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        // Pending leave starting in 3 days — should appear
        LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
            'start_date' => now()->addDays(3)->format('Y-m-d'),
            'end_date' => now()->addDays(3)->format('Y-m-d'),
            'admin_approved_by' => null,
        ]);

        // Pending leave starting in 15 days — should NOT appear (beyond 10-day window)
        LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
            'start_date' => now()->addDays(15)->format('Y-m-d'),
            'end_date' => now()->addDays(15)->format('Y-m-d'),
            'admin_approved_by' => null,
        ]);

        $result = $this->service->getPendingLeaveApprovals($admin);

        $this->assertEquals(1, $result['count']);
        $this->assertCount(1, $result['requests']);
        $this->assertArrayHasKey('user_name', $result['requests'][0]);
        $this->assertArrayHasKey('leave_type', $result['requests'][0]);
    }

    #[Test]
    public function it_returns_pending_leave_approvals_for_hr(): void
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
            'start_date' => now()->addDays(2)->format('Y-m-d'),
            'end_date' => now()->addDays(2)->format('Y-m-d'),
            'hr_approved_by' => null,
        ]);

        $result = $this->service->getPendingLeaveApprovals($hr);

        $this->assertEquals(1, $result['count']);
    }

    #[Test]
    public function it_filters_pending_leave_approvals_by_campaign_for_team_lead(): void
    {
        $campaign = Campaign::factory()->create();
        $otherCampaign = Campaign::factory()->create();
        $site = Site::factory()->create();

        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $tl->id,
            'campaign_id' => $campaign->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        // Agent in same campaign
        $sameCampaignAgent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $sameCampaignAgent->id,
            'campaign_id' => $campaign->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        // Agent in different campaign
        $otherAgent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $otherAgent->id,
            'campaign_id' => $otherCampaign->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        // Leave from same campaign agent — should appear
        LeaveRequest::factory()->create([
            'user_id' => $sameCampaignAgent->id,
            'status' => 'pending',
            'start_date' => now()->addDays(2)->format('Y-m-d'),
            'end_date' => now()->addDays(2)->format('Y-m-d'),
            'requires_tl_approval' => true,
            'tl_approved_by' => null,
            'tl_rejected' => false,
        ]);

        // Leave from other campaign agent — should NOT appear
        LeaveRequest::factory()->create([
            'user_id' => $otherAgent->id,
            'status' => 'pending',
            'start_date' => now()->addDays(2)->format('Y-m-d'),
            'end_date' => now()->addDays(2)->format('Y-m-d'),
            'requires_tl_approval' => true,
            'tl_approved_by' => null,
            'tl_rejected' => false,
        ]);

        $result = $this->service->getPendingLeaveApprovals($tl);

        $this->assertEquals(1, $result['count']);
        $this->assertCount(1, $result['requests']);
    }

    #[Test]
    public function it_excludes_already_approved_leave_from_pending(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        // Already admin-approved — should NOT appear
        LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
            'start_date' => now()->addDays(3)->format('Y-m-d'),
            'end_date' => now()->addDays(3)->format('Y-m-d'),
            'admin_approved_by' => $admin->id,
        ]);

        $result = $this->service->getPendingLeaveApprovals($admin);

        $this->assertEquals(0, $result['count']);
    }

    #[Test]
    public function it_returns_empty_for_tl_without_active_schedule(): void
    {
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);

        $result = $this->service->getPendingLeaveApprovals($tl);

        $this->assertEquals(0, $result['count']);
        $this->assertEmpty($result['requests']);
    }

    #[Test]
    public function it_excludes_past_leave_requests_from_pending(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        // Leave starting yesterday — should NOT appear
        LeaveRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'start_date' => now()->subDay()->format('Y-m-d'),
            'end_date' => now()->subDay()->format('Y-m-d'),
            'admin_approved_by' => null,
        ]);

        $result = $this->service->getPendingLeaveApprovals($admin);

        $this->assertEquals(0, $result['count']);
    }
}
