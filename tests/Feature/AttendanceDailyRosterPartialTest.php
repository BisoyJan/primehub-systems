<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceDailyRosterPartialTest extends TestCase
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
    public function generate_creates_partially_verified_record_when_time_out_is_omitted(): void
    {
        [$user, $schedule] = $this->createNightShiftUser();
        $shiftDate = '2026-05-08';

        $response = $this->actingAs($this->admin)->post('/attendance/generate', [
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'actual_time_in' => $shiftDate.'T22:05',
            // actual_time_out intentionally omitted
        ]);

        $response->assertRedirect();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->firstOrFail();

        $this->assertTrue((bool) $attendance->admin_verified);
        $this->assertTrue((bool) $attendance->is_partially_verified);
        $this->assertNull($attendance->actual_time_out);
        $this->assertNotNull($attendance->actual_time_in);
        $this->assertSame((int) $schedule->id, (int) $attendance->employee_schedule_id);
    }

    #[Test]
    public function generate_creates_fully_verified_record_when_time_out_is_provided(): void
    {
        [$user] = $this->createNightShiftUser();
        $shiftDate = '2026-05-08';

        $this->actingAs($this->admin)->post('/attendance/generate', [
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'actual_time_in' => $shiftDate.'T22:00',
            'actual_time_out' => '2026-05-09T07:00',
        ])->assertRedirect();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->firstOrFail();

        $this->assertTrue((bool) $attendance->admin_verified);
        $this->assertFalse((bool) $attendance->is_partially_verified);
        $this->assertNotNull($attendance->actual_time_out);
    }

    #[Test]
    public function daily_roster_returns_pending_time_outs_prop(): void
    {
        [$userA, $scheduleA, $siteA] = $this->createNightShiftUser();
        [$userB] = $this->createNightShiftUser();

        // Pending record for userA from May 8 (no time-out yet)
        Attendance::factory()->create([
            'user_id' => $userA->id,
            'employee_schedule_id' => $scheduleA->id,
            'shift_date' => Carbon::parse('2026-05-08'),
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-05-08 22:00:00'),
            'actual_time_out' => null,
            'status' => 'on_time',
            'admin_verified' => true,
            'is_partially_verified' => true,
            'bio_in_site_id' => $siteA->id,
        ]);

        // Fully verified record for userB — should NOT appear in pending panel
        Attendance::factory()->create([
            'user_id' => $userB->id,
            'shift_date' => Carbon::parse('2026-05-08'),
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-05-08 22:00:00'),
            'actual_time_out' => Carbon::parse('2026-05-09 07:00:00'),
            'status' => 'on_time',
            'admin_verified' => true,
            'is_partially_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)->get('/attendance/daily-roster?date=2026-05-09');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Attendance/Main/DailyRoster')
            ->has('pendingTimeOuts', 1)
            ->where('pendingTimeOuts.0.user_id', $userA->id)
            ->where('pendingTimeOuts.0.shift_date', '2026-05-08')
        );
    }

    #[Test]
    public function verify_completes_partially_verified_record_when_time_out_added(): void
    {
        [$user, $schedule, $site] = $this->createNightShiftUser();

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => Carbon::parse('2026-05-08'),
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
            'actual_time_in' => Carbon::parse('2026-05-08 22:00:00'),
            'actual_time_out' => null,
            'status' => 'on_time',
            'admin_verified' => true,
            'is_partially_verified' => true,
            'bio_in_site_id' => $site->id,
        ]);

        $this->actingAs($this->admin)->post("/attendance/{$attendance->id}/verify", [
            'status' => 'on_time',
            'actual_time_in' => '2026-05-08T22:00',
            'actual_time_out' => '2026-05-09T07:00',
            'verification_notes' => 'Time-out completed on next shift.',
        ])->assertRedirect();

        $attendance->refresh();
        $this->assertNotNull($attendance->actual_time_out);
        $this->assertFalse((bool) $attendance->is_partially_verified);
        $this->assertTrue((bool) $attendance->admin_verified);
    }
}
