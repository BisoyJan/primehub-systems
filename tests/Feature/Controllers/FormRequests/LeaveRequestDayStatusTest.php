<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Http\Middleware\EnsureUserHasSchedule;
use App\Models\AttendancePoint;
use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveRequestDeniedDate;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaveRequestDayStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $hr;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            EnsureUserHasSchedule::class,
        ]);

        $this->admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $this->hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $this->agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
        ]);
    }

    /**
     * Create SL credits for the agent.
     */
    private function giveSlCredits(int $credits, ?int $year = null): LeaveCredit
    {
        return LeaveCredit::create([
            'user_id' => $this->agent->id,
            'year' => $year ?? now()->year,
            'month' => now()->month,
            'credits_earned' => $credits,
            'credits_used' => 0,
            'credits_balance' => $credits,
            'accrued_at' => now(),
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

    /**
     * Perform dual approval with day statuses.
     */
    private function dualApproveWithDayStatuses(LeaveRequest $leaveRequest, array $dayStatuses = []): void
    {
        $postData = ['review_notes' => 'Admin approved with day statuses.'];
        if (! empty($dayStatuses)) {
            $postData['day_statuses'] = $dayStatuses;
        }

        $this->actingAs($this->admin)->post(route('leave-requests.approve', $leaveRequest), $postData);

        $this->actingAs($this->hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approved.',
        ]);
    }

    // ===================================================================
    // Test: SL approval with explicit per-day statuses (the main scenario)
    // ===================================================================

    #[Test]
    public function it_approves_sl_with_per_day_statuses_and_creates_day_records(): void
    {
        $this->giveSlCredits(2);

        // Jul 6-10 2026 is Mon-Fri (future dates)
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-10', 5);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited', 'notes' => 'Day 1 credited'],
            ['date' => '2026-07-07', 'status' => 'sl_credited', 'notes' => 'Day 2 credited'],
            ['date' => '2026-07-08', 'status' => 'ncns', 'notes' => 'Failed to inform'],
            ['date' => '2026-07-09', 'status' => 'advised_absence', 'notes' => 'Informed but no credits'],
            ['date' => '2026-07-10', 'status' => 'advised_absence', 'notes' => 'Informed but no credits'],
        ];

        $this->dualApproveWithDayStatuses($leaveRequest, $dayStatuses);

        $leaveRequest->refresh();

        // Request should be approved
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals('SL', $leaveRequest->leave_type);

        // Credits: only 2 days (sl_credited) should be deducted
        $this->assertEquals(2, (int) $leaveRequest->credits_deducted);

        // Verify leave_request_days records created
        $days = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(5, $days);

        $this->assertEquals('sl_credited', $days[0]->day_status);
        $this->assertEquals('sl_credited', $days[1]->day_status);
        $this->assertEquals('ncns', $days[2]->day_status);
        $this->assertEquals('advised_absence', $days[3]->day_status);
        $this->assertEquals('advised_absence', $days[4]->day_status);

        // Verify notes
        $this->assertEquals('Day 1 credited', $days[0]->notes);
        $this->assertEquals('Failed to inform', $days[2]->notes);

        // Verify assigned_by is set
        foreach ($days as $day) {
            $this->assertNotNull($day->assigned_by);
            $this->assertNotNull($day->assigned_at);
        }
    }

    #[Test]
    public function it_deducts_credits_only_for_sl_credited_days(): void
    {
        $credit = $this->giveSlCredits(3);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-10', 5);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'sl_credited'],
            ['date' => '2026-07-08', 'status' => 'ncns'],
            ['date' => '2026-07-09', 'status' => 'advised_absence'],
            ['date' => '2026-07-10', 'status' => 'advised_absence'],
        ];

        $this->dualApproveWithDayStatuses($leaveRequest, $dayStatuses);

        $leaveRequest->refresh();
        $credit->refresh();

        // Should only deduct 2 credits (for 2 sl_credited days)
        $this->assertEquals(2, (int) $leaveRequest->credits_deducted);
        $this->assertEquals(2, (float) $credit->credits_used);
        $this->assertEquals(1, (float) $credit->credits_balance);
    }

    #[Test]
    public function it_keeps_sl_as_sl_with_original_dates_when_per_day_statuses_assigned(): void
    {
        $this->giveSlCredits(1);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'advised_absence'],
            ['date' => '2026-07-08', 'status' => 'advised_absence'],
        ];

        $this->dualApproveWithDayStatuses($leaveRequest, $dayStatuses);

        $leaveRequest->refresh();

        // SL should stay as SL with original dates (NOT narrowed)
        $this->assertEquals('SL', $leaveRequest->leave_type);
        $this->assertEquals('2026-07-06', $leaveRequest->start_date->format('Y-m-d'));
        $this->assertEquals('2026-07-08', $leaveRequest->end_date->format('Y-m-d'));
    }

    // ===================================================================
    // Test: Approval requires day_statuses for SL (credit enforcement)
    // ===================================================================

    #[Test]
    public function it_rejects_sl_approval_when_no_day_statuses_provided(): void
    {
        $this->giveSlCredits(2);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        // Approve without specifying day_statuses — should be rejected
        $response = $this->actingAs($this->admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approved without day statuses.',
        ]);

        $response->assertSessionHasErrors('error');
        $leaveRequest->refresh();
        $this->assertEquals('pending', $leaveRequest->status);
    }

    // ===================================================================
    // Test: NCNS day creates attendance point
    // ===================================================================

    #[Test]
    public function it_creates_attendance_point_for_ncns_day(): void
    {
        $this->giveSlCredits(2);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'ncns', 'notes' => 'Did not call in'],
            ['date' => '2026-07-08', 'status' => 'sl_credited'],
        ];

        $this->dualApproveWithDayStatuses($leaveRequest, $dayStatuses);

        // NCNS day should have an attendance point
        $point = AttendancePoint::where('user_id', $this->agent->id)
            ->where('shift_date', '2026-07-07')
            ->where('point_type', 'whole_day_absence')
            ->first();

        $this->assertNotNull($point, 'NCNS day should generate an attendance point');
        $this->assertEquals(1.00, (float) $point->points);
    }

    // ===================================================================
    // Test: Post-approval editing of day statuses
    // ===================================================================

    #[Test]
    public function it_allows_admin_to_update_day_statuses_after_approval(): void
    {
        $this->giveSlCredits(3);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        // Initially approve all as sl_credited
        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'sl_credited'],
            ['date' => '2026-07-08', 'status' => 'sl_credited'],
        ];
        $this->dualApproveWithDayStatuses($leaveRequest, $dayStatuses);

        $leaveRequest->refresh();
        $this->assertEquals(3, (int) $leaveRequest->credits_deducted);

        // Now update: change day 3 from sl_credited to ncns
        $updatedStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'ncns', 'notes' => 'Changed to NCNS'],
            ['date' => '2026-07-08', 'status' => 'sl_credited'],
        ];

        $response = $this->actingAs($this->admin)->put(
            route('leave-requests.update-day-statuses', $leaveRequest),
            ['day_statuses' => $updatedStatuses]
        );

        $response->assertRedirect();

        $leaveRequest->refresh();

        // Should now only have 2 credited days
        $this->assertEquals(2, (int) $leaveRequest->credits_deducted);

        // Verify day records updated
        $ncnsDay = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->where('date', '2026-07-07')
            ->first();

        $this->assertEquals('ncns', $ncnsDay->day_status);
        $this->assertEquals('Changed to NCNS', $ncnsDay->notes);
    }

    #[Test]
    public function it_rejects_day_status_update_for_non_sl_vl_requests(): void
    {
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->agent->id,
            'leave_type' => 'BL',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-08',
            'days_requested' => 3,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->admin)->put(
            route('leave-requests.update-day-statuses', $leaveRequest),
            ['day_statuses' => [['date' => '2026-07-06', 'status' => 'advised_absence']]]
        );

        $response->assertSessionHasErrors('error');
    }

    #[Test]
    public function it_rejects_day_status_update_for_pending_requests(): void
    {
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $response = $this->actingAs($this->admin)->put(
            route('leave-requests.update-day-statuses', $leaveRequest),
            ['day_statuses' => [['date' => '2026-07-06', 'status' => 'advised_absence']]]
        );

        $response->assertSessionHasErrors('error');
    }

    // ===================================================================
    // Test: Show page returns per-day status data
    // ===================================================================

    #[Test]
    public function it_returns_day_status_data_on_show_page(): void
    {
        $this->giveSlCredits(2);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'ncns'],
            ['date' => '2026-07-08', 'status' => 'advised_absence'],
        ];

        $this->dualApproveWithDayStatuses($leaveRequest, $dayStatuses);

        $response = $this->actingAs($this->admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('leaveRequestDays', 3)
                ->where('leaveRequestDays.0.day_status', 'sl_credited')
                ->where('leaveRequestDays.1.day_status', 'ncns')
                ->where('leaveRequestDays.2.day_status', 'advised_absence')
            );
    }

    #[Test]
    public function it_returns_suggested_day_statuses_for_pending_sl(): void
    {
        $this->giveSlCredits(2);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $response = $this->actingAs($this->admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('suggestedDayStatuses', 3)
            );
    }

    // ===================================================================
    // Test: Cancellation cleans up day records
    // ===================================================================

    #[Test]
    public function it_deletes_day_records_on_cancellation(): void
    {
        $this->giveSlCredits(3);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'sl_credited'],
            ['date' => '2026-07-08', 'status' => 'sl_credited'],
        ];

        $this->dualApproveWithDayStatuses($leaveRequest, $dayStatuses);

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertCount(3, LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->get());

        // Cancel the request
        $this->actingAs($this->admin)->post(route('leave-requests.cancel', $leaveRequest), [
            'cancellation_reason' => 'No longer needed, cancelling the leave request.',
        ]);

        $leaveRequest->refresh();
        $this->assertEquals('cancelled', $leaveRequest->status);

        // Day records should be deleted
        $this->assertCount(0, LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->get());
    }

    // ===================================================================
    // Test: LeaveRequestDay model scopes and helpers
    // ===================================================================

    #[Test]
    public function it_correctly_reports_paid_and_unpaid_days(): void
    {
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $credited = LeaveRequestDay::factory()->slCredited()->create([
            'leave_request_id' => $leaveRequest->id,
            'date' => '2026-07-06',
        ]);

        $ncns = LeaveRequestDay::factory()->ncns()->create([
            'leave_request_id' => $leaveRequest->id,
            'date' => '2026-07-07',
        ]);

        $advised = LeaveRequestDay::factory()->advisedAbsence()->create([
            'leave_request_id' => $leaveRequest->id,
            'date' => '2026-07-08',
        ]);

        $this->assertTrue($credited->isPaid());
        $this->assertFalse($ncns->isPaid());
        $this->assertFalse($advised->isPaid());

        $this->assertFalse($credited->isUnpaid());
        $this->assertTrue($ncns->isUnpaid());
        $this->assertTrue($advised->isUnpaid());
    }

    #[Test]
    public function it_correctly_counts_days_by_status_on_leave_request(): void
    {
        $this->giveSlCredits(2);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-10', 5);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'sl_credited'],
            ['date' => '2026-07-08', 'status' => 'ncns'],
            ['date' => '2026-07-09', 'status' => 'advised_absence'],
            ['date' => '2026-07-10', 'status' => 'advised_absence'],
        ];

        $this->dualApproveWithDayStatuses($leaveRequest, $dayStatuses);

        $leaveRequest->refresh();
        $leaveRequest->load('days');

        $this->assertEquals(2, $leaveRequest->getCreditedDaysCount());
        $this->assertEquals(1, $leaveRequest->getNcnsDaysCount());
        $this->assertEquals(2, $leaveRequest->getAdvisedAbsenceDaysCount());
    }

    // ===================================================================
    // Test: Force approve with per-day SL statuses
    // ===================================================================

    #[Test]
    public function it_passes_day_statuses_through_force_approve_for_sl(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $this->giveSlCredits(3);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-10', 5);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited', 'notes' => 'Credited day 1'],
            ['date' => '2026-07-07', 'status' => 'sl_credited', 'notes' => 'Credited day 2'],
            ['date' => '2026-07-08', 'status' => 'ncns', 'notes' => 'No call no show'],
            ['date' => '2026-07-09', 'status' => 'advised_absence'],
            ['date' => '2026-07-10', 'status' => 'advised_absence'],
        ];

        $response = $this->actingAs($superAdmin)->post(
            route('leave-requests.force-approve', $leaveRequest),
            [
                'review_notes' => 'Force approved with day statuses by Super Admin.',
                'day_statuses' => $dayStatuses,
            ]
        );

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals(2, (int) $leaveRequest->credits_deducted);

        // Verify day records created with correct statuses
        $days = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(5, $days);
        $this->assertEquals('sl_credited', $days[0]->day_status);
        $this->assertEquals('sl_credited', $days[1]->day_status);
        $this->assertEquals('ncns', $days[2]->day_status);
        $this->assertEquals('advised_absence', $days[3]->day_status);
        $this->assertEquals('advised_absence', $days[4]->day_status);
        $this->assertEquals('Credited day 1', $days[0]->notes);
        $this->assertEquals('No call no show', $days[2]->notes);
    }

    #[Test]
    public function it_passes_day_statuses_through_force_approve_partial_for_sl(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $this->giveSlCredits(2);

        // 5-day request Mon-Fri
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-10', 5);

        // Force approve with partial denial (deny Thu+Fri), assign statuses for approved days
        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'ncns', 'notes' => 'No call'],
            ['date' => '2026-07-08', 'status' => 'advised_absence'],
        ];

        $response = $this->actingAs($superAdmin)->post(
            route('leave-requests.force-approve', $leaveRequest),
            [
                'review_notes' => 'Force partial approve with SL statuses.',
                'denied_dates' => ['2026-07-09', '2026-07-10'],
                'denial_reason' => 'Denied last two days, only partial coverage.',
                'day_statuses' => $dayStatuses,
            ]
        );

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertTrue((bool) $leaveRequest->has_partial_denial);

        // Only 1 credited day
        $this->assertEquals(1, (int) $leaveRequest->credits_deducted);

        // Day records should exist for approved days only
        $days = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(3, $days);
        $this->assertEquals('sl_credited', $days[0]->day_status);
        $this->assertEquals('ncns', $days[1]->day_status);
        $this->assertEquals('advised_absence', $days[2]->day_status);
    }

    #[Test]
    public function it_rejects_force_approve_when_no_day_statuses_provided_for_sl(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $this->giveSlCredits(2);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        // Force approve without day_statuses → should be rejected
        $response = $this->actingAs($superAdmin)->post(
            route('leave-requests.force-approve', $leaveRequest),
            [
                'review_notes' => 'Force approved without explicit day statuses.',
            ]
        );

        $response->assertSessionHasErrors('error');
        $leaveRequest->refresh();
        $this->assertEquals('pending', $leaveRequest->status);
    }

    // ===================================================================
    // Test: Partial deny with per-day SL statuses (dual approval)
    // ===================================================================

    #[Test]
    public function it_passes_day_statuses_through_partial_deny_for_sl(): void
    {
        $this->giveSlCredits(2);

        // 5-day request Mon-Fri
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-10', 5);

        // Admin partial deny: deny Thu+Fri, assign day statuses for Mon-Wed
        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited', 'notes' => 'Credited'],
            ['date' => '2026-07-07', 'status' => 'ncns', 'notes' => 'No call'],
            ['date' => '2026-07-08', 'status' => 'advised_absence'],
        ];

        $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => ['2026-07-09', '2026-07-10'],
                'denial_reason' => 'Last two days denied for coverage.',
                'review_notes' => 'Admin partial deny with SL statuses.',
                'day_statuses' => $dayStatuses,
            ]
        );

        // HR approves normally to complete dual approval
        $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'HR approved the partial.']
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertTrue((bool) $leaveRequest->has_partial_denial);

        // Only 1 sl_credited day → 1 credit deducted
        $this->assertEquals(1, (int) $leaveRequest->credits_deducted);

        // Day records should exist for approved dates
        $days = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(3, $days);
        $this->assertEquals('sl_credited', $days[0]->day_status);
        $this->assertEquals('ncns', $days[1]->day_status);
        $this->assertEquals('advised_absence', $days[2]->day_status);
        $this->assertEquals('Credited', $days[0]->notes);
        $this->assertEquals('No call', $days[1]->notes);
    }

    #[Test]
    public function it_pre_stores_day_statuses_in_partial_deny_for_dual_approval(): void
    {
        $this->giveSlCredits(3);

        // 3-day request Mon-Wed
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        // Admin partial deny: deny Wed, assign day statuses for Mon-Tue
        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited', 'notes' => 'Admin assigned credited'],
            ['date' => '2026-07-07', 'status' => 'ncns', 'notes' => 'Admin marked NCNS'],
        ];

        $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => ['2026-07-08'],
                'denial_reason' => 'Wednesday denied by admin.',
                'review_notes' => 'Admin partial deny.',
                'day_statuses' => $dayStatuses,
            ]
        );

        // After Admin partial deny, day records should be pre-stored
        $preStoredDays = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->orderBy('date')
            ->get();
        $this->assertCount(2, $preStoredDays, 'Day records should be pre-stored after Admin partial deny');
        $this->assertEquals('sl_credited', $preStoredDays[0]->day_status);
        $this->assertEquals('ncns', $preStoredDays[1]->day_status);

        // HR approves (without day_statuses) — should use pre-stored records
        $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'HR final approval.']
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);

        // Should use pre-stored statuses: 1 sl_credited → 1 credit deducted
        $this->assertEquals(1, (int) $leaveRequest->credits_deducted);

        // Final day records
        $finalDays = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(2, $finalDays);
        $this->assertEquals('sl_credited', $finalDays[0]->day_status);
        $this->assertEquals('ncns', $finalDays[1]->day_status);
        $this->assertEquals('Admin assigned credited', $finalDays[0]->notes);
        $this->assertEquals('Admin marked NCNS', $finalDays[1]->notes);
    }

    #[Test]
    public function it_hr_can_override_pre_stored_day_statuses_in_partial_deny(): void
    {
        $this->giveSlCredits(3);

        // 3-day request Mon-Wed
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        // Admin partial deny: deny Wed, assign statuses for Mon-Tue
        $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => ['2026-07-08'],
                'denial_reason' => 'Wednesday denied.',
                'review_notes' => 'Admin partial.',
                'day_statuses' => [
                    ['date' => '2026-07-06', 'status' => 'sl_credited'],
                    ['date' => '2026-07-07', 'status' => 'ncns'],
                ],
            ]
        );

        // HR approves WITH their own day_statuses (overriding Admin's)
        $this->actingAs($this->hr)->post(
            route('leave-requests.approve', $leaveRequest),
            [
                'review_notes' => 'HR overrides statuses.',
                'day_statuses' => [
                    ['date' => '2026-07-06', 'status' => 'sl_credited'],
                    ['date' => '2026-07-07', 'status' => 'sl_credited'],
                ],
            ]
        );

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);

        // HR overrode: 2 sl_credited → 2 credits deducted
        $this->assertEquals(2, (int) $leaveRequest->credits_deducted);

        $days = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(2, $days);
        $this->assertEquals('sl_credited', $days[0]->day_status);
        $this->assertEquals('sl_credited', $days[1]->day_status);
    }

    // ===================================================================
    // Test: Show page returns assigned_by_role for pre-stored day records
    // ===================================================================

    #[Test]
    public function it_returns_assigned_by_role_in_day_status_data(): void
    {
        $this->giveSlCredits(2);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        $dayStatuses = [
            ['date' => '2026-07-06', 'status' => 'sl_credited'],
            ['date' => '2026-07-07', 'status' => 'ncns'],
            ['date' => '2026-07-08', 'status' => 'advised_absence'],
        ];

        $this->dualApproveWithDayStatuses($leaveRequest, $dayStatuses);

        $response = $this->actingAs($this->admin)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('leaveRequestDays', 3)
                ->has('leaveRequestDays.0.assigned_by_role')
            );
    }

    #[Test]
    public function it_returns_pre_stored_day_records_for_pending_sl_with_partial_approval(): void
    {
        $this->giveSlCredits(3);

        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-08', 3);

        // Admin partial deny: deny Wed, assign statuses for Mon-Tue
        $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => ['2026-07-08'],
                'denial_reason' => 'Denied Wednesday for coverage.',
                'review_notes' => 'Admin partial approval.',
                'day_statuses' => [
                    ['date' => '2026-07-06', 'status' => 'sl_credited', 'notes' => 'Admin assigned'],
                    ['date' => '2026-07-07', 'status' => 'ncns'],
                ],
            ]
        );

        $leaveRequest->refresh();
        $this->assertEquals('pending', $leaveRequest->status);

        // HR views the show page — should see pre-stored day records
        $response = $this->actingAs($this->hr)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->has('leaveRequestDays', 2)
                ->where('leaveRequestDays.0.day_status', 'sl_credited')
                ->where('leaveRequestDays.0.notes', 'Admin assigned')
                ->where('leaveRequestDays.0.assigned_by', $this->admin->name)
                ->where('leaveRequestDays.0.assigned_by_role', 'Admin')
                ->where('leaveRequestDays.1.day_status', 'ncns')
            );
    }

    #[Test]
    public function it_formats_original_dates_in_show_after_partial_denial(): void
    {
        $this->giveSlCredits(5);

        // 5-day request Mon-Fri
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-10', 5);

        // Admin partial deny: deny Thu+Fri, keeping Mon-Wed
        $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => ['2026-07-09', '2026-07-10'],
                'denial_reason' => 'Deny Thu-Fri for coverage.',
                'review_notes' => 'Admin partial denial.',
            ]
        );

        $leaveRequest->refresh();
        $this->assertTrue($leaveRequest->has_partial_denial);
        $this->assertEquals('2026-07-06', $leaveRequest->original_start_date->format('Y-m-d'));
        $this->assertEquals('2026-07-10', $leaveRequest->original_end_date->format('Y-m-d'));
        // Narrowed dates
        $this->assertEquals('2026-07-06', $leaveRequest->start_date->format('Y-m-d'));
        $this->assertEquals('2026-07-08', $leaveRequest->end_date->format('Y-m-d'));

        // HR views the show page — original dates should be formatted as Y-m-d
        $response = $this->actingAs($this->hr)->get(route('leave-requests.show', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->where('leaveRequest.original_start_date', '2026-07-06')
                ->where('leaveRequest.original_end_date', '2026-07-10')
                ->where('leaveRequest.start_date', '2026-07-06')
                ->where('leaveRequest.end_date', '2026-07-08')
                ->where('leaveRequest.has_partial_denial', true)
                ->has('leaveRequest.denied_dates', 2)
            );
    }

    #[Test]
    public function it_replaces_denied_dates_when_second_approver_partially_denies(): void
    {
        $this->giveSlCredits(5);

        // 5-day request Mon-Fri
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-10', 5);

        // Admin partial deny: deny Thu+Fri
        $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => ['2026-07-09', '2026-07-10'],
                'denial_reason' => 'Deny Thu-Fri for coverage.',
                'review_notes' => 'Admin partial denial.',
            ]
        );

        $leaveRequest->refresh();
        $this->assertCount(2, LeaveRequestDeniedDate::where('leave_request_id', $leaveRequest->id)->get());
        $this->assertEquals(3, $leaveRequest->approved_days);

        // HR partially denies: re-includes Fri (un-denies it) but denies Mon instead
        // So HR approves Tue, Wed, Thu, Fri (4 days) and denies Mon
        $this->actingAs($this->hr)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => ['2026-07-06'],
                'denial_reason' => 'Deny Monday instead, re-approving Thu-Fri.',
                'review_notes' => 'HR re-assessment.',
            ]
        );

        $leaveRequest->refresh();

        // Old denied dates should be REPLACED, not accumulated
        $deniedDates = LeaveRequestDeniedDate::where('leave_request_id', $leaveRequest->id)
            ->orderBy('denied_date')
            ->get();
        $this->assertCount(1, $deniedDates, 'Old denied dates should be replaced, not accumulated');
        $this->assertEquals('2026-07-06', $deniedDates[0]->denied_date->format('Y-m-d'));
        $this->assertEquals($this->hr->id, $deniedDates[0]->denied_by);

        // Approved days should now be 4 (Tue-Fri)
        $this->assertEquals(4, $leaveRequest->approved_days);

        // Original dates should still be preserved from the first partial denial
        $this->assertEquals('2026-07-06', $leaveRequest->original_start_date->format('Y-m-d'));
        $this->assertEquals('2026-07-10', $leaveRequest->original_end_date->format('Y-m-d'));

        // Narrowed dates updated to Tue-Fri
        $this->assertEquals('2026-07-07', $leaveRequest->start_date->format('Y-m-d'));
        $this->assertEquals('2026-07-10', $leaveRequest->end_date->format('Y-m-d'));
    }

    #[Test]
    public function it_uses_original_date_range_for_second_partial_denial_validation(): void
    {
        $this->giveSlCredits(5);

        // 5-day request Mon-Fri
        $leaveRequest = $this->createSlRequest('2026-07-06', '2026-07-10', 5);

        // Admin partial deny: deny Mon (narrowed range becomes Tue-Fri)
        $this->actingAs($this->admin)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => ['2026-07-06'],
                'denial_reason' => 'Monday denied by admin.',
                'review_notes' => 'Admin partial denial.',
            ]
        );

        $leaveRequest->refresh();
        // Now start_date is 2026-07-07 (Tue), end_date is 2026-07-10 (Fri)
        // But original_start_date is still 2026-07-06 (Mon)

        // HR second partial deny — should be able to deny Fri (within original range)
        // AND re-include Mon (previously denied, within original range)
        // Denying only Fri, approving Mon-Thu
        $response = $this->actingAs($this->hr)->post(
            route('leave-requests.partial-deny', $leaveRequest),
            [
                'denied_dates' => ['2026-07-10'],
                'denial_reason' => 'Deny Friday instead.',
                'review_notes' => 'HR second partial denial.',
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $leaveRequest->refresh();
        $this->assertEquals(4, $leaveRequest->approved_days);

        // Only 1 denied date (Fri) — Mon was un-denied
        $deniedDates = LeaveRequestDeniedDate::where('leave_request_id', $leaveRequest->id)->get();
        $this->assertCount(1, $deniedDates);
        $this->assertEquals('2026-07-10', $deniedDates[0]->denied_date->format('Y-m-d'));
    }
}
