<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\AttendanceFileParser;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GraveyardShiftEarlyArrivalTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceProcessor $processor;

    private AttendanceFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AttendanceFileParser;
        $this->processor = new AttendanceProcessor($this->parser);
    }

    /**
     * Helper method to call protected methods using reflection
     */
    private function callProtectedMethod($object, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }

    /**
     * Create biometric record data for testing.
     */
    private function makeBioRecord(string $datetime, string $name = 'Portillo P'): array
    {
        return [
            'no' => '1',
            'dev_no' => '1',
            'user_id' => '38',
            'name' => $name,
            'mode' => 'FP',
            'datetime' => Carbon::parse($datetime),
            'normalized_name' => $this->parser->normalizeName($name),
        ];
    }

    #[Test]
    public function graveyard_shift_groups_late_evening_scan_to_current_day(): void
    {
        // Schedule: 00:30-09:30 graveyard shift, Mon-Fri
        $user = User::factory()->create([
            'first_name' => 'Prinz',
            'last_name' => 'Portillo',
        ]);

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_type' => 'graveyard_shift',
            'scheduled_time_in' => '00:30:00',
            'scheduled_time_out' => '09:30:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'is_active' => true,
        ]);

        // Mon 23:15 (TIME IN for Monday shift), Tue 09:02 (TIME OUT for Monday shift),
        // Tue 23:01 (TIME IN for Tuesday shift), Wed 09:11 (TIME OUT for Tuesday shift)
        $records = collect([
            $this->makeBioRecord('2026-01-19 23:15:15'),  // Mon evening
            $this->makeBioRecord('2026-01-20 09:02:24'),  // Tue morning
            $this->makeBioRecord('2026-01-20 23:01:07'),  // Tue evening
            $this->makeBioRecord('2026-01-21 09:11:24'),  // Wed morning
        ]);

        $groups = $this->callProtectedMethod($this->processor, 'groupRecordsByShiftDate', [$records, $user]);

        // Monday shift (Jan 19) should have: Mon 23:15 + Tue 09:02
        $this->assertArrayHasKey('2026-01-19', $groups);
        $this->assertCount(2, $groups['2026-01-19']);

        $mondayTimes = $groups['2026-01-19']->pluck('datetime')->map(fn ($dt) => $dt->format('Y-m-d H:i'));
        $this->assertTrue($mondayTimes->contains('2026-01-19 23:15'));
        $this->assertTrue($mondayTimes->contains('2026-01-20 09:02'));

        // Tuesday shift (Jan 20) should have: Tue 23:01 + Wed 09:11
        $this->assertArrayHasKey('2026-01-20', $groups);
        $this->assertCount(2, $groups['2026-01-20']);

        $tuesdayTimes = $groups['2026-01-20']->pluck('datetime')->map(fn ($dt) => $dt->format('Y-m-d H:i'));
        $this->assertTrue($tuesdayTimes->contains('2026-01-20 23:01'));
        $this->assertTrue($tuesdayTimes->contains('2026-01-21 09:11'));
    }

    #[Test]
    public function graveyard_shift_groups_on_time_arrival_correctly(): void
    {
        // Schedule: 00:30-09:30 graveyard shift, Mon-Fri
        $user = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_type' => 'graveyard_shift',
            'scheduled_time_in' => '00:30:00',
            'scheduled_time_out' => '09:30:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'is_active' => true,
        ]);

        // Employee arrives on-time (after midnight), not early
        $records = collect([
            $this->makeBioRecord('2026-01-20 00:35:00', 'Doe J'),  // Tue early morning
            $this->makeBioRecord('2026-01-20 09:25:00', 'Doe J'),  // Tue morning
        ]);

        $groups = $this->callProtectedMethod($this->processor, 'groupRecordsByShiftDate', [$records, $user]);

        // Both records should be grouped to Monday (Jan 19) since Tue morning -> prev day Monday
        $this->assertArrayHasKey('2026-01-19', $groups);
        $this->assertCount(2, $groups['2026-01-19']);
    }

    #[Test]
    public function process_attendance_detects_early_arrival_time_in_for_graveyard(): void
    {
        // Schedule: 00:30-09:30 graveyard shift, Mon-Fri
        $user = User::factory()->create([
            'first_name' => 'Prinz',
            'last_name' => 'Portillo',
        ]);

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_type' => 'graveyard_shift',
            'scheduled_time_in' => '00:30:00',
            'scheduled_time_out' => '09:30:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'is_active' => true,
        ]);

        // Records for Monday shift: early arrival at 23:15, time out at 09:02
        $records = collect([
            $this->makeBioRecord('2026-01-19 23:15:15'),  // Mon evening - early TIME IN
            $this->makeBioRecord('2026-01-20 09:02:24'),  // Tue morning - TIME OUT
        ]);

        $shiftDate = Carbon::parse('2026-01-19');

        $result = $this->callProtectedMethod(
            $this->processor,
            'processAttendance',
            [$user, $schedule, $records, $shiftDate, null]
        );

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', '2026-01-19')
            ->first();

        $this->assertNotNull($attendance, 'Attendance record should exist');
        $this->assertNotNull($attendance->actual_time_in, 'TIME IN should be detected');
        $this->assertNotNull($attendance->actual_time_out, 'TIME OUT should be detected');
        $this->assertEquals('2026-01-19 23:15:15', $attendance->actual_time_in->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-01-20 09:02:24', $attendance->actual_time_out->format('Y-m-d H:i:s'));
        $this->assertNotEquals('failed_bio_in', $attendance->status, 'Status should NOT be failed_bio_in');
    }

    #[Test]
    public function process_attendance_detects_on_time_arrival_for_graveyard(): void
    {
        // Schedule: 00:30-09:30 graveyard shift, Mon-Fri
        $user = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_type' => 'graveyard_shift',
            'scheduled_time_in' => '00:30:00',
            'scheduled_time_out' => '09:30:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'is_active' => true,
        ]);

        // Records for Monday shift: on-time arrival at 00:35, time out at 09:25
        $records = collect([
            $this->makeBioRecord('2026-01-20 00:35:00', 'Smith J'),
            $this->makeBioRecord('2026-01-20 09:25:00', 'Smith J'),
        ]);

        $shiftDate = Carbon::parse('2026-01-19');

        $result = $this->callProtectedMethod(
            $this->processor,
            'processAttendance',
            [$user, $schedule, $records, $shiftDate, null]
        );

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', '2026-01-19')
            ->first();

        $this->assertNotNull($attendance, 'Attendance record should exist');
        $this->assertNotNull($attendance->actual_time_in, 'TIME IN should be detected');
        $this->assertNotNull($attendance->actual_time_out, 'TIME OUT should be detected');
        $this->assertEquals('2026-01-20 00:35:00', $attendance->actual_time_in->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-01-20 09:25:00', $attendance->actual_time_out->format('Y-m-d H:i:s'));
        $this->assertNotEquals('failed_bio_in', $attendance->status);
    }

    #[Test]
    public function graveyard_shift_friday_early_arrival_groups_correctly(): void
    {
        // Schedule: 00:30-09:30 graveyard shift, Mon-Fri
        $user = User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_type' => 'graveyard_shift',
            'scheduled_time_in' => '00:30:00',
            'scheduled_time_out' => '09:30:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'is_active' => true,
        ]);

        // Friday shift: Fri 23:52 (TIME IN) + Sat 09:01 (TIME OUT)
        $records = collect([
            $this->makeBioRecord('2026-01-23 23:52:19', 'User T'),  // Fri evening
            $this->makeBioRecord('2026-01-24 09:01:40', 'User T'),  // Sat morning
        ]);

        $groups = $this->callProtectedMethod($this->processor, 'groupRecordsByShiftDate', [$records, $user]);

        // Friday shift (Jan 23) should have both records
        $this->assertArrayHasKey('2026-01-23', $groups);
        $this->assertCount(2, $groups['2026-01-23']);

        $fridayTimes = $groups['2026-01-23']->pluck('datetime')->map(fn ($dt) => $dt->format('Y-m-d H:i'));
        $this->assertTrue($fridayTimes->contains('2026-01-23 23:52'));
        $this->assertTrue($fridayTimes->contains('2026-01-24 09:01'));
    }

    #[Test]
    public function graveyard_midnight_shift_on_time_arrival_still_works(): void
    {
        // Schedule: 00:00-09:00 graveyard shift (exact midnight), Mon-Fri
        $user = User::factory()->create([
            'first_name' => 'Midnight',
            'last_name' => 'Worker',
        ]);

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_type' => 'graveyard_shift',
            'scheduled_time_in' => '00:00:00',
            'scheduled_time_out' => '09:00:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'is_active' => true,
        ]);

        // On-time arrival exactly at midnight
        $records = collect([
            $this->makeBioRecord('2026-01-20 00:02:00', 'Worker M'),
            $this->makeBioRecord('2026-01-20 08:55:00', 'Worker M'),
        ]);

        $shiftDate = Carbon::parse('2026-01-19');

        $result = $this->callProtectedMethod(
            $this->processor,
            'processAttendance',
            [$user, $schedule, $records, $shiftDate, null]
        );

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', '2026-01-19')
            ->first();

        $this->assertNotNull($attendance);
        $this->assertNotNull($attendance->actual_time_in, 'TIME IN should be detected for on-time midnight arrival');
        $this->assertNotNull($attendance->actual_time_out, 'TIME OUT should be detected');
        $this->assertEquals('2026-01-20 00:02:00', $attendance->actual_time_in->format('Y-m-d H:i:s'));
    }
}
