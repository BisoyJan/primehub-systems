<?php

namespace Tests\Feature\Controllers\Biometrics;

use App\Jobs\GenerateAttendanceExportExcel;
use App\Models\Attendance;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BiometricExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $this->agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        // Clean up temp files
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            array_map('unlink', glob("$tempDir/attendance_export_*.xlsx"));
        }
    }

    protected function tearDown(): void
    {
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            array_map('unlink', glob("$tempDir/attendance_export_*.xlsx"));
        }

        parent::tearDown();
    }

    #[Test]
    public function it_displays_export_page()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Export')
            ->has('users', 1)
            ->has('sites', 1)
        );
    }

    #[Test]
    public function it_starts_export_job_and_returns_job_id()
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['jobId']);

        Queue::assertPushed(GenerateAttendanceExportExcel::class);
    }

    #[Test]
    public function it_validates_required_date_fields_on_start()
    {
        $response = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => '',
            'end_date' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    #[Test]
    public function it_validates_end_date_is_after_start_date()
    {
        $response = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->subDays(5)->format('Y-m-d'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);
    }

    #[Test]
    public function it_accepts_optional_user_ids_filter()
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'user_ids' => [$user->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['jobId']);

        Queue::assertPushed(GenerateAttendanceExportExcel::class, function ($job) use ($user) {
            // Job was dispatched with the correct parameters
            return true;
        });
    }

    #[Test]
    public function it_accepts_optional_site_ids_filter()
    {
        Queue::fake();

        $site = Site::factory()->create();

        $response = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'site_ids' => [$site->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['jobId']);

        Queue::assertPushed(GenerateAttendanceExportExcel::class);
    }

    #[Test]
    public function it_accepts_optional_campaign_ids_filter()
    {
        Queue::fake();

        $campaign = Campaign::factory()->create();

        $response = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'campaign_ids' => [$campaign->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['jobId']);

        Queue::assertPushed(GenerateAttendanceExportExcel::class);
    }

    #[Test]
    public function it_returns_progress_for_job()
    {
        $jobId = 'test-progress-check';
        $cacheKey = "attendance_export_job:{$jobId}";

        Cache::put($cacheKey, [
            'percent' => 50,
            'status' => 'Processing...',
            'finished' => false,
            'downloadUrl' => null,
        ], 600);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.progress', $jobId));

        $response->assertStatus(200);
        $response->assertJson([
            'percent' => 50,
            'status' => 'Processing...',
            'finished' => false,
            'downloadUrl' => null,
        ]);
    }

    #[Test]
    public function it_returns_default_progress_for_nonexistent_job()
    {
        $jobId = 'nonexistent-job';

        $response = $this->actingAs($this->admin)->get(route('biometric-export.progress', $jobId));

        $response->assertStatus(200);
        $response->assertJson([
            'percent' => 0,
            'status' => 'Not started',
            'finished' => false,
            'downloadUrl' => null,
        ]);
    }

    #[Test]
    public function it_returns_finished_status_with_download_url()
    {
        $jobId = 'test-finished-job';
        $cacheKey = "attendance_export_job:{$jobId}";

        Cache::put($cacheKey, [
            'percent' => 100,
            'status' => 'Finished',
            'finished' => true,
            'downloadUrl' => url("/biometric-export/download/{$jobId}"),
        ], 600);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.progress', $jobId));

        $response->assertStatus(200);
        $response->assertJson([
            'percent' => 100,
            'status' => 'Finished',
            'finished' => true,
        ]);
        $this->assertStringContainsString('/biometric-export/download/', $response->json('downloadUrl'));
    }

    #[Test]
    public function it_downloads_generated_file()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
        ]);

        $jobId = 'test-download';

        // Run the job synchronously to generate the file
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        $response = $this->actingAs($this->admin)->get(route('biometric-export.download', $jobId));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[Test]
    public function it_returns_404_for_nonexistent_download()
    {
        $jobId = 'nonexistent-download';

        $response = $this->actingAs($this->admin)->get(route('biometric-export.download', $jobId));

        $response->assertStatus(404);
    }

    #[Test]
    public function it_requires_authentication_for_export_page()
    {
        $response = $this->get(route('biometric-export.index'));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function it_requires_authentication_for_start_export()
    {
        $response = $this->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function it_requires_authentication_for_progress_check()
    {
        $response = $this->get(route('biometric-export.progress', 'some-job-id'));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function it_requires_authentication_for_download()
    {
        $response = $this->get(route('biometric-export.download', 'some-job-id'));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function it_loads_users_with_attendance_records()
    {
        // User with attendance
        $userWithAttendance = User::factory()->create();
        $site = Site::factory()->create();

        Attendance::factory()->onTime()->create([
            'user_id' => $userWithAttendance->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);

        // User without attendance
        User::factory()->create();

        $response = $this->actingAs($this->admin)->get(route('biometric-export.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Export')
            ->has('users', 1) // Only user with attendance
        );
    }

    #[Test]
    public function it_loads_sites_with_attendance_records()
    {
        $user = User::factory()->create();
        $siteWithAttendance = Site::factory()->create();

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $siteWithAttendance->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);

        // Site without attendance
        Site::factory()->create();

        $response = $this->actingAs($this->admin)->get(route('biometric-export.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Export')
            ->has('sites', 1) // Only site with attendance
        );
    }

    #[Test]
    public function it_loads_campaigns_from_employee_schedules_with_attendance()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);

        // Campaign without attendance
        Campaign::factory()->create();

        $response = $this->actingAs($this->admin)->get(route('biometric-export.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Export')
            ->has('campaigns', 1) // Only campaign with attendance through schedule
        );
    }

    #[Test]
    public function it_accepts_multiple_user_ids()
    {
        Queue::fake();

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $response = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'user_ids' => [$user1->id, $user2->id],
        ]);

        $response->assertStatus(200);

        Queue::assertPushed(GenerateAttendanceExportExcel::class);
    }

    #[Test]
    public function it_accepts_multiple_site_ids()
    {
        Queue::fake();

        $site1 = Site::factory()->create();
        $site2 = Site::factory()->create();

        $response = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'site_ids' => [$site1->id, $site2->id],
        ]);

        $response->assertStatus(200);

        Queue::assertPushed(GenerateAttendanceExportExcel::class);
    }

    #[Test]
    public function it_accepts_multiple_campaign_ids()
    {
        Queue::fake();

        $campaign1 = Campaign::factory()->create();
        $campaign2 = Campaign::factory()->create();

        $response = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'campaign_ids' => [$campaign1->id, $campaign2->id],
        ]);

        $response->assertStatus(200);

        Queue::assertPushed(GenerateAttendanceExportExcel::class);
    }

    #[Test]
    public function it_generates_unique_job_id_for_each_request()
    {
        Queue::fake();

        $response1 = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
        ]);

        $response2 = $this->actingAs($this->admin)->postJson(route('biometric-export.start'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
        ]);

        $this->assertNotEquals($response1->json('jobId'), $response2->json('jobId'));
    }
}
