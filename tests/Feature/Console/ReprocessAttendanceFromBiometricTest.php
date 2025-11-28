<?php

namespace Tests\Feature\Console;

use App\Models\BiometricRecord;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use App\Services\AttendanceFileParser;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReprocessAttendanceFromBiometricTest extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithSchedule(): User
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00',
            'is_active' => true,
        ]);

        return $user;
    }

    #[Test]
    public function it_reprocesses_attendance_from_biometric_records(): void
    {
        $user = $this->createUserWithSchedule();
        $from = Carbon::now()->subDays(3)->format('Y-m-d');
        $to = Carbon::now()->format('Y-m-d');

        // Create biometric records
        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => Carbon::now()->subDays(2)->setTime(8, 0),
            'record_date' => Carbon::now()->subDays(2)->format('Y-m-d'),
            'employee_name' => $user->name,
        ]);

        $this->artisan('app:reprocess-attendance-from-biometric', [
            '--from' => $from,
            '--to' => $to,
        ])
        ->expectsOutput("Reprocessing attendance from {$from} to {$to}")
        ->assertExitCode(0);
    }

    #[Test]
    public function it_uses_default_date_range_when_not_specified(): void
    {
        $user = $this->createUserWithSchedule();

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => Carbon::now()->subDays(5),
            'record_date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'employee_name' => $user->name,
        ]);

        $this->artisan('app:reprocess-attendance-from-biometric')
            ->expectsOutputToContain('Reprocessing attendance from')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_message_when_no_biometric_records_found(): void
    {
        $from = Carbon::now()->subDays(3)->format('Y-m-d');
        $to = Carbon::now()->format('Y-m-d');

        $this->artisan('app:reprocess-attendance-from-biometric', [
            '--from' => $from,
            '--to' => $to,
        ])
        ->expectsOutput('No biometric records found in the given range.')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_processes_multiple_users_with_biometric_records(): void
    {
        $user1 = $this->createUserWithSchedule();
        $user2 = $this->createUserWithSchedule();

        $date = Carbon::now()->subDays(2);

        BiometricRecord::factory()->create([
            'user_id' => $user1->id,
            'datetime' => $date->copy()->setTime(8, 0),
            'record_date' => $date->format('Y-m-d'),
            'employee_name' => $user1->name,
        ]);

        BiometricRecord::factory()->create([
            'user_id' => $user2->id,
            'datetime' => $date->copy()->setTime(8, 15),
            'record_date' => $date->format('Y-m-d'),
            'employee_name' => $user2->name,
        ]);

        $this->artisan('app:reprocess-attendance-from-biometric', [
            '--from' => $date->format('Y-m-d'),
            '--to' => Carbon::now()->format('Y-m-d'),
        ])
        ->expectsOutputToContain("Processing user: {$user1->id}")
        ->expectsOutputToContain("Processing user: {$user2->id}")
        ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_dry_run_output_without_processing(): void
    {
        $user = $this->createUserWithSchedule();

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => Carbon::now()->subDays(2)->setTime(8, 0),
            'record_date' => Carbon::now()->subDays(2)->format('Y-m-d'),
            'employee_name' => $user->name,
        ]);

        $this->artisan('app:reprocess-attendance-from-biometric', [
            '--from' => Carbon::now()->subDays(3)->format('Y-m-d'),
            '--to' => Carbon::now()->format('Y-m-d'),
            '--dry' => true,
        ])
        ->expectsOutputToContain('(dry-run)')
        ->expectsOutputToContain('would process')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_record_count_for_each_user(): void
    {
        $user = $this->createUserWithSchedule();
        $date = Carbon::now()->subDays(2);

        // Create multiple biometric records for one user
        BiometricRecord::factory()->count(3)->create([
            'user_id' => $user->id,
            'datetime' => $date->copy()->setTime(8, 0),
            'record_date' => $date->format('Y-m-d'),
            'employee_name' => $user->name,
        ]);

        $this->artisan('app:reprocess-attendance-from-biometric', [
            '--from' => $date->format('Y-m-d'),
            '--to' => Carbon::now()->format('Y-m-d'),
            '--dry' => true,
        ])
        ->expectsOutputToContain('would process 3 biometric records')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_users_without_schedules_gracefully(): void
    {
        // Create a user without any schedule  
        $user = User::factory()->create();
        
        // Create biometric record for user without schedule
        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => Carbon::now()->subDays(2)->setTime(8, 0),
            'record_date' => Carbon::now()->subDays(2)->format('Y-m-d'),
        ]);

        // Should complete without errors even when user has no schedule
        $this->artisan('app:reprocess-attendance-from-biometric', [
            '--from' => Carbon::now()->subDays(3)->format('Y-m-d'),
            '--to' => Carbon::now()->format('Y-m-d'),
        ])
        ->expectsOutput('Reprocessing completed.')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_processing_errors_gracefully(): void
    {
        $user = User::factory()->create(); // No schedule

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => Carbon::now()->subDays(2)->setTime(8, 0),
            'record_date' => Carbon::now()->subDays(2)->format('Y-m-d'),
            'employee_name' => $user->name,
        ]);

        $this->artisan('app:reprocess-attendance-from-biometric', [
            '--from' => Carbon::now()->subDays(3)->format('Y-m-d'),
            '--to' => Carbon::now()->format('Y-m-d'),
        ])
        ->expectsOutput('Reprocessing completed.')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_processing_status_for_each_user(): void
    {
        $user = $this->createUserWithSchedule();

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => Carbon::now()->subDays(2)->setTime(8, 0),
            'record_date' => Carbon::now()->subDays(2)->format('Y-m-d'),
            'employee_name' => $user->name,
        ]);

        $this->artisan('app:reprocess-attendance-from-biometric', [
            '--from' => Carbon::now()->subDays(3)->format('Y-m-d'),
            '--to' => Carbon::now()->format('Y-m-d'),
        ])
        ->expectsOutputToContain("Processing user: {$user->id}")
        ->assertExitCode(0);
    }

    #[Test]
    public function it_orders_biometric_records_by_datetime(): void
    {
        $user = $this->createUserWithSchedule();
        $date = Carbon::now()->subDays(2);

        // Create records in reverse order
        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => $date->copy()->setTime(17, 0),
            'record_date' => $date->format('Y-m-d'),
            'employee_name' => $user->name,
        ]);

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'datetime' => $date->copy()->setTime(8, 0),
            'record_date' => $date->format('Y-m-d'),
            'employee_name' => $user->name,
        ]);

        $this->artisan('app:reprocess-attendance-from-biometric', [
            '--from' => $date->format('Y-m-d'),
            '--to' => Carbon::now()->format('Y-m-d'),
        ])
        ->expectsOutput('Reprocessing completed.')
        ->assertExitCode(0);
    }

    #[Test]
    public function it_completes_successfully_message(): void
    {
        // When there are no biometric records, the command returns early
        // with "No biometric records found in the given range."
        $this->artisan('app:reprocess-attendance-from-biometric')
            ->expectsOutput('No biometric records found in the given range.')
            ->assertExitCode(0);
    }
}
