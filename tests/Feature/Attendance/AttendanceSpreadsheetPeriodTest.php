<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceWeekTotal;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the monthly Attendance Spreadsheet view and the per-Saturday
 * "calculate week hours" endpoint. Each Saturday acts as a payroll button
 * that rolls up consecutive uncalculated prior weeks into a single display
 * anchor (display_group_end), persisting one row per week in the
 * attendance_week_totals table.
 */
class AttendanceSpreadsheetPeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Site $site;

    private EmployeeSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $this->employee = User::factory()->create([
            'first_name' => 'Period',
            'last_name' => 'Tester',
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $this->site = Site::factory()->create();

        $this->schedule = EmployeeSchedule::factory()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'grace_period_minutes' => 0,
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'effective_date' => '2025-01-01',
            'is_active' => true,
        ]);
    }

    private function makeAttendance(string $date, int $minutes): void
    {
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'employee_schedule_id' => $this->schedule->id,
            'shift_date' => $date,
            'status' => 'on_time',
            'actual_time_in' => $date.' 09:00:00',
            'actual_time_out' => $date.' 18:00:00',
            'total_minutes_worked' => $minutes,
        ]);
    }

    private function makeAttendanceWithOvertime(string $date, int $minutes, int $overtimeMinutes, bool $approved): void
    {
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'employee_schedule_id' => $this->schedule->id,
            'shift_date' => $date,
            'status' => 'on_time',
            'actual_time_in' => $date.' 09:00:00',
            'actual_time_out' => $date.' 20:00:00',
            'total_minutes_worked' => $minutes,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_approved' => $approved,
        ]);
    }

    #[Test]
    public function it_renders_a_full_month_of_columns(): void
    {
        $this->actingAs($this->admin)
            ->get('/attendance/spreadsheet?month=11&year=2025')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Attendance/Main/Spreadsheet')
                ->has('days', 42)
            );
    }

    #[Test]
    public function it_marks_saturday_columns(): void
    {
        $this->actingAs($this->admin)
            ->get('/attendance/spreadsheet?month=11&year=2025')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.13.date', '2025-11-08')
                ->where('days.13.is_saturday', true)
                ->where('days.6.date', '2025-11-01')
                ->where('days.6.is_saturday', true)
                ->where('days.8.date', '2025-11-03')
                ->where('days.8.is_saturday', false)
            );
    }

    #[Test]
    public function it_calculates_a_single_week_total(): void
    {
        // Week of Nov 8 (Sun Nov 2 - Sat Nov 8): 8h + 8h = 16h.
        $this->makeAttendance('2025-11-07', 480);
        $this->makeAttendance('2025-11-08', 480);

        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-08',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('attendance_week_totals', [
            'user_id' => $this->employee->id,
            'week_end' => '2025-11-08',
            'display_group_end' => '2025-11-08',
            'total_hours' => '16.00',
        ]);

        $this->assertSame(1, AttendanceWeekTotal::where('user_id', $this->employee->id)->count());
    }

    #[Test]
    public function it_rolls_up_consecutive_uncalculated_weeks_into_one_anchor(): void
    {
        // Week of Nov 8 = 8h, week of Nov 15 = 16h. Clicking Nov 15 first should
        // calculate BOTH weeks and group them under the Nov 15 display anchor.
        $this->makeAttendance('2025-11-08', 480);   // week ending Nov 8
        $this->makeAttendance('2025-11-14', 480);   // week ending Nov 15
        $this->makeAttendance('2025-11-15', 480);   // week ending Nov 15

        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-15',
            ])
            ->assertRedirect();

        $rows = AttendanceWeekTotal::where('user_id', $this->employee->id)
            ->orderBy('week_end')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('2025-11-08', $rows[0]->week_end->toDateString());
        $this->assertSame('2025-11-15', $rows[1]->week_end->toDateString());
        // Both grouped under the clicked Saturday's anchor.
        $this->assertSame('2025-11-15', $rows[0]->display_group_end->toDateString());
        $this->assertSame('2025-11-15', $rows[1]->display_group_end->toDateString());
        $this->assertSame('8.00', (string) $rows[0]->total_hours);
        $this->assertSame('16.00', (string) $rows[1]->total_hours);
    }

    #[Test]
    public function it_calculates_a_later_week_alone_when_the_prior_week_is_done(): void
    {
        $this->makeAttendance('2025-11-08', 480);
        $this->makeAttendance('2025-11-15', 480);

        // Calculate week 1 first.
        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-08',
            ]);

        // Then calculate week 2 — should NOT re-roll week 1.
        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-15',
            ]);

        $week1 = AttendanceWeekTotal::where('user_id', $this->employee->id)
            ->where('week_end', '2025-11-08')->firstOrFail();
        $week2 = AttendanceWeekTotal::where('user_id', $this->employee->id)
            ->where('week_end', '2025-11-15')->firstOrFail();

        // Week 1 keeps its own anchor, week 2 anchors only to itself.
        $this->assertSame('2025-11-08', $week1->display_group_end->toDateString());
        $this->assertSame('2025-11-15', $week2->display_group_end->toDateString());
    }

    #[Test]
    public function it_overwrites_a_week_total_on_recalculation(): void
    {
        $this->makeAttendance('2025-11-08', 480); // 8h

        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-08',
            ]);

        $this->assertDatabaseHas('attendance_week_totals', [
            'week_end' => '2025-11-08',
            'total_hours' => '8.00',
        ]);

        // Add more hours that week and recalculate.
        $this->makeAttendance('2025-11-07', 480); // +8h

        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-08',
            ]);

        $this->assertDatabaseHas('attendance_week_totals', [
            'week_end' => '2025-11-08',
            'total_hours' => '16.00',
        ]);
        $this->assertSame(1, AttendanceWeekTotal::where('user_id', $this->employee->id)->count());
    }

    #[Test]
    public function it_rejects_a_non_saturday_date(): void
    {
        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-05',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('attendance_week_totals', 0);
    }

    #[Test]
    public function it_removes_a_calculation_group_for_a_saturday(): void
    {
        // Roll up two weeks under the Nov 15 anchor.
        $this->makeAttendance('2025-11-08', 480);
        $this->makeAttendance('2025-11-15', 480);

        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-15',
            ]);

        $this->assertSame(2, AttendanceWeekTotal::where('user_id', $this->employee->id)->count());

        // Removing the anchor deletes every week rolled up under it.
        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/remove-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-15',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('attendance_week_totals', 0);
    }

    #[Test]
    public function it_caps_daily_hours_at_eight_when_overtime_is_not_approved(): void
    {
        // 11h worked (3h overtime) but NOT approved → counts only 8h.
        $this->makeAttendanceWithOvertime('2025-11-08', 660, 180, false);

        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-08',
            ]);

        $this->assertDatabaseHas('attendance_week_totals', [
            'week_end' => '2025-11-08',
            'total_hours' => '8.00',
        ]);
    }

    #[Test]
    public function it_counts_full_hours_when_overtime_is_approved(): void
    {
        // 11h worked (3h overtime) and approved → counts the full 11h.
        $this->makeAttendanceWithOvertime('2025-11-08', 660, 180, true);

        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-08',
            ]);

        $this->assertDatabaseHas('attendance_week_totals', [
            'week_end' => '2025-11-08',
            'total_hours' => '11.00',
        ]);
    }

    #[Test]
    public function it_excludes_small_overtime_minutes_when_not_approved(): void
    {
        // 8h7m worked (7 min overtime, below the 30-min UI tolerance) but NOT
        // approved → counts only the standard 8h, dropping the extra minutes.
        $this->makeAttendanceWithOvertime('2025-11-08', 487, 7, false);

        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-08',
            ]);

        $this->assertDatabaseHas('attendance_week_totals', [
            'week_end' => '2025-11-08',
            'total_hours' => '8.00',
        ]);
    }

    #[Test]
    public function it_counts_small_overtime_minutes_when_approved(): void
    {
        // 8h7m worked (7 min overtime) and approved → counts the full 8.12h.
        $this->makeAttendanceWithOvertime('2025-11-08', 487, 7, true);

        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-08',
            ]);

        $this->assertDatabaseHas('attendance_week_totals', [
            'week_end' => '2025-11-08',
            'total_hours' => '8.12',
        ]);
    }

    #[Test]
    public function it_keeps_undertime_hours_below_eight(): void
    {
        // 6h worked, no overtime → counts 6h (cap only applies above 8h).
        $this->makeAttendance('2025-11-08', 360);

        $this->actingAs($this->admin)
            ->post('/attendance/spreadsheet/calculate-week', [
                'user_id' => $this->employee->id,
                'saturday' => '2025-11-08',
            ]);

        $this->assertDatabaseHas('attendance_week_totals', [
            'week_end' => '2025-11-08',
            'total_hours' => '6.00',
        ]);
    }
}
