<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Http\Middleware\EnsureUserHasSchedule;
use App\Mail\LeaveRequestStatusUpdated;
use App\Mail\LeaveRequestSubmitted;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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
        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            EnsureUserHasSchedule::class,
        ]);
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

        // Give user sufficient credits (2 days needed: Mon + Tue)
        LeaveCredit::create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 10,
            'sick_leave_balance' => 10,
            'credits_earned' => 10,
            'credits_used' => 0,
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
        $user = $this->createAgentWithSchedule([
            'hired_date' => now()->subYear(),
        ]);

        // Give user sufficient credits
        LeaveCredit::create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 10,
            'credits_used' => 0,
            'credits_balance' => 10,
            'accrued_at' => now(),
        ]);

        // Use same dates and leave type for the existing and new request
        $startDate = now()->addWeeks(3)->startOfWeek()->format('Y-m-d');
        $endDate = now()->addWeeks(3)->startOfWeek()->addDay()->format('Y-m-d');

        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $response = $this->actingAs($user)->post(route('leave-requests.store'), [
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
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
        $hr = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'email' => 'employee@example.com',
            'hired_date' => now()->subYear(),
        ]);
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'VL',
            'start_date' => now()->addDays(7)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
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

        $dayStatuses = [
            ['date' => now()->addDays(7)->format('Y-m-d'), 'status' => 'vl_credited'],
        ];

        // Admin approves first (partial approval - still needs HR)
        $response = $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Approved by Admin for this request.',
            'day_statuses' => $dayStatuses,
        ]);

        $response->assertRedirect();

        // Status should still be pending (waiting for HR)
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'pending',
            'admin_approved_by' => $admin->id,
        ]);

        // HR approves second (completes dual approval)
        $response = $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Approved by HR for this request.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'approved',
            'reviewed_by' => $hr->id,
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
    public function it_allows_agent_to_cancel_own_past_date_pending_request()
    {
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'VL',
            'days_requested' => 1,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(8),
        ]);

        $response = $this->actingAs($user)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => 'Cancelling old pending request.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'cancelled',
            'cancelled_by' => $user->id,
        ]);
    }

    #[Test]
    public function it_allows_agent_to_cancel_own_past_date_partially_approved_request()
    {
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'leave_type' => 'VL',
            'days_requested' => 5,
            'has_partial_denial' => true,
            'approved_days' => 3,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(6),
        ]);

        $response = $this->actingAs($user)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => 'Cancelling old partial approval.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'cancelled',
            'cancelled_by' => $user->id,
        ]);
    }

    #[Test]
    public function it_prevents_agent_from_cancelling_own_past_date_fully_approved_request()
    {
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'leave_type' => 'VL',
            'days_requested' => 5,
            'has_partial_denial' => false,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(6),
        ]);

        $response = $this->actingAs($user)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => 'Trying to cancel approved past leave.',
        ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'approved',
        ]);
    }

    #[Test]
    public function it_blocks_admin_from_cancelling_past_date_approved_request()
    {
        $admin = $this->createAgentWithSchedule(['role' => 'Admin']);
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'leave_type' => 'VL',
            'days_requested' => 3,
            'has_partial_denial' => false,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(8),
        ]);

        $response = $this->actingAs($admin)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => 'Employee did not actually take this leave.',
        ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'approved',
        ]);
    }

    #[Test]
    public function it_allows_admin_to_cancel_future_date_approved_request()
    {
        $admin = $this->createAgentWithSchedule(['role' => 'Admin']);
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'leave_type' => 'VL',
            'days_requested' => 3,
            'has_partial_denial' => false,
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(5),
        ]);

        $response = $this->actingAs($admin)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => 'Employee requested cancellation.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'cancelled',
            'cancelled_by' => $admin->id,
        ]);
    }

    #[Test]
    public function it_blocks_hr_from_cancelling_past_date_approved_request()
    {
        $hr = $this->createAgentWithSchedule(['role' => 'HR']);
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'leave_type' => 'VL',
            'days_requested' => 2,
            'has_partial_denial' => false,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(9),
        ]);

        $response = $this->actingAs($hr)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => 'HR correction: employee worked during leave.',
        ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'approved',
        ]);
    }

    #[Test]
    public function it_blocks_team_lead_from_cancelling_past_date_approved_request()
    {
        $tl = $this->createAgentWithSchedule(['role' => 'Team Lead']);
        $user = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'leave_type' => 'VL',
            'days_requested' => 1,
            'has_partial_denial' => false,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(10),
        ]);

        $response = $this->actingAs($tl)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => 'TL correction: agent reported to work.',
        ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'approved',
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

    #[Test]
    public function it_allows_vl_submission_when_credits_insufficient_with_warning()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'test@example.com',
        ]);

        // Give user only 1.25 credits
        LeaveCredit::create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => now(),
        ]);

        // Request 5 working days (Mon-Fri) — exceeds 1.25 credits but allowed
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(4);

        $response = $this->actingAs($user)->post(route('leave-requests.store'), [
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'reason' => 'Vacation Leave Request for Testing',
            'campaign_department' => 'Tech',
        ]);

        $response->assertRedirect();

        // VL submission is now allowed with insufficient credits (UPTO conversion at approval)
        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function it_allows_sl_submission_without_sufficient_credits()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'test@example.com',
        ]);

        // Give user 0 credits
        LeaveCredit::create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 0,
            'credits_used' => 0,
            'credits_balance' => 0,
            'accrued_at' => now(),
        ]);

        // Request SL with no credits — should succeed (SL handles at approval time)
        $startDate = now()->addDays(1);
        if ($startDate->isWeekend()) {
            $startDate = $startDate->next(\Carbon\Carbon::MONDAY);
        }
        $endDate = $startDate->copy();

        $response = $this->actingAs($user)->post(route('leave-requests.store'), [
            'leave_type' => 'SL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'reason' => 'Sick Leave Request for Testing',
            'campaign_department' => 'Tech',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $user->id,
            'leave_type' => 'SL',
            'status' => 'pending',
        ]);
    }

    // =====================================================================
    // Phase 5: VL Credit Check & SL Per-Day Status at Approval Tests
    // =====================================================================

    #[Test]
    public function it_approves_vl_with_full_credits_and_deducts_normally()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-vl-full@example.com',
        ]);

        // Give agent 10 credits (enough for the request)
        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 10,
            'credits_used' => 0,
            'credits_balance' => 10,
            'accrued_at' => now(),
        ]);

        // Create 2-day VL request (Mon-Tue future)
        $startDate = now()->addWeeks(3)->startOfWeek(); // Monday
        $endDate = $startDate->copy()->addDay(); // Tuesday

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 2,
            'status' => 'pending',
        ]);

        // Admin approves with day_statuses (all vl_credited since full credits available)
        $dayStatuses = [
            ['date' => $startDate->format('Y-m-d'), 'status' => 'vl_credited'],
            ['date' => $endDate->format('Y-m-d'), 'status' => 'vl_credited'],
        ];

        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Approved by Admin - full credits test.',
            'day_statuses' => $dayStatuses,
        ]);

        // HR approves with day_statuses (triggers final approval + credit handling)
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Approved by HR - full credits test.',
            'day_statuses' => $dayStatuses,
        ]);

        $leaveRequest->refresh();

        // VL stays as VL, status approved, credits deducted
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals('VL', $leaveRequest->leave_type);
        $this->assertEquals(2, (float) $leaveRequest->credits_deducted);

        // Credits should be deducted in leave_credits table
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(2, (float) $credit->credits_used);
    }

    #[Test]
    public function it_shows_partial_credit_preview_when_vl_credits_insufficient()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-vl-partial@example.com',
        ]);

        // Give agent only 2 credits (less than 4 days requested)
        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 2,
            'credits_used' => 0,
            'credits_balance' => 2,
            'accrued_at' => now(),
        ]);

        // Create 4-day VL request
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(3);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 4,
            'status' => 'pending',
        ]);

        // View show page — credit preview should indicate partial credit (2 VL + 2 UPTO)
        $response = $this->actingAs($admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('creditPreview')
                ->where('creditPreview.should_deduct', true)
                ->where('creditPreview.partial_credit', true)
                ->where('creditPreview.credits_to_deduct', 2)
                ->where('creditPreview.upto_days', 2)
            );

        // Credits should NOT have been deducted yet
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(0, (float) $credit->credits_used);
    }

    #[Test]
    public function it_shows_upto_conversion_preview_when_vl_zero_credits()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-vl-zero@example.com',
        ]);

        // Give agent 0 credits
        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 0,
            'credits_used' => 0,
            'credits_balance' => 0,
            'accrued_at' => now(),
        ]);

        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDay();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 2,
            'status' => 'pending',
        ]);

        // Show page should indicate all UPTO conversion
        $response = $this->actingAs($admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('creditPreview')
                ->where('creditPreview.should_deduct', false)
                ->where('creditPreview.convert_to_upto', true)
                ->where('creditPreview.upto_days', 2)
            );
    }

    #[Test]
    public function it_assigns_per_day_statuses_when_sl_approved_with_partial_credits()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-sl-partial@example.com',
        ]);

        // Give agent only 1 credit
        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 1,
            'credits_used' => 0,
            'credits_balance' => 1,
            'accrued_at' => now(),
        ]);

        // Create 3-day SL request (Mon-Wed)
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(2);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'SL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 3,
            'medical_cert_submitted' => true,
            'status' => 'pending',
        ]);

        // Dual approval (with explicit day_statuses — required for SL)
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');
        $midDateStr = $startDate->copy()->addDay()->format('Y-m-d');

        $dayStatuses = [
            ['date' => $startDateStr, 'status' => 'sl_credited'],
            ['date' => $midDateStr, 'status' => 'advised_absence'],
            ['date' => $endDateStr, 'status' => 'advised_absence'],
        ];

        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves partial SL test.',
            'day_statuses' => $dayStatuses,
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves partial SL test.',
        ]);

        $leaveRequest->refresh();

        // SL stays as SL with per-day statuses (no narrowing, no companion UPTO)
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals('SL', $leaveRequest->leave_type);
        $this->assertEquals(1, (float) $leaveRequest->credits_deducted);

        // Parent dates should NOT be narrowed — SL keeps original dates
        $this->assertEquals($startDate->format('Y-m-d'), $leaveRequest->start_date->format('Y-m-d'));
        $this->assertEquals($endDate->format('Y-m-d'), $leaveRequest->end_date->format('Y-m-d'));

        // Per-day status records should exist: 1 sl_credited, 2 advised_absence
        $dayRecords = \App\Models\LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(3, $dayRecords);
        $this->assertEquals(1, $dayRecords->where('day_status', 'sl_credited')->count());
        $this->assertEquals(2, $dayRecords->where('day_status', 'advised_absence')->count());
    }

    #[Test]
    public function it_includes_credit_preview_in_show_page_for_pending_vl()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-show-preview@example.com',
        ]);

        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 2,
            'credits_used' => 0,
            'credits_balance' => 2,
            'accrued_at' => now(),
        ]);

        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(3);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 4,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('creditPreview')
                ->where('creditPreview.partial_credit', true)
                ->where('creditPreview.credits_to_deduct', 2)
                ->where('creditPreview.upto_days', 2)
                ->has('suggestedDayStatuses')
            );
    }

    #[Test]
    public function it_shows_upto_conversion_preview_when_agent_not_eligible()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        // Agent hired less than 6 months ago — not eligible for VL credits
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subMonths(3),
            'email' => 'agent-vl-ineligible@example.com',
        ]);

        // Even with credits, ineligibility means all days convert to UPTO
        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 5,
            'credits_used' => 0,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDay();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 2,
            'status' => 'pending',
        ]);

        // Show page should indicate all days convert to UPTO for ineligible agent
        $response = $this->actingAs($admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('creditPreview')
                ->where('creditPreview.should_deduct', false)
                ->where('creditPreview.convert_to_upto', true)
                ->where('creditPreview.upto_days', 2)
                ->has('suggestedDayStatuses')
            );

        // No credits should be deducted yet (just preview)
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(0, (float) $credit->credits_used);
    }

    // =====================================================================
    // Fractional Credit Floor Tests
    // =====================================================================

    #[Test]
    public function it_shows_partial_credit_preview_when_fractional_credits_insufficient()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-vl-frac@example.com',
        ]);

        // Give agent 2.75 credits — floor to 2 whole days, requesting 5 → partial credit
        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 2.75,
            'credits_used' => 0,
            'credits_balance' => 2.75,
            'accrued_at' => now(),
        ]);

        // 5-day VL (Mon-Fri)
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(4);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 5,
            'status' => 'pending',
        ]);

        // Show page should indicate partial credit (floor(2.75)=2 credited, 3 UPTO)
        $response = $this->actingAs($admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('creditPreview')
                ->where('creditPreview.should_deduct', true)
                ->where('creditPreview.partial_credit', true)
                ->where('creditPreview.credits_to_deduct', 2)
                ->where('creditPreview.upto_days', 3)
                ->has('suggestedDayStatuses')
            );

        // Credits untouched (just preview)
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(0, (float) $credit->credits_used);
        $this->assertEquals(2.75, (float) $credit->credits_balance);
    }

    #[Test]
    public function it_shows_upto_conversion_when_only_fractional_credits_remain()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-vl-frac-zero@example.com',
        ]);

        // Give agent 0.75 credits — floor(0.75) = 0, all days convert to UPTO
        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 0.75,
            'credits_used' => 0,
            'credits_balance' => 0.75,
            'accrued_at' => now(),
        ]);

        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDay();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 2,
            'status' => 'pending',
        ]);

        // Show page should indicate all days convert to UPTO
        $response = $this->actingAs($admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('creditPreview')
                ->where('creditPreview.should_deduct', false)
                ->where('creditPreview.convert_to_upto', true)
                ->where('creditPreview.upto_days', 2)
                ->has('suggestedDayStatuses')
            );

        // Credits untouched (just preview)
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(0, (float) $credit->credits_used);
        $this->assertEquals(0.75, (float) $credit->credits_balance);
    }

    #[Test]
    public function it_floors_fractional_sl_credits_to_whole_number_on_approval()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-sl-frac@example.com',
        ]);

        // Give agent 1.25 credits — floor to 1
        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => now(),
        ]);

        // 3-day SL (Mon-Wed)
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(2);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'SL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 3,
            'medical_cert_submitted' => true,
            'status' => 'pending',
        ]);

        // Dual approval (with explicit day_statuses — required for SL)
        $dayStatuses = [
            ['date' => $startDate->format('Y-m-d'), 'status' => 'sl_credited'],
            ['date' => $startDate->copy()->addDay()->format('Y-m-d'), 'status' => 'advised_absence'],
            ['date' => $endDate->format('Y-m-d'), 'status' => 'advised_absence'],
        ];

        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves fractional SL.',
            'day_statuses' => $dayStatuses,
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves fractional SL.',
        ]);

        $leaveRequest->refresh();

        // Should deduct 1 whole credit (not 1.25)
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals('SL', $leaveRequest->leave_type);
        $this->assertEquals(1, (float) $leaveRequest->credits_deducted);

        // SL keeps original dates (no narrowing with per-day statuses)
        $this->assertEquals($startDate->format('Y-m-d'), $leaveRequest->start_date->format('Y-m-d'));
        $this->assertEquals($endDate->format('Y-m-d'), $leaveRequest->end_date->format('Y-m-d'));

        // Per-day status records: 1 sl_credited, 2 advised_absence
        $dayRecords = \App\Models\LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(3, $dayRecords);
        $this->assertEquals(1, $dayRecords->where('day_status', 'sl_credited')->count());
        $this->assertEquals(2, $dayRecords->where('day_status', 'advised_absence')->count());

        // 0.25 stays in balance
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(1, (float) $credit->credits_used);
        $this->assertEquals(0.25, (float) $credit->credits_balance);
    }
}
