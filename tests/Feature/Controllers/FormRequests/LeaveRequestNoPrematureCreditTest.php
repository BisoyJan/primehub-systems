<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Http\Middleware\EnsureUserHasSchedule;
use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests that VL approval uses only actual accrued credits (not projected),
 * no phantom credit records are pre-created for future months,
 * and cancellation properly cleans up VL day records.
 */
class LeaveRequestNoPrematureCreditTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $hr;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // Time-travel to March 2026 — agent has only Jan+Feb credits accrued
        $this->travelTo(Carbon::create(2026, 3, 3, 9, 0, 0));

        Mail::fake();
        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            EnsureUserHasSchedule::class,
        ]);

        $this->superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $this->admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $this->hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $this->agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => Carbon::create(2025, 1, 1), // well over 6 months
        ]);
    }

    /**
     * Create actual accrued credit records for Jan-Feb 2026 (1.25 each = 2.50 total).
     */
    private function giveActualCredits(): void
    {
        foreach ([1, 2] as $month) {
            LeaveCredit::create([
                'user_id' => $this->agent->id,
                'year' => 2026,
                'month' => $month,
                'credits_earned' => 1.25,
                'credits_used' => 0,
                'credits_balance' => 1.25,
                'accrued_at' => Carbon::create(2026, $month, 1)->endOfMonth(),
            ]);
        }
    }

    /**
     * Create a pending VL request for the agent.
     */
    private function createVlRequest(string $startDate, string $endDate, int $days): LeaveRequest
    {
        return LeaveRequest::factory()->create([
            'user_id' => $this->agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => $days,
            'medical_cert_submitted' => false,
            'status' => 'pending',
        ]);
    }

    // ===================================================================
    // Future-month VL blocked when actual credits insufficient
    // ===================================================================

    #[Test]
    public function approve_blocks_future_vl_when_credited_days_exceed_actual_balance(): void
    {
        // Agent has 2.50 actual credits (Jan+Feb) → floor = 2 whole credits
        $this->giveActualCredits();

        // Request 3 days VL in June — a future month
        $leaveRequest = $this->createVlRequest('2026-06-01', '2026-06-03', 3);

        // Try to mark all 3 days as vl_credited — should be blocked (only 2 available)
        $dayStatuses = [
            ['date' => '2026-06-01', 'status' => 'vl_credited'],
            ['date' => '2026-06-02', 'status' => 'vl_credited'],
            ['date' => '2026-06-03', 'status' => 'vl_credited'],
        ];

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approve all as VL.', 'day_statuses' => $dayStatuses]
        );

        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString('Cannot approve', session('errors')->get('error')[0]);

        $leaveRequest->refresh();
        $this->assertEquals('pending', $leaveRequest->status);
    }

    #[Test]
    public function force_approve_blocks_future_vl_when_credited_days_exceed_actual_balance(): void
    {
        $this->giveActualCredits();
        $leaveRequest = $this->createVlRequest('2026-06-01', '2026-06-03', 3);

        $dayStatuses = [
            ['date' => '2026-06-01', 'status' => 'vl_credited'],
            ['date' => '2026-06-02', 'status' => 'vl_credited'],
            ['date' => '2026-06-03', 'status' => 'vl_credited'],
        ];

        $response = $this->actingAs($this->superAdmin)->post(
            route('leave-requests.force-approve', $leaveRequest),
            ['review_notes' => 'Force approve.', 'day_statuses' => $dayStatuses]
        );

        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString('Cannot approve', session('errors')->get('error')[0]);
    }

    // ===================================================================
    // Future-month VL succeeds when credited days ≤ actual balance
    // ===================================================================

    #[Test]
    public function approve_future_vl_succeeds_when_credited_days_within_actual_balance(): void
    {
        // Agent has 2.50 actual credits — 2 whole credits available
        $this->giveActualCredits();

        $leaveRequest = $this->createVlRequest('2026-06-01', '2026-06-03', 3);

        // 2 credited + 1 UPTO — within actual balance
        $dayStatuses = [
            ['date' => '2026-06-01', 'status' => 'vl_credited'],
            ['date' => '2026-06-02', 'status' => 'vl_credited'],
            ['date' => '2026-06-03', 'status' => 'upto'],
        ];

        // Admin approves
        $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Admin approved future VL.', 'day_statuses' => $dayStatuses]
        );

        // HR approves (day statuses pre-stored by admin)
        $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'HR approved.']
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals(2, (int) $leaveRequest->credits_deducted);
    }

    // ===================================================================
    // No phantom credit records created on VL approval
    // ===================================================================

    #[Test]
    public function approve_does_not_create_phantom_credit_records_for_future_months(): void
    {
        $this->giveActualCredits();
        $leaveRequest = $this->createVlRequest('2026-06-01', '2026-06-03', 3);

        // Count existing credit records before approval
        $creditCountBefore = LeaveCredit::where('user_id', $this->agent->id)->count();

        $dayStatuses = [
            ['date' => '2026-06-01', 'status' => 'vl_credited'],
            ['date' => '2026-06-02', 'status' => 'vl_credited'],
            ['date' => '2026-06-03', 'status' => 'upto'],
        ];

        // Admin approves
        $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Admin approved.', 'day_statuses' => $dayStatuses]
        );

        // HR approves
        $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'HR approved.']
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);

        // No new credit records should have been created for Mar-Jun
        $creditCountAfter = LeaveCredit::where('user_id', $this->agent->id)->count();
        $this->assertEquals($creditCountBefore, $creditCountAfter);

        // Specifically: no records for months 3-6
        $phantomRecords = LeaveCredit::where('user_id', $this->agent->id)
            ->where('year', 2026)
            ->whereIn('month', [3, 4, 5, 6])
            ->count();
        $this->assertEquals(0, $phantomRecords);
    }

    // ===================================================================
    // Cancellation cleans up VL day records
    // ===================================================================

    #[Test]
    public function cancel_approved_vl_deletes_day_records(): void
    {
        $this->giveActualCredits();
        $leaveRequest = $this->createVlRequest('2026-06-01', '2026-06-02', 2);

        $dayStatuses = [
            ['date' => '2026-06-01', 'status' => 'vl_credited'],
            ['date' => '2026-06-02', 'status' => 'upto'],
        ];

        // Admin approves
        $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Admin approved.', 'day_statuses' => $dayStatuses]
        );

        // HR approves
        $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'HR approved.']
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);

        // Verify day records exist
        $this->assertGreaterThan(0, LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->count());

        // Cancel the leave
        $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'Testing cancellation cleans up VL day records.']
        );

        $leaveRequest->refresh();
        $this->assertEquals('cancelled', $leaveRequest->status);

        // VL day records should be cleaned up
        $this->assertEquals(0, LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->count());
    }

    #[Test]
    public function cancel_approved_vl_restores_credits_without_phantom_records(): void
    {
        $this->giveActualCredits();

        // Get initial balance
        $initialBalance = LeaveCredit::where('user_id', $this->agent->id)
            ->where('year', 2026)
            ->sum('credits_balance');

        $leaveRequest = $this->createVlRequest('2026-06-01', '2026-06-02', 2);

        $dayStatuses = [
            ['date' => '2026-06-01', 'status' => 'vl_credited'],
            ['date' => '2026-06-02', 'status' => 'vl_credited'],
        ];

        // Admin + HR approve
        $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Admin approved.', 'day_statuses' => $dayStatuses]
        );
        $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'HR approved.']
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);

        // Cancel
        $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'Testing credit restoration after cancel.']
        );

        // Balance should be fully restored
        $restoredBalance = LeaveCredit::where('user_id', $this->agent->id)
            ->where('year', 2026)
            ->sum('credits_balance');

        $this->assertEquals($initialBalance, $restoredBalance);

        // Still no phantom records for future months
        $phantomRecords = LeaveCredit::where('user_id', $this->agent->id)
            ->where('year', 2026)
            ->whereIn('month', [3, 4, 5, 6])
            ->count();
        $this->assertEquals(0, $phantomRecords);
    }

    // ===================================================================
    // checkVlCreditDeduction uses actual balance (unit-level verification)
    // ===================================================================

    #[Test]
    public function check_vl_credit_deduction_uses_actual_balance_for_future_leave(): void
    {
        $this->giveActualCredits(); // 2.50 actual

        $leaveRequest = $this->createVlRequest('2026-06-01', '2026-06-03', 3);

        $service = app(\App\Services\LeaveCreditService::class);
        $result = $service->checkVlCreditDeduction($this->agent, $leaveRequest);

        // Should see partial credit: 2 credited + 1 UPTO (based on actual 2.50, not projected 7.50)
        $this->assertTrue($result['should_deduct']);
        $this->assertTrue($result['partial_credit']);
        $this->assertEquals(2, $result['credits_to_deduct']);
        $this->assertEquals(1, $result['upto_days']);
    }

    #[Test]
    public function check_vl_credit_deduction_returns_zero_when_no_actual_credits(): void
    {
        // No credits at all
        $leaveRequest = $this->createVlRequest('2026-06-01', '2026-06-03', 3);

        $service = app(\App\Services\LeaveCreditService::class);
        $result = $service->checkVlCreditDeduction($this->agent, $leaveRequest);

        // Should convert all to UPTO
        $this->assertFalse($result['should_deduct']);
        $this->assertTrue($result['convert_to_upto']);
        $this->assertEquals(0, $result['credits_to_deduct']);
        $this->assertEquals(3, $result['upto_days']);
    }
}
