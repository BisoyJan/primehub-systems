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
        EmployeeSchedule::factory()->create(['user_id' => $agent->id]);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $agent->id]);

        $response = $this->actingAs($agent)->get(route('leave-requests.show', $leaveRequest));

        $response->assertOk();
    }

    #[Test]
    public function agent_cannot_access_other_users_leave_request()
    {
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create(['user_id' => $agent->id]);
        $otherUser = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create(['user_id' => $otherUser->id]);
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
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(5)->toDateString(),
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
        EmployeeSchedule::factory()->create(['user_id' => $agent->id]);
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

    // =====================================================================
    // Approved Leave Editing: Admin vs Super Admin
    // =====================================================================

    #[Test]
    public function admin_can_edit_approved_leave_dates_before_end_date()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'BL',
            'status' => 'approved',
            'start_date' => now()->addWeek(),
            'end_date' => now()->addWeek()->addDays(2),
            'campaign_department' => 'Sales',
        ]);

        $response = $this->actingAs($admin)->put(route('leave-requests.update', $leaveRequest), [
            'leave_type' => 'BL',
            'start_date' => $leaveRequest->start_date->format('Y-m-d'),
            'end_date' => now()->addWeek()->addDays(3)->format('Y-m-d'),
            'reason' => $leaveRequest->reason,
            'campaign_department' => 'Sales',
            'date_modification_reason' => 'Extending leave by a day.',
        ]);

        $response->assertRedirect(route('leave-requests.show', $leaveRequest));
        $response->assertSessionDoesntHaveErrors();
    }

    #[Test]
    public function admin_cannot_edit_approved_leave_once_end_date_has_passed()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'BL',
            'status' => 'approved',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->subDays(3)->toDateString(),
            'campaign_department' => 'Sales',
        ]);

        $response = $this->actingAs($admin)->put(route('leave-requests.update', $leaveRequest), [
            'leave_type' => 'BL',
            'start_date' => $leaveRequest->start_date->format('Y-m-d'),
            'end_date' => now()->subDays(2)->format('Y-m-d'),
            'reason' => $leaveRequest->reason,
            'campaign_department' => 'Sales',
            'date_modification_reason' => 'Testing past edit block.',
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertEquals(
            now()->subDays(3)->toDateString(),
            $leaveRequest->fresh()->end_date->toDateString()
        );
    }

    #[Test]
    public function super_admin_can_edit_approved_leave_before_end_date()
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'BL',
            'status' => 'approved',
            'start_date' => now()->addWeek(),
            'end_date' => now()->addWeek()->addDays(2),
            'campaign_department' => 'Sales',
            'reason' => 'Original reason for this leave.',
        ]);

        $response = $this->actingAs($superAdmin)->put(route('leave-requests.update', $leaveRequest), [
            'leave_type' => 'BL',
            'start_date' => $leaveRequest->start_date->format('Y-m-d'),
            'end_date' => $leaveRequest->end_date->format('Y-m-d'),
            'reason' => 'Corrected reason for this leave.',
            'campaign_department' => 'Sales',
            'date_modification_reason' => 'Fixing records before the leave ends.',
        ]);

        $response->assertRedirect(route('leave-requests.show', $leaveRequest));
        $response->assertSessionDoesntHaveErrors();
        $this->assertEquals('Corrected reason for this leave.', $leaveRequest->fresh()->reason);
    }

    #[Test]
    public function super_admin_can_edit_approved_leave_once_end_date_has_passed()
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'BL',
            'status' => 'approved',
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->subDays(3)->toDateString(),
            'campaign_department' => 'Sales',
            'reason' => 'Original reason for this completed leave.',
        ]);

        $response = $this->actingAs($superAdmin)->put(route('leave-requests.update', $leaveRequest), [
            'leave_type' => 'BL',
            'start_date' => $leaveRequest->start_date->format('Y-m-d'),
            'end_date' => $leaveRequest->end_date->format('Y-m-d'),
            'reason' => 'Corrected reason for this completed leave.',
            'campaign_department' => 'Sales',
            'date_modification_reason' => 'Correcting records after the leave has ended.',
        ]);

        $response->assertRedirect(route('leave-requests.show', $leaveRequest));
        $response->assertSessionDoesntHaveErrors();
        $this->assertEquals('Corrected reason for this completed leave.', $leaveRequest->fresh()->reason);
    }
}
