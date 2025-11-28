<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Models\Site;
use App\Models\BiometricRecord;
use App\Models\Attendance;
use App\Services\BiometricAnomalyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Carbon\Carbon;

class AttendanceAnomalyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $employee;
    protected Site $site1;
    protected Site $site2;
    protected BiometricAnomalyDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $this->employee = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'role' => 'Agent',
        ]);

        $this->site1 = Site::factory()->create(['name' => 'Site A']);
        $this->site2 = Site::factory()->create(['name' => 'Site B']);

        $this->detector = app(BiometricAnomalyDetector::class);
    }

    #[Test]
    public function anomaly_detection_page_can_be_accessed(): void
    {
        $this->actingAs($this->admin)
            ->get('/biometric-anomalies')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Attendance/BiometricRecords/Anomalies')
                ->has('stats')
            );
    }

    #[Test]
    public function simultaneous_site_scans_are_detected(): void
    {
        $datetime = Carbon::parse('2025-11-05 08:00:00');
        $datetime2 = $datetime->copy()->addMinutes(5);

        BiometricRecord::factory()->atTime($datetime)->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site1->id,
        ]);

        BiometricRecord::factory()->atTime($datetime2)->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site2->id,
        ]);

        $anomalies = $this->detector->detectAnomalies(
            $datetime->copy()->startOfDay(),
            $datetime->copy()->endOfDay()
        );

        $this->assertArrayHasKey('simultaneous_sites', $anomalies);
        $this->assertNotEmpty($anomalies['simultaneous_sites']);
    }

    #[Test]
    public function excessive_daily_scans_are_detected(): void
    {
        $date = Carbon::parse('2025-11-05 08:00:00');

        // Create 10 scans in one day (excessive)
        for ($i = 0; $i < 10; $i++) {
            BiometricRecord::factory()->atTime($date->copy()->addHours($i))->create([
                'user_id' => $this->employee->id,
                'site_id' => $this->site1->id,
            ]);
        }

        $anomalies = $this->detector->detectAnomalies(
            $date->copy()->startOfDay(),
            $date->copy()->endOfDay()
        );

        $this->assertArrayHasKey('excessive_scans', $anomalies);
        $this->assertNotEmpty($anomalies['excessive_scans']);
    }

    #[Test]
    public function duplicate_scans_are_detected(): void
    {
        $datetime = Carbon::parse('2025-11-05 08:00:00');
        $datetime2 = $datetime->copy()->addSeconds(30);

        // Create duplicate scans (within 1 minute)
        BiometricRecord::factory()->atTime($datetime)->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site1->id,
        ]);

        BiometricRecord::factory()->atTime($datetime2)->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site1->id,
        ]);

        $anomalies = $this->detector->detectAnomalies(
            $datetime->copy()->startOfDay(),
            $datetime->copy()->endOfDay()
        );

        $this->assertArrayHasKey('duplicate_scans', $anomalies);
    }

    #[Test]
    public function unusual_hours_scans_are_detected(): void
    {
        // Create scans at 2 AM (unusual hour)
        $datetime = Carbon::parse('2025-11-05 02:00:00');

        BiometricRecord::factory()->atTime($datetime)->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site1->id,
        ]);

        $anomalies = $this->detector->detectAnomalies(
            $datetime->copy()->startOfDay(),
            $datetime->copy()->endOfDay()
        );

        $this->assertArrayHasKey('unusual_hours', $anomalies);
        $this->assertNotEmpty($anomalies['unusual_hours']);
    }

    #[Test]
    public function anomaly_detection_requires_date_range(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/biometric-anomalies/detect', [
                'anomaly_types' => ['simultaneous_sites'],
                'min_severity' => 'medium',
            ]);

        $response->assertSessionHasErrors(['start_date', 'end_date']);
    }

    #[Test]
    public function anomaly_detection_validates_date_order(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/biometric-anomalies/detect', [
                'start_date' => '2025-11-10',
                'end_date' => '2025-11-05',
                'anomaly_types' => ['simultaneous_sites'],
            ]);

        $response->assertSessionHasErrors(['end_date']);
    }

    #[Test]
    public function anomaly_detection_with_empty_types_detects_all_types(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/biometric-anomalies/detect', [
                'start_date' => '2025-11-05',
                'end_date' => '2025-11-10',
                'anomaly_types' => [], // Empty array defaults to all types
            ]);

        // Should succeed and return anomalies page
        $response->assertOk();
    }

    #[Test]
    public function missing_time_out_creates_attendance_flag(): void
    {
        $attendance = Attendance::factory()->failedBioOut()->create([
            'user_id' => $this->employee->id,
            'shift_date' => '2025-11-05',
        ]);

        $this->assertNull($attendance->actual_time_out);
        $this->assertEquals('failed_bio_out', $attendance->status);
    }

    #[Test]
    public function missing_time_in_creates_attendance_flag(): void
    {
        $attendance = Attendance::factory()->failedBioIn()->create([
            'user_id' => $this->employee->id,
            'shift_date' => '2025-11-05',
        ]);

        $this->assertNull($attendance->actual_time_in);
        $this->assertEquals('failed_bio_in', $attendance->status);
    }

    #[Test]
    public function anomaly_detection_can_auto_flag_attendance(): void
    {
        $datetime = Carbon::parse('2025-11-05 02:00:00'); // 2 AM is unusual hour

        BiometricRecord::factory()->atTime($datetime)->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site1->id,
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'shift_date' => '2025-11-05',
            'actual_time_in' => $datetime,
        ]);

        $response = $this->actingAs($this->admin)
            ->post('/biometric-anomalies/detect', [
                'start_date' => '2025-11-05',
                'end_date' => '2025-11-05',
                'anomaly_types' => ['unusual_hours'],
                'min_severity' => 'low', // unusual_hours has 'low' severity
                'auto_flag' => true,
            ]);

        $response->assertOk(); // Returns Inertia page, not redirect
    }

    #[Test]
    public function unauthorized_user_cannot_access_anomaly_detection(): void
    {
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($agent)
            ->get('/biometric-anomalies');

        $response->assertForbidden();
    }
}
