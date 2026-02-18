<?php

namespace Tests\Feature\Controllers\FormRequests;

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

/**
 * Tests for leave request partial denial credit deduction fixes.
 *
 * Bug #1: TL partial denial should NOT deduct credits (credits deducted later during final approval).
 * Bug #2: checkSlCreditDeduction should use approved_days for partial denials, not days_requested.
 * Bug #3: When Admin/HR completes dual approval via partialDeny, credits MUST be deducted.
 */
class LeaveRequestPartialDenialCreditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $hr;

    protected User $teamLead;

    protected User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $this->hr = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
        ]);

        $this->teamLead = $this->createUserWithSchedule([
            'role' => 'Team Lead',
            'is_approved' => true,
        ]);

        $this->employee = $this->createUserWithSchedule([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
        ]);
    }

    protected function createUserWithSchedule(array $overrides = []): User
    {
        $user = User::factory()->create($overrides);

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

    protected function createLeaveCredits(User $user, float $earned = 1.25, float $used = 0, ?int $month = null, ?int $year = null): LeaveCredit
    {
        return LeaveCredit::create([
            'user_id' => $user->id,
            'year' => $year ?? now()->year,
            'month' => $month ?? now()->month,
            'credits_earned' => $earned,
            'credits_used' => $used,
            'credits_balance' => $earned - $used,
            'accrued_at' => now(),
        ]);
    }

    // ────────────────────────────────────────────────
    // Bug #1: TL partial denial should NOT deduct credits
    // ────────────────────────────────────────────────

    #[Test]
    public function tl_partial_denial_does_not_deduct_vl_credits(): void
    {
        // Employee has 1.25 credits
        $credit = $this->createLeaveCredits($this->employee, 1.25, 0);

        // Employee requests 2 days VL (Mon-Tue next week)
        $startDate = now()->addWeeks(3)->startOfWeek(); // Monday
        $endDate = $startDate->copy()->addDay(); // Tuesday

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 2,
            'status' => 'pending',
            'requires_tl_approval' => true,
        ]);

        // TL partially denies (denies Tuesday, approves Monday only)
        $response = $this->actingAs($this->teamLead)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => [$endDate->format('Y-m-d')],
                'denial_reason' => 'Tuesday is a busy day, cannot approve leave for that day.',
                'review_notes' => 'Partially approved by TL.',
            ]
        );

        $response->assertRedirect();

        // CRITICAL: Credits should NOT be deducted yet (TL approval is step 1)
        $credit->refresh();
        $this->assertEquals(0, (float) $credit->credits_used, 'Credits should NOT be deducted during TL partial denial');
        $this->assertEquals(1.25, (float) $credit->credits_balance, 'Credits balance should remain unchanged during TL partial denial');

        // Leave request should still be pending (awaiting Admin/HR approval)
        $leaveRequest->refresh();
        $this->assertEquals('pending', $leaveRequest->status, 'Leave should remain pending after TL partial denial');
        $this->assertTrue((bool) $leaveRequest->has_partial_denial, 'has_partial_denial should be true');
        $this->assertEquals(1, (int) $leaveRequest->approved_days, 'approved_days should be 1');
        $this->assertNull($leaveRequest->credits_deducted, 'credits_deducted should not be set yet');
    }

    #[Test]
    public function tl_partial_denial_does_not_deduct_sl_credits(): void
    {
        // Employee has 1.25 credits
        $credit = $this->createLeaveCredits($this->employee, 1.25, 0);

        // Employee requests 2 days SL
        $startDate = now()->addWeeks(1)->startOfWeek(); // Monday
        $endDate = $startDate->copy()->addDay(); // Tuesday

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 2,
            'status' => 'pending',
            'requires_tl_approval' => true,
            'medical_cert_submitted' => true,
        ]);

        // TL partially denies (denies Tuesday)
        $response = $this->actingAs($this->teamLead)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => [$endDate->format('Y-m-d')],
                'denial_reason' => 'Only approving one day of sick leave for now.',
                'review_notes' => 'TL partial approval for SL.',
            ]
        );

        $response->assertRedirect();

        // Credits should NOT be deducted during TL partial denial
        $credit->refresh();
        $this->assertEquals(0, (float) $credit->credits_used, 'SL credits should NOT be deducted during TL partial denial');
        $this->assertEquals(1.25, (float) $credit->credits_balance, 'SL credits balance should remain unchanged');

        // Leave should remain pending
        $leaveRequest->refresh();
        $this->assertEquals('pending', $leaveRequest->status);
        $this->assertTrue((bool) $leaveRequest->has_partial_denial);
        $this->assertEquals(1, (int) $leaveRequest->approved_days);
    }

    // ────────────────────────────────────────────────
    // Bug #3: Admin/HR completing dual approval via partialDeny
    //         MUST deduct credits
    // ────────────────────────────────────────────────

    #[Test]
    public function admin_partial_deny_completing_dual_approval_deducts_vl_credits(): void
    {
        // Employee has 5 credits (across months)
        $this->createLeaveCredits($this->employee, 1.25, 0, 1);
        $this->createLeaveCredits($this->employee, 1.25, 0, 2);
        $this->createLeaveCredits($this->employee, 1.25, 0, 3);
        $this->createLeaveCredits($this->employee, 1.25, 0, 4);

        // 3-day VL request (Mon-Wed)
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(2); // Wednesday

        // HR has already approved, Admin does partial deny to complete dual approval
        $leaveRequest = LeaveRequest::factory()->hrApproved($this->hr)->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 3,
            'status' => 'pending',
        ]);

        // Admin partially denies Wednesday, approving Mon+Tue only
        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => [$endDate->format('Y-m-d')],
                'denial_reason' => 'Wednesday is not approved due to project deadline requirements.',
                'review_notes' => 'Admin partial approval.',
            ]
        );

        $response->assertRedirect();

        // Leave should now be approved (Admin + HR both approved)
        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status, 'Leave should be approved when dual approval completes');
        $this->assertTrue((bool) $leaveRequest->has_partial_denial);
        $this->assertEquals(2, (int) $leaveRequest->approved_days);

        // CRITICAL: Credits MUST be deducted for approved days (2, not 3)
        $this->assertEquals(2, (float) $leaveRequest->credits_deducted, 'Credits deducted should equal approved_days (2)');

        // Verify credit records were updated
        $totalUsed = LeaveCredit::where('user_id', $this->employee->id)->sum('credits_used');
        $this->assertEquals(2.0, (float) $totalUsed, 'Total credits used should be 2.0');
    }

    #[Test]
    public function hr_partial_deny_completing_dual_approval_deducts_vl_credits(): void
    {
        // Employee has 5 credits
        $this->createLeaveCredits($this->employee, 1.25, 0, 1);
        $this->createLeaveCredits($this->employee, 1.25, 0, 2);
        $this->createLeaveCredits($this->employee, 1.25, 0, 3);
        $this->createLeaveCredits($this->employee, 1.25, 0, 4);

        // 3-day VL request (Mon-Wed)
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(2);

        // Admin has already approved, HR does partial deny to complete dual approval
        $leaveRequest = LeaveRequest::factory()->adminApproved($this->admin)->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 3,
            'status' => 'pending',
        ]);

        // HR partially denies Wednesday
        $response = $this->actingAs($this->hr)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => [$endDate->format('Y-m-d')],
                'denial_reason' => 'Wednesday is not approved per company policy compliance.',
                'review_notes' => 'HR partial approval.',
            ]
        );

        $response->assertRedirect();

        // Leave should now be approved
        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals(2, (int) $leaveRequest->approved_days);

        // Credits MUST be deducted for approved days (2)
        $this->assertEquals(2, (float) $leaveRequest->credits_deducted);

        $totalUsed = LeaveCredit::where('user_id', $this->employee->id)->sum('credits_used');
        $this->assertEquals(2.0, (float) $totalUsed);
    }

    #[Test]
    public function partial_deny_completing_approval_with_tl_required_deducts_credits(): void
    {
        // Employee has 5 credits
        $this->createLeaveCredits($this->employee, 1.25, 0, 1);
        $this->createLeaveCredits($this->employee, 1.25, 0, 2);
        $this->createLeaveCredits($this->employee, 1.25, 0, 3);
        $this->createLeaveCredits($this->employee, 1.25, 0, 4);

        // 3-day VL request (Mon-Wed), TL already approved
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(2);

        $leaveRequest = LeaveRequest::factory()->hrApproved($this->hr)->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 3,
            'status' => 'pending',
            'requires_tl_approval' => true,
            'tl_approved_by' => $this->teamLead->id,
            'tl_approved_at' => now(),
        ]);

        // Admin partial deny completes the triple approval (TL + HR + Admin)
        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => [$endDate->format('Y-m-d')],
                'denial_reason' => 'Cannot approve Wednesday, schedule conflict with team.',
                'review_notes' => 'Partial approval.',
            ]
        );

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals(2, (int) $leaveRequest->approved_days);
        $this->assertEquals(2, (float) $leaveRequest->credits_deducted);
    }

    #[Test]
    public function partial_deny_not_completing_dual_approval_does_not_deduct_credits(): void
    {
        // Employee has 5 credits
        $this->createLeaveCredits($this->employee, 1.25, 0, 1);
        $this->createLeaveCredits($this->employee, 1.25, 0, 2);

        // 3-day VL request (Mon-Wed)
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(2);

        // No prior approval - Admin partially denies first, HR hasn't approved yet
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 3,
            'status' => 'pending',
        ]);

        // Admin partially denies (but HR hasn't approved yet, so not fully approved)
        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => [$endDate->format('Y-m-d')],
                'denial_reason' => 'Cannot approve Wednesday, will wait for HR approval.',
                'review_notes' => 'Admin partial.',
            ]
        );

        $response->assertRedirect();

        $leaveRequest->refresh();
        // Should still be pending (only Admin approved, HR hasn't)
        $this->assertEquals('pending', $leaveRequest->status);
        $this->assertTrue((bool) $leaveRequest->has_partial_denial);
        $this->assertEquals(2, (int) $leaveRequest->approved_days);

        // Credits should NOT be deducted since it's not fully approved
        $this->assertNull($leaveRequest->credits_deducted);
        $totalUsed = LeaveCredit::where('user_id', $this->employee->id)->sum('credits_used');
        $this->assertEquals(0, (float) $totalUsed);
    }

    // ────────────────────────────────────────────────
    // Bug #2: checkSlCreditDeduction uses approved_days
    //         for partial denials
    // ────────────────────────────────────────────────

    #[Test]
    public function sl_partial_denial_deducts_approved_days_not_days_requested(): void
    {
        // Employee has 2.50 credits (enough for 2 days but request is for 2 days with partial denial to 1)
        $this->createLeaveCredits($this->employee, 1.25, 0, 1);
        $this->createLeaveCredits($this->employee, 1.25, 0, 2);

        // 2-day SL request (Mon-Tue)
        $startDate = now()->addWeeks(1)->startOfWeek();
        $endDate = $startDate->copy()->addDay();

        // HR already approved, Admin does partial deny completing dual approval
        $leaveRequest = LeaveRequest::factory()->hrApproved($this->hr)->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 2,
            'status' => 'pending',
            'medical_cert_submitted' => true,
        ]);

        // Admin partially denies Tuesday, approving Monday only
        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => [$endDate->format('Y-m-d')],
                'denial_reason' => 'Only approving one day of sick leave per approval.',
                'review_notes' => 'Admin partial SL approval.',
            ]
        );

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertTrue((bool) $leaveRequest->has_partial_denial);
        $this->assertEquals(1, (int) $leaveRequest->approved_days);

        // CRITICAL: Only 1 credit should be deducted (approved_days), NOT 2 (days_requested)
        $totalUsed = LeaveCredit::where('user_id', $this->employee->id)->sum('credits_used');
        $this->assertLessThanOrEqual(1.0, (float) $totalUsed, 'Should deduct at most 1.0 credit (approved_days), not 2.0 (days_requested)');
    }

    // ────────────────────────────────────────────────
    // Unit-level tests for checkSlCreditDeduction fix
    // ────────────────────────────────────────────────

    #[Test]
    public function check_sl_credit_deduction_uses_approved_days_when_partial_denial(): void
    {
        $service = app(\App\Services\LeaveCreditService::class);

        // Give employee 1.25 credits
        $this->createLeaveCredits($this->employee, 1.25, 0);

        // Create an SL leave request with partial denial
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(6)->format('Y-m-d'),
            'days_requested' => 2,           // Original: 2 days
            'has_partial_denial' => true,
            'approved_days' => 1,            // After partial denial: 1 day
            'medical_cert_submitted' => true,
            'status' => 'pending',
        ]);

        $result = $service->checkSlCreditDeduction($this->employee, $leaveRequest);

        $this->assertTrue($result['should_deduct']);
        // Should use approved_days (1), not days_requested (2)
        $this->assertEquals(1.0, $result['credits_to_deduct'], 'Should plan to deduct 1.0 credit (approved_days), not 2.0 (days_requested)');
        $this->assertFalse($result['partial_credit'], 'Should not need partial credit since 1.25 >= 1.0');
    }

    #[Test]
    public function check_sl_credit_deduction_uses_days_requested_when_no_partial_denial(): void
    {
        $service = app(\App\Services\LeaveCreditService::class);

        // Give employee 2.50 credits
        $this->createLeaveCredits($this->employee, 1.25, 0, 1);
        $this->createLeaveCredits($this->employee, 1.25, 0, 2);

        // Create an SL leave request WITHOUT partial denial
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(6)->format('Y-m-d'),
            'days_requested' => 2,
            'has_partial_denial' => false,
            'approved_days' => null,
            'medical_cert_submitted' => true,
            'status' => 'pending',
        ]);

        $result = $service->checkSlCreditDeduction($this->employee, $leaveRequest);

        $this->assertTrue($result['should_deduct']);
        // Should use days_requested (2) since no partial denial
        $this->assertEquals(2.0, $result['credits_to_deduct'], 'Should deduct 2.0 credits (days_requested) when no partial denial');
    }

    #[Test]
    public function check_sl_credit_deduction_handles_insufficient_credits_with_partial_denial(): void
    {
        $service = app(\App\Services\LeaveCreditService::class);

        // Give employee only 0.50 credits
        $this->createLeaveCredits($this->employee, 0.50, 0);

        // SL request with partial denial: 2 days requested, 1 approved
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(6)->format('Y-m-d'),
            'days_requested' => 2,
            'has_partial_denial' => true,
            'approved_days' => 1,
            'medical_cert_submitted' => true,
            'status' => 'pending',
        ]);

        $result = $service->checkSlCreditDeduction($this->employee, $leaveRequest);

        // Should detect partial credit scenario: 0.50 < 1.0 (approved_days)
        $this->assertTrue($result['should_deduct']);
        $this->assertTrue($result['partial_credit'], 'Should be partial credit when balance (0.50) < approved_days (1.0)');
        $this->assertEquals(0.50, $result['credits_to_deduct'], 'Should plan to deduct available balance (0.50)');
        $this->assertEquals(0.50, $result['upto_days'], 'Remaining days should be converted to UPTO');
    }
}
