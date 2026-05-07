<?php

namespace Tests\Unit;

use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use App\Services\AttendanceFileParser;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected AttendanceProcessor $processor;

    protected AttendanceFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AttendanceFileParser;
        $this->processor = app(AttendanceProcessor::class);
    }

    #[Test]
    public function it_determines_time_in_status_correctly()
    {
        // Rule: grace period is a forgiveness window.
        //   tardy_minutes <= grace_period_minutes  ⇒ on_time
        //   tardy_minutes >  grace_period_minutes  ⇒ tardy
        // half_day_absence is no longer auto-applied based on tardiness.

        $testCases = [
            // Grace period 15 minutes
            [-10, 15, 'on_time'],  // 10 minutes early
            [0, 15, 'on_time'],    // exactly on time
            [1, 15, 'on_time'],    // within grace
            [10, 15, 'on_time'],   // within grace
            [14, 15, 'on_time'],   // within grace
            [15, 15, 'on_time'],   // exactly at grace
            [16, 15, 'tardy'],     // beyond grace
            [120, 15, 'tardy'],    // way beyond grace

            // Grace period 10 minutes
            [5, 10, 'on_time'],
            [10, 10, 'on_time'],
            [15, 10, 'tardy'],
            [20, 10, 'tardy'],

            // Grace period 20 minutes
            [14, 20, 'on_time'],
            [15, 20, 'on_time'],
            [18, 20, 'on_time'],
            [20, 20, 'on_time'],
            [21, 20, 'tardy'],

            // Grace period 30 minutes
            [15, 30, 'on_time'],
            [25, 30, 'on_time'],
            [30, 30, 'on_time'],
            [31, 30, 'tardy'],

            // Grace period 0 (system-wide default) — every minute late is tardy
            [0, 0, 'on_time'],
            [1, 0, 'tardy'],
            [60, 0, 'tardy'],
        ];

        foreach ($testCases as [$tardyMinutes, $gracePeriod, $expectedStatus]) {
            $status = $this->invokeMethod($this->processor, 'determineTimeInStatus', [$tardyMinutes, $gracePeriod]);
            $this->assertEquals($expectedStatus, $status, "Tardy minutes {$tardyMinutes} with grace period {$gracePeriod} should be {$expectedStatus}");
        }
    }

    #[Test]
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

    #[Test]
    public function it_detects_next_day_shift_correctly()
    {
        $testCases = [
            ['09:00:00', '18:00:00', false], // Same day: 9 AM - 6 PM
            ['08:00:00', '17:00:00', false], // Same day: 8 AM - 5 PM (morning shift)
            ['14:00:00', '23:00:00', false], // Same day: 2 PM - 11 PM (afternoon shift)
            ['00:00:00', '09:00:00', false], // Same day: midnight - 9 AM (GRAVEYARD SHIFT - same calendar day)
            ['01:00:00', '10:00:00', false], // Same day: 1 AM - 10 AM (GRAVEYARD SHIFT - same calendar day)
            ['15:00:00', '00:00:00', true],  // Next day: 3 PM - midnight
            ['22:00:00', '07:00:00', true],  // Next day: 10 PM - 7 AM (night shift spans calendar days)
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_matches_single_initial_to_correct_sibling_when_other_uses_two_letters()
    {
        // Scenario: Siblings with same last name and first initial
        // Biometric uses "Robinios Jo" for Joannah (2-letter prefix) and "Robinios J" for Jessica (1-letter)
        // When we see "Robinios J", we should match Jessica, not Joannah

        $userJoannah = User::factory()->create([
            'first_name' => 'Joannah Salve',
            'last_name' => 'Robinios',
        ]);

        $userJessica = User::factory()->create([
            'first_name' => 'Jessica',
            'last_name' => 'Robinios',
        ]);

        // Two-letter pattern should match Joannah specifically
        $foundJo = $this->invokeMethod($this->processor, 'findUserByName', ['robinios jo', null]);
        $this->assertEquals($userJoannah->id, $foundJo->id, 'robinios jo should match Joannah');

        // Single-initial pattern should match Jessica (because Joannah has her own distinct 2-letter pattern)
        $foundJ = $this->invokeMethod($this->processor, 'findUserByName', ['robinios j', null]);
        $this->assertEquals($userJessica->id, $foundJ->id, 'robinios j should match Jessica, not Joannah');
    }

    #[Test]
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
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')],
        ]);

        // Night record (10 PM)
        $nightRecords = collect([
            ['datetime' => Carbon::parse('2025-11-05 22:00:00')],
        ]);

        $foundMorning = $this->invokeMethod($this->processor, 'findUserByName', ['nodado', $morningRecords]);
        $foundNight = $this->invokeMethod($this->processor, 'findUserByName', ['nodado', $nightRecords]);

        $this->assertEquals($morningUser->id, $foundMorning->id);
        $this->assertEquals($nightUser->id, $foundNight->id);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_groups_records_for_graveyard_shift()
    {
        $user = User::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'scheduled_time_in' => '00:00:00',
            'scheduled_time_out' => '09:00:00', // Graveyard shift (SAME DAY: midnight to 9 AM)
            'is_active' => true,
        ]);

        // Test case 1: Same day graveyard shift - both records on Nov 5
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 00:02:00')], // Time in at 00:02 on Nov 5
            ['datetime' => Carbon::parse('2025-11-05 09:03:00')], // Time out at 09:03 on Nov 5
        ]);

        $grouped = $this->invokeMethod($this->processor, 'groupRecordsByShiftDate', [$records, $user]);

        // Both should be grouped to Nov 4 shift (graveyard assigns to previous work day)
        $this->assertArrayHasKey('2025-11-04', $grouped);
        $this->assertCount(2, $grouped['2025-11-04']);
        $this->assertEquals('00:02:00', $grouped['2025-11-04']->first()['datetime']->format('H:i:s'));
        $this->assertEquals('09:03:00', $grouped['2025-11-04']->last()['datetime']->format('H:i:s'));
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_processes_on_time_attendance()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
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
            $site->id,
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($attendance);
        $this->assertEquals('on_time', $attendance->status);
        $this->assertNull($attendance->tardy_minutes);
    }

    #[Test]
    public function it_processes_tardy_attendance()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'grace_period_minutes' => 20,
            'work_days' => ['tuesday'],
            'is_active' => true,
        ]);

        $shiftDate = Carbon::parse('2025-11-05'); // Tuesday
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 09:25:00')], // 25 min late (beyond 20-min grace)
            ['datetime' => Carbon::parse('2025-11-05 18:00:00')], // On time out
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $site->id,
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertEquals('tardy', $attendance->status);
        $this->assertEquals(25, $attendance->tardy_minutes);
    }

    #[Test]
    public function it_processes_half_day_absence()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'work_days' => ['tuesday'],
            'is_active' => true,
        ]);

        $shiftDate = Carbon::parse('2025-11-05');
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 09:20:00')], // 20 min late
        ]);

        // Grace period is a forgiveness window: tardy_minutes <= grace ⇒ on_time,
        // beyond grace ⇒ tardy. half_day_absence is never auto-applied based on
        // tardiness any more (admins promote manually). With grace=15 and
        // tardy=20, status is `tardy`.
        $schedule->update(['grace_period_minutes' => 15]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $site->id,
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertEquals('tardy', $attendance->status);
        $this->assertEquals(20, $attendance->tardy_minutes);
    }

    #[Test]
    public function it_detects_cross_site_bio()
    {
        $user = User::factory()->create();
        $assignedSite = Site::factory()->create(['name' => 'Site A']);
        $bioSite = Site::factory()->create(['name' => 'Site B']);

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
            $bioSite->id, // Different site
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertTrue($attendance->is_cross_site_bio);
    }

    #[Test]
    public function it_handles_missing_time_out_record()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
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
            ['datetime' => Carbon::parse('2025-11-05 09:00:00')], // Only TIME IN on time, no TIME OUT
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $site->id,
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($attendance);
        // Status is failed_bio_out when no time out record exists (new behavior)
        $this->assertEquals('failed_bio_out', $attendance->status);
        $this->assertNotNull($attendance->actual_time_in);
        $this->assertEquals('09:00:00', $attendance->actual_time_in->format('H:i:s'));
        // time out should be NULL when no time out record exists
        $this->assertNull($attendance->actual_time_out);
    }

    #[Test]
    public function it_handles_missing_time_in_record()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
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
            ['datetime' => Carbon::parse('2025-11-05 18:07:00')], // Only TIME OUT, no TIME IN
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $site->id,
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($attendance);
        // Status is failed_bio_in when no time in was recorded (new behavior)
        $this->assertEquals('failed_bio_in', $attendance->status);
        // time in should be NULL when no time in record exists
        $this->assertNull($attendance->actual_time_in);
        $this->assertNotNull($attendance->actual_time_out);
        $this->assertEquals('18:07:00', $attendance->actual_time_out->format('H:i:s'));
    }

    #[Test]
    public function it_handles_no_biometric_records()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'work_days' => ['tuesday'],
            'is_active' => true,
        ]);

        $shiftDate = Carbon::parse('2025-11-05'); // Tuesday
        $records = collect([]); // No records at all

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $site->id,
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($attendance);
        $this->assertEquals('ncns', $attendance->status); // No Call No Show
        $this->assertNull($attendance->actual_time_in);
        $this->assertNull($attendance->actual_time_out);
    }

    #[Test]
    public function it_resets_old_values_when_reprocessing()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'work_days' => ['tuesday'],
            'is_active' => true,
        ]);

        $shiftDate = Carbon::parse('2025-11-05');

        // First processing: Create attendance with both time in and time out
        $firstRecords = collect([
            ['datetime' => Carbon::parse('2025-11-05 09:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 18:00:00')],
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $firstRecords,
            $shiftDate,
            $site->id,
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($attendance->actual_time_in);
        $this->assertNotNull($attendance->actual_time_out);
        $this->assertEquals('on_time', $attendance->status);

        // Second processing: Reprocess with only TIME IN (missing TIME OUT)
        $secondRecords = collect([
            ['datetime' => Carbon::parse('2025-11-05 09:00:00')], // Only TIME IN on time
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $secondRecords,
            $shiftDate,
            $site->id,
        ]);

        $attendance->refresh();

        // After reprocessing with only time_in, status should be failed_bio_out
        $this->assertNotNull($attendance->actual_time_in);
        $this->assertEquals('09:00:00', $attendance->actual_time_in->format('H:i:s'));
        $this->assertNull($attendance->actual_time_out); // Should be NULL, not old value
        $this->assertEquals('failed_bio_out', $attendance->status);
    }

    #[Test]
    public function it_handles_graveyard_shift_with_only_time_in()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '00:00:00',  // Graveyard shift
            'scheduled_time_out' => '09:00:00',
            'work_days' => ['tuesday'],
            'is_active' => true,
        ]);

        $shiftDate = Carbon::parse('2025-11-05'); // Tuesday
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 20:00:00')], // Only TIME IN (evening before) - 20 hours late
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $site->id,
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($attendance);
        // Very late scan (20 hours) triggers extreme pattern detection
        // and is flagged for manual review instead of half_day_absence
        $this->assertEquals('needs_manual_review', $attendance->status);
        $this->assertNotNull($attendance->actual_time_in);
        $this->assertNull($attendance->actual_time_out);
        $this->assertNotEmpty($attendance->warnings); // Should have warnings
    }

    #[Test]
    public function it_handles_graveyard_shift_with_only_time_out()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '00:00:00',  // Graveyard shift
            'scheduled_time_out' => '09:00:00',
            'work_days' => ['tuesday'],
            'is_active' => true,
        ]);

        $shiftDate = Carbon::parse('2025-11-05'); // Tuesday
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-06 08:00:00')], // Only TIME OUT (morning, next calendar day)
        ]);

        $this->invokeMethod($this->processor, 'processAttendance', [
            $user,
            $schedule,
            $records,
            $shiftDate,
            $site->id,
        ]);

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        $this->assertNotNull($attendance);
        // No time in means status is failed_bio_in (new behavior)
        $this->assertEquals('failed_bio_in', $attendance->status);
        $this->assertNull($attendance->actual_time_in);
        $this->assertNotNull($attendance->actual_time_out);
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
