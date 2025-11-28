<?php

namespace Tests\Feature\Controllers\Biometrics;

use App\Models\Attendance;
use App\Models\BiometricRecord;
use App\Models\User;
use App\Services\BiometricAnomalyDetector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BiometricAnomalyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $agent;
    protected $detectorMock;

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

        $this->detectorMock = Mockery::mock(BiometricAnomalyDetector::class);
        $this->app->instance(BiometricAnomalyDetector::class, $this->detectorMock);
    }

    #[Test]
    public function it_displays_anomaly_detection_page()
    {
        BiometricRecord::factory()->create(['datetime' => now()]);

        $response = $this->actingAs($this->admin)->get(route('biometric-anomalies.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Anomalies')
            ->has('stats.total_records')
            ->has('stats.oldest_record')
            ->has('stats.newest_record')
        );
    }

    #[Test]
    public function it_detects_anomalies()
    {
        $startDate = now()->subDays(7);
        $endDate = now();

        $mockAnomalies = [
            'simultaneous_sites' => [
                [
                    'severity' => 'high',
                    'description' => 'Simultaneous login',
                    'user_id' => 1,
                    'user_name' => 'John Doe',
                    'minutes_apart' => 5,
                    'record_1' => ['id' => 1, 'datetime' => now(), 'site' => 'Site A'],
                    'record_2' => ['id' => 2, 'datetime' => now(), 'site' => 'Site B'],
                ]
            ]
        ];

        $this->detectorMock
            ->shouldReceive('detectAnomalies')
            ->once()
            ->andReturn($mockAnomalies);

        $response = $this->actingAs($this->admin)->post(route('biometric-anomalies.detect'), [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Anomalies')
            ->has('results.anomalies', 1)
            ->where('results.anomalies.0.type', 'simultaneous_sites')
            ->where('results.anomalies.0.severity', 'high')
        );
    }

    #[Test]
    public function it_filters_anomalies_by_severity()
    {
        $mockAnomalies = [
            'simultaneous_sites' => [
                [
                    'severity' => 'low',
                    'description' => 'Low severity',
                    'user_id' => 1,
                    'user_name' => 'John Doe',
                    'minutes_apart' => 5,
                    'record_1' => ['id' => 1, 'datetime' => now(), 'site' => 'Site A'],
                    'record_2' => ['id' => 2, 'datetime' => now(), 'site' => 'Site B'],
                ]
            ]
        ];

        $this->detectorMock
            ->shouldReceive('detectAnomalies')
            ->once()
            ->andReturn($mockAnomalies);

        $response = $this->actingAs($this->admin)->post(route('biometric-anomalies.detect'), [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'min_severity' => 'medium', // Should filter out 'low'
        ]);

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->has('results.anomalies', 0)
        );
    }

    #[Test]
    public function it_auto_flags_high_severity_anomalies()
    {
        $user = User::factory()->create();
        $date = now()->format('Y-m-d');
        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $date,
            'admin_verified' => false,
        ]);

        $mockAnomalies = [
            'simultaneous_sites' => [
                [
                    'severity' => 'high',
                    'description' => 'High severity',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'minutes_apart' => 5,
                    'record_1' => ['id' => 1, 'datetime' => now(), 'site' => 'Site A'],
                    'record_2' => ['id' => 2, 'datetime' => now(), 'site' => 'Site B'],
                ]
            ]
        ];

        $this->detectorMock
            ->shouldReceive('detectAnomalies')
            ->once()
            ->andReturn($mockAnomalies);

        $response = $this->actingAs($this->admin)->post(route('biometric-anomalies.detect'), [
            'start_date' => $date,
            'end_date' => $date,
            'auto_flag' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'shift_date' => $date,
        ]);

        // Verify the note was appended
        $attendance = Attendance::where('user_id', $user->id)->first();
        $this->assertStringContainsString('[AUTO-FLAGGED]', $attendance->verification_notes);
    }
}
