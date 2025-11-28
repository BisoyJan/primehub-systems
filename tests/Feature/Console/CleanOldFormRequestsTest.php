<?php

namespace Tests\Feature\Console;

use App\Models\EmployeeSchedule;
use App\Models\FormRequestRetentionPolicy;
use App\Models\ItConcern;
use App\Models\LeaveRequest;
use App\Models\MedicationRequest;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanOldFormRequestsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_old_leave_requests_based_on_retention_policy(): void
    {
        $site = Site::factory()->create();
        $user = User::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        // Create retention policy for leave requests (6 months)
        FormRequestRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'form_type' => 'leave_request',
            'retention_months' => 6,
        ]);

        // Old leave request (8 months ago)
        $oldRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subMonths(8),
        ]);

        // Recent leave request (3 months ago)
        $recentRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subMonths(3),
        ]);

        $this->artisan('form-request:clean-old-records', ['--force' => true])
            ->expectsOutput('Starting cleanup of form requests based on retention policies...')
            ->expectsOutput('Processing form type: leave_request')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('leave_requests', ['id' => $oldRequest->id]);
        $this->assertDatabaseHas('leave_requests', ['id' => $recentRequest->id]);
    }

    #[Test]
    public function it_deletes_old_it_concerns_based_on_retention_policy(): void
    {
        $site = Site::factory()->create();

        FormRequestRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'form_type' => 'it_concern',
            'retention_months' => 6,
        ]);

        // Old IT concern with site_id
        $oldConcern = ItConcern::factory()->create([
            'site_id' => $site->id,
            'created_at' => Carbon::now()->subMonths(8),
        ]);

        // Recent IT concern
        $recentConcern = ItConcern::factory()->create([
            'site_id' => $site->id,
            'created_at' => Carbon::now()->subMonths(3),
        ]);

        $this->artisan('form-request:clean-old-records', ['--force' => true])
            ->expectsOutput('Processing form type: it_concern')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('it_concerns', ['id' => $oldConcern->id]);
        $this->assertDatabaseHas('it_concerns', ['id' => $recentConcern->id]);
    }

    #[Test]
    public function it_deletes_old_medication_requests_based_on_retention_policy(): void
    {
        $site = Site::factory()->create();
        $user = User::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        FormRequestRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'form_type' => 'medication_request',
            'retention_months' => 6,
        ]);

        $oldRequest = MedicationRequest::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subMonths(8),
        ]);

        $recentRequest = MedicationRequest::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subMonths(3),
        ]);

        $this->artisan('form-request:clean-old-records', ['--force' => true])
            ->expectsOutput('Processing form type: medication_request')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('medication_requests', ['id' => $oldRequest->id]);
        $this->assertDatabaseHas('medication_requests', ['id' => $recentRequest->id]);
    }

    #[Test]
    public function it_applies_site_specific_retention_policies(): void
    {
        $site1 = Site::factory()->create();
        $site2 = Site::factory()->create();

        // Site 1: 3 months retention
        FormRequestRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site1->id,
            'form_type' => 'it_concern',
            'retention_months' => 3,
        ]);

        // Site 2: 12 months retention
        FormRequestRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site2->id,
            'form_type' => 'it_concern',
            'retention_months' => 12,
        ]);

        // Create 6-month-old concerns for both sites
        $site1Concern = ItConcern::factory()->create([
            'site_id' => $site1->id,
            'created_at' => Carbon::now()->subMonths(6),
        ]);

        $site2Concern = ItConcern::factory()->create([
            'site_id' => $site2->id,
            'created_at' => Carbon::now()->subMonths(6),
        ]);

        $this->artisan('form-request:clean-old-records', ['--force' => true])
            ->assertExitCode(0);

        // Site 1 concern should be deleted (6 > 3)
        $this->assertDatabaseMissing('it_concerns', ['id' => $site1Concern->id]);

        // Site 2 concern should remain (6 < 12)
        $this->assertDatabaseHas('it_concerns', ['id' => $site2Concern->id]);
    }

    #[Test]
    public function it_handles_global_retention_policy(): void
    {
        $user = User::factory()->create();

        // User with no active schedule (no site)
        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => null,
            'is_active' => true,
        ]);

        // Global retention policy
        FormRequestRetentionPolicy::factory()->create([
            'applies_to_type' => 'global',
            'applies_to_id' => null,
            'form_type' => 'leave_request',
            'retention_months' => 6,
        ]);

        $oldRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subMonths(8),
        ]);

        $this->artisan('form-request:clean-old-records', ['--force' => true])
            ->expectsOutputToContain('No Site (Global)')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('leave_requests', ['id' => $oldRequest->id]);
    }

    #[Test]
    public function it_displays_dry_run_output_without_deleting(): void
    {
        $site = Site::factory()->create();
        $user = User::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        FormRequestRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'form_type' => 'leave_request',
            'retention_months' => 6,
        ]);

        $oldRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subMonths(8),
        ]);

        $this->artisan('form-request:clean-old-records', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);

        // Record should still exist
        $this->assertDatabaseHas('leave_requests', ['id' => $oldRequest->id]);
    }

    #[Test]
    public function it_displays_no_records_message_when_nothing_to_delete(): void
    {
        $user = User::factory()->create();

        // Create recent request
        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subDays(10),
        ]);

        $this->artisan('form-request:clean-old-records', ['--force' => true])
            ->expectsOutput('No old records found to delete.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_processes_all_form_types(): void
    {
        $site = Site::factory()->create();
        $user = User::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        // Create policies for all form types
        foreach (['leave_request', 'it_concern', 'medication_request'] as $formType) {
            FormRequestRetentionPolicy::factory()->create([
                'applies_to_type' => 'site',
                'applies_to_id' => $site->id,
                'form_type' => $formType,
                'retention_months' => 6,
            ]);
        }

        $this->artisan('form-request:clean-old-records', ['--force' => true])
            ->expectsOutput('Processing form type: leave_request')
            ->expectsOutput('Processing form type: it_concern')
            ->expectsOutput('Processing form type: medication_request')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_logs_cleanup_activity(): void
    {
        $site = Site::factory()->create();
        $user = User::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        FormRequestRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'form_type' => 'leave_request',
            'retention_months' => 6,
        ]);

        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subMonths(8),
        ]);

        $this->artisan('form-request:clean-old-records', ['--force' => true])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_total_deleted_count(): void
    {
        $site = Site::factory()->create();
        $user = User::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        FormRequestRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'form_type' => 'leave_request',
            'retention_months' => 6,
        ]);

        LeaveRequest::factory()->count(3)->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subMonths(8),
        ]);

        $this->artisan('form-request:clean-old-records', ['--force' => true])
            ->expectsOutputToContain('deleted')
            ->assertExitCode(0);
    }
}
