<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recommendation #9 — Cross-surface harmony tests.
 *
 * Asserts that the same business scenario produces the same Attendance
 * row regardless of which admin surface (Manual, Daily Roster,
 * Spreadsheet, Review/Verify) created it.
 *
 * Some of these tests are EXPECTED TO FAIL on the current codebase —
 * that failure is the audit's safety net. They will turn green once the
 * unification work in recommendations #1-#3 is complete.
 */
class AttendanceCrossSurfaceHarmonyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $this->employee = User::factory()->create([
            'first_name' => 'Harmony',
            'last_name' => 'Tester',
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $this->site = Site::factory()->create();
    }

    /** Build (or rebuild) an active morning schedule for the employee. */
    private function morningSchedule(int $gracePeriod = 0): EmployeeSchedule
    {
        return EmployeeSchedule::factory()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'grace_period_minutes' => $gracePeriod,
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            'effective_date' => '2025-01-01',
            'is_active' => true,
        ]);
    }

    private function createViaManual(string $shiftDate, string $timeIn, string $timeOut): Attendance
    {
        $this->actingAs($this->admin)->post('/attendance', [
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'actual_time_in' => $shiftDate.'T'.$timeIn,
            'actual_time_out' => $shiftDate.'T'.$timeOut,
        ]);

        return Attendance::where('user_id', $this->employee->id)
            ->where('shift_date', $shiftDate)
            ->firstOrFail();
    }

    private function createViaRoster(string $shiftDate, string $timeIn, string $timeOut): Attendance
    {
        $this->actingAs($this->admin)->post('/attendance/generate', [
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'actual_time_in' => $shiftDate.'T'.$timeIn,
            'actual_time_out' => $shiftDate.'T'.$timeOut,
        ]);

        return Attendance::where('user_id', $this->employee->id)
            ->where('shift_date', $shiftDate)
            ->firstOrFail();
    }

    private function createViaSpreadsheet(string $shiftDate, string $timeIn, string $timeOut): Attendance
    {
        $this->actingAs($this->admin)->post('/attendance/spreadsheet/cell/create', [
            'user_id' => $this->employee->id,
            'shift_date' => $shiftDate,
            'actual_time_in' => $timeIn,
            'actual_time_out' => $timeOut,
        ]);

        return Attendance::where('user_id', $this->employee->id)
            ->where('shift_date', $shiftDate)
            ->firstOrFail();
    }

    /**
     * Verify (Review surface) operates on an existing record. We seed an
     * empty NCNS row first, then verify with the same actual times so the
     * recalculation path runs.
     */
    private function createViaVerify(string $shiftDate, string $timeIn, string $timeOut, EmployeeSchedule $schedule, string $status = 'on_time'): Attendance
    {
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'status' => 'ncns',
            'admin_verified' => false,
        ]);

        $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/verify", [
            'status' => $status,
            'actual_time_in' => $shiftDate.' '.$timeIn.':00',
            'actual_time_out' => $shiftDate.' '.$timeOut.':00',
        ]);

        return $attendance->fresh();
    }

    // ---------------------------------------------------------------------
    // Scenario 1 — Late arrival (10 min, within tardy band, no grace)
    //   Asserts ALL FOUR surfaces produce identical status and
    //   tardy_minutes for the same input. This is a true harmony test —
    //   the assertion is equality, not a specific number.
    // ---------------------------------------------------------------------

    #[Test]
    public function same_late_arrival_produces_same_status_and_tardy_minutes_across_all_four_surfaces(): void
    {
        $this->morningSchedule(gracePeriod: 0);
        $schedule = $this->employee->employeeSchedules()->first();

        $manual = $this->createViaManual('2025-11-03', '09:10', '18:00');
        $roster = $this->createViaRoster('2025-11-04', '09:10', '18:00');
        $sheet = $this->createViaSpreadsheet('2025-11-05', '09:10', '18:00');
        $verify = $this->createViaVerify('2025-11-06', '09:10', '18:00', $schedule, 'tardy');

        $statuses = [
            'manual' => $manual->status,
            'roster' => $roster->status,
            'sheet' => $sheet->status,
            'verify' => $verify->status,
        ];
        $tardies = [
            'manual' => (int) $manual->tardy_minutes,
            'roster' => (int) $roster->tardy_minutes,
            'sheet' => (int) $sheet->tardy_minutes,
            'verify' => (int) $verify->tardy_minutes,
        ];

        $this->assertCount(1, array_unique($statuses), 'All four surfaces must agree on status. Got: '.json_encode($statuses));
        $this->assertCount(1, array_unique($tardies), 'All four surfaces must agree on tardy_minutes. Got: '.json_encode($tardies));
    }

    // ---------------------------------------------------------------------
    // Scenario 2 — Slight overtime (15 min past schedule, no approval)
    //   Asserts ALL FOUR surfaces produce identical overtime_minutes for
    //   the same input. Currently fails because Daily Roster records the
    //   raw delta (15) while the processor and verify apply different
    //   thresholds — proves audit Theme 1 (Calculator divergence).
    // ---------------------------------------------------------------------

    #[Test]
    public function same_slight_overtime_produces_same_overtime_minutes_across_all_four_surfaces(): void
    {
        $this->morningSchedule(gracePeriod: 0);
        $schedule = $this->employee->employeeSchedules()->first();

        $manual = $this->createViaManual('2025-11-03', '09:00', '18:15');
        $roster = $this->createViaRoster('2025-11-04', '09:00', '18:15');
        $sheet = $this->createViaSpreadsheet('2025-11-05', '09:00', '18:15');
        $verify = $this->createViaVerify('2025-11-06', '09:00', '18:15', $schedule, 'on_time');

        $overtimes = [
            'manual' => (int) $manual->overtime_minutes,
            'roster' => (int) $roster->overtime_minutes,
            'sheet' => (int) $sheet->overtime_minutes,
            'verify' => (int) $verify->overtime_minutes,
        ];

        $this->assertCount(
            1,
            array_unique($overtimes),
            'All four surfaces must agree on overtime_minutes for a 15-min late time-out. Got: '.json_encode($overtimes)
        );
    }

    // ---------------------------------------------------------------------
    // Scenario 3 — Approved leave conflict
    //   Expected: admin_verified=false on every write surface so HR can review.
    //   Currently FAILS for Roster + Spreadsheet (they auto-verify
    //   unconditionally) — proves the divergence flagged in audit Theme 2.
    // ---------------------------------------------------------------------

    #[Test]
    public function approved_leave_blocks_auto_verification_in_all_write_surfaces(): void
    {
        $this->morningSchedule(gracePeriod: 0);

        // Seed an approved leave that covers all three test dates.
        LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => '2025-11-03',
            'end_date' => '2025-11-05',
            'status' => 'approved',
        ]);

        $manual = $this->createViaManual('2025-11-03', '09:00', '18:00');
        $roster = $this->createViaRoster('2025-11-04', '09:00', '18:00');
        $sheet = $this->createViaSpreadsheet('2025-11-05', '09:00', '18:00');

        $rows = compact('manual', 'roster', 'sheet');

        foreach ($rows as $surface => $row) {
            $this->assertFalse(
                (bool) $row->admin_verified,
                "[$surface] admin_verified must be FALSE when employee has an approved leave on shift_date — needs HR review"
            );
        }
    }

    // ---------------------------------------------------------------------
    // Scenario 4 — Same on-time arrival, no violations
    //   Expected: status=on_time, tardy/UT/OT all null/0 across surfaces.
    //   Sanity baseline that should already be green.
    // ---------------------------------------------------------------------

    #[Test]
    public function clean_on_time_attendance_produces_identical_baseline_across_all_four_surfaces(): void
    {
        $this->morningSchedule(gracePeriod: 0);
        $schedule = $this->employee->employeeSchedules()->first();

        $manual = $this->createViaManual('2025-11-03', '09:00', '18:00');
        $roster = $this->createViaRoster('2025-11-04', '09:00', '18:00');
        $sheet = $this->createViaSpreadsheet('2025-11-05', '09:00', '18:00');
        $verify = $this->createViaVerify('2025-11-06', '09:00', '18:00', $schedule, 'on_time');

        $rows = compact('manual', 'roster', 'sheet', 'verify');

        foreach ($rows as $surface => $row) {
            $this->assertSame('on_time', $row->status, "[$surface] status should be 'on_time'");
            $this->assertTrue(
                $row->tardy_minutes === null || (int) $row->tardy_minutes === 0,
                "[$surface] tardy_minutes should be null/0"
            );
            $this->assertTrue(
                $row->undertime_minutes === null || (int) $row->undertime_minutes === 0,
                "[$surface] undertime_minutes should be null/0"
            );
            $this->assertTrue(
                $row->overtime_minutes === null || (int) $row->overtime_minutes === 0,
                "[$surface] overtime_minutes should be null/0"
            );
        }
    }
}
