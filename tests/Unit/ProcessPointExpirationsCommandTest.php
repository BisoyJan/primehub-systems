<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Console\Commands\ProcessPointExpirations;
use App\Models\AttendancePoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

class ProcessPointExpirationsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_processes_sro_expirations_for_standard_violations()
    {
        // Create points past their expiration date (6 months for standard violations)
        $user = User::factory()->create();
        $oldDate = now()->subMonths(7); // 7 months ago

        $tardy = AttendancePoint::factory()
            ->tardy()
            ->forUser($user)
            ->onDate($oldDate)
            ->create([
                'is_expired' => false,
                'expires_at' => $oldDate->copy()->addMonths(6), // Expired 1 month ago
            ]);

        $undertime = AttendancePoint::factory()
            ->undertime()
            ->forUser($user)
            ->onDate($oldDate)
            ->create([
                'is_expired' => false,
                'expires_at' => $oldDate->copy()->addMonths(6),
            ]);

        // Create a point not yet expired
        $recentPoint = AttendancePoint::factory()->tardy()->create([
            'shift_date' => now()->subMonths(2),
            'expires_at' => now()->addMonths(4),
            'is_expired' => false,
        ]);

        Artisan::call('points:process-expirations');

        // Check that old points are expired
        $this->assertTrue($tardy->fresh()->is_expired);
        $this->assertEquals('sro', $tardy->fresh()->expiration_type);
        $this->assertNotNull($tardy->fresh()->expired_at);

        $this->assertTrue($undertime->fresh()->is_expired);
        $this->assertEquals('sro', $undertime->fresh()->expiration_type);

        // Check that recent point is still active
        $this->assertFalse($recentPoint->fresh()->is_expired);
    }

    /** @test */
    public function it_processes_sro_expirations_for_ncns_after_one_year()
    {
        $user = User::factory()->create();
        $oldDate = now()->subYears(2); // 2 years ago

        $ncns = AttendancePoint::factory()
            ->ncns()
            ->forUser($user)
            ->onDate($oldDate)
            ->create([
                'is_expired' => false,
                'expires_at' => $oldDate->copy()->addYear(), // Expired 1 year ago
            ]);

        Artisan::call('points:process-expirations');

        // NCNS should be expired via SRO after 1 year
        $this->assertTrue($ncns->fresh()->is_expired);
        $this->assertEquals('sro', $ncns->fresh()->expiration_type);
    }

    /** @test */
    public function it_processes_gbro_for_users_with_60_days_clean_record()
    {
        $user = User::factory()->create();

        // Create 3 old violations (more than 60 days ago)
        $oldDate = now()->subDays(65);

        $oldest = AttendancePoint::factory()
            ->tardy()
            ->forUser($user)
            ->onDate($oldDate->copy()->subDays(10))
            ->create(['eligible_for_gbro' => true]);

        $middle = AttendancePoint::factory()
            ->undertime()
            ->forUser($user)
            ->onDate($oldDate->copy()->subDays(5))
            ->create(['eligible_for_gbro' => true]);

        $newest = AttendancePoint::factory()
            ->halfDayAbsence()
            ->forUser($user)
            ->onDate($oldDate)
            ->create(['eligible_for_gbro' => true]);

        Artisan::call('points:process-expirations');

        // Last 2 points (most recent) should be expired via GBRO
        $this->assertFalse($oldest->fresh()->is_expired); // Oldest remains
        $this->assertTrue($middle->fresh()->is_expired); // 2nd most recent expired
        $this->assertTrue($newest->fresh()->is_expired); // Most recent expired

        $this->assertEquals('gbro', $middle->fresh()->expiration_type);
        $this->assertEquals('gbro', $newest->fresh()->expiration_type);
        $this->assertNotNull($middle->fresh()->gbro_applied_at);
        $this->assertNotNull($newest->fresh()->gbro_applied_at);
    }

    /** @test */
    public function it_does_not_apply_gbro_if_less_than_60_days_clean()
    {
        $user = User::factory()->create();

        // Most recent violation only 50 days ago (< 60 days)
        $recentDate = now()->subDays(50);

        $point1 = AttendancePoint::factory()
            ->tardy()
            ->forUser($user)
            ->onDate($recentDate->copy()->subDays(5))
            ->create(['eligible_for_gbro' => true]);

        $point2 = AttendancePoint::factory()
            ->undertime()
            ->forUser($user)
            ->onDate($recentDate)
            ->create(['eligible_for_gbro' => true]);

        Artisan::call('points:process-expirations');

        // Neither point should be expired (not enough clean days)
        $this->assertFalse($point1->fresh()->is_expired);
        $this->assertFalse($point2->fresh()->is_expired);
    }

    /** @test */
    public function it_does_not_apply_gbro_to_ncns_ftn_points()
    {
        $user = User::factory()->create();
        $oldDate = now()->subDays(65);

        // Create NCNS point (not eligible for GBRO)
        $ncns = AttendancePoint::factory()
            ->ncns()
            ->forUser($user)
            ->onDate($oldDate)
            ->create([
                'eligible_for_gbro' => false,
            ]);

        // Create eligible point
        $tardy = AttendancePoint::factory()
            ->tardy()
            ->forUser($user)
            ->onDate($oldDate->copy()->subDays(5))
            ->create(['eligible_for_gbro' => true]);

        Artisan::call('points:process-expirations');

        // NCNS should NOT be expired via GBRO
        $this->assertFalse($ncns->fresh()->is_expired);

        // Tardy could be expired if it's one of the last 2 eligible points
        // In this case, it's the only eligible point, so it should be expired
        $this->assertTrue($tardy->fresh()->is_expired);
        $this->assertEquals('gbro', $tardy->fresh()->expiration_type);
    }

    /** @test */
    public function it_does_not_expire_already_expired_points()
    {
        $user = User::factory()->create();
        $oldDate = now()->subMonths(7);

        $alreadyExpired = AttendancePoint::factory()
            ->tardy()
            ->forUser($user)
            ->expiredSro()
            ->create([
                'shift_date' => $oldDate,
                'expires_at' => $oldDate->copy()->addMonths(6),
            ]);

        $expiredAt = $alreadyExpired->expired_at;

        Artisan::call('points:process-expirations');

        // Should not change the expired_at timestamp
        $this->assertEquals($expiredAt->format('Y-m-d H:i:s'), $alreadyExpired->fresh()->expired_at->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_does_not_expire_excused_points()
    {
        $user = User::factory()->create();
        $oldDate = now()->subMonths(7);

        $excused = AttendancePoint::factory()
            ->tardy()
            ->forUser($user)
            ->excused()
            ->create([
                'shift_date' => $oldDate,
                'expires_at' => $oldDate->copy()->addMonths(6),
            ]);

        Artisan::call('points:process-expirations');

        // Excused point should not be expired
        $this->assertFalse($excused->fresh()->is_expired);
    }

    /** @test */
    public function it_handles_dry_run_mode_without_making_changes()
    {
        $user = User::factory()->create();
        $oldDate = now()->subMonths(7);

        $point = AttendancePoint::factory()
            ->tardy()
            ->forUser($user)
            ->onDate($oldDate)
            ->create([
                'is_expired' => false,
                'expires_at' => $oldDate->copy()->addMonths(6),
            ]);

        Artisan::call('points:process-expirations', ['--dry-run' => true]);

        // Point should still be active (dry run makes no changes)
        $this->assertFalse($point->fresh()->is_expired);
        $this->assertNull($point->fresh()->expired_at);
    }

    /** @test */
    public function it_assigns_same_gbro_batch_id_to_points_expired_together()
    {
        $user = User::factory()->create();
        $oldDate = now()->subDays(65);

        $point1 = AttendancePoint::factory()
            ->tardy()
            ->forUser($user)
            ->onDate($oldDate->copy()->subDays(5))
            ->create(['eligible_for_gbro' => true]);

        $point2 = AttendancePoint::factory()
            ->undertime()
            ->forUser($user)
            ->onDate($oldDate)
            ->create(['eligible_for_gbro' => true]);

        Artisan::call('points:process-expirations');

        // Both points should have the same GBRO batch ID
        $this->assertNotNull($point1->fresh()->gbro_batch_id);
        $this->assertNotNull($point2->fresh()->gbro_batch_id);
        $this->assertEquals($point1->fresh()->gbro_batch_id, $point2->fresh()->gbro_batch_id);
    }

    /** @test */
    public function it_only_expires_last_2_eligible_points_via_gbro()
    {
        $user = User::factory()->create();
        $oldDate = now()->subDays(65);

        // Create 5 eligible points
        $point1 = AttendancePoint::factory()->tardy()->forUser($user)->onDate($oldDate->copy()->subDays(20))->create(['eligible_for_gbro' => true]);
        $point2 = AttendancePoint::factory()->tardy()->forUser($user)->onDate($oldDate->copy()->subDays(15))->create(['eligible_for_gbro' => true]);
        $point3 = AttendancePoint::factory()->tardy()->forUser($user)->onDate($oldDate->copy()->subDays(10))->create(['eligible_for_gbro' => true]);
        $point4 = AttendancePoint::factory()->undertime()->forUser($user)->onDate($oldDate->copy()->subDays(5))->create(['eligible_for_gbro' => true]);
        $point5 = AttendancePoint::factory()->halfDayAbsence()->forUser($user)->onDate($oldDate)->create(['eligible_for_gbro' => true]);

        Artisan::call('points:process-expirations');

        // Only the last 2 (most recent) should be expired
        $this->assertFalse($point1->fresh()->is_expired);
        $this->assertFalse($point2->fresh()->is_expired);
        $this->assertFalse($point3->fresh()->is_expired);
        $this->assertTrue($point4->fresh()->is_expired); // 2nd most recent
        $this->assertTrue($point5->fresh()->is_expired); // Most recent

        $this->assertEquals('gbro', $point4->fresh()->expiration_type);
        $this->assertEquals('gbro', $point5->fresh()->expiration_type);
    }

    /** @test */
    public function it_handles_multiple_users_independently()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $oldDate = now()->subDays(65);

        // User 1 has old points (eligible for GBRO)
        $user1Point = AttendancePoint::factory()
            ->tardy()
            ->forUser($user1)
            ->onDate($oldDate)
            ->create(['eligible_for_gbro' => true]);

        // User 2 has recent point (not eligible for GBRO)
        $user2Point = AttendancePoint::factory()
            ->tardy()
            ->forUser($user2)
            ->onDate(now()->subDays(30))
            ->create(['eligible_for_gbro' => true]);

        Artisan::call('points:process-expirations');

        // User 1's point should be expired via GBRO
        $this->assertTrue($user1Point->fresh()->is_expired);
        $this->assertEquals('gbro', $user1Point->fresh()->expiration_type);

        // User 2's point should remain active
        $this->assertFalse($user2Point->fresh()->is_expired);
    }

    /** @test */
    public function it_returns_success_exit_code()
    {
        $exitCode = Artisan::call('points:process-expirations');

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_handles_users_with_no_points_gracefully()
    {
        // Create user with no points
        User::factory()->create();

        $exitCode = Artisan::call('points:process-expirations');

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_skips_points_already_processed_by_gbro()
    {
        $user = User::factory()->create();
        $oldDate = now()->subDays(65);

        // Create points already processed by GBRO
        $alreadyProcessed = AttendancePoint::factory()
            ->tardy()
            ->forUser($user)
            ->expiredGbro('batch123')
            ->create([
                'shift_date' => $oldDate,
                'eligible_for_gbro' => true,
            ]);

        // Create new eligible point
        $newPoint = AttendancePoint::factory()
            ->undertime()
            ->forUser($user)
            ->onDate($oldDate->copy()->addDays(5))
            ->create(['eligible_for_gbro' => true]);

        Artisan::call('points:process-expirations');

        // Already processed point should remain unchanged
        $this->assertEquals('batch123', $alreadyProcessed->fresh()->gbro_batch_id);

        // New point should be processed
        $this->assertTrue($newPoint->fresh()->is_expired);
        $this->assertNotEquals('batch123', $newPoint->fresh()->gbro_batch_id);
    }
}

