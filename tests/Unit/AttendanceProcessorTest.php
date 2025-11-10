<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\EmployeeSchedule;
use App\Models\Attendance;
use App\Models\AttendanceUpload;
use App\Services\AttendanceProcessor;
use App\Services\AttendanceFileParser;
use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class AttendanceProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected AttendanceProcessor $processor;
    protected AttendanceFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AttendanceFileParser();
        $this->processor = new AttendanceProcessor($this->parser);
    }

    /** @test */
    public function it_determines_time_in_status_correctly()
    {
        $testCases = [
            [-10, 'on_time'],  // 10 minutes early
            [0, 'on_time'],    // exactly on time
            [1, 'tardy'],      // 1 minute late
            [15, 'tardy'],     // 15 minutes late
            [16, 'half_day_absence'], // more than 15 minutes
            [120, 'half_day_absence'],
        ];

        foreach ($testCases as [$tardyMinutes, $expectedStatus]) {
            $status = $this->invokeMethod($this->processor, 'determineTimeInStatus', [$tardyMinutes]);
            $this->assertEquals($expectedStatus, $status, "Tardy minutes {$tardyMinutes} should be {$expectedStatus}");
        }
    }

    /** @test */
    public function it_determines_shift_type_correctly()
    {
        $testCases = [
            ['05:00:00', 'morning'],    // 5 AM
            ['09:00:00', 'morning'],    // 9 AM
            ['12:00:00', 'afternoon'],  // 12 PM
            ['15:00:00', 'afternoon'],  // 3 PM
            ['18:00:00', 'evening'],    // 6 PM
            ['21:00:00', 'evening'],    // 9 PM
            ['22:00:00', 'night'],      // 10 PM
            ['23:00:00', 'night'],      // 11 PM
            ['00:00:00', 'graveyard'],  // midnight
            ['03:00:00', 'graveyard'],  // 3 AM
        ];

        foreach ($testCases as [$timeIn, $expectedType]) {
            $schedule = EmployeeSchedule::factory()->create([
                'scheduled_time_in' => $timeIn,
            ]);

            $shiftType = $this->invokeMethod($this->processor, 'determineShiftType', [$schedule]);
            $this->assertEquals($expectedType, $shiftType, "Time {$timeIn} should be {$expectedType}");
        }
    }

    /** @test */
    public function it_detects_next_day_shift_correctly()
    {
        $testCases = [
            ['09:00:00', '18:00:00', false], // Same day: 9 AM - 6 PM
            ['15:00:00', '00:00:00', true],  // Next day: 3 PM - midnight
            ['22:00:00', '07:00:00', true],  // Next day: 10 PM - 7 AM
            ['00:00:00', '09:00:00', true],  // Graveyard: midnight - 9 AM
            ['01:00:00', '10:00:00', true],  // Graveyard: 1 AM - 10 AM
        ];

        foreach ($testCases as [$timeIn, $timeOut, $expectedNextDay]) {
            $schedule = EmployeeSchedule::factory()->create([
                'scheduled_time_in' => $timeIn,
                'scheduled_time_out' => $timeOut,
            ]);

            $isNextDay = $this->invokeMethod($this->processor, 'isNextDayShift', [$schedule]);
            $this->assertEquals($expectedNextDay, $isNextDay, "Shift {$timeIn}-{$timeOut} next day detection failed");
        }
    }

    /** @test */
    public function it_finds_user_by_normalized_name()
    {
        $user = User::factory()->create([
            'first_name' => 'Angelo',
            'last_name' => 'Nodado',
        ]);

        // Test various name patterns
        $testCases = [
            'nodado',        // Just last name
            'nodado a',      // Last name + initial
            'nodado an',     // Last name + 2 letters
        ];

        foreach ($testCases as $normalizedName) {
            $foundUser = $this->invokeMethod($this->processor, 'findUserByName', [$normalizedName, null]);
            $this->assertNotNull($foundUser, "Should find user for: {$normalizedName}");
            $this->assertEquals($user->id, $foundUser->id);
        }
    }

    /** @test */
    public function it_distinguishes_users_with_same_last_name_using_initials()
    {
        $userA = User::factory()->create([
            'first_name' => 'Angelo',
            'last_name' => 'Nodado',
        ]);

        $userB = User::factory()->create([
            'first_name' => 'Benedict',
            'last_name' => 'Nodado',
        ]);

        $foundUserA = $this->invokeMethod($this->processor, 'findUserByName', ['nodado a', null]);
        $foundUserB = $this->invokeMethod($this->processor, 'findUserByName', ['nodado b', null]);

        $this->assertEquals($userA->id, $foundUserA->id);
        $this->assertEquals($userB->id, $foundUserB->id);
    }

    /** @test */
    public function it_distinguishes_users_with_same_initial_using_two_letters()
    {
        $userJe = User::factory()->create([
            'first_name' => 'Jerome',
            'last_name' => 'Robinios',
        ]);

        $userJo = User::factory()->create([
            'first_name' => 'Joseph',
            'last_name' => 'Robinios',
        ]);

        $foundJe = $this->invokeMethod($this->processor, 'findUserByName', ['robinios je', null]);
        $foundJo = $this->invokeMethod($this->processor, 'findUserByName', ['robinios jo', null]);

        $this->assertEquals($userJe->id, $foundJe->id);
        $this->assertEquals($userJo->id, $foundJo->id);
    }

    /** @test */
    public function it_uses_shift_timing_to_match_users_with_same_last_name()
    {
        // Create two users with same last name but different shifts
        $morningUser = User::factory()->create([
            'first_name' => 'Angelo',
            'last_name' => 'Nodado',
        ]);
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $morningUser->id,
            'is_active' => true,
        ]);

        $nightUser = User::factory()->create([
            'first_name' => 'Benedict',
            'last_name' => 'Nodado',
        ]);
        EmployeeSchedule::factory()->nightShift()->create([
            'user_id' => $nightUser->id,
            'is_active' => true,
        ]);

        // Morning record (6 AM)
        $morningRecords = collect([
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')]
        ]);

        // Night record (10 PM)
        $nightRecords = collect([
            ['datetime' => Carbon::parse('2025-11-05 22:00:00')]
        ]);

        $foundMorning = $this->invokeMethod($this->processor, 'findUserByName', ['nodado', $morningRecords]);
        $foundNight = $this->invokeMethod($this->processor, 'findUserByName', ['nodado', $nightRecords]);

        $this->assertEquals($morningUser->id, $foundMorning->id);
        $this->assertEquals($nightUser->id, $foundNight->id);
    }

    /** @test */
    public function it_groups_records_by_shift_date_for_same_day_shift()
    {
        $user = User::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00', // Same day shift
            'is_active' => true,
        ]);

        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 09:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 18:00:00')],
        ]);

        $grouped = $this->invokeMethod($this->processor, 'groupRecordsByShiftDate', [$records, $user]);

        $this->assertArrayHasKey('2025-11-05', $grouped);
        $this->assertCount(2, $grouped['2025-11-05']);
    }

    /** @test */
    public function it_groups_records_by_shift_date_for_next_day_shift()
    {
        $user = User::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00', // Next day shift
            'is_active' => true,
        ]);

        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 22:00:00')], // Time in on Nov 5
            ['datetime' => Carbon::parse('2025-11-06 07:00:00')], // Time out on Nov 6
        ]);

        $grouped = $this->invokeMethod($this->processor, 'groupRecordsByShiftDate', [$records, $user]);

        // Both records should be grouped to Nov 5 shift
        $this->assertArrayHasKey('2025-11-05', $grouped);
        $this->assertCount(2, $grouped['2025-11-05']);
        $this->assertEquals('22:00:00', $grouped['2025-11-05']->first()['datetime']->format('H:i:s'));
        $this->assertEquals('07:00:00', $grouped['2025-11-05']->last()['datetime']->format('H:i:s'));
    }

    /** @test */
    public function it_groups_records_for_graveyard_shift()
    {
        $user = User::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'scheduled_time_in' => '00:00:00',
            'scheduled_time_out' => '09:00:00', // Graveyard shift
            'is_active' => true,
        ]);

        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 20:00:00')], // Late evening time in
            ['datetime' => Carbon::parse('2025-11-06 08:00:00')], // Morning time out
        ]);

        $grouped = $this->invokeMethod($this->processor, 'groupRecordsByShiftDate', [$records, $user]);

        // Both should be grouped to Nov 5 shift
        $this->assertArrayHasKey('2025-11-05', $grouped);
        $this->assertCount(2, $grouped['2025-11-05']);
    }

    /** @test */
    public function it_validates_file_dates_match_expected_dates()
    {
        $shiftDate = Carbon::parse('2025-11-05');

        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 09:00:00')],
            ['datetime' => Carbon::parse('2025-11-06 07:00:00')],
        ]);

        $validation = $this->invokeMethod($this->processor, 'validateFileDates', [$records, $shiftDate]);

        $this->assertIsArray($validation);
        $this->assertArrayHasKey('warnings', $validation);
        $this->assertArrayHasKey('dates_found', $validation);
        $this->assertEquals(['2025-11-05', '2025-11-06'], $validation['dates_found']);
    }

    /** @test */
    public function it_generates_warning_for_unexpected_dates()
    {
        $shiftDate = Carbon::parse('2025-11-05');

        $records = collect([
            ['datetime' => Carbon::parse('2025-11-03 09:00:00')], // Unexpected date
            ['datetime' => Carbon::parse('2025-11-05 09:00:00')],
        ]);

        $validation = $this->invokeMethod($this->processor, 'validateFileDates', [$records, $shiftDate]);

        $this->assertNotEmpty($validation['warnings']);
        $this->assertStringContainsString('unexpected dates', $validation['warnings'][0]);
    }

    /** @test */
    public function it_processes_on_time_attendance()
    {
        $user = User::factory()->create();
        $site = \App\Models\Site::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'work_days' => ['tuesday'],
            'is_active' => true,
        ]);

        $shiftDate = Carbon::parse('2025-11-05'); // Tuesday
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 08:55:00')], // 5 min early
            ['datetime' => Carbon::parse('2025-11-05 18:00:00')],
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $site->id
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($attendance);
        $this->assertEquals('on_time', $attendance->status);
        $this->assertNull($attendance->tardy_minutes);
    }

    /** @test */
    public function it_processes_tardy_attendance()
    {
        $user = User::factory()->create();
        $site = \App\Models\Site::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'work_days' => ['tuesday'],
            'is_active' => true,
        ]);

        $shiftDate = Carbon::parse('2025-11-05'); // Tuesday
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 09:10:00')], // 10 min late
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $site->id
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertEquals('tardy', $attendance->status);
        $this->assertEquals(10, $attendance->tardy_minutes);
    }

    /** @test */
    public function it_processes_half_day_absence()
    {
        $user = User::factory()->create();
        $site = \App\Models\Site::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'work_days' => ['tuesday'],
            'is_active' => true,
        ]);

        $shiftDate = Carbon::parse('2025-11-05');
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 09:20:00')], // More than 15 min late
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $site->id
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertEquals('half_day_absence', $attendance->status);
        $this->assertEquals(20, $attendance->tardy_minutes);
    }

    /** @test */
    public function it_detects_cross_site_bio()
    {
        $user = User::factory()->create();
        $assignedSite = \App\Models\Site::factory()->create(['name' => 'Site A']);
        $bioSite = \App\Models\Site::factory()->create(['name' => 'Site B']);

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $assignedSite->id,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'work_days' => ['tuesday'],
            'is_active' => true,
        ]);

        $shiftDate = Carbon::parse('2025-11-05');
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 09:00:00')],
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $bioSite->id // Different site
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertTrue($attendance->is_cross_site_bio);
    }

    /**
     * Helper method to invoke protected/private methods for testing.
     */
    protected function invokeMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
