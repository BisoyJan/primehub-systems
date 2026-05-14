<?php

namespace Tests\Feature\AttendancePoint;

use App\Models\AttendancePoint;
use App\Models\GbroAnomalyLog;
use App\Models\User;
use App\Services\AttendancePoint\GbroAnomalyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for the GBRO anomaly detector + repair orchestrator.
 *
 * The service composes `AttendancePointMaintenanceService::fixAnomalies()` and
 * `GbroCalculationService::cascadeRecalculateGbro()` and persists findings to
 * the `gbro_anomaly_logs` table, so these tests assert detection rows + the
 * persisted audit trail rather than re-validating the underlying repairs.
 */
class GbroAnomalyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected GbroAnomalyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->service = app(GbroAnomalyService::class);
    }

    #[Test]
    public function detect_returns_empty_collection_when_no_drift(): void
    {
        AttendancePoint::factory()
            ->forUser($this->user)
            ->onDate(now()->subDays(10))
            ->tardy()
            ->create();

        $this->assertCount(0, $this->service->detect());
    }

    #[Test]
    public function detect_reports_stale_pending_gbro(): void
    {
        AttendancePoint::factory()
            ->forUser($this->user)
            ->onDate(now()->subDays(70))
            ->tardy()
            ->create([
                'eligible_for_gbro' => true,
                'gbro_expires_at' => now()->subDay(),
                'is_expired' => false,
                'is_excused' => false,
            ]);

        $found = $this->service->detect()->where('type', 'STALE_PENDING_GBRO');
        $this->assertCount(1, $found);
    }

    #[Test]
    public function detect_reports_orphan_gbro_date(): void
    {
        AttendancePoint::factory()
            ->forUser($this->user)
            ->onDate(now()->subDays(5))
            ->ncns()
            ->create([
                'eligible_for_gbro' => false,
                'gbro_expires_at' => now()->addDays(30),
            ]);

        $found = $this->service->detect()->where('type', 'ORPHAN_GBRO_DATE');
        $this->assertCount(1, $found);
    }

    #[Test]
    public function detect_reports_excused_with_gbro_date(): void
    {
        AttendancePoint::factory()
            ->forUser($this->user)
            ->onDate(now()->subDays(5))
            ->tardy()
            ->excused()
            ->create([
                'gbro_expires_at' => now()->addDays(30),
            ]);

        $found = $this->service->detect()->where('type', 'EXCUSED_HAS_GBRO_DATE');
        $this->assertCount(1, $found);
    }

    #[Test]
    public function repair_persists_anomalies_and_returns_summary(): void
    {
        AttendancePoint::factory()
            ->forUser($this->user)
            ->onDate(now()->subDays(70))
            ->tardy()
            ->create([
                'eligible_for_gbro' => true,
                'gbro_expires_at' => now()->subDay(),
                'is_expired' => false,
                'is_excused' => false,
            ]);

        $result = $this->service->repair(null, 'manual_run');

        $this->assertGreaterThanOrEqual(1, $result['detected']);
        $this->assertGreaterThanOrEqual(1, $result['affected_users']);
        $this->assertNotEmpty($result['batch_id']);

        $this->assertDatabaseHas('gbro_anomaly_logs', [
            'batch_id' => $result['batch_id'],
            'trigger' => 'manual_run',
            'type' => 'STALE_PENDING_GBRO',
            'user_id' => $this->user->id,
        ]);
    }

    #[Test]
    public function dry_run_persists_findings_without_invoking_maintenance(): void
    {
        AttendancePoint::factory()
            ->forUser($this->user)
            ->onDate(now()->subDays(70))
            ->tardy()
            ->create([
                'eligible_for_gbro' => true,
                'gbro_expires_at' => now()->subDay(),
                'is_expired' => false,
            ]);

        $result = $this->service->repair(null, 'manual_run', dryRun: true);

        $this->assertGreaterThanOrEqual(1, $result['detected']);
        $this->assertSame(0, $result['repaired']);
        $this->assertEmpty($result['maintenance']);

        $this->assertGreaterThanOrEqual(1, GbroAnomalyLog::count());
        $this->assertDatabaseHas('gbro_anomaly_logs', [
            'batch_id' => $result['batch_id'],
            'repaired' => false,
        ]);
    }

    #[Test]
    public function repair_returns_zero_summary_when_clean(): void
    {
        AttendancePoint::factory()
            ->forUser($this->user)
            ->onDate(now()->subDays(10))
            ->tardy()
            ->create();

        $result = $this->service->repair();

        $this->assertSame(0, $result['detected']);
        $this->assertSame(0, $result['repaired']);
        $this->assertDatabaseCount('gbro_anomaly_logs', 0);
    }

    #[Test]
    public function repair_can_be_scoped_to_a_single_user(): void
    {
        $other = User::factory()->create();

        AttendancePoint::factory()
            ->forUser($this->user)
            ->onDate(now()->subDays(70))
            ->tardy()
            ->create([
                'eligible_for_gbro' => true,
                'gbro_expires_at' => now()->subDay(),
                'is_expired' => false,
            ]);

        AttendancePoint::factory()
            ->forUser($other)
            ->onDate(now()->subDays(70))
            ->tardy()
            ->create([
                'eligible_for_gbro' => true,
                'gbro_expires_at' => now()->subDay(),
                'is_expired' => false,
            ]);

        $result = $this->service->repair($this->user->id, 'manual_run');

        $this->assertGreaterThanOrEqual(1, $result['detected']);

        $this->assertDatabaseHas('gbro_anomaly_logs', [
            'user_id' => $this->user->id,
        ]);
        $this->assertDatabaseMissing('gbro_anomaly_logs', [
            'user_id' => $other->id,
        ]);
    }

    #[Test]
    public function audit_command_runs_and_persists_log_rows(): void
    {
        AttendancePoint::factory()
            ->forUser($this->user)
            ->onDate(now()->subDays(70))
            ->tardy()
            ->create([
                'eligible_for_gbro' => true,
                'gbro_expires_at' => now()->subDay(),
                'is_expired' => false,
            ]);

        $this->artisan('points:audit-gbro')
            ->assertSuccessful();

        $this->assertDatabaseHas('gbro_anomaly_logs', [
            'trigger' => 'scheduled',
            'type' => 'STALE_PENDING_GBRO',
            'user_id' => $this->user->id,
        ]);
    }

    #[Test]
    public function audit_command_dry_run_does_not_repair_but_logs(): void
    {
        AttendancePoint::factory()
            ->forUser($this->user)
            ->onDate(now()->subDays(70))
            ->tardy()
            ->create([
                'eligible_for_gbro' => true,
                'gbro_expires_at' => now()->subDay(),
                'is_expired' => false,
            ]);

        $this->artisan('points:audit-gbro', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertGreaterThanOrEqual(1, GbroAnomalyLog::count());
        $this->assertSame(0, GbroAnomalyLog::where('repaired', true)->count());
    }
}
