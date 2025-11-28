<?php

namespace Tests\Unit\Models;

use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_casts_shift_date_to_carbon_date(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => '2024-01-15',
        ]);

        $this->assertInstanceOf(Carbon::class, $attendance->shift_date);
        $this->assertEquals('2024-01-15', $attendance->shift_date->format('Y-m-d'));
    }

    #[Test]
    public function it_casts_actual_time_in_to_datetime(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'actual_time_in' => '2024-01-15 08:00:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $attendance->actual_time_in);
    }

    #[Test]
    public function it_casts_actual_time_out_to_datetime(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'actual_time_out' => '2024-01-15 17:00:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $attendance->actual_time_out);
    }

    #[Test]
    public function it_casts_overtime_approved_at_to_datetime(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'overtime_approved_at' => '2024-01-15 17:30:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $attendance->overtime_approved_at);
    }

    #[Test]
    public function it_casts_is_advised_to_boolean(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'is_advised' => 1,
        ]);

        $this->assertIsBool($attendance->is_advised);
        $this->assertTrue($attendance->is_advised);
    }

    #[Test]
    public function it_casts_admin_verified_to_boolean(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'admin_verified' => 0,
        ]);

        $this->assertIsBool($attendance->admin_verified);
        $this->assertFalse($attendance->admin_verified);
    }

    #[Test]
    public function it_casts_is_cross_site_bio_to_boolean(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'is_cross_site_bio' => 1,
        ]);

        $this->assertIsBool($attendance->is_cross_site_bio);
    }

    #[Test]
    public function it_casts_overtime_approved_to_boolean(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'overtime_approved' => 1,
        ]);

        $this->assertIsBool($attendance->overtime_approved);
    }

    #[Test]
    public function it_casts_tardy_minutes_to_integer(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'tardy_minutes' => '30',
        ]);

        $this->assertIsInt($attendance->tardy_minutes);
        $this->assertEquals(30, $attendance->tardy_minutes);
    }

    #[Test]
    public function it_casts_undertime_minutes_to_integer(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'undertime_minutes' => '45',
        ]);

        $this->assertIsInt($attendance->undertime_minutes);
    }

    #[Test]
    public function it_casts_overtime_minutes_to_integer(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'overtime_minutes' => '120',
        ]);

        $this->assertIsInt($attendance->overtime_minutes);
    }

    #[Test]
    public function it_casts_warnings_to_array(): void
    {
        $user = User::factory()->create();

        $warnings = ['extreme_scans' => 'Too many scans'];

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'warnings' => $warnings,
        ]);

        $this->assertIsArray($attendance->warnings);
        $this->assertEquals($warnings, $attendance->warnings);
    }

    #[Test]
    public function it_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $attendance = Attendance::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $attendance->user);
        $this->assertEquals($user->id, $attendance->user->id);
    }

    #[Test]
    public function it_belongs_to_employee_schedule(): void
    {
        $user = User::factory()->create();
        $schedule = EmployeeSchedule::factory()->create(['user_id' => $user->id]);

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
        ]);

        $this->assertInstanceOf(EmployeeSchedule::class, $attendance->employeeSchedule);
        $this->assertEquals($schedule->id, $attendance->employeeSchedule->id);
    }

    #[Test]
    public function it_belongs_to_leave_request(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $user->id]);

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'leave_request_id' => $leaveRequest->id,
        ]);

        $this->assertInstanceOf(LeaveRequest::class, $attendance->leaveRequest);
        $this->assertEquals($leaveRequest->id, $attendance->leaveRequest->id);
    }

    #[Test]
    public function it_belongs_to_bio_in_site(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
        ]);

        $this->assertInstanceOf(Site::class, $attendance->bioInSite);
        $this->assertEquals($site->id, $attendance->bioInSite->id);
    }

    #[Test]
    public function it_belongs_to_bio_out_site(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'bio_out_site_id' => $site->id,
        ]);

        $this->assertInstanceOf(Site::class, $attendance->bioOutSite);
        $this->assertEquals($site->id, $attendance->bioOutSite->id);
    }

    #[Test]
    public function it_belongs_to_overtime_approved_by_user(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'overtime_approved_by' => $approver->id,
        ]);

        $this->assertInstanceOf(User::class, $attendance->overtimeApprovedBy);
        $this->assertEquals($approver->id, $attendance->overtimeApprovedBy->id);
    }

    #[Test]
    public function it_filters_by_status_scope(): void
    {
        $user = User::factory()->create();
        Attendance::factory()->create(['user_id' => $user->id, 'status' => 'on_time']);
        Attendance::factory()->create(['user_id' => $user->id, 'status' => 'tardy']);

        $onTime = Attendance::byStatus('on_time')->get();

        $this->assertCount(1, $onTime);
        $this->assertEquals('on_time', $onTime->first()->status);
    }

    #[Test]
    public function it_filters_by_date_range_scope(): void
    {
        $user = User::factory()->create();

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => '2024-01-10',
        ]);

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => '2024-01-20',
        ]);

        $inRange = Attendance::dateRange('2024-01-01', '2024-01-15')->get();

        $this->assertCount(1, $inRange);
        /** @var Carbon $shiftDate */
        $shiftDate = $inRange->first()->shift_date;
        $this->assertEquals('2024-01-10', $shiftDate->toDateString());
    }

    #[Test]
    public function it_filters_records_with_overtime_scope(): void
    {
        $user = User::factory()->create();

        Attendance::factory()->create([
            'user_id' => $user->id,
            'overtime_minutes' => 60,
        ]);

        Attendance::factory()->create([
            'user_id' => $user->id,
            'overtime_minutes' => 0,
        ]);

        $withOvertime = Attendance::hasOvertime()->get();

        $this->assertCount(1, $withOvertime);
        $this->assertGreaterThan(0, $withOvertime->first()->overtime_minutes);
    }

    #[Test]
    public function it_detects_records_needing_verification_with_failed_bio(): void
    {
        $user = User::factory()->create();

        Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'failed_bio_in',
            'admin_verified' => false,
        ]);

        $needsVerification = Attendance::needsVerification()->get();

        $this->assertCount(1, $needsVerification);
    }

    #[Test]
    public function it_detects_records_needing_verification_with_cross_site_bio(): void
    {
        $user = User::factory()->create();

        Attendance::factory()->create([
            'user_id' => $user->id,
            'is_cross_site_bio' => true,
            'admin_verified' => false,
        ]);

        $needsVerification = Attendance::needsVerification()->get();

        $this->assertCount(1, $needsVerification);
    }

    #[Test]
    public function it_detects_records_needing_verification_with_warnings(): void
    {
        $user = User::factory()->create();

        Attendance::factory()->create([
            'user_id' => $user->id,
            'warnings' => ['extreme_scans' => 'Too many'],
            'admin_verified' => false,
        ]);

        $needsVerification = Attendance::needsVerification()->get();

        $this->assertCount(1, $needsVerification);
    }

    #[Test]
    public function it_excludes_verified_records_from_needs_verification(): void
    {
        $user = User::factory()->create();

        Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'failed_bio_in',
            'admin_verified' => true,
        ]);

        $needsVerification = Attendance::needsVerification()->get();

        $this->assertCount(0, $needsVerification);
    }

    #[Test]
    public function it_filters_records_needing_manual_review(): void
    {
        $user = User::factory()->create();

        Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'needs_manual_review',
            'admin_verified' => false,
        ]);

        $needsReview = Attendance::needsManualReview()->get();

        $this->assertCount(1, $needsReview);
    }

    #[Test]
    public function it_filters_suspicious_patterns(): void
    {
        $user = User::factory()->create();

        Attendance::factory()->create([
            'user_id' => $user->id,
            'warnings' => ['extreme_scans' => 'Suspicious'],
            'admin_verified' => false,
            'shift_date' => '2024-01-15',
        ]);

        $suspicious = Attendance::suspiciousPatterns()->get();

        $this->assertCount(1, $suspicious);
    }

    #[Test]
    public function it_detects_issues_with_tardy_status(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'tardy',
        ]);

        $this->assertTrue($attendance->hasIssues());
    }

    #[Test]
    public function it_detects_issues_with_ncns_status(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'ncns',
        ]);

        $this->assertTrue($attendance->hasIssues());
    }

    #[Test]
    public function it_detects_issues_with_warnings(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'on_time',
            'warnings' => ['extreme_scans' => 'Too many'],
        ]);

        $this->assertTrue($attendance->hasIssues());
    }

    #[Test]
    public function it_does_not_detect_issues_for_on_time(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'on_time',
            'warnings' => null,
        ]);

        $this->assertFalse($attendance->hasIssues());
    }

    #[Test]
    public function it_returns_green_color_for_on_time(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'on_time',
        ]);

        $this->assertEquals('green', $attendance->status_color);
    }

    #[Test]
    public function it_returns_yellow_color_for_tardy(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'tardy',
        ]);

        $this->assertEquals('yellow', $attendance->status_color);
    }

    #[Test]
    public function it_returns_red_color_for_ncns(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'ncns',
        ]);

        $this->assertEquals('red', $attendance->status_color);
    }

    #[Test]
    public function it_returns_blue_color_for_on_leave(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'on_leave',
        ]);

        $this->assertEquals('blue', $attendance->status_color);
    }

    #[Test]
    public function it_returns_orange_color_for_half_day_absence(): void
    {
        $user = User::factory()->create();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'half_day_absence',
        ]);

        $this->assertEquals('orange', $attendance->status_color);
    }
}
