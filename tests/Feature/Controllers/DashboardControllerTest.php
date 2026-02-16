<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\EmployeeSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Create an approved user with the given role and, for Agent/Team Lead,
     * ensure an active EmployeeSchedule exists so middleware won't redirect.
     */
    private function createUserForRole(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'is_approved' => true,
        ]);

        // Agent & Team Lead need an active schedule or they get redirected
        if (in_array($role, ['Agent', 'Team Lead'])) {
            EmployeeSchedule::factory()->create([
                'user_id' => $user->id,
                'is_active' => true,
            ]);
        }

        return $user;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Auth Guards
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    #[Test]
    public function unapproved_users_are_redirected_away(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => false,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('pending-approval'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // 7.1 — Feature tests: each role can load dashboard with correct Inertia page
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_access_dashboard(): void
    {
        $this->actingAs($this->createUserForRole('Super Admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('userRole')
                ->where('userRole', 'Super Admin')
            );
    }

    #[Test]
    public function admin_can_access_dashboard(): void
    {
        $this->actingAs($this->createUserForRole('Admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('userRole', 'Admin')
            );
    }

    #[Test]
    public function hr_can_access_dashboard(): void
    {
        $this->actingAs($this->createUserForRole('HR'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('userRole', 'HR')
            );
    }

    #[Test]
    public function it_role_can_access_dashboard(): void
    {
        $this->actingAs($this->createUserForRole('IT'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('userRole', 'IT')
            );
    }

    #[Test]
    public function team_lead_can_access_dashboard(): void
    {
        $this->actingAs($this->createUserForRole('Team Lead'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('userRole', 'Team Lead')
            );
    }

    #[Test]
    public function agent_can_access_dashboard(): void
    {
        $this->actingAs($this->createUserForRole('Agent'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('userRole', 'Agent')
                ->where('isRestrictedRole', true)
            );
    }

    #[Test]
    public function utility_can_access_dashboard(): void
    {
        $this->actingAs($this->createUserForRole('Utility'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('userRole', 'Utility')
                ->where('isRestrictedRole', true)
            );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 7.2 — Restricted roles do NOT receive admin-only data
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_receives_infrastructure_data(): void
    {
        $this->actingAs($this->createUserForRole('Super Admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('totalStations')
                ->has('noPcs')
                ->has('vacantStations')
                ->has('dualMonitor')
                ->has('maintenanceDue')
                ->has('unassignedPcSpecs')
                ->has('itConcernStats')
                ->has('stockSummary')
                ->has('userAccountStats')
                ->has('recentActivityLogs')
                ->has('biometricAnomalies')
                ->has('pointsEscalation')
                ->has('ncnsTrend')
                ->has('leaveUtilization')
                ->has('campaignPresence')
                ->has('pointsByCampaign')
            );
    }

    #[Test]
    public function agent_does_not_receive_admin_only_data(): void
    {
        $this->actingAs($this->createUserForRole('Agent'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                // Agent should have personal data
                ->has('personalSchedule')
                ->has('personalRequests')
                ->has('personalAttendanceSummary')
                ->has('notificationSummary')
                // Agent should NOT have admin-only props
                ->missing('totalStations')
                ->missing('itConcernStats')
                ->missing('stockSummary')
                ->missing('userAccountStats')
                ->missing('recentActivityLogs')
                ->missing('biometricAnomalies')
                ->missing('pointsEscalation')
                ->missing('campaignPresence')
                ->missing('pointsByCampaign')
            );
    }

    #[Test]
    public function utility_does_not_receive_admin_only_data(): void
    {
        $this->actingAs($this->createUserForRole('Utility'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                // Utility should have personal data
                ->has('personalSchedule')
                ->has('personalRequests')
                ->has('personalAttendanceSummary')
                // Utility should NOT have infra, IT, or admin props
                ->missing('totalStations')
                ->missing('itConcernStats')
                ->missing('stockSummary')
                ->missing('userAccountStats')
                ->missing('recentActivityLogs')
                // Utility should NOT have presence insights
                ->missing('presenceInsights')
                ->missing('campaignPresence')
            );
    }

    #[Test]
    public function hr_gets_escalation_but_not_campaign_data(): void
    {
        $this->actingAs($this->createUserForRole('HR'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('pointsEscalation')
                ->has('ncnsTrend')
                ->has('leaveUtilization')
                ->has('biometricAnomalies')
                // HR should NOT get campaign data or infrastructure
                ->missing('campaignPresence')
                ->missing('pointsByCampaign')
                ->missing('totalStations')
                ->missing('itConcernStats')
                ->missing('stockSummary')
            );
    }

    #[Test]
    public function it_role_gets_infrastructure_but_not_admin_widgets(): void
    {
        $this->actingAs($this->createUserForRole('IT'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('totalStations')
                ->has('itConcernStats')
                ->has('stockSummary')
                // IT should NOT get admin-only widgets
                ->missing('userAccountStats')
                ->missing('recentActivityLogs')
                ->missing('biometricAnomalies')
                // IT should NOT get Phase 4 analytics
                ->missing('pointsEscalation')
                ->missing('campaignPresence')
            );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 7.4 — Cache is role-specific (no cross-role data leaks)
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function cache_key_is_role_specific(): void
    {
        Cache::flush();

        // Load dashboard as Super Admin — this caches with role in key
        $this->actingAs($this->createUserForRole('Super Admin'))
            ->get(route('dashboard'))
            ->assertOk();

        // Load dashboard as Agent — should use a different cache key
        $agent = $this->createUserForRole('Agent');
        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Agent should NOT see infra data even if Super Admin cached first
                ->missing('totalStations')
                ->missing('itConcernStats')
                ->has('personalSchedule')
            );
    }

    #[Test]
    public function deprecated_ssd_hdd_props_are_not_returned(): void
    {
        $this->actingAs($this->createUserForRole('Super Admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->missing('ssdPcs')
                ->missing('hddPcs')
            );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Common props present for all roles
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function all_roles_receive_common_props(): void
    {
        foreach (['Super Admin', 'Admin', 'HR', 'IT', 'Team Lead', 'Agent', 'Utility'] as $role) {
            $user = $this->createUserForRole($role);

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('dashboard')
                    ->has('attendanceStatistics')
                    ->has('monthlyAttendanceData')
                    ->has('startDate')
                    ->has('endDate')
                    ->has('userRole')
                    ->has('notificationSummary')
                );
        }
    }
}
