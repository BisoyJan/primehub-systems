<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaveRequestIndexSortingTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_approved' => true,
        ]);
    }

    private function createTeamLeadWithCampaign(): array
    {
        $tl = $this->createUserWithRole('Team Lead');
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $tl->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return [$tl, $campaign];
    }

    /**
     * Helper to extract leave request IDs from an Inertia response in order.
     */
    private function getLeaveRequestIds($response): array
    {
        $page = $response->original->getData()['page'];

        return collect($page['props']['leaveRequests']['data'])->pluck('id')->toArray();
    }

    #[Test]
    public function admin_sees_items_needing_admin_approval_first(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $agent = $this->createUserWithRole('Agent');

        // Already admin-approved (should NOT be priority for Admin)
        $adminApproved = LeaveRequest::factory()->adminApproved($admin)->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(6),
            'leave_type' => 'BL',
        ]);

        // Needs admin approval (should be FIRST for Admin)
        $needsAdminApproval = LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(4),
            'leave_type' => 'BL',
        ]);

        // Approved request (should be LAST)
        $approved = LeaveRequest::factory()->fullyApproved()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(7),
            'end_date' => now()->addDays(8),
            'leave_type' => 'BL',
        ]);

        $response = $this->actingAs($admin)->get(route('leave-requests.index'));

        $response->assertOk();
        $ids = $this->getLeaveRequestIds($response);

        // needsAdminApproval should come before adminApproved and approved
        $this->assertLessThan(
            array_search($adminApproved->id, $ids),
            array_search($needsAdminApproval->id, $ids),
            'Pending item needing admin approval should appear before already-admin-approved item'
        );
        $this->assertLessThan(
            array_search($approved->id, $ids),
            array_search($needsAdminApproval->id, $ids),
            'Pending item needing admin approval should appear before fully approved item'
        );
    }

    #[Test]
    public function hr_sees_items_needing_hr_approval_first(): void
    {
        $hr = $this->createUserWithRole('HR');
        $agent = $this->createUserWithRole('Agent');

        // Already HR-approved (should NOT be priority for HR)
        $hrApproved = LeaveRequest::factory()->hrApproved($hr)->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(6),
            'leave_type' => 'BL',
        ]);

        // Needs HR approval (should be FIRST for HR)
        $needsHrApproval = LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(4),
            'leave_type' => 'BL',
        ]);

        $response = $this->actingAs($hr)->get(route('leave-requests.index'));

        $response->assertOk();
        $ids = $this->getLeaveRequestIds($response);

        $this->assertLessThan(
            array_search($hrApproved->id, $ids),
            array_search($needsHrApproval->id, $ids),
            'Pending item needing HR approval should appear before already-HR-approved item'
        );
    }

    #[Test]
    public function super_admin_sees_any_pending_needing_approval_first(): void
    {
        $superAdmin = $this->createUserWithRole('Super Admin');
        $agent = $this->createUserWithRole('Agent');

        // Fully approved (should be LAST)
        $approved = LeaveRequest::factory()->fullyApproved()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(7),
            'end_date' => now()->addDays(8),
            'leave_type' => 'BL',
        ]);

        // Pending with no approvals (should be FIRST — needs both)
        $pendingBoth = LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(4),
            'leave_type' => 'BL',
        ]);

        // Pending with only admin approved (still needs HR — should be priority)
        $pendingHrOnly = LeaveRequest::factory()->adminApproved($superAdmin)->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(6),
            'leave_type' => 'BL',
        ]);

        $response = $this->actingAs($superAdmin)->get(route('leave-requests.index'));

        $response->assertOk();
        $ids = $this->getLeaveRequestIds($response);

        // Both pending items should appear before the fully approved one
        $this->assertLessThan(
            array_search($approved->id, $ids),
            array_search($pendingBoth->id, $ids),
            'Pending item needing both approvals should appear before fully approved'
        );
        $this->assertLessThan(
            array_search($approved->id, $ids),
            array_search($pendingHrOnly->id, $ids),
            'Pending item needing HR approval should appear before fully approved'
        );
    }

    #[Test]
    public function team_lead_sees_items_needing_tl_approval_first(): void
    {
        [$teamLead, $campaign] = $this->createTeamLeadWithCampaign();
        $agent = $this->createUserWithRole('Agent');

        // TL's own leave (does not need TL approval)
        $ownLeave = LeaveRequest::factory()->pending()->create([
            'user_id' => $teamLead->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(2),
            'leave_type' => 'BL',
            'campaign_department' => $campaign->name,
        ]);

        // Agent leave needing TL approval (should be FIRST)
        $needsTlApproval = LeaveRequest::factory()->pending()->requiresTlApproval()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(4),
            'leave_type' => 'BL',
            'campaign_department' => $campaign->name,
        ]);

        $response = $this->actingAs($teamLead)->get(route('leave-requests.index'));

        $response->assertOk();
        $ids = $this->getLeaveRequestIds($response);

        $this->assertLessThan(
            array_search($ownLeave->id, $ids),
            array_search($needsTlApproval->id, $ids),
            'Agent leave needing TL approval should appear before TL own leave'
        );
    }

    #[Test]
    public function upcoming_pending_leaves_appear_before_past_pending(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $agent = $this->createUserWithRole('Agent');

        // Past pending leave (start_date already passed)
        $pastPending = LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => now()->subDays(5),
            'end_date' => now()->subDays(3),
            'leave_type' => 'BL',
        ]);

        // Upcoming pending leave (start_date in the future)
        $upcomingPending = LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(2),
            'end_date' => now()->addDays(3),
            'leave_type' => 'BL',
        ]);

        $response = $this->actingAs($admin)->get(route('leave-requests.index'));

        $response->assertOk();
        $ids = $this->getLeaveRequestIds($response);

        $this->assertLessThan(
            array_search($pastPending->id, $ids),
            array_search($upcomingPending->id, $ids),
            'Upcoming pending leave should appear before past pending leave'
        );
    }

    #[Test]
    public function pending_items_appear_before_non_pending(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $agent = $this->createUserWithRole('Agent');

        // Cancelled leave
        $cancelled = LeaveRequest::factory()->cancelled()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(2),
            'leave_type' => 'BL',
        ]);

        // Pending leave (should appear first)
        $pending = LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(4),
            'leave_type' => 'BL',
        ]);

        // Denied leave
        $denied = LeaveRequest::factory()->denied()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(6),
            'leave_type' => 'BL',
        ]);

        $response = $this->actingAs($admin)->get(route('leave-requests.index'));

        $response->assertOk();
        $ids = $this->getLeaveRequestIds($response);

        $this->assertLessThan(
            array_search($cancelled->id, $ids),
            array_search($pending->id, $ids),
            'Pending leave should appear before cancelled leave'
        );
        $this->assertLessThan(
            array_search($denied->id, $ids),
            array_search($pending->id, $ids),
            'Pending leave should appear before denied leave'
        );
    }

    #[Test]
    public function tl_pending_items_not_ready_for_admin_are_deprioritized_for_admin(): void
    {
        $admin = $this->createUserWithRole('Admin');
        $agent = $this->createUserWithRole('Agent');

        // Pending but requires TL approval and TL hasn't approved yet
        // Should NOT be top priority for Admin (TL must act first)
        $waitingForTl = LeaveRequest::factory()->pending()->requiresTlApproval()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(2),
            'leave_type' => 'BL',
        ]);

        // Pending and ready for admin (no TL approval required)
        $readyForAdmin = LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(4),
            'leave_type' => 'BL',
        ]);

        $response = $this->actingAs($admin)->get(route('leave-requests.index'));

        $response->assertOk();
        $ids = $this->getLeaveRequestIds($response);

        $this->assertLessThan(
            array_search($waitingForTl->id, $ids),
            array_search($readyForAdmin->id, $ids),
            'Ready-for-admin item should appear before item still waiting for TL approval'
        );
    }

    #[Test]
    public function agent_sees_own_leaves_sorted_by_upcoming_pending_first(): void
    {
        $agent = $this->createUserWithRole('Agent');

        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $agent->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        // Past pending
        $pastPending = LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => now()->subDays(5),
            'end_date' => now()->subDays(3),
            'leave_type' => 'BL',
        ]);

        // Upcoming pending (should appear first even for regular employees)
        $upcomingPending = LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(2),
            'end_date' => now()->addDays(3),
            'leave_type' => 'BL',
        ]);

        // Approved (should appear last)
        $approved = LeaveRequest::factory()->fullyApproved()->create([
            'user_id' => $agent->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(2),
            'leave_type' => 'BL',
        ]);

        $response = $this->actingAs($agent)->get(route('leave-requests.index'));

        $response->assertOk();
        $ids = $this->getLeaveRequestIds($response);

        // Upcoming pending should come first
        $this->assertLessThan(
            array_search($pastPending->id, $ids),
            array_search($upcomingPending->id, $ids),
            'Upcoming pending should appear before past pending for agents'
        );
        $this->assertLessThan(
            array_search($approved->id, $ids),
            array_search($upcomingPending->id, $ids),
            'Upcoming pending should appear before approved for agents'
        );
    }
}
