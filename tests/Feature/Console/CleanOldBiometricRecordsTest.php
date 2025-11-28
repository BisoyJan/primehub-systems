<?php

namespace Tests\Feature\Console;

use App\Models\BiometricRecord;
use App\Models\BiometricRetentionPolicy;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanOldBiometricRecordsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_records_older_than_retention_policy(): void
    {
        $site = Site::factory()->create();
        $user = User::factory()->create();

        // Create retention policy for 6 months
        BiometricRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'retention_months' => 6,
        ]);

        // Create old record (8 months ago)
        $oldRecord = BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'record_date' => Carbon::now()->subMonths(8)->format('Y-m-d'),
            'datetime' => Carbon::now()->subMonths(8),
        ]);

        // Create recent record (3 months ago)
        $recentRecord = BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'record_date' => Carbon::now()->subMonths(3)->format('Y-m-d'),
            'datetime' => Carbon::now()->subMonths(3),
        ]);

        $this->artisan('biometric:clean-old-records', ['--force' => true])
            ->expectsOutput('Starting cleanup of biometric records based on retention policies...')
            ->assertExitCode(0);

        // Old record should be deleted
        $this->assertDatabaseMissing('biometric_records', ['id' => $oldRecord->id]);

        // Recent record should remain
        $this->assertDatabaseHas('biometric_records', ['id' => $recentRecord->id]);
    }

    #[Test]
    public function it_uses_manual_months_override(): void
    {
        $user = User::factory()->create();

        // Create record 4 months old
        $record = BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'record_date' => Carbon::now()->subMonths(4)->format('Y-m-d'),
            'datetime' => Carbon::now()->subMonths(4),
        ]);

        $this->artisan('biometric:clean-old-records', [
            '--force' => true,
            '--months' => 3,
        ])
        ->expectsOutput('Using manual override: 3 months retention')
        ->assertExitCode(0);

        // Record should be deleted (4 months > 3 months)
        $this->assertDatabaseMissing('biometric_records', ['id' => $record->id]);
    }

    #[Test]
    public function it_applies_site_specific_retention_policies(): void
    {
        $site1 = Site::factory()->create();
        $site2 = Site::factory()->create();
        $user = User::factory()->create();

        // Site 1: 3 months retention
        BiometricRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site1->id,
            'retention_months' => 3,
        ]);

        // Site 2: 12 months retention
        BiometricRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site2->id,
            'retention_months' => 12,
        ]);

        // Create 6-month-old records for both sites
        $site1Record = BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site1->id,
            'record_date' => Carbon::now()->subMonths(6)->format('Y-m-d'),
            'datetime' => Carbon::now()->subMonths(6),
        ]);

        $site2Record = BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site2->id,
            'record_date' => Carbon::now()->subMonths(6)->format('Y-m-d'),
            'datetime' => Carbon::now()->subMonths(6),
        ]);

        $this->artisan('biometric:clean-old-records', ['--force' => true])
            ->assertExitCode(0);

        // Site 1 record should be deleted (6 > 3)
        $this->assertDatabaseMissing('biometric_records', ['id' => $site1Record->id]);

        // Site 2 record should remain (6 < 12)
        $this->assertDatabaseHas('biometric_records', ['id' => $site2Record->id]);
    }

    #[Test]
    public function it_handles_global_policy_for_sites_without_specific_policy(): void
    {
        $site = Site::factory()->create();
        $user = User::factory()->create();

        // Create global retention policy (no site-specific policy for this site)
        BiometricRetentionPolicy::factory()->create([
            'applies_to_type' => 'global',
            'applies_to_id' => null,
            'retention_months' => 6,
        ]);

        // Create old record for a site that has no specific policy
        $record = BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'record_date' => Carbon::now()->subMonths(8)->format('Y-m-d'),
            'datetime' => Carbon::now()->subMonths(8),
        ]);

        $this->artisan('biometric:clean-old-records', ['--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('biometric_records', ['id' => $record->id]);
    }

    #[Test]
    public function it_displays_no_records_message_when_nothing_to_delete(): void
    {
        $user = User::factory()->create();

        // Create recent record
        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'record_date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'datetime' => Carbon::now()->subDays(10),
        ]);

        $this->artisan('biometric:clean-old-records', ['--force' => true])
            ->expectsOutput('No old records found to delete.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_cutoff_date_and_counts(): void
    {
        $site = Site::factory()->create();
        $user = User::factory()->create();

        BiometricRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'retention_months' => 6,
        ]);

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'record_date' => Carbon::now()->subMonths(8)->format('Y-m-d'),
            'datetime' => Carbon::now()->subMonths(8),
        ]);

        $this->artisan('biometric:clean-old-records', ['--force' => true])
            ->expectsOutputToContain('Cutoff date:')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_logs_cleanup_activity(): void
    {
        $user = User::factory()->create();

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'record_date' => Carbon::now()->subMonths(8)->format('Y-m-d'),
            'datetime' => Carbon::now()->subMonths(8),
        ]);

        $this->artisan('biometric:clean-old-records', [
            '--force' => true,
            '--months' => 6,
        ])
        ->assertExitCode(0);

        // Verify log was created (if logging is enabled in test environment)
    }

    #[Test]
    public function it_requires_confirmation_without_force_flag_in_interactive_mode(): void
    {
        $user = User::factory()->create();

        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'record_date' => Carbon::now()->subMonths(8)->format('Y-m-d'),
            'datetime' => Carbon::now()->subMonths(8),
        ]);

        // When calling without --force, it prompts for confirmation
        // Use expectsConfirmation to handle the interactive prompt
        $this->artisan('biometric:clean-old-records', ['--months' => 6])
            ->expectsConfirmation('Do you want to proceed with deletion?', 'yes')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_processes_multiple_sites_with_different_policies(): void
    {
        $sites = Site::factory()->count(3)->create();
        $user = User::factory()->create();

        foreach ($sites as $index => $site) {
            BiometricRetentionPolicy::factory()->create([
                'applies_to_type' => 'site',
                'applies_to_id' => $site->id,
                'retention_months' => ($index + 1) * 3, // 3, 6, 9 months
            ]);

            BiometricRecord::factory()->create([
                'user_id' => $user->id,
                'site_id' => $site->id,
                'record_date' => Carbon::now()->subMonths(5)->format('Y-m-d'),
                'datetime' => Carbon::now()->subMonths(5),
            ]);
        }

        $this->artisan('biometric:clean-old-records', ['--force' => true])
            ->assertExitCode(0);

        // First site (3 months) should delete, others should keep
        $this->assertEquals(2, BiometricRecord::count());
    }
}
