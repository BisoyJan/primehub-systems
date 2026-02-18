<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Models\Attendance;
use App\Models\AttendancePoint;
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

class LeaveRequestCancellationRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

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

        $this->employee = $this->createAgentWithSchedule();
    }

    protected function createAgentWithSchedule(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
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

    // ────────────────────────────────────────────────
    // SL Cancellation Rollback Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function it_reverts_advised_absence_to_original_status_when_sl_cancelled(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        // Create existing attendance record with 'tardy' status
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'admin_verified' => false,
        ]);

        // Simulate SL approval: changed to advised_absence with pre_leave_status stored
        $attendance->update([
            'pre_leave_status' => 'tardy',
            'status' => 'advised_absence',
            'admin_verified' => true,
        ]);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 1,
        ]);

        $attendance->update(['leave_request_id' => $leaveRequest->id]);

        // Give credits so restore works
        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 5,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        // Admin cancels the leave
        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'Employee changed plans, no longer needs leave.']
        );

        $response->assertRedirect();

        // Verify attendance reverted to original status
        $attendance->refresh();
        $this->assertEquals('tardy', $attendance->status);
        $this->assertNull($attendance->pre_leave_status);
        $this->assertNull($attendance->leave_request_id);
        $this->assertFalse($attendance->admin_verified);

        // Verify leave request is cancelled
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'cancelled',
        ]);
    }

    #[Test]
    public function it_deletes_attendance_created_by_sl_approval_when_cancelled(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 1,
        ]);

        // Simulate attendance created by approval (pre_leave_status is null)
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'advised_absence',
            'pre_leave_status' => null,
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 5,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'Cancel leave, employee will work.']
        );

        $response->assertRedirect();

        // Verify attendance record was deleted (since it was created by approval)
        $this->assertDatabaseMissing('attendances', [
            'id' => $attendance->id,
        ]);
    }

    #[Test]
    public function it_preserves_ncns_rollback_on_sl_cancel(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 0,
        ]);

        // NCNS record: status is kept as ncns, but pre_leave_status is stored as 'ncns'
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'ncns',
            'pre_leave_status' => 'ncns',
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'Cancelling SL that covered NCNS day.']
        );

        $response->assertRedirect();

        // Verify NCNS record is reverted to ncns (stays as ncns)
        $attendance->refresh();
        $this->assertEquals('ncns', $attendance->status);
        $this->assertNull($attendance->pre_leave_status);
        $this->assertNull($attendance->leave_request_id);
    }

    // ────────────────────────────────────────────────
    // VL/BL Cancellation Rollback Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function it_reverts_on_leave_to_original_status_when_vl_cancelled(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        // Existing attendance with 'half_day_absence' before VL approval
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'on_leave',
            'pre_leave_status' => 'half_day_absence',
            'admin_verified' => true,
        ]);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 1,
        ]);

        $attendance->update(['leave_request_id' => $leaveRequest->id]);

        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 5,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'VL cancelled by admin.']
        );

        $response->assertRedirect();

        $attendance->refresh();
        $this->assertEquals('half_day_absence', $attendance->status);
        $this->assertNull($attendance->pre_leave_status);
        $this->assertNull($attendance->leave_request_id);
    }

    #[Test]
    public function it_deletes_attendance_created_by_vl_approval_when_cancelled(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 1,
        ]);

        // Attendance created by approval (pre_leave_status is null)
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'on_leave',
            'pre_leave_status' => null,
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 5,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'VL cancelled.']
        );

        $response->assertRedirect();

        $this->assertDatabaseMissing('attendances', [
            'id' => $attendance->id,
        ]);
    }

    // ────────────────────────────────────────────────
    // Non-Credited Leave Cancellation Rollback Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function it_rollbacks_attendance_for_non_credited_leave_on_cancel(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'UPTO',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 0,
        ]);

        // Existing attendance changed to on_leave
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'on_leave',
            'pre_leave_status' => 'ncns',
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'UPTO cancelled.']
        );

        $response->assertRedirect();

        // Even with 0 credits deducted, attendance should be rolled back
        $attendance->refresh();
        $this->assertEquals('ncns', $attendance->status);
        $this->assertNull($attendance->pre_leave_status);
        $this->assertNull($attendance->leave_request_id);
    }

    // ────────────────────────────────────────────────
    // AttendancePoint Un-excusing Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function it_un_excuses_auto_excused_points_when_sl_with_medical_cert_cancelled(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 1,
            'medical_cert_submitted' => true,
        ]);

        // Attendance record linked to leave
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'advised_absence',
            'pre_leave_status' => 'ncns',
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        // Auto-excused point with leave request reference in excuse_reason
        $point = AttendancePoint::factory()->create([
            'user_id' => $this->employee->id,
            'attendance_id' => $attendance->id,
            'shift_date' => $shiftDate,
            'point_type' => 'whole_day_absence',
            'points' => 1.0,
            'is_excused' => true,
            'excused_by' => $this->admin->id,
            'excused_at' => now(),
            'excuse_reason' => "Auto-excused: Approved SL with medical certificate - Leave Request #{$leaveRequest->id}",
        ]);

        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 5,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'SL with medical cert cancelled.']
        );

        $response->assertRedirect();

        // Verify point was un-excused
        $point->refresh();
        $this->assertFalse($point->is_excused);
        $this->assertNull($point->excused_by);
        $this->assertNull($point->excused_at);
        $this->assertNull($point->excuse_reason);
    }

    #[Test]
    public function it_does_not_un_excuse_manually_excused_points(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 1,
            'medical_cert_submitted' => true,
        ]);

        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'advised_absence',
            'pre_leave_status' => 'tardy',
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        // Manually excused point (reason does NOT mention Leave Request #ID)
        $manuallyExcusedPoint = AttendancePoint::factory()->create([
            'user_id' => $this->employee->id,
            'attendance_id' => $attendance->id,
            'shift_date' => $shiftDate,
            'point_type' => 'whole_day_absence',
            'points' => 1.0,
            'is_excused' => true,
            'excused_by' => $this->admin->id,
            'excused_at' => now(),
            'excuse_reason' => 'Manually excused by supervisor for personal reasons.',
        ]);

        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 5,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'SL cancelled.']
        );

        $response->assertRedirect();

        // Manually excused point should remain excused
        $manuallyExcusedPoint->refresh();
        $this->assertTrue($manuallyExcusedPoint->is_excused);
        $this->assertEquals('Manually excused by supervisor for personal reasons.', $manuallyExcusedPoint->excuse_reason);
    }

    #[Test]
    public function it_does_not_un_excuse_points_for_non_medical_cert_leave(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 1,
            'medical_cert_submitted' => false,
        ]);

        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'on_leave',
            'pre_leave_status' => 'tardy',
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        // Point that happens to be excused for other reasons
        $point = AttendancePoint::factory()->create([
            'user_id' => $this->employee->id,
            'attendance_id' => $attendance->id,
            'shift_date' => $shiftDate,
            'point_type' => 'whole_day_absence',
            'points' => 1.0,
            'is_excused' => true,
            'excused_by' => $this->admin->id,
            'excused_at' => now(),
            'excuse_reason' => 'Some other reason',
        ]);

        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 5,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'VL cancelled.']
        );

        $response->assertRedirect();

        // VL doesn't have medical cert auto-excuse, so point should remain excused
        $point->refresh();
        $this->assertTrue($point->is_excused);
    }

    // ────────────────────────────────────────────────
    // Edge Case Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function it_handles_cancel_when_no_attendance_records_exist(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 0,
        ]);

        // No attendance records at all — should not error
        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'No attendance records edge case.']
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'cancelled',
        ]);
    }

    #[Test]
    public function it_handles_multi_day_leave_with_mixed_attendance_records(): void
    {
        $startDate = now()->addDays(5);
        $endDate = now()->addDays(7);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 3,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 2,
        ]);

        // Day 1: existing record that was modified (has pre_leave_status)
        $attendance1 = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $startDate->format('Y-m-d'),
            'status' => 'advised_absence',
            'pre_leave_status' => 'tardy',
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        // Day 2: created by approval (no pre_leave_status)
        $attendance2 = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $startDate->copy()->addDay()->format('Y-m-d'),
            'status' => 'advised_absence',
            'pre_leave_status' => null,
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        // Day 3: NCNS preserved
        $attendance3 = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $endDate->format('Y-m-d'),
            'status' => 'ncns',
            'pre_leave_status' => 'ncns',
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 5,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'Multi-day SL cancel test.']
        );

        $response->assertRedirect();

        // Day 1: reverted to tardy
        $attendance1->refresh();
        $this->assertEquals('tardy', $attendance1->status);
        $this->assertNull($attendance1->pre_leave_status);

        // Day 2: deleted (was created by approval)
        $this->assertDatabaseMissing('attendances', ['id' => $attendance2->id]);

        // Day 3: reverted to ncns
        $attendance3->refresh();
        $this->assertEquals('ncns', $attendance3->status);
        $this->assertNull($attendance3->pre_leave_status);
    }

    #[Test]
    public function it_does_not_rollback_attendance_for_pending_leave_cancel(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'pending',
        ]);

        // Unrelated attendance record (not linked to leave)
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'pre_leave_status' => null,
            'leave_request_id' => null,
        ]);

        $response = $this->actingAs($this->employee)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'Changed my mind about this VL.']
        );

        $response->assertRedirect();

        // Pending cancel should NOT touch attendance
        $attendance->refresh();
        $this->assertEquals('tardy', $attendance->status);
        $this->assertNull($attendance->leave_request_id);
    }

    // ────────────────────────────────────────────────
    // Destroy Rollback Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function it_rollbacks_attendance_when_approved_leave_is_deleted(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'credits_deducted' => 1,
        ]);

        // Existing attendance modified by approval
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'on_leave',
            'pre_leave_status' => 'on_time',
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 5,
            'sick_leave_balance' => 5,
            'credits_earned' => 1.25,
            'credits_balance' => 5,
            'accrued_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->delete(
            route('leave-requests.destroy', $leaveRequest)
        );

        $response->assertRedirect();

        // Attendance should be reverted before the leave request is deleted
        $attendance->refresh();
        $this->assertEquals('on_time', $attendance->status);
        $this->assertNull($attendance->pre_leave_status);
        $this->assertNull($attendance->leave_request_id);
    }

    // ────────────────────────────────────────────────
    // pre_leave_status Column Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function it_has_pre_leave_status_column_on_attendances(): void
    {
        $attendance = Attendance::factory()->create([
            'pre_leave_status' => 'tardy',
        ]);

        $this->assertEquals('tardy', $attendance->pre_leave_status);

        $attendance->update(['pre_leave_status' => null]);
        $attendance->refresh();
        $this->assertNull($attendance->pre_leave_status);
    }
}
