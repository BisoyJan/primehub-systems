<?php

namespace Tests\Unit\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Policies\LeaveRequestPolicy;
use App\Services\PermissionService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

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
}
