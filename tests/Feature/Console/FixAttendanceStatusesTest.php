<?php

namespace Tests\Feature\Console;

use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixAttendanceStatusesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a user with a schedule and return both.
     * The schedule is needed to properly link attendances.
     */
    protected function createUserWithSchedule(): array
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00',
            'is_active' => true,
        ]);

        return ['user' => $user, 'schedule' => $schedule];
    }

    #[Test]
    public function it_fixes_ncns_status_for_missing_time_in_and_out(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $date = Carbon::today()->format('Y-m-d');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => null,
            'actual_time_out' => null,
            'status' => 'on_time', // Wrong status
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->expectsOutput("Fixing attendance statuses for {$date}...")
            ->assertExitCode(0);

        $attendance->refresh();
        $this->assertEquals('ncns', $attendance->status);
        $this->assertNull($attendance->secondary_status);
    }

    #[Test]
    public function it_fixes_failed_bio_in_status(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $date = Carbon::today()->format('Y-m-d');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => null,
            'actual_time_out' => Carbon::parse($date . ' 17:00:00'),
            'status' => 'on_time', // Wrong status
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->assertExitCode(0);

        $attendance->refresh();
        $this->assertEquals('failed_bio_in', $attendance->status);
        $this->assertNull($attendance->secondary_status);
    }

    #[Test]
    public function it_fixes_failed_bio_out_for_on_time_clock_in(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $date = Carbon::today()->format('Y-m-d');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => Carbon::parse($date . ' 08:00:00'), // On time
            'actual_time_out' => null,
            'status' => 'on_time', // Should change to failed_bio_out
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->assertExitCode(0);

        $attendance->refresh();
        $this->assertEquals('failed_bio_out', $attendance->status);
        $this->assertNull($attendance->secondary_status);
        $this->assertNull($attendance->tardy_minutes);
    }

    #[Test]
    public function it_fixes_tardy_with_failed_bio_out_secondary_status(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $date = Carbon::today()->format('Y-m-d');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => Carbon::parse($date . ' 08:10:00'), // 10 minutes late
            'actual_time_out' => null,
            'status' => 'on_time', // Wrong status
            'tardy_minutes' => null,
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->assertExitCode(0);

        $attendance->refresh();
        $this->assertEquals('tardy', $attendance->status);
        $this->assertEquals('failed_bio_out', $attendance->secondary_status);
        $this->assertEquals(10, $attendance->tardy_minutes);
    }

    #[Test]
    public function it_fixes_half_day_absence_with_failed_bio_out(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $date = Carbon::today()->format('Y-m-d');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => Carbon::parse($date . ' 08:30:00'), // 30 minutes late (> 15 mins)
            'actual_time_out' => null,
            'status' => 'on_time', // Wrong status
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->assertExitCode(0);

        $attendance->refresh();
        $this->assertEquals('half_day_absence', $attendance->status);
        $this->assertEquals('failed_bio_out', $attendance->secondary_status);
        $this->assertEquals(30, $attendance->tardy_minutes);
    }

    #[Test]
    public function it_recalculates_on_time_status_with_both_times(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $date = Carbon::today()->format('Y-m-d');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => Carbon::parse($date . ' 08:00:00'),
            'actual_time_out' => Carbon::parse($date . ' 17:00:00'),
            'status' => 'tardy', // Wrong status
            'tardy_minutes' => 10, // Should be cleared
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->assertExitCode(0);

        $attendance->refresh();
        $this->assertEquals('on_time', $attendance->status);
        $this->assertNull($attendance->secondary_status);
        $this->assertNull($attendance->tardy_minutes);
    }

    #[Test]
    public function it_recalculates_tardy_status_with_both_times(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $date = Carbon::today()->format('Y-m-d');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => Carbon::parse($date . ' 08:10:00'), // 10 minutes late
            'actual_time_out' => Carbon::parse($date . ' 17:00:00'),
            'status' => 'on_time', // Wrong status
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->assertExitCode(0);

        $attendance->refresh();
        $this->assertEquals('tardy', $attendance->status);
        $this->assertNull($attendance->secondary_status);
        $this->assertEquals(10, $attendance->tardy_minutes);
    }

    #[Test]
    public function it_processes_date_range_with_from_and_to_options(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $from = Carbon::today()->subDays(7)->format('Y-m-d');
        $to = Carbon::today()->format('Y-m-d');

        // Create multiple attendances in range with distinct dates
        $dates = [
            Carbon::today()->subDays(1)->format('Y-m-d'),
            Carbon::today()->subDays(2)->format('Y-m-d'),
            Carbon::today()->subDays(3)->format('Y-m-d'),
            Carbon::today()->subDays(4)->format('Y-m-d'),
            Carbon::today()->subDays(5)->format('Y-m-d'),
        ];

        foreach ($dates as $shiftDate) {
            Attendance::factory()->create([
                'user_id' => $user->id,
                'employee_schedule_id' => $schedule->id,
                'shift_date' => $shiftDate,
                'actual_time_in' => null,
                'actual_time_out' => null,
                'status' => 'on_time', // Wrong status
            ]);
        }

        $this->artisan('attendance:fix-statuses', [
            '--from' => $from,
            '--to' => $to,
        ])
        ->expectsOutput("Fixing attendance statuses from {$from} to {$to}...")
        ->assertExitCode(0);

        // All should be fixed to ncns
        $this->assertEquals(5, Attendance::where('status', 'ncns')->count());
    }

    #[Test]
    public function it_uses_today_as_default_date(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $today = Carbon::today()->format('Y-m-d');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $today,
            'actual_time_in' => null,
            'actual_time_out' => null,
            'status' => 'on_time',
        ]);

        $this->artisan('attendance:fix-statuses')
            ->expectsOutput("Fixing attendance statuses for {$today}...")
            ->assertExitCode(0);
    }

    #[Test]
    public function it_skips_attendances_without_employee_schedule(): void
    {
        $user = User::factory()->create(); // No schedule
        $date = Carbon::today()->format('Y-m-d');

        // Create attendance with null employee_schedule_id
        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => null,
            'shift_date' => $date,
            'actual_time_in' => null,
            'actual_time_out' => null,
            'status' => 'on_time',
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->assertExitCode(0);

        // Status should remain unchanged
        $attendance->refresh();
        $this->assertEquals('on_time', $attendance->status);
    }

    #[Test]
    public function it_displays_update_messages_for_changed_statuses(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $date = Carbon::today()->format('Y-m-d');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => null,
            'actual_time_out' => null,
            'status' => 'on_time',
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->expectsOutputToContain('Updated')
            ->assertExitCode(0);

        // Also verify the update actually happened
        $attendance->refresh();
        $this->assertEquals('ncns', $attendance->status);
    }

    #[Test]
    public function it_displays_total_updated_count(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $date = Carbon::today()->format('Y-m-d');

        Attendance::factory()->count(3)->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => null,
            'actual_time_out' => null,
            'status' => 'on_time',
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->expectsOutput('Total updated: 3')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_does_not_update_already_correct_statuses(): void
    {
        ['user' => $user, 'schedule' => $schedule] = $this->createUserWithSchedule();
        $date = Carbon::today()->format('Y-m-d');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => null,
            'actual_time_out' => null,
            'status' => 'ncns', // Already correct
        ]);

        $this->artisan('attendance:fix-statuses', ['--date' => $date])
            ->expectsOutput('Total updated: 0')
            ->assertExitCode(0);
    }
}
