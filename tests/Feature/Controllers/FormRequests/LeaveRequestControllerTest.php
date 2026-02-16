<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Mail\LeaveRequestStatusUpdated;
use App\Mail\LeaveRequestSubmitted;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaveRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

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
    public function it_displays_leave_requests_index()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('leave-requests.index'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Index')
                ->has('leaveRequests.data', 1)
                ->where('leaveRequests.data.0.id', $leaveRequest->id)
            );
    }

    #[Test]
    public function it_displays_create_form()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(), // Ensure eligible
        ]);
        // Ensure user has schedule for campaign logic
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('leave-requests.create'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Create')
                ->has('creditsSummary')
                ->has('attendancePoints')
            );
    }

    #[Test]
    public function it_stores_new_leave_request()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(), // Ensure eligible
            'email' => 'test@example.com',
        ]);

        // Give user some credits
        LeaveCredit::create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 10,
            'sick_leave_balance' => 10,
            'credits_earned' => 1.25,
            'credits_balance' => 10,
            'accrued_at' => now(),
        ]);

        // Must be at least 2 weeks in advance for VL
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDay();

        $response = $this->actingAs($user)->post(route('leave-requests.store'), [
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'reason' => 'Vacation Leave Request for Testing', // > 10 chars
            'campaign_department' => 'Tech',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'reason' => 'Vacation Leave Request for Testing',
            'status' => 'pending',
        ]);

        Mail::assertQueued(LeaveRequestSubmitted::class);
    }

    #[Test]
    public function it_prevents_duplicate_pending_requests()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
        ]);
        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->post(route('leave-requests.store'), [
            'leave_type' => 'VL',
            'start_date' => now()->addWeeks(3)->format('Y-m-d'),
            'end_date' => now()->addWeeks(3)->addDay()->format('Y-m-d'),
            'reason' => 'Vacation Leave Request for Testing',
            'campaign_department' => 'Tech',
        ]);

        $response->assertSessionHasErrors('error');
    }

    #[Test]
    public function it_shows_leave_request_details()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->where('leaveRequest.id', $leaveRequest->id)
            );
    }

    #[Test]
    public function it_allows_admin_to_approve_request()
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'email' => 'employee@example.com',
        ]);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'VL',
            'days_requested' => 1,
        ]);

        // Give user credits to deduct
        LeaveCredit::create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 5,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Approved',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);

        Mail::assertQueued(LeaveRequestStatusUpdated::class);
    }

    #[Test]
    public function it_allows_admin_to_deny_request()
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'email' => 'employee@example.com',
        ]);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'VL',
            'days_requested' => 1,
        ]);

        $response = $this->actingAs($admin)->post(route('leave-requests.deny', $leaveRequest), [
            'review_notes' => 'Denied due to workload', // > 10 chars
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'denied',
            'reviewed_by' => $admin->id,
        ]);

        Mail::assertQueued(LeaveRequestStatusUpdated::class);
    }

    #[Test]
    public function it_allows_user_to_cancel_own_pending_request()
    {
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'VL',
            'days_requested' => 1,
        ]);

        $response = $this->actingAs($user)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => 'I no longer need this leave.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'cancelled',
        ]);
    }

    #[Test]
    public function it_allows_user_to_cancel_own_partially_approved_request()
    {
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'leave_type' => 'VL',
            'days_requested' => 5,
            'has_partial_denial' => true,
            'approved_days' => 3,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(9),
        ]);

        $response = $this->actingAs($user)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => 'Plans changed, cancelling partial approval.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'cancelled',
            'cancelled_by' => $user->id,
        ]);
    }

    #[Test]
    public function it_prevents_user_from_cancelling_fully_approved_request()
    {
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'leave_type' => 'VL',
            'days_requested' => 5,
            'has_partial_denial' => false,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(9),
        ]);

        $response = $this->actingAs($user)->post(route('leave-requests.cancel', $leaveRequest));

        $response->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'approved',
        ]);
    }

    #[Test]
    public function it_requires_cancellation_reason_when_cancelling()
    {
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'VL',
            'days_requested' => 1,
        ]);

        $response = $this->actingAs($user)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => '',
        ]);

        $response->assertSessionHasErrors('cancellation_reason');

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function it_calculates_days_correctly()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($user)->post(route('leave-requests.api.calculate-days'), [
            'start_date' => '2025-11-01', // Saturday
            'end_date' => '2025-11-05',   // Wednesday
        ]);

        // Expect 3 days (Mon, Tue, Wed)
        $response->assertStatus(200)
            ->assertJson(['days' => 3]);
    }

    #[Test]
    public function it_gets_credits_balance()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
        ]);
        LeaveCredit::create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 10,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 15,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('leave-requests.api.credits-balance'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'balance',
                'total_earned',
                'total_used',
            ]);
    }
}
