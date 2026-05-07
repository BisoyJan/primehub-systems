<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 4.5 — adjustLeaveForWorkDay integration tests.
 *
 * These tests verify the scenarios triggered when verify() is called with
 * a non-'on_leave' status on an attendance linked to an approved leave request.
 *
 * Scenarios: single-day cancel (with/without credits), first-day adjust,
 * last-day adjust, middle-day split, year-mismatch no-restore.
 */
class LeaveAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $employee;

    protected Site $site;

    protected int $currentYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentYear = now()->year;

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

    /** Create an EmployeeSchedule for the employee valid from 2025-01-01. */
    private function makeSchedule(): EmployeeSchedule
    {
        return EmployeeSchedule::factory()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'effective_date' => "{$this->currentYear}-01-01",
        ]);
    }

    /** Create a LeaveCredit row so restoreCredits() has something to restore. */
    private function makeLeaveCredit(int $year): LeaveCredit
    {
        return LeaveCredit::factory()->create([
            'user_id' => $this->employee->id,
            'year' => $year,
            'month' => 1,
            'credits_earned' => 5.0,
            'credits_used' => 2.0,
            'credits_balance' => 3.0,
        ]);
    }

    /** Create an approved LeaveRequest with the given dates. */
    private function makeLeave(string $start, string $end, int $creditsYear, float $creditsDeducted = 1.0): LeaveRequest
    {
        $days = (int) now()->parse($start)->diffInDays(now()->parse($end)) + 1;

        return LeaveRequest::factory()->approved()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => $start,
            'end_date' => $end,
            'days_requested' => $days,
            'credits_deducted' => $creditsDeducted,
            'credits_year' => $creditsYear,
        ]);
    }

    /** Create an on_leave Attendance linked to the given LeaveRequest. */
    private function makeAttendance(EmployeeSchedule $schedule, LeaveRequest $leave, string $date): Attendance
    {
        return Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'status' => 'on_leave',
            'leave_request_id' => $leave->id,
            'admin_verified' => false,
        ]);
    }

    private function verify(Attendance $attendance, array $data): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post("/attendance/{$attendance->id}/verify", $data);
    }

    /**
     * Single-day leave: verify triggers full cancellation and credit restoration.
     */
    #[Test]
    public function single_day_leave_is_cancelled_when_employee_works(): void
    {
        $schedule = $this->makeSchedule();
        $credit = $this->makeLeaveCredit($this->currentYear);
        $date = now()->startOfYear()->addMonths(2)->format('Y-m-d'); // e.g. 2026-03-01
        $leave = $this->makeLeave($date, $date, $this->currentYear, 1.0);
        $attendance = $this->makeAttendance($schedule, $leave, $date);

        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => $date.' 09:00:00',
            'actual_time_out' => $date.' 18:00:00',
        ]);

        $this->assertSame('cancelled', $leave->fresh()->status,
            'Single-day leave should be cancelled when employee works that day.');
    }

    /**
     * Single-day leave with current-year credits_year: credits are restored.
     */
    #[Test]
    public function single_day_leave_restores_credits_when_year_matches(): void
    {
        $schedule = $this->makeSchedule();
        $credit = $this->makeLeaveCredit($this->currentYear);
        $date = now()->startOfYear()->addMonths(2)->format('Y-m-d');
        $leave = $this->makeLeave($date, $date, $this->currentYear, 1.0);
        $attendance = $this->makeAttendance($schedule, $leave, $date);

        $creditBefore = $credit->fresh()->credits_balance;

        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => $date.' 09:00:00',
            'actual_time_out' => $date.' 18:00:00',
        ]);

        $creditAfter = $credit->fresh()->credits_balance;
        $this->assertGreaterThan($creditBefore, $creditAfter,
            'credits_balance should increase by 1 after leave cancellation with matching year.');
    }

    /**
     * Year mismatch: leave is cancelled but credits are NOT restored.
     */
    #[Test]
    public function credits_not_restored_when_credits_year_does_not_match_current_year(): void
    {
        $schedule = $this->makeSchedule();
        $pastYear = $this->currentYear - 1;
        $credit = $this->makeLeaveCredit($pastYear);
        $date = now()->startOfYear()->addMonths(2)->format('Y-m-d');
        $leave = $this->makeLeave($date, $date, $pastYear, 1.0);
        $attendance = $this->makeAttendance($schedule, $leave, $date);

        $creditBefore = $credit->fresh()->credits_balance;

        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => $date.' 09:00:00',
            'actual_time_out' => $date.' 18:00:00',
        ]);

        $creditAfter = $credit->fresh()->credits_balance;

        $this->assertSame('cancelled', $leave->fresh()->status,
            'Leave should still be cancelled on year mismatch.');
        $this->assertSame((float) $creditBefore, (float) $creditAfter,
            'Credits should NOT be restored when credits_year does not match current year.');
    }

    /**
     * Multi-day leave worked on first day: start_date advances by 1 day.
     */
    #[Test]
    public function multi_day_leave_start_is_adjusted_when_first_day_is_worked(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeLeaveCredit($this->currentYear);

        $start = now()->startOfYear()->addMonths(2)->format('Y-m-d');
        $end = now()->startOfYear()->addMonths(2)->addDays(2)->format('Y-m-d'); // 3-day leave
        $leave = $this->makeLeave($start, $end, $this->currentYear, 3.0);
        $attendance = $this->makeAttendance($schedule, $leave, $start);

        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => $start.' 09:00:00',
            'actual_time_out' => $start.' 18:00:00',
        ]);

        $fresh = $leave->fresh();
        $expectedStart = now()->parse($start)->addDay()->format('Y-m-d');
        $this->assertSame($expectedStart, $fresh->start_date->format('Y-m-d'),
            'Leave start_date should advance by 1 day when employee works the first day.');
    }

    /**
     * Multi-day leave worked on last day: end_date moves back by 1 day.
     */
    #[Test]
    public function multi_day_leave_end_is_adjusted_when_last_day_is_worked(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeLeaveCredit($this->currentYear);

        $start = now()->startOfYear()->addMonths(2)->format('Y-m-d');
        $end = now()->startOfYear()->addMonths(2)->addDays(2)->format('Y-m-d'); // 3-day leave
        $leave = $this->makeLeave($start, $end, $this->currentYear, 3.0);
        $attendance = $this->makeAttendance($schedule, $leave, $end); // work the last day

        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => $end.' 09:00:00',
            'actual_time_out' => $end.' 18:00:00',
        ]);

        $fresh = $leave->fresh();
        $expectedEnd = now()->parse($end)->subDay()->format('Y-m-d');
        $this->assertSame($expectedEnd, $fresh->end_date->format('Y-m-d'),
            'Leave end_date should move back by 1 day when employee works the last day.');
    }

    /**
     * Multi-day leave worked on a middle day: a sibling leave is created for the segment after.
     */
    #[Test]
    public function multi_day_leave_is_split_when_middle_day_is_worked(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeLeaveCredit($this->currentYear);

        $start = now()->startOfYear()->addMonths(2)->format('Y-m-d');
        $middle = now()->startOfYear()->addMonths(2)->addDays(1)->format('Y-m-d');
        $end = now()->startOfYear()->addMonths(2)->addDays(2)->format('Y-m-d');
        $leave = $this->makeLeave($start, $end, $this->currentYear, 3.0);
        $attendance = $this->makeAttendance($schedule, $leave, $middle);

        $leavesCountBefore = LeaveRequest::where('user_id', $this->employee->id)->count();

        $this->verify($attendance, [
            'status' => 'on_time',
            'actual_time_in' => $middle.' 09:00:00',
            'actual_time_out' => $middle.' 18:00:00',
        ]);

        $leavesCountAfter = LeaveRequest::where('user_id', $this->employee->id)->count();
        $this->assertGreaterThan($leavesCountBefore, $leavesCountAfter,
            'A sibling leave request should be created for the segment after the worked middle day.');

        // Original leave ends the day before the worked day
        $fresh = $leave->fresh();
        $expectedEnd = now()->parse($middle)->subDay()->format('Y-m-d');
        $this->assertSame($expectedEnd, $fresh->end_date->format('Y-m-d'),
            'Original leave end_date should be the day before the worked middle day.');
    }
}
