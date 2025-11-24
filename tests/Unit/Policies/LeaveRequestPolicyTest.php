<?php

namespace Tests\Unit\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Policies\LeaveRequestPolicy;
use App\Services\PermissionService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    /** @test */
    public function super_admin_can_view_any_leave_requests()
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);

        $this->assertTrue($this->policy->viewAny($superAdmin));
    }

    /** @test */
    public function admin_can_view_any_leave_requests()
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->viewAny($admin));
    }

    /** @test */
    public function agent_can_view_any_leave_requests()
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->viewAny($agent));
    }

    /** @test */
    public function user_can_view_their_own_leave_request()
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($this->policy->view($user, $leaveRequest));
    }

    /** @test */
    public function agent_cannot_view_other_users_leave_request()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($agent, $leaveRequest));
    }

    /** @test */
    public function admin_can_view_all_leave_requests()
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $otherUser->id]);

        $this->assertTrue($this->policy->view($admin, $leaveRequest));
    }

    /** @test */
    public function hr_can_view_all_leave_requests()
    {
        $hr = User::factory()->create(['role' => 'HR']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $otherUser->id]);

        $this->assertTrue($this->policy->view($hr, $leaveRequest));
    }

    /** @test */
    public function agent_can_create_leave_requests()
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->create($agent));
    }

    /** @test */
    public function admin_can_approve_leave_requests()
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->approve($admin));
    }

    /** @test */
    public function agent_cannot_approve_leave_requests()
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->approve($agent));
    }

    /** @test */
    public function admin_can_deny_leave_requests()
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->deny($admin));
    }

    /** @test */
    public function user_can_cancel_their_own_pending_leave_request()
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->assertTrue($this->policy->cancel($user, $leaveRequest));
    }

    /** @test */
    public function user_cannot_cancel_other_users_leave_request()
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'pending',
        ]);

        $this->assertFalse($this->policy->cancel($user, $leaveRequest));
    }

    /** @test */
    public function user_cannot_cancel_approved_leave_request()
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
        ]);

        $this->assertFalse($this->policy->cancel($user, $leaveRequest));
    }

    /** @test */
    public function user_cannot_cancel_denied_leave_request()
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'denied',
        ]);

        $this->assertFalse($this->policy->cancel($user, $leaveRequest));
    }

    /** @test */
    public function admin_can_cancel_any_pending_leave_request()
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'pending',
        ]);

        $this->assertTrue($this->policy->cancel($admin, $leaveRequest));
    }
}
