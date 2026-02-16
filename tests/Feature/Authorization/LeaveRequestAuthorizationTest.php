<?php

namespace Tests\Feature\Authorization;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for LeaveRequest authorization in real HTTP requests
 *
 * Permissions are role-based via config/permissions.php:
 * - Agent: leave.view, leave.create, leave.cancel
 * - Admin: leave.view, leave.create, leave.approve, leave.deny, leave.cancel, leave.view_all
 * - HR: leave.view, leave.create, leave.approve, leave.deny, leave.view_all
 */
class LeaveRequestAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function createAgentWithSchedule(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'Agent',
            'is_approved' => true,
        ], $overrides));

        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return $user;
    }

    #[Test]
    public function agent_can_access_their_own_leave_request()
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $agent->id]);

        $response = $this->actingAs($agent)->get(route('leave-requests.show', $leaveRequest));

        $response->assertOk();
    }

    #[Test]
    public function agent_cannot_access_other_users_leave_request()
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $otherUser = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($agent)->get(route('leave-requests.show', $leaveRequest));

        $response->assertForbidden();
    }

    #[Test]
    public function admin_can_access_any_leave_request()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $agent->id]);

        $response = $this->actingAs($admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertOk();
    }

    #[Test]
    public function agent_can_cancel_their_own_pending_leave_request()
    {
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($agent)
            ->post(route('leave-requests.cancel', $leaveRequest), [
                'cancellation_reason' => 'No longer needed.',
            ]);

        $response->assertRedirect();
        $this->assertEquals('cancelled', $leaveRequest->fresh()->status);
    }

    #[Test]
    public function agent_cannot_cancel_other_users_leave_request()
    {
        $agent = $this->createAgentWithSchedule();
        $otherUser = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($agent)
            ->post(route('leave-requests.cancel', $leaveRequest), [
                'cancellation_reason' => 'Trying to cancel someone else.',
            ]);

        $response->assertForbidden();
        $this->assertEquals('pending', $leaveRequest->fresh()->status);
    }

    #[Test]
    public function agent_cannot_cancel_approved_leave_request()
    {
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($agent)
            ->post(route('leave-requests.cancel', $leaveRequest), [
                'cancellation_reason' => 'Trying to cancel approved leave.',
            ]);

        $response->assertForbidden();
        $this->assertEquals('approved', $leaveRequest->fresh()->status);
    }

    #[Test]
    public function agent_cannot_approve_leave_requests()
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($agent)
            ->post(route('leave-requests.approve', $leaveRequest), [
                'review_notes' => 'Test approval',
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function admin_can_approve_leave_requests()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('leave-requests.approve', $leaveRequest), [
                'review_notes' => 'Approved',
            ]);

        $response->assertRedirect();
    }

    #[Test]
    public function hr_can_approve_leave_requests()
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($hr)
            ->post(route('leave-requests.approve', $leaveRequest), [
                'review_notes' => 'Approved by HR',
            ]);

        $response->assertRedirect();
    }
}
