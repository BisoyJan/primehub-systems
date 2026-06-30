<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IWLeaveTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $hr;

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

    /**
     * Helper to create a pending IW leave request with HR pre-approved,
     * so admin approval triggers full approval flow.
     */
    protected function createPendingIwWithHrApproval(array $overrides = []): LeaveRequest
    {
        return LeaveRequest::factory()->create(array_merge([
            'user_id' => $this->employee->id,
            'leave_type' => 'IW',
            'status' => 'pending',
            'hr_approved_by' => $this->hr->id,
            'hr_approved_at' => now(),
        ], $overrides));
    }

    // ────────────────────────────────────────────────
    // IW Auto-Excuse on Approval Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function it_auto_excuses_attendance_points_when_iw_with_supporting_doc_is_approved(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        // Create existing attendance with biometric data (employee reported to work)
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'admin_verified' => true,
            'actual_time_in' => '08:15:00',
            'actual_time_out' => '12:00:00',
        ]);

        $point = AttendancePoint::factory()->create([
            'user_id' => $this->employee->id,
            'attendance_id' => $attendance->id,
            'shift_date' => $shiftDate,
            'point_type' => 'undertime_more_than_hour',
            'points' => 0.5,
            'is_excused' => false,
        ]);

        // Create pending IW request with supporting document (HR already approved)
        $leaveRequest = $this->createPendingIwWithHrApproval([
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'medical_cert_submitted' => true,
            'medical_cert_path' => 'medical_certificates/test_supporting_doc.pdf',
        ]);

        // Admin approves the IW (triggers full approval)
        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approved IW with supporting document.']
        );

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);

        // Verify attendance point was auto-excused
        $point->refresh();
        $this->assertTrue($point->is_excused);
        $this->assertNotNull($point->excused_at);
        $this->assertStringContainsString('supporting document', $point->excuse_reason);
        $this->assertStringContainsString("Leave Request #{$leaveRequest->id}", $point->excuse_reason);
    }

    #[Test]
    public function it_preserves_biometric_attendance_when_iw_is_approved(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        // Create existing attendance with biometric data showing partial work
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'on_time',
            'admin_verified' => true,
            'actual_time_in' => '08:00:00',
            'actual_time_out' => '13:00:00',
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00',
        ]);

        $leaveRequest = $this->createPendingIwWithHrApproval([
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'medical_cert_submitted' => true,
            'medical_cert_path' => 'medical_certificates/test_supporting_doc.pdf',
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approved IW.']
        );

        $response->assertRedirect();

        // Attendance should NOT be 'on_leave' — should preserve worked-hours status
        $attendance->refresh();
        $this->assertNotEquals('on_leave', $attendance->status);
        $this->assertEquals($leaveRequest->id, $attendance->leave_request_id);
        $this->assertNotNull($attendance->pre_leave_status);
    }

    #[Test]
    public function it_does_not_auto_excuse_points_when_iw_without_supporting_doc_is_approved(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'admin_verified' => true,
        ]);

        $point = AttendancePoint::factory()->create([
            'user_id' => $this->employee->id,
            'attendance_id' => $attendance->id,
            'shift_date' => $shiftDate,
            'point_type' => 'undertime_more_than_hour',
            'points' => 0.5,
            'is_excused' => false,
        ]);

        $leaveRequest = $this->createPendingIwWithHrApproval([
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'medical_cert_submitted' => false,
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approved IW without supporting document.']
        );

        $response->assertRedirect();

        // Verify attendance point was NOT auto-excused
        $point->refresh();
        $this->assertFalse($point->is_excused);
        $this->assertNull($point->excused_at);
        $this->assertNull($point->excuse_reason);
    }

    // ────────────────────────────────────────────────
    // IW Credit Deduction Test
    // ────────────────────────────────────────────────

    #[Test]
    public function it_does_not_deduct_leave_credits_when_iw_is_approved(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = $this->createPendingIwWithHrApproval([
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'medical_cert_submitted' => false,
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approved IW.']
        );

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertNull($leaveRequest->credits_deducted);
    }

    // ────────────────────────────────────────────────
    // IW Cancellation Rollback Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function it_un_excuses_auto_excused_points_when_iw_with_doc_is_cancelled(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'IW',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'admin_approved_by' => $this->admin->id,
            'admin_approved_at' => now(),
            'hr_approved_by' => $this->hr->id,
            'hr_approved_at' => now(),
            'credits_deducted' => 0,
            'medical_cert_submitted' => true,
            'medical_cert_path' => 'medical_certificates/test_supporting_doc.pdf',
        ]);

        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'pre_leave_status' => 'on_time',
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        // Auto-excused point with IW leave request reference
        $point = AttendancePoint::factory()->create([
            'user_id' => $this->employee->id,
            'attendance_id' => $attendance->id,
            'shift_date' => $shiftDate,
            'point_type' => 'undertime_more_than_hour',
            'points' => 0.5,
            'is_excused' => true,
            'excused_by' => $this->admin->id,
            'excused_at' => now(),
            'excuse_reason' => "Auto-excused: Approved IW with supporting document - Leave Request #{$leaveRequest->id}",
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'IW with supporting doc cancelled.']
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
    public function it_does_not_un_excuse_points_for_iw_without_doc(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'IW',
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'admin_approved_by' => $this->admin->id,
            'admin_approved_at' => now(),
            'hr_approved_by' => $this->hr->id,
            'hr_approved_at' => now(),
            'credits_deducted' => 0,
            'medical_cert_submitted' => false,
        ]);

        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'pre_leave_status' => 'on_time',
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true,
        ]);

        // Manually excused point (not by auto-excuse)
        $point = AttendancePoint::factory()->create([
            'user_id' => $this->employee->id,
            'attendance_id' => $attendance->id,
            'shift_date' => $shiftDate,
            'point_type' => 'undertime_more_than_hour',
            'points' => 0.5,
            'is_excused' => true,
            'excused_by' => $this->admin->id,
            'excused_at' => now(),
            'excuse_reason' => 'Manually excused by supervisor.',
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.cancel', $leaveRequest),
            ['cancellation_reason' => 'IW without doc cancelled.']
        );

        $response->assertRedirect();

        // Point should remain excused
        $point->refresh();
        $this->assertTrue($point->is_excused);
        $this->assertEquals('Manually excused by supervisor.', $point->excuse_reason);
    }

    // ────────────────────────────────────────────────
    // Edge Case Tests
    // ────────────────────────────────────────────────

    #[Test]
    public function it_handles_iw_approval_with_doc_when_no_attendance_points_exist(): void
    {
        $shiftDate = now()->addDays(5)->format('Y-m-d');

        $leaveRequest = $this->createPendingIwWithHrApproval([
            'start_date' => $shiftDate,
            'end_date' => $shiftDate,
            'days_requested' => 1,
            'medical_cert_submitted' => true,
            'medical_cert_path' => 'medical_certificates/test_supporting_doc.pdf',
        ]);

        // No attendance points exist — should not error
        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approved IW, no existing points.']
        );

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
    }

    #[Test]
    public function it_auto_excuses_multiple_points_across_multi_day_iw(): void
    {
        $startDate = now()->addDays(5);
        $endDate = now()->addDays(7);

        // Create attendance and points for each day
        $points = [];
        for ($i = 0; $i < 3; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');

            $attendance = Attendance::factory()->create([
                'user_id' => $this->employee->id,
                'shift_date' => $date,
                'status' => 'on_time',
                'admin_verified' => true,
            ]);

            $points[] = AttendancePoint::factory()->create([
                'user_id' => $this->employee->id,
                'attendance_id' => $attendance->id,
                'shift_date' => $date,
                'point_type' => 'undertime',
                'points' => 0.5,
                'is_excused' => false,
            ]);
        }

        $leaveRequest = $this->createPendingIwWithHrApproval([
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 3,
            'medical_cert_submitted' => true,
            'medical_cert_path' => 'medical_certificates/test_supporting_doc.pdf',
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('leave-requests.approve', $leaveRequest),
            ['review_notes' => 'Approved multi-day IW with supporting document.']
        );

        $response->assertRedirect();

        // All 3 points should be auto-excused
        foreach ($points as $point) {
            $point->refresh();
            $this->assertTrue($point->is_excused, "Point on {$point->shift_date} should be excused");
            $this->assertStringContainsString('supporting document', $point->excuse_reason);
        }
    }
}
