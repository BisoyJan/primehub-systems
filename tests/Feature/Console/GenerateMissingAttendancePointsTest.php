<?php

namespace Tests\Feature\Console;

use Tests\TestCase;
use App\Models\User;
use App\Models\Site;
use App\Models\EmployeeSchedule;
use App\Models\Attendance;
use App\Models\AttendancePoint;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class GenerateMissingAttendancePointsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_missing_points_for_specified_date_range()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        // Create attendance with violation but NO point
        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'tardy_minutes' => 15,
            'admin_verified' => true, // Even if verified, point might be missing
        ]);

        $this->artisan('attendance:generate-points', [
            '--from' => '2025-11-01',
            '--to' => '2025-11-10',
        ])
        ->expectsOutput('Generating Missing Attendance Points')
        ->expectsOutput("Processing attendance records from 2025-11-01 to 2025-11-10")
        ->expectsOutput("Found 1 attendance records with violations")
        ->expectsOutput("✓ Successfully created 1 attendance points")
        ->assertExitCode(0);

        $this->assertDatabaseHas('attendance_points', [
            'user_id' => $user->id,
            'shift_date' => $shiftDate->format('Y-m-d 00:00:00'),
            'point_type' => 'tardy',
            'points' => 0.25,
        ]);
    }

    #[Test]
    public function it_skips_existing_points()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'tardy_minutes' => 15,
            'admin_verified' => true,
        ]);

        // Create existing point
        AttendancePoint::factory()->create([
            'user_id' => $user->id,
            'attendance_id' => $attendance->id,
            'shift_date' => $shiftDate,
            'point_type' => 'tardy',
            'points' => 0.25,
        ]);

        $this->artisan('attendance:generate-points', [
            '--from' => '2025-11-01',
            '--to' => '2025-11-10',
        ])
        ->expectsOutput("Found 1 attendance records with violations")
        ->assertExitCode(0);

        // Should still have only 1 point
        $this->assertEquals(1, AttendancePoint::where('user_id', $user->id)->count());
    }

    #[Test]
    public function it_generates_points_for_all_records_with_flag()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'ncns',
            'admin_verified' => true,
        ]);

        $this->artisan('attendance:generate-points', [
            '--all' => true,
        ])
        ->expectsOutput('Processing ALL attendance records...')
        ->expectsOutput("Found 1 attendance records with violations")
        ->expectsOutput("✓ Successfully created 1 attendance points")
        ->assertExitCode(0);

        $this->assertDatabaseHas('attendance_points', [
            'user_id' => $user->id,
            'shift_date' => $shiftDate->format('Y-m-d 00:00:00'),
            'point_type' => 'whole_day_absence',
        ]);
    }

    #[Test]
    public function it_validates_date_inputs()
    {
        $this->artisan('attendance:generate-points', [
            '--from' => 'invalid-date',
            '--to' => '2025-11-10',
        ])
        ->expectsOutput('Invalid date format. Please use YYYY-MM-DD format.')
        ->assertExitCode(1);

        $this->artisan('attendance:generate-points', [
            '--from' => '2025-11-10',
            '--to' => '2025-11-01',
        ])
        ->expectsOutput('End date must be after start date')
        ->assertExitCode(1);
    }

    protected function createUserWithSchedule(): User
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '07:00:00',
            'scheduled_time_out' => '17:00:00',
            'is_active' => true,
        ]);

        return $user;
    }
}
