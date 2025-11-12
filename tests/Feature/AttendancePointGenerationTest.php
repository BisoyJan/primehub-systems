<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Site;
use App\Models\EmployeeSchedule;
use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\AttendanceUpload;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class AttendancePointGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected AttendanceProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = app(AttendanceProcessor::class);
        Storage::fake('local');
    }

    /** @test */
    public function it_automatically_generates_points_for_ncns_violations()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        // Create NCNS attendance
        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'ncns',
            'is_advised' => false,
        ]);

        // Simulate upload processing
        $upload = AttendanceUpload::factory()->create([
            'shift_date' => $shiftDate,
            'status' => 'completed',
        ]);

        // Manually trigger point generation (normally called in processUpload)
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $pointsCreated = $method->invoke($this->processor, $shiftDate);

        // Assert point was created
        $this->assertEquals(1, $pointsCreated);

        $point = AttendancePoint::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($point);
        $this->assertEquals('whole_day_absence', $point->point_type);
        $this->assertEquals(1.00, $point->points);
        $this->assertEquals('ncns', $point->status);
        $this->assertFalse($point->is_advised);
        $this->assertFalse($point->eligible_for_gbro); // NCNS not eligible for GBRO
        // expires_at is cast to 'date' which becomes Carbon
        $expectedDate = $shiftDate->copy()->addYear()->toDateString();
        $actualDate = $point->expires_at instanceof \Carbon\Carbon ? $point->expires_at->toDateString() : $point->expires_at;
        $this->assertEquals($expectedDate, $actualDate); // 1 year expiration
    }

    /** @test */
    public function it_generates_points_for_half_day_absence()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'half_day_absence',
            'tardy_minutes' => 45,
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $pointsCreated = $method->invoke($this->processor, $shiftDate);

        $this->assertEquals(1, $pointsCreated);

        $point = AttendancePoint::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($point);
        $this->assertEquals('half_day_absence', $point->point_type);
        $this->assertEquals(0.50, $point->points);
        $this->assertTrue($point->eligible_for_gbro); // Standard violation eligible for GBRO
        $this->assertEquals($shiftDate->copy()->addMonths(6)->toDateString(), ($point->expires_at instanceof \Carbon\Carbon ? $point->expires_at->toDateString() : $point->expires_at)); // 6 months expiration
    }

    /** @test */
    public function it_generates_points_for_tardy()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'tardy_minutes' => 12,
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $pointsCreated = $method->invoke($this->processor, $shiftDate);

        $this->assertEquals(1, $pointsCreated);

        $point = AttendancePoint::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($point);
        $this->assertEquals('tardy', $point->point_type);
        $this->assertEquals(0.25, $point->points);
        $this->assertEquals(12, $point->tardy_minutes);
        $this->assertTrue($point->eligible_for_gbro);
    }

    /** @test */
    public function it_generates_points_for_undertime()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'undertime',
            'undertime_minutes' => 90,
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $pointsCreated = $method->invoke($this->processor, $shiftDate);

        $this->assertEquals(1, $pointsCreated);

        $point = AttendancePoint::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($point);
        $this->assertEquals('undertime', $point->point_type);
        $this->assertEquals(0.25, $point->points);
        $this->assertEquals(90, $point->undertime_minutes);
        $this->assertTrue($point->eligible_for_gbro);
    }

    /** @test */
    public function it_does_not_create_duplicate_points_for_same_violation()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'tardy_minutes' => 10,
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);

        // Generate points first time
        $pointsCreated1 = $method->invoke($this->processor, $shiftDate);
        $this->assertEquals(1, $pointsCreated1);

        // Try to generate again
        $pointsCreated2 = $method->invoke($this->processor, $shiftDate);
        $this->assertEquals(0, $pointsCreated2); // No new points

        // Verify only one point exists
        $pointCount = AttendancePoint::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->count();

        $this->assertEquals(1, $pointCount);
    }

    /** @test */
    public function it_does_not_generate_points_for_on_time_attendance()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'on_time',
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $pointsCreated = $method->invoke($this->processor, $shiftDate);

        $this->assertEquals(0, $pointsCreated);

        $pointCount = AttendancePoint::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->count();

        $this->assertEquals(0, $pointCount);
    }

    /** @test */
    public function it_generates_violation_details_text()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
            'tardy_minutes' => 12,
            'scheduled_time_in' => '07:00:00',
            'actual_time_in' => $shiftDate->copy()->setTime(7, 12, 0),
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $method->invoke($this->processor, $shiftDate);

        $point = AttendancePoint::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($point->violation_details);
        $this->assertStringContainsString('Tardy', $point->violation_details);
        $this->assertStringContainsString('12 minutes', $point->violation_details);
    }

    /** @test */
    public function it_generates_points_for_multiple_users_on_same_date()
    {
        $user1 = $this->createUserWithSchedule();
        $user2 = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user1->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
        ]);

        Attendance::factory()->create([
            'user_id' => $user2->id,
            'shift_date' => $shiftDate,
            'status' => 'undertime',
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $pointsCreated = $method->invoke($this->processor, $shiftDate);

        $this->assertEquals(2, $pointsCreated);

        $point1 = AttendancePoint::where('user_id', $user1->id)->first();
        $point2 = AttendancePoint::where('user_id', $user2->id)->first();

        $this->assertNotNull($point1);
        $this->assertNotNull($point2);
        $this->assertEquals('tardy', $point1->point_type);
        $this->assertEquals('undertime', $point2->point_type);
    }

    /** @test */
    public function it_sets_correct_expiration_type_for_ncns()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'ncns',
            'is_advised' => false,
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $method->invoke($this->processor, $shiftDate);

        $point = AttendancePoint::where('user_id', $user->id)->first();

        $this->assertEquals('none', $point->expiration_type); // NCNS uses 'none' initially (not SRO eligible for GBRO)
        $this->assertEquals($shiftDate->copy()->addYear()->toDateString(), ($point->expires_at instanceof \Carbon\Carbon ? $point->expires_at->toDateString() : $point->expires_at));
    }

    /** @test */
    public function it_sets_correct_expiration_type_for_standard_violations()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $method->invoke($this->processor, $shiftDate);

        $point = AttendancePoint::where('user_id', $user->id)->first();

        $this->assertEquals('sro', $point->expiration_type); // Standard violations use SRO
        $this->assertEquals($shiftDate->copy()->addMonths(6)->toDateString(), ($point->expires_at instanceof \Carbon\Carbon ? $point->expires_at->toDateString() : $point->expires_at));
    }

    /** @test */
    public function it_links_point_to_attendance_record()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'tardy',
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $method->invoke($this->processor, $shiftDate);

        $point = AttendancePoint::where('user_id', $user->id)->first();

        $this->assertEquals($attendance->id, $point->attendance_id);
        $this->assertInstanceOf(Attendance::class, $point->attendance);
    }

    /** @test */
    public function it_handles_advised_absence_correctly()
    {
        $user = $this->createUserWithSchedule();
        $shiftDate = Carbon::parse('2025-11-05');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'ncns',
            'is_advised' => true, // Employee notified admin - advised absence
        ]);

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateAttendancePoints');
        $method->setAccessible(true);
        $method->invoke($this->processor, $shiftDate);

        $point = AttendancePoint::where('user_id', $user->id)->first();

        $this->assertEquals('whole_day_absence', $point->point_type);
        $this->assertEquals(1.00, $point->points);
        $this->assertTrue($point->is_advised); // Employee DID notify
        $this->assertTrue($point->eligible_for_gbro); // Advised absence IS eligible for GBRO
        // Advised absence gets 6 months expiration (not 1 year like NCNS)
        $expectedDate = $shiftDate->copy()->addMonths(6)->toDateString();
        $actualDate = $point->expires_at instanceof \Carbon\Carbon ? $point->expires_at->toDateString() : $point->expires_at;
        $this->assertEquals($expectedDate, $actualDate);
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

