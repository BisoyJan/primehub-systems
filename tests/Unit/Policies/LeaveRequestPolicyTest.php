<?php

namespace Tests\Unit\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Policies\LeaveRequestPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaveRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected LeaveRequestPolicy $policy;

    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new LeaveRequestPolicy($this->permissionService);
    }

    #[Test]
    public function super_admin_can_view_any_leave_requests(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);

        $this->assertTrue($this->policy->viewAny($superAdmin));
    }

    #[Test]
    public function admin_can_view_any_leave_requests(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->viewAny($admin));
    }

    #[Test]
    public function agent_can_view_any_leave_requests(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->viewAny($agent));
    }

    #[Test]
    public function user_can_view_their_own_leave_request(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($this->policy->view($user, $leaveRequest));
    }

    #[Test]
    public function agent_cannot_view_other_users_leave_request(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($agent, $leaveRequest));
    }

    #[Test]
    public function admin_can_view_all_leave_requests(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $otherUser->id]);

        $this->assertTrue($this->policy->view($admin, $leaveRequest));
    }

    #[Test]
    public function hr_can_view_all_leave_requests(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $otherUser->id]);

        $this->assertTrue($this->policy->view($hr, $leaveRequest));
    }

    #[Test]
    public function agent_can_create_leave_requests(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->create($agent));
    }

    #[Test]
    public function admin_can_approve_leave_requests(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->approve($admin));
    }

    #[Test]
    public function agent_cannot_approve_leave_requests(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->approve($agent));
    }

    #[Test]
    public function admin_can_deny_leave_requests(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->deny($admin));
    }

    #[Test]
    public function user_can_cancel_their_own_pending_leave_request(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->assertTrue($this->policy->cancel($user, $leaveRequest));
    }

    #[Test]
    public function user_cannot_cancel_other_users_leave_request(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'pending',
        ]);

        $this->assertFalse($this->policy->cancel($user, $leaveRequest));
    }

    #[Test]
    public function user_cannot_cancel_approved_leave_request(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
        ]);

        $this->assertFalse($this->policy->cancel($user, $leaveRequest));
    }

    #[Test]
    public function user_cannot_cancel_denied_leave_request(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'denied',
        ]);

        $this->assertFalse($this->policy->cancel($user, $leaveRequest));
    }

    #[Test]
    public function admin_can_cancel_any_pending_leave_request(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'pending',
        ]);

        $this->assertTrue($this->policy->cancel($admin, $leaveRequest));
    }

    #[Test]
    public function user_can_cancel_own_partially_approved_leave_request(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'has_partial_denial' => true,
            'approved_days' => 3,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(7),
        ]);

        $this->assertTrue($this->policy->cancel($user, $leaveRequest));
    }

    #[Test]
    public function user_can_cancel_partially_approved_leave_request_that_has_started(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'has_partial_denial' => true,
            'approved_days' => 3,
            'start_date' => now()->subDays(1),
            'end_date' => now()->addDays(3),
        ]);

        $this->assertTrue($this->policy->cancel($user, $leaveRequest));
    }

    #[Test]
    public function user_cannot_cancel_other_users_partially_approved_leave_request(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'approved',
            'has_partial_denial' => true,
            'approved_days' => 3,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(7),
        ]);

        $this->assertFalse($this->policy->cancel($user, $leaveRequest));
    }

    // --- Past-date cancellation tests ---

    #[Test]
    public function agent_can_cancel_own_past_date_pending_leave_request(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(7),
        ]);

        $this->assertTrue($this->policy->cancel($user, $leaveRequest));
    }

    #[Test]
    public function agent_can_cancel_own_past_date_partially_approved_leave_request(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'has_partial_denial' => true,
            'approved_days' => 3,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(7),
        ]);

        $this->assertTrue($this->policy->cancel($user, $leaveRequest));
    }

    #[Test]
    public function agent_cannot_cancel_own_past_date_fully_approved_leave_request(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'has_partial_denial' => false,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(7),
        ]);

        $this->assertFalse($this->policy->cancel($user, $leaveRequest));
    }

    #[Test]
    public function admin_cannot_cancel_past_date_approved_leave_request(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'approved',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(7),
        ]);

        $this->assertFalse($this->policy->cancel($admin, $leaveRequest));
    }

    #[Test]
    public function admin_can_cancel_future_date_approved_leave_request(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'approved',
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(5),
        ]);

        $this->assertTrue($this->policy->cancel($admin, $leaveRequest));
    }

    #[Test]
    public function hr_cannot_cancel_past_date_approved_leave_request(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'approved',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(7),
        ]);

        $this->assertFalse($this->policy->cancel($hr, $leaveRequest));
    }

    #[Test]
    public function hr_can_cancel_future_date_approved_leave_request(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'approved',
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(5),
        ]);

        $this->assertTrue($this->policy->cancel($hr, $leaveRequest));
    }

    #[Test]
    public function team_lead_cannot_cancel_past_date_approved_leave_request(): void
    {
        $tl = User::factory()->create(['role' => 'Team Lead']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'approved',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(7),
        ]);

        $this->assertFalse($this->policy->cancel($tl, $leaveRequest));
    }

    #[Test]
    public function hr_can_cancel_past_date_pending_leave_request(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'pending',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(7),
        ]);

        $this->assertTrue($this->policy->cancel($hr, $leaveRequest));
    }

    #[Test]
    public function cancel_approved_denies_hr_for_past_date(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'approved',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(7),
        ]);

        $this->assertFalse($this->policy->cancelApproved($hr, $leaveRequest));
    }

    #[Test]
    public function cancel_approved_allows_hr_for_future_date(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'approved',
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(5),
        ]);

        $this->assertTrue($this->policy->cancelApproved($hr, $leaveRequest));
    }

    #[Test]
    public function cancel_approved_denies_team_lead_for_past_date(): void
    {
        $tl = User::factory()->create(['role' => 'Team Lead']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'approved',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(7),
        ]);

        $this->assertFalse($this->policy->cancelApproved($tl, $leaveRequest));
    }

    #[Test]
    public function cancel_approved_allows_team_lead_for_future_date(): void
    {
        $tl = User::factory()->create(['role' => 'Team Lead']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'approved',
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(5),
        ]);

        $this->assertTrue($this->policy->cancelApproved($tl, $leaveRequest));
    }

    #[Test]
    public function cancel_approved_denies_agent(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'approved',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(7),
        ]);

        $this->assertFalse($this->policy->cancelApproved($agent, $leaveRequest));
    }
}
