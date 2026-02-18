<?php

namespace Tests\Unit\Models;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $user = User::factory()->create();
        $reviewer = User::factory()->create();

        $leaveRequest = LeaveRequest::create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'start_date' => '2024-01-15',
            'end_date' => '2024-01-17',
            'days_requested' => 3.00,
            'reason' => 'Personal matter',
            'campaign_department' => 'Sales',
            'medical_cert_submitted' => false,
            'status' => 'pending',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => 'Approved',
            'credits_deducted' => 3.00,
            'credits_year' => 2024,
            'attendance_points_at_request' => 2.5,
            'auto_rejected' => false,
            'auto_rejection_reason' => null,
        ]);

        $this->assertEquals($user->id, $leaveRequest->user_id);
        $this->assertEquals('VL', $leaveRequest->leave_type);
        $this->assertEquals('2024-01-15', $leaveRequest->start_date->format('Y-m-d'));
    }

    #[Test]
    public function it_casts_start_date_to_carbon_date(): void
    {
        $user = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'start_date' => '2024-01-15',
        ]);

        $this->assertInstanceOf(Carbon::class, $leaveRequest->start_date);
        /** @var Carbon $startDate */
        $startDate = $leaveRequest->start_date;
        $this->assertEquals('2024-01-15', $startDate->toDateString());
    }

    #[Test]
    public function it_casts_end_date_to_carbon_date(): void
    {
        $user = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'end_date' => '2024-01-17',
        ]);

        $this->assertInstanceOf(Carbon::class, $leaveRequest->end_date);
    }

    #[Test]
    public function it_casts_days_requested_to_decimal(): void
    {
        $user = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'days_requested' => '3.5',
        ]);

        $this->assertEquals('3.50', $leaveRequest->days_requested);
    }

    #[Test]
    public function it_casts_medical_cert_submitted_to_boolean(): void
    {
        $user = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'medical_cert_submitted' => 1,
        ]);

        $this->assertIsBool($leaveRequest->medical_cert_submitted);
        $this->assertTrue($leaveRequest->medical_cert_submitted);
    }

    #[Test]
    public function it_casts_reviewed_at_to_datetime(): void
    {
        $user = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'reviewed_at' => '2024-01-15 14:30:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $leaveRequest->reviewed_at);
    }

    #[Test]
    public function it_casts_credits_deducted_to_decimal(): void
    {
        $user = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'credits_deducted' => '2.5',
        ]);

        $this->assertEquals('2.50', $leaveRequest->credits_deducted);
    }

    #[Test]
    public function it_casts_credits_year_to_integer(): void
    {
        $user = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'credits_year' => '2024',
        ]);

        $this->assertIsInt($leaveRequest->credits_year);
        $this->assertEquals(2024, $leaveRequest->credits_year);
    }

    #[Test]
    public function it_casts_attendance_points_at_request_to_decimal(): void
    {
        $user = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'attendance_points_at_request' => '3.25',
        ]);

        $this->assertEquals('3.25', $leaveRequest->attendance_points_at_request);
    }

    #[Test]
    public function it_casts_auto_rejected_to_boolean(): void
    {
        $user = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'auto_rejected' => 1,
        ]);

        $this->assertIsBool($leaveRequest->auto_rejected);
    }

    #[Test]
    public function it_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $leaveRequest->user);
        $this->assertEquals($user->id, $leaveRequest->user->id);
    }

    #[Test]
    public function it_belongs_to_reviewer(): void
    {
        $user = User::factory()->create();
        $reviewer = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'reviewed_by' => $reviewer->id,
        ]);

        $this->assertInstanceOf(User::class, $leaveRequest->reviewer);
        $this->assertEquals($reviewer->id, $leaveRequest->reviewer->id);
    }

    #[Test]
    public function it_has_many_attendances(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $user->id]);

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'leave_request_id' => $leaveRequest->id,
        ]);

        $this->assertCount(1, $leaveRequest->attendances);
        $this->assertTrue($leaveRequest->attendances->contains($attendance));
    }

    #[Test]
    public function it_requires_credits_for_vl_leave_type(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
        ]);

        $this->assertTrue($leaveRequest->requiresCredits());
    }

    #[Test]
    public function it_requires_credits_for_sl_leave_type(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SL',
        ]);

        $this->assertTrue($leaveRequest->requiresCredits());
    }

    #[Test]
    public function it_does_not_require_credits_for_bl_leave_type(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'BL',
        ]);

        $this->assertFalse($leaveRequest->requiresCredits());
    }

    #[Test]
    public function it_does_not_require_credits_for_spl_leave_type(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SPL',
        ]);

        $this->assertFalse($leaveRequest->requiresCredits());
    }

    #[Test]
    public function it_does_not_require_credits_for_loa_leave_type(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'LOA',
        ]);

        $this->assertFalse($leaveRequest->requiresCredits());
    }

    #[Test]
    public function it_requires_attendance_points_check_for_vl(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
        ]);

        $this->assertTrue($leaveRequest->requiresAttendancePointsCheck());
    }

    #[Test]
    public function it_requires_attendance_points_check_for_bl(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'BL',
        ]);

        $this->assertTrue($leaveRequest->requiresAttendancePointsCheck());
    }

    #[Test]
    public function it_does_not_require_attendance_points_check_for_sl(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SL',
        ]);

        $this->assertFalse($leaveRequest->requiresAttendancePointsCheck());
    }

    #[Test]
    public function it_requires_two_week_notice_for_vl(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
        ]);

        $this->assertTrue($leaveRequest->requiresTwoWeekNotice());
    }

    #[Test]
    public function it_requires_two_week_notice_for_bl(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'BL',
        ]);

        $this->assertTrue($leaveRequest->requiresTwoWeekNotice());
    }

    #[Test]
    public function it_does_not_require_two_week_notice_for_sl(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SL',
        ]);

        $this->assertFalse($leaveRequest->requiresTwoWeekNotice());
    }

    #[Test]
    public function it_requires_thirty_day_absence_check_for_vl(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
        ]);

        $this->assertTrue($leaveRequest->requiresThirtyDayAbsenceCheck());
    }

    #[Test]
    public function it_requires_thirty_day_absence_check_for_bl(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'BL',
        ]);

        $this->assertTrue($leaveRequest->requiresThirtyDayAbsenceCheck());
    }

    #[Test]
    public function it_filters_by_status_scope(): void
    {
        $user = User::factory()->create();
        LeaveRequest::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        LeaveRequest::factory()->create(['user_id' => $user->id, 'status' => 'approved']);

        $pending = LeaveRequest::byStatus('pending')->get();

        $this->assertCount(1, $pending);
        $this->assertEquals('pending', $pending->first()->status);
    }

    #[Test]
    public function it_filters_by_type_scope(): void
    {
        $user = User::factory()->create();
        LeaveRequest::factory()->create(['user_id' => $user->id, 'leave_type' => 'VL']);
        LeaveRequest::factory()->create(['user_id' => $user->id, 'leave_type' => 'SL']);

        $vlRequests = LeaveRequest::byType('VL')->get();

        $this->assertCount(1, $vlRequests);
        $this->assertEquals('VL', $vlRequests->first()->leave_type);
    }

    #[Test]
    public function it_filters_by_user_scope(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        LeaveRequest::factory()->create(['user_id' => $user1->id]);
        LeaveRequest::factory()->create(['user_id' => $user2->id]);

        $user1Requests = LeaveRequest::forUser($user1->id)->get();

        $this->assertCount(1, $user1Requests);
        $this->assertEquals($user1->id, $user1Requests->first()->user_id);
    }

    #[Test]
    public function it_filters_by_pending_scope(): void
    {
        $user = User::factory()->create();
        LeaveRequest::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        LeaveRequest::factory()->create(['user_id' => $user->id, 'status' => 'approved']);

        $pending = LeaveRequest::pending()->get();

        $this->assertCount(1, $pending);
    }

    #[Test]
    public function it_filters_by_approved_scope(): void
    {
        $user = User::factory()->create();
        LeaveRequest::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        LeaveRequest::factory()->create(['user_id' => $user->id, 'status' => 'approved']);

        $approved = LeaveRequest::approved()->get();

        $this->assertCount(1, $approved);
    }

    #[Test]
    public function it_checks_if_request_is_pending(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->assertTrue($leaveRequest->isPending());
    }

    #[Test]
    public function it_checks_if_request_is_approved(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
        ]);

        $this->assertTrue($leaveRequest->isApproved());
    }

    #[Test]
    public function it_allows_cancelling_pending_request(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'start_date' => now()->addDays(5),
        ]);

        $this->assertTrue($leaveRequest->canBeCancelled());
    }

    #[Test]
    public function it_allows_cancelling_approved_future_request(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'start_date' => now()->addDays(5),
        ]);

        $this->assertTrue($leaveRequest->canBeCancelled());
    }

    #[Test]
    public function it_does_not_allow_cancelling_approved_past_request(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'start_date' => now()->subDays(5),
        ]);

        $this->assertFalse($leaveRequest->canBeCancelled());
    }

    #[Test]
    public function it_does_not_allow_cancelling_rejected_request(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'denied',
            'start_date' => now()->addDays(5),
        ]);

        $this->assertFalse($leaveRequest->canBeCancelled());
    }

    #[Test]
    public function it_detects_partially_approved_request(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'has_partial_denial' => true,
            'approved_days' => 3,
            'start_date' => now()->addDays(5),
        ]);

        $this->assertTrue($leaveRequest->isPartiallyApproved());
    }

    #[Test]
    public function it_does_not_detect_fully_approved_as_partially_approved(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'has_partial_denial' => false,
            'start_date' => now()->addDays(5),
        ]);

        $this->assertFalse($leaveRequest->isPartiallyApproved());
    }

    #[Test]
    public function it_does_not_detect_pending_with_partial_denial_as_partially_approved(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'has_partial_denial' => true,
            'start_date' => now()->addDays(5),
        ]);

        $this->assertFalse($leaveRequest->isPartiallyApproved());
    }
}
