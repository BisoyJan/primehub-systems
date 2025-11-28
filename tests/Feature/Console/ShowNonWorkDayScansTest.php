<?php

namespace Tests\Feature\Console;

use App\Models\BiometricRecord;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShowNonWorkDayScansTest extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithSchedule(array $workDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']): User
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        // Normalize work days to lowercase
        $normalizedWorkDays = array_map('strtolower', $workDays);

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00',
            'work_days' => $normalizedWorkDays,
            'is_active' => true,
        ]);

        return $user;
    }

    #[Test]
    public function it_detects_biometric_scans_on_non_work_days(): void
    {
        $user = $this->createUserWithSchedule(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);

        // Find the next Saturday
        $saturday = Carbon::now()->next(Carbon::SATURDAY);

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => $saturday->copy()->setTime(8, 0),
            'record_date' => $saturday->format('Y-m-d'),
        ]);

        $this->artisan('attendance:show-non-work-scans', [
            '--from' => $saturday->copy()->subDays(1)->format('Y-m-d'),
            '--to' => $saturday->copy()->addDays(1)->format('Y-m-d'),
        ])
        ->expectsOutput('⚠ Biometric scans detected on non-scheduled work days:')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_uses_default_date_range_of_last_7_days(): void
    {
        $user = $this->createUserWithSchedule(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);

        $lastSaturday = Carbon::now()->previous(Carbon::SATURDAY);

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => $lastSaturday->copy()->setTime(8, 0),
            'record_date' => $lastSaturday->format('Y-m-d'),
        ]);

        $this->artisan('attendance:show-non-work-scans')
            ->expectsOutputToContain('Checking biometric scans from')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_no_scans_message_when_none_found(): void
    {
        $user = $this->createUserWithSchedule();
        $from = Carbon::now()->subDays(7)->format('Y-m-d');
        $to = Carbon::now()->format('Y-m-d');

        // Create scans only on work days
        $monday = Carbon::now()->previous(Carbon::MONDAY);
        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => $monday->copy()->setTime(8, 0),
            'record_date' => $monday->format('Y-m-d'),
        ]);

        $this->artisan('attendance:show-non-work-scans', [
            '--from' => $from,
            '--to' => $to,
        ])
        ->expectsOutput('✓ No biometric scans found on non-scheduled work days.')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_table_with_scan_details(): void
    {
        $user = $this->createUserWithSchedule(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);

        $saturday = Carbon::now()->next(Carbon::SATURDAY);

        BiometricRecord::factory()->count(2)->create([
            'user_id' => $user->id,
            'datetime' => $saturday->copy()->setTime(8, 0),
            'record_date' => $saturday->format('Y-m-d'),
        ]);

        $this->artisan('attendance:show-non-work-scans', [
            '--from' => $saturday->copy()->subDays(1)->format('Y-m-d'),
            '--to' => $saturday->copy()->addDays(1)->format('Y-m-d'),
        ])
        ->expectsOutputToContain($user->name)
        ->assertExitCode(0);
    }

    #[Test]
    public function it_counts_multiple_scans_on_same_non_work_day(): void
    {
        $user = $this->createUserWithSchedule(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);

        $sunday = Carbon::now()->next(Carbon::SUNDAY);

        // Create 3 scans on Sunday
        BiometricRecord::factory()->count(3)->create([
            'user_id' => $user->id,
            'datetime' => $sunday->copy()->setTime(8, 0),
            'record_date' => $sunday->format('Y-m-d'),
        ]);

        $this->artisan('attendance:show-non-work-scans', [
            '--from' => $sunday->copy()->subDays(1)->format('Y-m-d'),
            '--to' => $sunday->copy()->addDays(1)->format('Y-m-d'),
        ])
        ->expectsOutputToContain('3')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_total_count_of_non_work_day_scans(): void
    {
        $user = $this->createUserWithSchedule(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);

        $saturday = Carbon::now()->next(Carbon::SATURDAY);
        $sunday = $saturday->copy()->addDay();

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => $saturday->copy()->setTime(8, 0),
            'record_date' => $saturday->format('Y-m-d'),
        ]);

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => $sunday->copy()->setTime(8, 0),
            'record_date' => $sunday->format('Y-m-d'),
        ]);

        $this->artisan('attendance:show-non-work-scans', [
            '--from' => $saturday->copy()->subDays(1)->format('Y-m-d'),
            '--to' => $sunday->copy()->addDays(1)->format('Y-m-d'),
        ])
        ->expectsOutputToContain('Total: 2 date(s) with non-work day scans')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_helpful_context_message(): void
    {
        $user = $this->createUserWithSchedule();

        $saturday = Carbon::now()->next(Carbon::SATURDAY);

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => $saturday->copy()->setTime(8, 0),
            'record_date' => $saturday->format('Y-m-d'),
        ]);

        $this->artisan('attendance:show-non-work-scans', [
            '--from' => $saturday->copy()->subDays(1)->format('Y-m-d'),
            '--to' => $saturday->copy()->addDays(1)->format('Y-m-d'),
        ])
        ->expectsOutputToContain('These scans were not processed into attendance records')
        ->expectsOutputToContain('Review if these represent overtime, special work days, or data issues')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_skips_users_without_active_schedule(): void
    {
        $user = User::factory()->create();

        $saturday = Carbon::now()->next(Carbon::SATURDAY);

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => $saturday->copy()->setTime(8, 0),
            'record_date' => $saturday->format('Y-m-d'),
        ]);

        $this->artisan('attendance:show-non-work-scans', [
            '--from' => $saturday->copy()->subDays(1)->format('Y-m-d'),
            '--to' => $saturday->copy()->addDays(1)->format('Y-m-d'),
        ])
        ->expectsOutput('✓ No biometric scans found on non-scheduled work days.')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_multiple_users_with_different_schedules(): void
    {
        // User 1: Mon-Fri
        $user1 = $this->createUserWithSchedule(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);

        // User 2: Works on Saturday too
        $user2 = $this->createUserWithSchedule(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']);

        $saturday = Carbon::now()->next(Carbon::SATURDAY);

        // Both scan on Saturday
        BiometricRecord::factory()->create([
            'user_id' => $user1->id,
            'datetime' => $saturday->copy()->setTime(8, 0),
            'record_date' => $saturday->format('Y-m-d'),
        ]);

        BiometricRecord::factory()->create([
            'user_id' => $user2->id,
            'datetime' => $saturday->copy()->setTime(8, 0),
            'record_date' => $saturday->format('Y-m-d'),
        ]);

        $this->artisan('attendance:show-non-work-scans', [
            '--from' => $saturday->copy()->subDays(1)->format('Y-m-d'),
            '--to' => $saturday->copy()->addDays(1)->format('Y-m-d'),
        ])
        ->expectsOutputToContain($user1->name) // Only user1 should appear (Saturday is non-work day)
        ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_date_range_in_output(): void
    {
        $from = Carbon::now()->subDays(7)->format('Y-m-d');
        $to = Carbon::now()->format('Y-m-d');

        $this->artisan('attendance:show-non-work-scans', [
            '--from' => $from,
            '--to' => $to,
        ])
        ->expectsOutput("Checking biometric scans from {$from} to {$to}")
        ->assertExitCode(0);
    }

    #[Test]
    public function it_returns_zero_exit_code_on_success(): void
    {
        $this->artisan('attendance:show-non-work-scans')
            ->assertExitCode(0);
    }
}
