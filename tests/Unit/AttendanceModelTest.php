<?php

namespace Tests\Unit;

use App\Models\Attendance;
use App\Models\User;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class AttendanceModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_has_fillable_attributes()
    {
        $fillable = [
            'user_id',
            'employee_schedule_id',
            'shift_date',
            'scheduled_time_in',
            'scheduled_time_out',
            'actual_time_in',
            'actual_time_out',
            'bio_in_site_id',
            'bio_out_site_id',
            'status',
            'secondary_status',
            'tardy_minutes',
            'undertime_minutes',
            'overtime_minutes',
            'overtime_approved',
            'overtime_approved_at',
            'overtime_approved_by',
            'is_advised',
            'admin_verified',
            'is_cross_site_bio',
            'verification_notes',
            'notes',
        ];

        $attendance = new Attendance();
        $this->assertEquals($fillable, $attendance->getFillable());
    }

    /** @test */
    public function it_casts_attributes_correctly()
    {
        $attendance = Attendance::factory()->create([
            'shift_date' => '2025-11-05',
            'is_advised' => true,
            'admin_verified' => false,
            'is_cross_site_bio' => true,
            'tardy_minutes' => 15,
        ]);

        $this->assertInstanceOf(Carbon::class, $attendance->shift_date);
        $this->assertTrue($attendance->is_advised);
        $this->assertFalse($attendance->admin_verified);
        $this->assertTrue($attendance->is_cross_site_bio);
        $this->assertIsInt($attendance->tardy_minutes);
    }

    /** @test */
    public function it_belongs_to_user()
    {
        $user = User::factory()->create();
        $attendance = Attendance::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $attendance->user);
        $this->assertEquals($user->id, $attendance->user->id);
    }

    /** @test */
    public function it_belongs_to_employee_schedule()
    {
        $schedule = EmployeeSchedule::factory()->create();
        $attendance = Attendance::factory()->create(['employee_schedule_id' => $schedule->id]);

        $this->assertInstanceOf(EmployeeSchedule::class, $attendance->employeeSchedule);
        $this->assertEquals($schedule->id, $attendance->employeeSchedule->id);
    }

    /** @test */
    public function it_belongs_to_bio_in_site()
    {
        $site = Site::factory()->create();
        $attendance = Attendance::factory()->create(['bio_in_site_id' => $site->id]);

        $this->assertInstanceOf(Site::class, $attendance->bioInSite);
        $this->assertEquals($site->id, $attendance->bioInSite->id);
    }

    /** @test */
    public function it_belongs_to_bio_out_site()
    {
        $site = Site::factory()->create();
        $attendance = Attendance::factory()->create(['bio_out_site_id' => $site->id]);

        $this->assertInstanceOf(Site::class, $attendance->bioOutSite);
        $this->assertEquals($site->id, $attendance->bioOutSite->id);
    }

    /** @test */
    public function it_filters_by_status()
    {
        Attendance::factory()->create(['status' => 'on_time']);
        Attendance::factory()->create(['status' => 'tardy']);
        Attendance::factory()->create(['status' => 'ncns']);

        $tardyRecords = Attendance::byStatus('tardy')->get();
        $this->assertCount(1, $tardyRecords);
        $this->assertEquals('tardy', $tardyRecords->first()->status);
    }

    /** @test */
    public function it_filters_by_date_range()
    {
        Attendance::factory()->create(['shift_date' => '2025-11-01']);
        Attendance::factory()->create(['shift_date' => '2025-11-05']);
        Attendance::factory()->create(['shift_date' => '2025-11-10']);
        Attendance::factory()->create(['shift_date' => '2025-11-15']);

        $records = Attendance::dateRange('2025-11-05', '2025-11-10')->get();

        $this->assertCount(2, $records);
        $this->assertTrue($records->contains('shift_date', Carbon::parse('2025-11-05')));
        $this->assertTrue($records->contains('shift_date', Carbon::parse('2025-11-10')));
    }

    /** @test */
    public function it_filters_records_needing_verification()
    {
        // Should be included
        Attendance::factory()->create(['status' => 'failed_bio_in', 'admin_verified' => false]);
        Attendance::factory()->create(['status' => 'failed_bio_out', 'admin_verified' => false]);
        Attendance::factory()->create(['status' => 'ncns', 'admin_verified' => false]);
        Attendance::factory()->create(['is_cross_site_bio' => true, 'admin_verified' => false, 'status' => 'on_time']);

        // Should not be included
        Attendance::factory()->create(['status' => 'on_time', 'admin_verified' => false, 'is_cross_site_bio' => false]);
        Attendance::factory()->create(['status' => 'failed_bio_in', 'admin_verified' => true]);

        $needsVerification = Attendance::needsVerification()->get();

        $this->assertCount(4, $needsVerification);
    }

    /** @test */
    public function it_detects_if_attendance_has_issues()
    {
        $issueStatuses = [
            'tardy',
            'half_day_absence',
            'ncns',
            'undertime',
            'failed_bio_in',
            'failed_bio_out'
        ];

        foreach ($issueStatuses as $status) {
            $attendance = Attendance::factory()->create(['status' => $status]);
            $this->assertTrue($attendance->hasIssues(), "Status {$status} should have issues");
        }

        $noIssueStatuses = ['on_time', 'advised_absence', 'present_no_bio'];
        foreach ($noIssueStatuses as $status) {
            $attendance = Attendance::factory()->create(['status' => $status]);
            $this->assertFalse($attendance->hasIssues(), "Status {$status} should not have issues");
        }
    }

    /** @test */
    public function it_returns_correct_status_badge_color()
    {
        $colorMap = [
            'on_time' => 'green',
            'tardy' => 'yellow',
            'half_day_absence' => 'orange',
            'advised_absence' => 'blue',
            'ncns' => 'red',
            'undertime' => 'orange',
            'failed_bio_in' => 'purple',
            'failed_bio_out' => 'purple',
            'present_no_bio' => 'gray',
        ];

        foreach ($colorMap as $status => $expectedColor) {
            $attendance = Attendance::factory()->create(['status' => $status]);
            $this->assertEquals($expectedColor, $attendance->status_color, "Status {$status} should be {$expectedColor}");
        }
    }

    /** @test */
    public function it_returns_default_gray_color_for_unknown_status()
    {
        $attendance = Attendance::factory()->create(['status' => 'present_no_bio']);
        $this->assertEquals('gray', $attendance->status_color);
    }

    /** @test */
    public function it_handles_null_tardy_and_undertime_minutes()
    {
        $attendance = Attendance::factory()->create([
            'tardy_minutes' => null,
            'undertime_minutes' => null,
        ]);

        $this->assertNull($attendance->tardy_minutes);
        $this->assertNull($attendance->undertime_minutes);
    }

    /** @test */
    public function it_can_be_created_with_all_required_fields()
    {
        $user = User::factory()->create();
        $schedule = EmployeeSchedule::factory()->create(['user_id' => $user->id]);

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => '2025-11-05',
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'status' => 'ncns',
        ]);

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'shift_date' => '2025-11-05',
            'status' => 'ncns',
        ]);
    }
}
