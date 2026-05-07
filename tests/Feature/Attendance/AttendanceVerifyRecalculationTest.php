<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 4.4 — Attendance verify() recalculation tests.
 *
 * Covers: admin_verified flag, status persistence, tardy recalculation
 * when actual_time_in is late, undertime recalculation when actual_time_out
 * is early, and overtime recalculation when actual_time_out is late.
 */
class AttendanceVerifyRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $employee;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $this->employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $this->site = Site::factory()->create();
    }

    /** Build an attendance with a predictable morning shift (09:00-18:00). */
    private function makeAttendance(array $overrides = []): Attendance
    {
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'effective_date' => '2025-01-01',
        ]);

        return Attendance::factory()->create(array_merge([
            'user_id' => $this->employee->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => '2025-11-05',
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'status' => 'ncns',
            'admin_verified' => false,
            'tardy_minutes' => null,
            'undertime_minutes' => null,
            'overtime_minutes' => null,
        ], $overrides));
    }

    private function verify(Attendance $attendance, array $data): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post("/attendance/{$attendance->id}/verify", $data);
    }

    #[Test]
    public function verify_sets_admin_verified_flag_to_true(): void
    {
        $attendance = $this->makeAttendance();

        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => '2025-11-05 09:00:00',
            'actual_time_out' => '2025-11-05 18:00:00',
        ]);

        $this->assertTrue($attendance->fresh()->admin_verified);
    }

    #[Test]
    public function verify_persists_the_provided_status(): void
    {
        $attendance = $this->makeAttendance();

        $this->verify($attendance, [
            'status' => 'tardy',
            'actual_time_in' => '2025-11-05 09:20:00',
            'actual_time_out' => '2025-11-05 18:00:00',
        ]);

        $this->assertSame('tardy', $attendance->fresh()->status);
    }

    #[Test]
    public function verify_recalculates_tardy_minutes_when_time_in_is_late(): void
    {
        // 09:00 scheduled, 09:20 actual → 20 minutes tardy
        $attendance = $this->makeAttendance();

        $this->verify($attendance, [
            'status' => 'tardy',
            'actual_time_in' => '2025-11-05 09:20:00',
            'actual_time_out' => '2025-11-05 18:00:00',
        ]);

        $fresh = $attendance->fresh();
        $this->assertSame(20, $fresh->tardy_minutes,
            'tardy_minutes should be recalculated to 20 when employee is 20 minutes late.');
    }

    #[Test]
    public function verify_clears_tardy_when_time_in_is_on_time(): void
    {
        // Previous tardy cleared when verified with on-time time-in
        $attendance = $this->makeAttendance(['tardy_minutes' => 15]);

        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => '2025-11-05 09:00:00',
            'actual_time_out' => '2025-11-05 18:00:00',
        ]);

        $this->assertNull($attendance->fresh()->tardy_minutes,
            'tardy_minutes should be cleared when employee is verified as on-time.');
    }

    #[Test]
    public function verify_recalculates_undertime_when_time_out_is_early(): void
    {
        // 18:00 scheduled, 17:00 actual → 60 minutes undertime
        $attendance = $this->makeAttendance();

        $this->verify($attendance, [
            'status' => 'undertime',
            'actual_time_in' => '2025-11-05 09:00:00',
            'actual_time_out' => '2025-11-05 17:00:00',
        ]);

        $fresh = $attendance->fresh();
        $this->assertSame(60, $fresh->undertime_minutes,
            'undertime_minutes should be 60 when employee leaves 1 hour early.');
    }

    #[Test]
    public function verify_recalculates_overtime_when_time_out_is_late(): void
    {
        // 18:00 scheduled, 19:00 actual → 60 minutes overtime
        $attendance = $this->makeAttendance();

        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => '2025-11-05 09:00:00',
            'actual_time_out' => '2025-11-05 19:00:00',
        ]);

        $fresh = $attendance->fresh();
        $this->assertSame(60, $fresh->overtime_minutes,
            'overtime_minutes should be 60 when employee stays 1 hour late.');
    }

    #[Test]
    public function verify_saves_verification_notes(): void
    {
        $attendance = $this->makeAttendance();

        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => '2025-11-05 09:00:00',
            'actual_time_out' => '2025-11-05 18:00:00',
            'verification_notes' => 'Manually verified from paper log.',
        ]);

        $this->assertSame('Manually verified from paper log.', $attendance->fresh()->verification_notes);
    }

    #[Test]
    public function verify_allows_re_verification_of_already_verified_record(): void
    {
        $attendance = $this->makeAttendance([
            'status' => 'tardy',
            'admin_verified' => true,
            'tardy_minutes' => 30,
            'actual_time_in' => '2025-11-05 09:30:00',
        ]);

        // Re-verify with corrected time
        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => '2025-11-05 09:00:00',
            'actual_time_out' => '2025-11-05 18:00:00',
        ]);

        $fresh = $attendance->fresh();
        $this->assertSame('on_time', $fresh->status);
        $this->assertTrue($fresh->admin_verified);
        $this->assertNull($fresh->tardy_minutes);
    }
}
