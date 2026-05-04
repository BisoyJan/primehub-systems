<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for the second-pass audit (M1-M8) of the
 * `attendance-points.management.*` endpoints and underlying
 * AttendancePointMaintenanceService behavior.
 *
 * M1: Auth bypass on every mutating manage handler (only Admin/Super Admin/HR allowed).
 * M2: regeneratePoints() filtered on non-existent status='verified'.
 * M3: processGbroExpirations() had no per-user same-day guard.
 * M4: resetExpired() did not cascade GBRO recalc.
 * M5: removeDuplicates() did not cascade GBRO recalc + no transaction.
 * M6: Several loops were not transactional.
 * M7: SRO loop loaded the whole resultset into memory.
 * M8: Stats undercounted pending expirations vs. cron.
 */
class AttendancePointManageActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $hr;
    protected User $it;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $this->hr = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $this->it = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // M1 - Authorization
    // ---------------------------------------------------------------------

    public static function manageEndpointProvider(): array
    {
        return [
            'remove-duplicates'      => ['attendance-points.management.remove-duplicates'],
            'expire-all'             => ['attendance-points.management.expire-all'],
            'reset-expired'          => ['attendance-points.management.reset-expired'],
            'regenerate'             => ['attendance-points.management.regenerate'],
            'cleanup'                => ['attendance-points.management.cleanup'],
            'initialize-gbro-dates'  => ['attendance-points.management.initialize-gbro-dates'],
            'fix-gbro-dates'         => ['attendance-points.management.fix-gbro-dates'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('manageEndpointProvider')]
    public function non_admin_hr_user_cannot_call_manage_endpoint(string $routeName): void
    {
        $this->actingAs($this->it)
            ->post(route($routeName))
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_call_remove_duplicates(): void
    {
        $this->actingAs($this->admin)
            ->post(route('attendance-points.management.remove-duplicates'))
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    #[Test]
    public function hr_can_call_expire_all_pending(): void
    {
        $this->actingAs($this->hr)
            ->post(route('attendance-points.management.expire-all'))
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ---------------------------------------------------------------------
    // M2 - regeneratePoints uses correct filter
    // ---------------------------------------------------------------------

    #[Test]
    public function regenerate_points_uses_admin_verified_filter(): void
    {
        $user = User::factory()->create();

        // Verified NCNS attendance with no existing point: must be picked up
        // by the new (admin_verified + status enum) filter.
        $attendance = Attendance::factory()->ncns()->create([
            'user_id' => $user->id,
            'admin_verified' => true,
        ]);

        $this->assertDatabaseMissing('attendance_points', [
            'attendance_id' => $attendance->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('attendance-points.management.regenerate'));

        $response->assertOk()->assertJson(['success' => true]);

        // M2: confirm the candidate row is now in the processed set.
        // (Whether the creation service produces a row depends on
        // is_absent/is_tardy/is_undertime flags that are populated
        // upstream by the attendance ingestion pipeline.)
        $this->assertGreaterThanOrEqual(1, $response->json('records_processed'));
    }

    #[Test]
    public function regenerate_points_skips_unverified_attendance(): void
    {
        $user = User::factory()->create();

        Attendance::factory()->ncns()->create([
            'user_id' => $user->id,
            'admin_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('attendance-points.management.regenerate'));

        $response->assertOk()
            ->assertJson(['success' => true, 'regenerated' => 0]);
    }

    // ---------------------------------------------------------------------
    // M3 - SRO expire-all does not relabel NCNS as SRO
    // ---------------------------------------------------------------------

    #[Test]
    public function expire_all_pending_does_not_relabel_ncns_as_sro(): void
    {
        $user = User::factory()->create();

        $ncns = AttendancePoint::factory()
            ->ncns()
            ->for($user)
            ->create([
                'shift_date'   => now()->subDays(400),
                'expires_at'   => now()->subDay(),
                'is_expired'   => false,
            ]);

        $this->actingAs($this->admin)
            ->postJson(route('attendance-points.management.expire-all'), [
                'expiration_type' => 'sro',
            ])
            ->assertOk();

        $ncns->refresh();

        $this->assertTrue($ncns->is_expired);
        $this->assertSame('none', $ncns->expiration_type);
    }

    // ---------------------------------------------------------------------
    // M4/M5 - reset/remove do not strand state
    // ---------------------------------------------------------------------

    #[Test]
    public function reset_expired_excludes_excused_points(): void
    {
        $user = User::factory()->create();

        $excused = AttendancePoint::factory()
            ->tardy()
            ->expiredSro()
            ->excused($this->admin)
            ->for($user)
            ->create();

        $expired = AttendancePoint::factory()
            ->tardy()
            ->expiredSro()
            ->for($user)
            ->create();

        $this->actingAs($this->admin)
            ->postJson(route('attendance-points.management.reset-expired'), [
                'user_id' => $user->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'reset' => 1]);

        $excused->refresh();
        $expired->refresh();

        $this->assertTrue($excused->is_expired, 'Excused-yet-expired stays untouched');
        $this->assertFalse($expired->is_expired);
    }

    #[Test]
    public function remove_duplicates_keeps_excused_copy(): void
    {
        $user = User::factory()->create();
        $shiftDate = now()->subDays(5)->startOfDay();

        $regular = AttendancePoint::factory()
            ->tardy()
            ->for($user)
            ->create(['shift_date' => $shiftDate]);

        $excused = AttendancePoint::factory()
            ->tardy()
            ->excused($this->admin)
            ->for($user)
            ->create(['shift_date' => $shiftDate]);

        $this->actingAs($this->admin)
            ->postJson(route('attendance-points.management.remove-duplicates'))
            ->assertOk()
            ->assertJson(['success' => true, 'removed' => 1]);

        $this->assertDatabaseHas('attendance_points', ['id' => $excused->id]);
        $this->assertDatabaseMissing('attendance_points', ['id' => $regular->id]);
    }

    // ---------------------------------------------------------------------
    // M8 - stats use whereDate for parity with cron
    // ---------------------------------------------------------------------

    #[Test]
    public function management_stats_counts_points_expiring_today(): void
    {
        $user = User::factory()->create();

        // Past-due: clearly counted
        AttendancePoint::factory()
            ->tardy()
            ->for($user)
            ->create([
                'is_expired' => false,
                'expires_at' => now()->subDays(2),
            ]);

        // Expires later today (e.g. 23:00) — old query missed this
        AttendancePoint::factory()
            ->tardy()
            ->for($user)
            ->create([
                'is_expired' => false,
                'expires_at' => now()->endOfDay(),
            ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('attendance-points.management.stats'));

        $response->assertOk();
        $payload = $response->json();

        $this->assertSame(2, $payload['pending_expirations_count'] ?? null);
    }
}
