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
 * Tests that SL/VL leave request approval is BLOCKED unless
 * the admin explicitly assigns per-day statuses (credited vs UPTO)
 * that respect available leave credits.
 */
class LeaveRequestCreditEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $hr;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // Time-travel to July 2026 so leave dates (Jul 6-10) fall in the current month,
        // avoiding projected-balance calculations that inflate credits with future accruals.
        $this->travelTo(Carbon::create(2026, 7, 1, 9, 0, 0));

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
            'hired_date' => now()->subYear(),
        ]);
    }

    /**
     * Give leave credits to the agent for the current (2026) year.
     */
    private function giveCredits(int $credits): LeaveCredit
    {
        return LeaveCredit::create([
            'user_id' => $this->agent->id,
            'year' => 2026,
            'month' => 6,  // June — already accrued before Jul 1
            'credits_earned' => $credits,
            'credits_used' => 0,
            'credits_balance' => $credits,
            'accrued_at' => Carbon::create(2026, 6, 30),
        ]);
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

    /**
     * Create a pending SL request for the agent.
     */
    private function createSlRequest(string $startDate, string $endDate, int $days, bool $medCert = true): LeaveRequest
    {
        return LeaveRequest::factory()->create([
            'user_id' => $this->agent->id,
            'leave_type' => 'SL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => $days,
            'medical_cert_submitted' => $medCert,
            'status' => 'pending',
        ]);
    }

    // ===================================================================
    // approve() — day_statuses required
    // ===================================================================

    #[Test]
    public function approve_rejects_vl_without_day_statuses(): void
    {
        $this->giveCredits(3);
        $leaveRequest = $this->createVlRequest('2026-07-06', '2026-07-08', 3);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approved the VL request.']
        );

        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString('Per-day status assignment is required', session('errors')->get('error')[0]);
        $leaveRequest->refresh();
        $this->assertEquals('pending', $leaveRequest->status);
    }

    #[Test]
    public function approve_rejects_sl_without_day_statuses(): void
    {
        $this->giveCredits(2);
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approved the SL request.']
        );

        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString('Per-day status assignment is required', session('errors')->get('error')[0]);
    }

    #[Test]
    public function approve_rejects_vl_when_credited_days_exceed_available_credits(): void
    {
        $this->giveCredits(1);
        $leaveRequest = $this->createVlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'vl_credited'],
            ['date' => '2026-07-07', 'status' => 'vl_credited'],
            ['date' => '2026-07-08', 'status' => 'vl_credited'],
        ];

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approve all as VL.', 'day_statuses' => $dayStatuses]
        );

        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString('Cannot approve', session('errors')->get('error')[0]);
    }

    #[Test]
    public function approve_rejects_when_days_still_pending(): void
    {
        $this->giveCredits(3);
        $leaveRequest = $this->createVlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'vl_credited'],
            ['date' => '2026-07-07', 'status' => 'pending'],
            ['date' => '2026-07-08', 'status' => 'upto'],
        ];

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approve with pending.', 'day_statuses' => $dayStatuses]
        );

        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString('still pending', session('errors')->get('error')[0]);
    }

    #[Test]
    public function approve_succeeds_vl_when_excess_days_set_to_upto(): void
    {
        $this->giveCredits(1);
        $leaveRequest = $this->createVlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'vl_credited'],
            ['date' => '2026-07-07', 'status' => 'upto'],
            ['date' => '2026-07-08', 'status' => 'upto'],
        ];

        // Admin approves with day statuses
        $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approve with UPTO.', 'day_statuses' => $dayStatuses]
        );

        // HR approves (day statuses already pre-stored by admin)
        $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'HR approved.']
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals(1, (int) $leaveRequest->credits_deducted);

        // Verify day records
        $days = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->orderBy('date')->get();
        $this->assertCount(3, $days);
        $this->assertEquals('vl_credited', $days[0]->day_status);
        $this->assertEquals('upto', $days[1]->day_status);
        $this->assertEquals('upto', $days[2]->day_status);
    }

    #[Test]
    public function approve_allows_hr_when_admin_already_pre_stored_day_statuses(): void
    {
        $this->giveCredits(2);
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'sl_credited'],
            ['date' => '2026-07-08', 'status' => 'advised_absence'],
        ];

        // Admin approves with day statuses
        $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Admin approved with statuses.', 'day_statuses' => $dayStatuses]
        );

        // Verify admin pre-stored day statuses
        $this->assertTrue(LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->exists());

        // HR approves WITHOUT day_statuses — should succeed because pre-stored exist
        $response = $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'HR approved.']
        );

        $response->assertSessionDoesntHaveErrors('error');
        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
    }

    // ===================================================================
    // forceApprove() — day_statuses required
    // ===================================================================

    #[Test]
    public function force_approve_rejects_vl_without_day_statuses(): void
    {
        $this->giveCredits(2);
        $leaveRequest = $this->createVlRequest('2026-07-06', '2026-07-08', 3);

        $response = $this->actingAs($this->superAdmin)->post(
            route('leave-requests.force-approve', $leaveRequest),
            ['review_notes' => 'Force approved.']
        );

        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString('Per-day status assignment is required', session('errors')->get('error')[0]);
    }

    #[Test]
    public function force_approve_rejects_sl_without_day_statuses(): void
    {
        $this->giveCredits(2);
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $response = $this->actingAs($this->superAdmin)->post(
            route('leave-requests.force-approve', $leaveRequest),
            ['review_notes' => 'Force approved.']
        );

        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString('Per-day status assignment is required', session('errors')->get('error')[0]);
    }

    #[Test]
    public function force_approve_rejects_when_credited_days_exceed_credits(): void
    {
        $this->giveCredits(1);
        $leaveRequest = $this->createVlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'vl_credited'],
            ['date' => '2026-07-07', 'status' => 'vl_credited'],
            ['date' => '2026-07-08', 'status' => 'upto'],
        ];

        $response = $this->actingAs($this->superAdmin)->post(
            route('leave-requests.force-approve', $leaveRequest),
            ['review_notes' => 'Force approved with bad credits.', 'day_statuses' => $dayStatuses]
        );

        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString('Cannot approve', session('errors')->get('error')[0]);
    }

    #[Test]
    public function force_approve_succeeds_vl_with_correct_day_statuses(): void
    {
        $this->giveCredits(1);
        $leaveRequest = $this->createVlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'vl_credited'],
            ['date' => '2026-07-07', 'status' => 'upto'],
            ['date' => '2026-07-08', 'status' => 'upto'],
        ];

        $this->actingAs($this->superAdmin)->post(
            route('leave-requests.force-approve', $leaveRequest),
            ['review_notes' => 'Force approved correctly.', 'day_statuses' => $dayStatuses]
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals(1, (int) $leaveRequest->credits_deducted);

        $days = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->orderBy('date')->get();
        $this->assertCount(3, $days);
        $vlCredited = $days->where('day_status', 'vl_credited')->count();
        $upto = $days->where('day_status', 'upto')->count();
        $this->assertEquals(1, $vlCredited);
        $this->assertEquals(2, $upto);
    }

    #[Test]
    public function force_approve_succeeds_sl_with_correct_day_statuses(): void
    {
        $this->giveCredits(2);
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-10', 5);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'sl_credited'],
            ['date' => '2026-07-08', 'status' => 'ncns'],
            ['date' => '2026-07-09', 'status' => 'advised_absence'],
            ['date' => '2026-07-10', 'status' => 'advised_absence'],
        ];

        $this->actingAs($this->superAdmin)->post(
            route('leave-requests.force-approve', $leaveRequest),
            ['review_notes' => 'Force approved correctly.', 'day_statuses' => $dayStatuses]
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals(2, (int) $leaveRequest->credits_deducted);
    }

    // ===================================================================
    // approve() — VL with zero credits forces all-UPTO
    // ===================================================================

    #[Test]
    public function approve_vl_all_upto_when_no_credits(): void
    {
        // No credits given
        $leaveRequest = $this->createVlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'upto'],
            ['date' => '2026-07-07', 'status' => 'upto'],
            ['date' => '2026-07-08', 'status' => 'upto'],
        ];

        $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'All UPTO, no credits.', 'day_statuses' => $dayStatuses]
        );

        $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'HR approved.']
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals(0, (int) $leaveRequest->credits_deducted);
    }

    // ===================================================================
    // partialDeny() — VL day_statuses support
    // ===================================================================

    #[Test]
    public function partial_deny_includes_vl_day_statuses(): void
    {
        $this->giveCredits(1);
        $leaveRequest = $this->createVlRequest('2026-07-06', '2026-07-10', 5);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'vl_credited'],
            ['date' => '2026-07-07', 'status' => 'upto'],
            ['date' => '2026-07-08', 'status' => 'upto'],
        ];

        // Admin partial deny: approve 3 of 5 days with VL day statuses
        $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => ['2026-07-09', '2026-07-10'],
                'denial_reason' => 'Staffing shortage, denying last 2 days.',
                'review_notes' => 'Partial approval with VL day statuses.',
                'day_statuses' => $dayStatuses,
            ]
        );

        $leaveRequest->refresh();
        $this->assertTrue($leaveRequest->has_partial_denial);

        // Verify pre-stored VL day statuses
        $preStored = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->orderBy('date')->get();
        $this->assertCount(3, $preStored);
        $this->assertEquals('vl_credited', $preStored[0]->day_status);
        $this->assertEquals('upto', $preStored[1]->day_status);
        $this->assertEquals('upto', $preStored[2]->day_status);
    }

    // ===================================================================
    // Non-credited leave types (BL, UPTO, etc.) don't require day_statuses
    // ===================================================================

    #[Test]
    public function approve_non_credited_leave_without_day_statuses_succeeds(): void
    {
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->agent->id,
            'leave_type' => 'BL',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-08',
            'days_requested' => 3,
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approved BL request.']
        );

        $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'HR approved BL.']
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
    }
}
