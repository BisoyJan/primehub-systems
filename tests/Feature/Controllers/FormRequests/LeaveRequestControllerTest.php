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

        // Admin approves first (partial approval - still needs HR)
        $response = $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Approved by Admin for this request.',
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
    public function it_blocks_vl_submission_when_credits_insufficient()
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

        // Request 5 working days (Mon-Fri) — exceeds 1.25 credits
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
        $response->assertSessionHasErrors('validation');

        $this->assertDatabaseMissing('leave_requests', [
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
    // Phase 5: VL/SL → UPTO Split at Approval Tests
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

        // Admin approves
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Approved by Admin - full credits test.',
        ]);

        // HR approves (triggers final approval + credit handling)
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Approved by HR - full credits test.',
        ]);

        $leaveRequest->refresh();

        // VL stays as VL, status approved, credits deducted
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals('VL', $leaveRequest->leave_type);
        $this->assertEquals(2, (float) $leaveRequest->credits_deducted);
        $this->assertTrue((bool) $leaveRequest->vl_credits_applied);
        $this->assertNull($leaveRequest->vl_no_credit_reason);

        // No linked UPTO companion should be created
        $this->assertDatabaseMissing('leave_requests', [
            'linked_request_id' => $leaveRequest->id,
            'leave_type' => 'UPTO',
        ]);

        // Credits should be deducted in leave_credits table
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(2, (float) $credit->credits_used);
    }

    #[Test]
    public function it_creates_linked_upto_when_vl_approved_with_partial_credits()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
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

        // Create 4-day VL request (Mon-Thu future)
        $startDate = now()->addWeeks(3)->startOfWeek(); // Monday
        $endDate = $startDate->copy()->addDays(3); // Thursday

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 4,
            'status' => 'pending',
        ]);

        // Dual approval
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves partial VL test.',
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves partial VL test.',
        ]);

        $leaveRequest->refresh();

        // VL stays as VL with partial credit info
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals('VL', $leaveRequest->leave_type);
        $this->assertEquals(2, (float) $leaveRequest->credits_deducted);
        $this->assertTrue((bool) $leaveRequest->vl_credits_applied);
        $this->assertNotNull($leaveRequest->vl_no_credit_reason);
        $this->assertStringContainsString('Partial VL credits', $leaveRequest->vl_no_credit_reason);

        // Parent dates should be narrowed to the credited VL days (first 2 days)
        $this->assertEquals($startDate->format('Y-m-d'), $leaveRequest->start_date->format('Y-m-d'));
        $this->assertEquals($startDate->copy()->addDay()->format('Y-m-d'), $leaveRequest->end_date->format('Y-m-d'));

        // A linked UPTO companion should be created for 2 excess days
        $companion = LeaveRequest::where('linked_request_id', $leaveRequest->id)
            ->where('leave_type', 'UPTO')
            ->first();

        $this->assertNotNull($companion, 'Linked UPTO companion should exist');
        $this->assertEquals($agent->id, $companion->user_id);
        $this->assertEquals(2, (float) $companion->days_requested);
        $this->assertEquals('approved', $companion->status);
        $this->assertEquals(0, (float) $companion->credits_deducted);

        // UPTO companion dates should cover the excess days (last 2 days)
        $this->assertEquals($startDate->copy()->addDays(2)->format('Y-m-d'), $companion->start_date->format('Y-m-d'));
        $this->assertEquals($endDate->format('Y-m-d'), $companion->end_date->format('Y-m-d'));

        // Companion should have approval info copied from parent
        $this->assertEquals($admin->id, $companion->admin_approved_by);
        $this->assertEquals($hr->id, $companion->hr_approved_by);

        // Credits should be deducted for the VL portion only
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(2, (float) $credit->credits_used);
    }

    #[Test]
    public function it_converts_vl_to_upto_when_approved_with_zero_credits()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
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

        // Create 2-day VL request
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

        // Dual approval
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves zero credit VL.',
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves zero credit VL.',
        ]);

        $leaveRequest->refresh();

        // VL should be converted to UPTO entirely
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals('UPTO', $leaveRequest->leave_type);
        $this->assertEquals(0, (float) $leaveRequest->credits_deducted);
        $this->assertFalse((bool) $leaveRequest->vl_credits_applied);
        $this->assertNotNull($leaveRequest->vl_no_credit_reason);
        $this->assertStringContainsString('No VL credits available', $leaveRequest->vl_no_credit_reason);

        // No linked companion (entire VL was converted, not split)
        $this->assertDatabaseMissing('leave_requests', [
            'linked_request_id' => $leaveRequest->id,
        ]);
    }

    #[Test]
    public function it_creates_linked_upto_when_sl_approved_with_partial_credits()
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

        // Dual approval
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves partial SL test.',
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves partial SL test.',
        ]);

        $leaveRequest->refresh();

        // SL stays as SL with partial credit info
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals('SL', $leaveRequest->leave_type);
        $this->assertEquals(1, (float) $leaveRequest->credits_deducted);

        // Parent dates should be narrowed to the credited SL day (first day only)
        $this->assertEquals($startDate->format('Y-m-d'), $leaveRequest->start_date->format('Y-m-d'));
        $this->assertEquals($startDate->format('Y-m-d'), $leaveRequest->end_date->format('Y-m-d'));

        // Linked UPTO companion for 2 excess days
        $companion = LeaveRequest::where('linked_request_id', $leaveRequest->id)
            ->where('leave_type', 'UPTO')
            ->first();

        $this->assertNotNull($companion, 'Linked UPTO companion for SL should exist');
        $this->assertEquals($agent->id, $companion->user_id);
        $this->assertEquals(2, (float) $companion->days_requested);
        $this->assertEquals('approved', $companion->status);
        $this->assertEquals($leaveRequest->id, $companion->linked_request_id);

        // UPTO companion dates should cover the excess days
        $this->assertEquals($startDate->copy()->addDay()->format('Y-m-d'), $companion->start_date->format('Y-m-d'));
        $this->assertEquals($endDate->format('Y-m-d'), $companion->end_date->format('Y-m-d'));
    }

    #[Test]
    public function it_validates_linked_upto_request_fields_are_correct()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-linked-fields@example.com',
        ]);

        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 1,
            'credits_used' => 0,
            'credits_balance' => 1,
            'accrued_at' => now(),
        ]);

        // 3-day VL → 1 day VL + 2 days UPTO
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(2);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 3,
            'status' => 'pending',
        ]);

        // Dual approval
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves linked fields test.',
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves linked fields test.',
        ]);

        $leaveRequest->refresh();

        $companion = LeaveRequest::where('linked_request_id', $leaveRequest->id)->first();
        $this->assertNotNull($companion);

        // Verify companion UPTO fields
        $this->assertEquals('UPTO', $companion->leave_type);
        $this->assertEquals('approved', $companion->status);
        $this->assertEquals($agent->id, $companion->user_id);
        $this->assertEquals(0, (float) $companion->credits_deducted);
        $this->assertEquals($leaveRequest->id, $companion->linked_request_id);

        // Approval info should be copied from parent
        $this->assertEquals($admin->id, $companion->admin_approved_by);
        $this->assertNotNull($companion->admin_approved_at);
        $this->assertEquals($hr->id, $companion->hr_approved_by);
        $this->assertNotNull($companion->hr_approved_at);
        $this->assertNotNull($companion->reviewed_by);
        $this->assertNotNull($companion->reviewed_at);

        // Reason should reference parent
        $this->assertStringContainsString("Request #{$leaveRequest->id}", $companion->reason);

        // UPTO dates should be the excess dates (days 2 and 3)
        $this->assertEquals(2, (float) $companion->days_requested);

        // Parent dates should be narrowed to just day 1
        $this->assertEquals($startDate->format('Y-m-d'), $leaveRequest->start_date->format('Y-m-d'));
        $this->assertEquals($startDate->format('Y-m-d'), $leaveRequest->end_date->format('Y-m-d'));

        // Companion dates should cover days 2 and 3
        $this->assertEquals($startDate->copy()->addDay()->format('Y-m-d'), $companion->start_date->format('Y-m-d'));
        $this->assertEquals($startDate->copy()->addDays(2)->format('Y-m-d'), $companion->end_date->format('Y-m-d'));

        // Verify model relationships work
        $this->assertEquals($leaveRequest->id, $companion->linkedRequest->id);
        $this->assertTrue($leaveRequest->companionRequests->contains('id', $companion->id));
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
                ->has('linkedRequest', null)
                ->has('companionRequests')
            );
    }

    #[Test]
    public function it_includes_companion_requests_in_show_page_after_approval()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-show-companion@example.com',
        ]);

        LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 1,
            'credits_used' => 0,
            'credits_balance' => 1,
            'accrued_at' => now(),
        ]);

        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(2);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => 3,
            'status' => 'pending',
        ]);

        // Approve to trigger companion creation
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves companion show test.',
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves companion show test.',
        ]);

        // View the approved parent VL — should show companion requests
        $response = $this->actingAs($admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('companionRequests', 1)
                ->where('companionRequests.0.leave_type', 'UPTO')
                ->where('companionRequests.0.days_requested', '2.00')
                ->where('companionRequests.0.status', 'approved')
            );
    }

    #[Test]
    public function it_converts_vl_to_upto_when_agent_not_eligible()
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

        // Even with credits, ineligibility should convert to UPTO
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

        // Dual approval
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves ineligible VL.',
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves ineligible VL.',
        ]);

        $leaveRequest->refresh();

        // Should be converted to UPTO due to ineligibility
        $this->assertEquals('UPTO', $leaveRequest->leave_type);
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertFalse((bool) $leaveRequest->vl_credits_applied);
        $this->assertStringContainsString('Not eligible', $leaveRequest->vl_no_credit_reason);
        $this->assertEquals(0, (float) $leaveRequest->credits_deducted);

        // No credits should be deducted
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(0, (float) $credit->credits_used);
    }

    // =====================================================================
    // Fractional Credit Floor Tests
    // =====================================================================

    #[Test]
    public function it_floors_fractional_vl_credits_to_whole_number_on_approval()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-vl-frac@example.com',
        ]);

        // Give agent 2.75 credits — should floor to 2 whole days
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

        // Dual approval
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves fractional VL.',
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves fractional VL.',
        ]);

        $leaveRequest->refresh();

        // Should deduct 2 whole credits (not 2.75)
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals('VL', $leaveRequest->leave_type);
        $this->assertEquals(2, (float) $leaveRequest->credits_deducted);

        // Parent dates narrowed to first 2 days (Mon-Tue)
        $this->assertEquals($startDate->format('Y-m-d'), $leaveRequest->start_date->format('Y-m-d'));
        $this->assertEquals($startDate->copy()->addDay()->format('Y-m-d'), $leaveRequest->end_date->format('Y-m-d'));

        // UPTO companion for 3 excess days (Wed-Fri)
        $companion = LeaveRequest::where('linked_request_id', $leaveRequest->id)->first();
        $this->assertNotNull($companion);
        $this->assertEquals('UPTO', $companion->leave_type);
        $this->assertEquals(3, (float) $companion->days_requested);
        $this->assertEquals($startDate->copy()->addDays(2)->format('Y-m-d'), $companion->start_date->format('Y-m-d'));
        $this->assertEquals($endDate->format('Y-m-d'), $companion->end_date->format('Y-m-d'));

        // Only 2 credits used (not 2.75), 0.75 stays in balance
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(2, (float) $credit->credits_used);
        $this->assertEquals(0.75, (float) $credit->credits_balance);
    }

    #[Test]
    public function it_converts_vl_to_upto_when_only_fractional_credits_remain()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent-vl-frac-zero@example.com',
        ]);

        // Give agent 0.75 credits — floor(0.75) = 0, should convert to UPTO
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

        // Dual approval
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves frac-zero VL.',
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves frac-zero VL.',
        ]);

        $leaveRequest->refresh();

        // Should convert entirely to UPTO (floor(0.75) = 0 whole credits)
        $this->assertEquals('UPTO', $leaveRequest->leave_type);
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals(0, (float) $leaveRequest->credits_deducted);
        $this->assertFalse((bool) $leaveRequest->vl_credits_applied);

        // No companion created (full conversion, not split)
        $this->assertDatabaseMissing('leave_requests', [
            'linked_request_id' => $leaveRequest->id,
        ]);

        // Credits untouched
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

        // Dual approval
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approves fractional SL.',
        ]);
        $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approves fractional SL.',
        ]);

        $leaveRequest->refresh();

        // Should deduct 1 whole credit (not 1.25)
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals('SL', $leaveRequest->leave_type);
        $this->assertEquals(1, (float) $leaveRequest->credits_deducted);

        // Parent dates narrowed to first day only (Mon)
        $this->assertEquals($startDate->format('Y-m-d'), $leaveRequest->start_date->format('Y-m-d'));
        $this->assertEquals($startDate->format('Y-m-d'), $leaveRequest->end_date->format('Y-m-d'));

        // UPTO companion for 2 excess days (Tue-Wed)
        $companion = LeaveRequest::where('linked_request_id', $leaveRequest->id)->first();
        $this->assertNotNull($companion);
        $this->assertEquals('UPTO', $companion->leave_type);
        $this->assertEquals(2, (float) $companion->days_requested);

        // 0.25 stays in balance
        $credit = LeaveCredit::where('user_id', $agent->id)->first();
        $this->assertEquals(1, (float) $credit->credits_used);
        $this->assertEquals(0.25, (float) $credit->credits_balance);
    }

    // ────────────────────────────────────────────────
    // Destroy (Delete) Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function agent_can_delete_own_cancelled_leave_request(): void
    {
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'cancelled',
        ]);

        $response = $this->actingAs($agent)->delete(
            route('leave-requests.destroy', $leaveRequest)
        );

        $response->assertRedirect(route('leave-requests.index'));
        $this->assertDatabaseMissing('leave_requests', ['id' => $leaveRequest->id]);
    }

    #[Test]
    public function agent_can_delete_own_denied_leave_request(): void
    {
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'denied',
        ]);

        $response = $this->actingAs($agent)->delete(
            route('leave-requests.destroy', $leaveRequest)
        );

        $response->assertRedirect(route('leave-requests.index'));
        $this->assertDatabaseMissing('leave_requests', ['id' => $leaveRequest->id]);
    }

    #[Test]
    public function agent_cannot_delete_own_pending_leave_request(): void
    {
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($agent)->delete(
            route('leave-requests.destroy', $leaveRequest)
        );

        $response->assertForbidden();
        $this->assertDatabaseHas('leave_requests', ['id' => $leaveRequest->id]);
    }

    #[Test]
    public function agent_cannot_delete_own_approved_leave_request(): void
    {
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($agent)->delete(
            route('leave-requests.destroy', $leaveRequest)
        );

        $response->assertForbidden();
        $this->assertDatabaseHas('leave_requests', ['id' => $leaveRequest->id]);
    }

    #[Test]
    public function agent_cannot_delete_other_users_cancelled_leave_request(): void
    {
        $agent = $this->createAgentWithSchedule();
        $otherAgent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $otherAgent->id,
            'status' => 'cancelled',
        ]);

        $response = $this->actingAs($agent)->delete(
            route('leave-requests.destroy', $leaveRequest)
        );

        $response->assertForbidden();
        $this->assertDatabaseHas('leave_requests', ['id' => $leaveRequest->id]);
    }

    #[Test]
    public function admin_can_delete_any_leave_request(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = $this->createAgentWithSchedule();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->delete(
            route('leave-requests.destroy', $leaveRequest)
        );

        $response->assertRedirect(route('leave-requests.index'));
        $this->assertDatabaseMissing('leave_requests', ['id' => $leaveRequest->id]);
    }

    #[Test]
    public function deleting_approved_leave_does_not_restore_credits(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = $this->createAgentWithSchedule();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
            'status' => 'approved',
            'credits_deducted' => 1,
        ]);

        $leaveCredit = LeaveCredit::create([
            'user_id' => $agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 1.25,
            'credits_used' => 1,
            'credits_balance' => 0.25,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($admin)->delete(
            route('leave-requests.destroy', $leaveRequest)
        );

        $response->assertRedirect(route('leave-requests.index'));
        $this->assertDatabaseMissing('leave_requests', ['id' => $leaveRequest->id]);

        // Credits should NOT be restored — used stays at 1, balance stays at 0.25
        $leaveCredit->refresh();
        $this->assertEquals(1, (float) $leaveCredit->credits_used);
        $this->assertEquals(0.25, (float) $leaveCredit->credits_balance);
    }
}
