<?php

namespace Tests\Feature\Controllers\Biometrics;

use App\Models\Attendance;
use App\Models\BiometricRecord;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BiometricReprocessingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $agent;
    protected $processorMock;

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

        $this->processorMock = Mockery::mock(AttendanceProcessor::class);
        $this->app->instance(AttendanceProcessor::class, $this->processorMock);
    }

    #[Test]
    public function it_displays_reprocessing_page()
    {
        BiometricRecord::factory()->create(['datetime' => now()]);

        $response = $this->actingAs($this->admin)->get(route('biometric-reprocessing.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Reprocessing')
            ->has('stats.total_records')
            ->has('stats.oldest_record')
            ->has('stats.newest_record')
        );
    }

    #[Test]
    public function it_previews_reprocessing()
    {
        $user = User::factory()->create();
        $date = now()->format('Y-m-d');
        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'record_date' => $date,
        ]);

        $response = $this->actingAs($this->admin)->post(route('biometric-reprocessing.preview'), [
            'start_date' => $date,
            'end_date' => $date,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('preview');
        $preview = session('preview');
        $this->assertEquals(1, $preview['total_records']);
        $this->assertEquals(1, $preview['affected_users']);
    }

    #[Test]
    public function it_executes_reprocessing()
    {
        $user = User::factory()->create();
        $date = now()->format('Y-m-d');
        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'record_date' => $date,
            'datetime' => now(),
        ]);

        $this->processorMock
            ->shouldReceive('reprocessEmployeeRecords')
            ->once()
            ->andReturn(['records_processed' => 1]);

        $response = $this->actingAs($this->admin)->post(route('biometric-reprocessing.reprocess'), [
            'start_date' => $date,
            'end_date' => $date,
            'delete_existing' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionHas('results');
        $results = session('results');
        $this->assertEquals(1, $results['processed']);
    }

    #[Test]
    public function it_fixes_statuses()
    {
        $user = User::factory()->create();
        $date = now()->format('Y-m-d');

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00',
        ]);

        // Create attendance with missing time out (failed_bio_out)
        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $date,
            'actual_time_in' => $date . ' 08:05:00',
            'actual_time_out' => null,
            'status' => 'on_time', // Incorrect status
            'admin_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)->post(route('biometric-reprocessing.fix-statuses'), [
            'start_date' => Carbon::parse($date)->subDay()->format('Y-m-d'),
            'end_date' => Carbon::parse($date)->addDay()->format('Y-m-d'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('fixResults');

        $attendance->refresh();
        $this->assertEquals('tardy', $attendance->status);
        $this->assertEquals('failed_bio_out', $attendance->secondary_status);
    }
}
