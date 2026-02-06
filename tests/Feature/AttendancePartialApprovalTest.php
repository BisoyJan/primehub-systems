<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendancePartialApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Super Admin',
            'is_approved' => true,
        ]);
    }

    protected function createNightShiftUser(): array
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $site = Site::factory()->create();

        $schedule = EmployeeSchedule::factory()->nightShift()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
        ]);

        return [$user, $schedule, $site];
    }

    #[Test]
    public function it_can_partially_approve_a_night_shift_without_time_out(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();
        $shiftDate = Carbon::parse('2026-02-06');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:03:00'),
            'actual_time_out' => null,
            'status' => 'failed_bio_out',
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        $response = $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/partial-approve", [
            'verification_notes' => 'Partially approved - time out pending.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('message');

        $attendance->refresh();
        $this->assertTrue($attendance->admin_verified);
        $this->assertTrue($attendance->is_partially_verified);
        $this->assertNull($attendance->actual_time_out);
        $this->assertEquals('Partially approved - time out pending.', $attendance->verification_notes);
    }

    #[Test]
    public function it_preserves_tardy_status_on_partial_approval(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();
        $shiftDate = Carbon::parse('2026-02-06');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:10:00'),
            'actual_time_out' => null,
            'status' => 'tardy',
            'tardy_minutes' => 10,
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        $response = $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/partial-approve", [
            'verification_notes' => 'Tardy noted, time out pending.',
        ]);

        $response->assertRedirect();

        $attendance->refresh();
        $this->assertTrue($attendance->admin_verified);
        $this->assertTrue($attendance->is_partially_verified);
        $this->assertEquals('tardy', $attendance->status);
    }

    #[Test]
    public function it_allows_status_override_on_partial_approval(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();
        $shiftDate = Carbon::parse('2026-02-06');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:03:00'),
            'actual_time_out' => null,
            'status' => 'failed_bio_out',
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        $response = $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/partial-approve", [
            'status' => 'on_time',
            'verification_notes' => 'On time, awaiting time out.',
        ]);

        $response->assertRedirect();

        $attendance->refresh();
        $this->assertEquals('on_time', $attendance->status);
        $this->assertTrue($attendance->is_partially_verified);
    }

    #[Test]
    public function it_generates_points_for_tardy_on_partial_approval(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();
        $shiftDate = Carbon::parse('2026-02-06');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:10:00'),
            'actual_time_out' => null,
            'status' => 'tardy',
            'tardy_minutes' => 10,
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/partial-approve", [
            'verification_notes' => 'Tardy, time out pending.',
        ]);

        $attendance->refresh();
        $this->assertTrue($attendance->admin_verified);
        $this->assertTrue($attendance->is_partially_verified);

        // Points should be generated for tardy
        $point = AttendancePoint::where('attendance_id', $attendance->id)->first();
        $this->assertNotNull($point);
        $this->assertEquals('tardy', $point->point_type);
        $this->assertEquals(0.25, (float) $point->points);
    }

    #[Test]
    public function it_can_partially_approve_a_day_shift_without_time_out(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();

        $schedule = EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
        ]);

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => Carbon::parse('2026-02-06'),
            'scheduled_time_in' => '06:00:00',
            'scheduled_time_out' => '15:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 06:00:00'),
            'actual_time_out' => null,
            'status' => 'failed_bio_out',
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        $response = $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/partial-approve", [
            'verification_notes' => 'Day shift partial approve - time out pending.',
        ]);

        $response->assertRedirect();

        $attendance->refresh();
        $this->assertTrue($attendance->admin_verified);
        $this->assertTrue($attendance->is_partially_verified);
        $this->assertNull($attendance->actual_time_out);
    }

    #[Test]
    public function it_rejects_partial_approval_when_time_out_exists(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => Carbon::parse('2026-02-06'),
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:00:00'),
            'actual_time_out' => Carbon::parse('2026-02-07 07:00:00'),
            'status' => 'on_time',
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
            'bio_out_site_id' => $site->id,
        ]);

        $response = $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/partial-approve", [
            'verification_notes' => 'Try partial approve.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('type', 'error');
    }

    #[Test]
    public function it_rejects_partial_approval_without_time_in(): void
    {
        [$user, $schedule] = $this->createNightShiftUser();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => Carbon::parse('2026-02-06'),
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => null,
            'actual_time_out' => null,
            'status' => 'ncns',
            'admin_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/partial-approve", [
            'verification_notes' => 'Try partial approve.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('type', 'error');
    }

    #[Test]
    public function verify_completes_partially_verified_record_when_time_out_provided(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();
        $shiftDate = Carbon::parse('2026-02-06');

        // Create a partially verified attendance
        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:03:00'),
            'actual_time_out' => null,
            'status' => 'tardy',
            'tardy_minutes' => 3,
            'admin_verified' => true,
            'is_partially_verified' => true,
            'verification_notes' => 'Partially approved.',
            'bio_in_site_id' => $site->id,
        ]);

        // Now verify with time out
        $response = $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/verify", [
            'status' => 'tardy',
            'actual_time_in' => '2026-02-06 22:03:00',
            'actual_time_out' => '2026-02-07 07:00:00',
            'verification_notes' => 'Fully verified with time out.',
        ]);

        $response->assertRedirect();

        $attendance->refresh();
        $this->assertTrue($attendance->admin_verified);
        $this->assertFalse($attendance->is_partially_verified);
        $this->assertNotNull($attendance->actual_time_out);
        $this->assertEquals('Fully verified with time out.', $attendance->verification_notes);
    }

    #[Test]
    public function verify_keeps_partial_flag_when_no_time_out_provided(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();
        $shiftDate = Carbon::parse('2026-02-06');

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:03:00'),
            'actual_time_out' => null,
            'status' => 'tardy',
            'tardy_minutes' => 3,
            'admin_verified' => true,
            'is_partially_verified' => true,
            'bio_in_site_id' => $site->id,
        ]);

        // Verify again but still no time out
        $response = $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/verify", [
            'status' => 'on_time',
            'actual_time_in' => '2026-02-06 22:03:00',
            'verification_notes' => 'Status corrected, still waiting for time out.',
        ]);

        $response->assertRedirect();

        $attendance->refresh();
        $this->assertTrue($attendance->admin_verified);
        $this->assertTrue($attendance->is_partially_verified);
    }

    #[Test]
    public function points_are_recalculated_when_partial_approval_is_completed(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();
        $shiftDate = Carbon::parse('2026-02-06');

        // Step 1: Partial approve with tardy status
        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:10:00'),
            'actual_time_out' => null,
            'status' => 'tardy',
            'tardy_minutes' => 10,
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/partial-approve", [
            'verification_notes' => 'Tardy, time out pending.',
        ]);

        // Verify tardy points were created
        $point = AttendancePoint::where('attendance_id', $attendance->id)->first();
        $this->assertNotNull($point);
        $this->assertEquals('tardy', $point->point_type);

        // Step 2: Complete verification with time out (employee left early → undertime)
        $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/verify", [
            'status' => 'tardy',
            'secondary_status' => 'undertime',
            'actual_time_in' => '2026-02-06 22:10:00',
            'actual_time_out' => '2026-02-07 06:00:00',
            'verification_notes' => 'Completed. Left 1 hour early.',
        ]);

        $attendance->refresh();
        $this->assertFalse($attendance->is_partially_verified);

        // Points should have been recalculated
        $points = AttendancePoint::where('attendance_id', $attendance->id)->get();
        $this->assertGreaterThanOrEqual(1, $points->count());
    }

    #[Test]
    public function review_filter_returns_partially_verified_records(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();
        $shiftDate = Carbon::parse('2026-02-06');

        // Create a partially verified record
        Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:03:00'),
            'actual_time_out' => null,
            'status' => 'failed_bio_out',
            'admin_verified' => true,
            'is_partially_verified' => true,
        ]);

        // Create a fully verified record
        Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate->copy()->subDay(),
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-05 22:00:00'),
            'actual_time_out' => Carbon::parse('2026-02-06 07:00:00'),
            'status' => 'on_time',
            'admin_verified' => true,
            'is_partially_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)->get('/attendance/review?verified=partially_verified');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Attendance/Main/Review')
            ->has('attendances.data', 1)
            ->where('partiallyVerifiedCount', 1)
            ->has('statusCounts')
            ->has('verificationCounts')
        );
    }

    #[Test]
    public function batch_partial_approve_approves_multiple_records(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();
        $shiftDate = Carbon::parse('2026-02-06');

        $attendance1 = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:03:00'),
            'actual_time_out' => null,
            'status' => 'failed_bio_out',
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        $attendance2 = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate->copy()->subDay(),
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-05 22:10:00'),
            'actual_time_out' => null,
            'status' => 'tardy',
            'tardy_minutes' => 10,
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        $response = $this->actingAs($this->admin)->post('/attendance/batch-partial-approve', [
            'record_ids' => [$attendance1->id, $attendance2->id],
            'verification_notes' => 'Batch partially approved.',
        ]);

        $response->assertRedirect();

        $attendance1->refresh();
        $attendance2->refresh();

        $this->assertTrue($attendance1->admin_verified);
        $this->assertTrue($attendance1->is_partially_verified);

        $this->assertTrue($attendance2->admin_verified);
        $this->assertTrue($attendance2->is_partially_verified);
        $this->assertEquals('tardy', $attendance2->status);
    }

    #[Test]
    public function batch_partial_approve_skips_ineligible_records(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();
        $shiftDate = Carbon::parse('2026-02-06');

        // Eligible record
        $eligible = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate,
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 22:03:00'),
            'actual_time_out' => null,
            'status' => 'failed_bio_out',
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        // Ineligible: already has time-out
        $hasTimeOut = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $shiftDate->copy()->subDay(),
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-02-05 22:00:00'),
            'actual_time_out' => Carbon::parse('2026-02-06 07:00:00'),
            'status' => 'on_time',
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        $response = $this->actingAs($this->admin)->post('/attendance/batch-partial-approve', [
            'record_ids' => [$eligible->id, $hasTimeOut->id],
            'verification_notes' => 'Batch test.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('message');

        $eligible->refresh();
        $hasTimeOut->refresh();

        $this->assertTrue($eligible->is_partially_verified);
        $this->assertFalse($hasTimeOut->is_partially_verified);
    }

    #[Test]
    public function it_can_partially_approve_mid_shift_without_time_out(): void
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'shift_type' => 'afternoon_shift',
            'scheduled_time_in' => '14:00:00',
            'scheduled_time_out' => '23:00:00',
        ]);

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => Carbon::parse('2026-02-06'),
            'scheduled_time_in' => '14:00:00',
            'scheduled_time_out' => '23:00:00',
            'actual_time_in' => Carbon::parse('2026-02-06 14:05:00'),
            'actual_time_out' => null,
            'status' => 'tardy',
            'tardy_minutes' => 5,
            'admin_verified' => false,
            'bio_in_site_id' => $site->id,
        ]);

        $response = $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/partial-approve", [
            'verification_notes' => 'Mid shift partial approve.',
        ]);

        $response->assertRedirect();

        $attendance->refresh();
        $this->assertTrue($attendance->admin_verified);
        $this->assertTrue($attendance->is_partially_verified);
        $this->assertEquals('tardy', $attendance->status);
    }
}
