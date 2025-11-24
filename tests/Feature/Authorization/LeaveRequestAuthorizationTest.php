<?php

namespace Tests\Feature\Authorization;

use App\Models\LeaveRequest;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Feature tests for LeaveRequest authorization in real HTTP requests
 */
class LeaveRequestAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function agent_can_access_their_own_leave_request()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $agent->id]);

        $response = $this->actingAs($agent)->get(route('leave-requests.show', $leaveRequest));

        $response->assertOk();
    }

    /** @test */
    public function agent_cannot_access_other_users_leave_request()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($agent)->get(route('leave-requests.show', $leaveRequest));

        $response->assertForbidden();
    }

    /** @test */
    public function admin_can_access_any_leave_request()
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $agent = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $agent->id]);

        $response = $this->actingAs($admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertOk();
    }

    /** @test */
    public function agent_can_cancel_their_own_pending_leave_request()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($agent)
            ->post(route('leave-requests.cancel', $leaveRequest));

        $response->assertRedirect();
        $this->assertEquals('cancelled', $leaveRequest->fresh()->status);
    }

    /** @test */
    public function agent_cannot_cancel_other_users_leave_request()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($agent)
            ->post(route('leave-requests.cancel', $leaveRequest));

        $response->assertForbidden();
        $this->assertEquals('pending', $leaveRequest->fresh()->status);
    }

    /** @test */
    public function agent_cannot_cancel_approved_leave_request()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($agent)
            ->post(route('leave-requests.cancel', $leaveRequest));

        $response->assertForbidden();
        $this->assertEquals('approved', $leaveRequest->fresh()->status);
    }

    /** @test */
    public function agent_cannot_approve_leave_requests()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
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

    /** @test */
    public function admin_can_approve_leave_requests()
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $agent = User::factory()->create(['role' => 'Agent']);
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

    /** @test */
    public function hr_can_approve_leave_requests()
    {
        $hr = User::factory()->create(['role' => 'HR']);
        $agent = User::factory()->create(['role' => 'Agent']);
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
