<?php

namespace Tests\Feature\Schedules;

use App\Models\EmployeeSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HiredDateSyncTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function changing_hired_date_cascades_to_all_schedules(): void
    {
        $user = User::factory()->create(['hired_date' => '2025-01-20']);
        $first = EmployeeSchedule::factory()->create(['user_id' => $user->id, 'effective_date' => '2025-01-20']);
        $second = EmployeeSchedule::factory()->create(['user_id' => $user->id, 'effective_date' => '2025-01-20']);

        $user->update(['hired_date' => '2024-06-01']);

        $this->assertSame('2024-06-01', $first->fresh()->effective_date->format('Y-m-d'));
        $this->assertSame('2024-06-01', $second->fresh()->effective_date->format('Y-m-d'));
    }

    #[Test]
    public function unrelated_user_updates_leave_schedules_untouched(): void
    {
        $user = User::factory()->create(['hired_date' => '2025-01-20']);
        $schedule = EmployeeSchedule::factory()->create(['user_id' => $user->id, 'effective_date' => '2025-01-20']);

        $user->update(['first_name' => 'Renamed']);

        $this->assertSame('2025-01-20', $schedule->fresh()->effective_date->format('Y-m-d'));
    }

    #[Test]
    public function realign_command_fixes_mismatched_schedules(): void
    {
        $user = User::factory()->create(['hired_date' => '2025-01-20']);
        $schedule = EmployeeSchedule::factory()->create(['user_id' => $user->id]);
        // Bypass the observer to simulate legacy mismatched data.
        EmployeeSchedule::where('id', $schedule->id)->update(['effective_date' => '2026-01-20']);

        $this->artisan('employee-schedules:realign-hired-dates')->assertExitCode(0);

        $this->assertSame('2025-01-20', $schedule->fresh()->effective_date->format('Y-m-d'));
    }

    #[Test]
    public function realign_command_dry_run_changes_nothing(): void
    {
        $user = User::factory()->create(['hired_date' => '2025-01-20']);
        $schedule = EmployeeSchedule::factory()->create(['user_id' => $user->id]);
        EmployeeSchedule::where('id', $schedule->id)->update(['effective_date' => '2026-01-20']);

        $this->artisan('employee-schedules:realign-hired-dates', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('2026-01-20', $schedule->fresh()->effective_date->format('Y-m-d'));
    }
}
