<?php

namespace Tests\Unit\Models;

use App\Models\Attendance;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeScheduleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create();
        $site = Site::factory()->create();

        $schedule = EmployeeSchedule::create([
            'user_id' => $user->id,
            'campaign_id' => $campaign->id,
            'site_id' => $site->id,
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'grace_period_minutes' => 15,
            'is_active' => true,
            'effective_date' => '2024-01-01',
            'end_date' => null,
        ]);

        $this->assertEquals($user->id, $schedule->user_id);
        $this->assertEquals('morning_shift', $schedule->shift_type);
    }

    #[Test]
    public function it_casts_work_days_to_array(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'work_days' => ['monday', 'wednesday', 'friday'],
        ]);

        $this->assertIsArray($schedule->work_days);
        $this->assertContains('monday', $schedule->work_days);
    }

    #[Test]
    public function it_casts_is_active_to_boolean(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'is_active' => 1,
        ]);

        $this->assertIsBool($schedule->is_active);
        $this->assertTrue($schedule->is_active);
    }

    #[Test]
    public function it_casts_effective_date_to_carbon_date(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'effective_date' => '2024-01-01',
        ]);

        $this->assertInstanceOf(Carbon::class, $schedule->effective_date);
        $this->assertEquals('2024-01-01', $schedule->effective_date->format('Y-m-d'));
    }

    #[Test]
    public function it_casts_end_date_to_carbon_date(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'end_date' => '2024-12-31',
        ]);

        $this->assertInstanceOf(Carbon::class, $schedule->end_date);
    }

    #[Test]
    public function it_casts_grace_period_minutes_to_integer(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'grace_period_minutes' => '15',
        ]);

        $this->assertIsInt($schedule->grace_period_minutes);
        $this->assertEquals(15, $schedule->grace_period_minutes);
    }

    #[Test]
    public function it_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $schedule = EmployeeSchedule::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $schedule->user);
        $this->assertEquals($user->id, $schedule->user->id);
    }

    #[Test]
    public function it_belongs_to_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'campaign_id' => $campaign->id,
        ]);

        $this->assertInstanceOf(Campaign::class, $schedule->campaign);
        $this->assertEquals($campaign->id, $schedule->campaign->id);
    }

    #[Test]
    public function it_belongs_to_site(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
        ]);

        $this->assertInstanceOf(Site::class, $schedule->site);
        $this->assertEquals($site->id, $schedule->site->id);
    }

    #[Test]
    public function it_has_many_attendances(): void
    {
        $user = User::factory()->create();
        $schedule = EmployeeSchedule::factory()->create(['user_id' => $user->id]);

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
        ]);

        $this->assertCount(1, $schedule->attendances);
        $this->assertTrue($schedule->attendances->contains($attendance));
    }

    #[Test]
    public function it_filters_active_schedules(): void
    {
        $user = User::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'effective_date' => now()->subDays(10),
            'end_date' => null,
        ]);

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
            'effective_date' => now()->subDays(10),
        ]);

        $active = EmployeeSchedule::active()->get();

        $this->assertCount(1, $active);
        $this->assertTrue($active->first()->is_active);
    }

    #[Test]
    public function it_excludes_future_schedules_from_active(): void
    {
        $user = User::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'effective_date' => now()->addDays(10),
        ]);

        $active = EmployeeSchedule::active()->get();

        $this->assertCount(0, $active);
    }

    #[Test]
    public function it_excludes_expired_schedules_from_active(): void
    {
        $user = User::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'effective_date' => now()->subMonths(2),
            'end_date' => now()->subDays(1),
        ]);

        $active = EmployeeSchedule::active()->get();

        $this->assertCount(0, $active);
    }

    #[Test]
    public function it_filters_schedules_for_specific_date(): void
    {
        $user = User::factory()->create();
        $targetDate = now();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'effective_date' => $targetDate->copy()->subDays(10),
            'end_date' => $targetDate->copy()->addDays(10),
        ]);

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'effective_date' => $targetDate->copy()->addDays(20),
        ]);

        $forDate = EmployeeSchedule::forDate($targetDate)->get();

        $this->assertCount(1, $forDate);
    }

    #[Test]
    public function it_detects_night_shift_by_type(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_type' => 'night_shift',
        ]);

        $this->assertTrue($schedule->isNightShift());
    }

    #[Test]
    public function it_detects_night_shift_by_time(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_type' => 'night_shift',
            'scheduled_time_in' => '22:00:00',
        ]);

        $this->assertTrue($schedule->isNightShift());
    }

    #[Test]
    public function it_does_not_detect_day_shift_as_night_shift(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '08:00:00',
        ]);

        $this->assertFalse($schedule->isNightShift());
    }

    #[Test]
    public function it_checks_if_employee_works_on_monday(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'work_days' => ['monday', 'tuesday', 'wednesday'],
        ]);

        $this->assertTrue($schedule->worksOnDay('Monday'));
    }

    #[Test]
    public function it_checks_if_employee_does_not_work_on_sunday(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);

        $this->assertFalse($schedule->worksOnDay('Sunday'));
    }

    #[Test]
    public function it_handles_case_insensitive_day_names(): void
    {
        $user = User::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'work_days' => ['monday', 'friday'],
        ]);

        $this->assertTrue($schedule->worksOnDay('FRIDAY'));
        $this->assertTrue($schedule->worksOnDay('Monday'));
    }
}
