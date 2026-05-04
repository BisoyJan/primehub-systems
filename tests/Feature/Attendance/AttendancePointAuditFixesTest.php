<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendancePoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests for the attendance points audit fixes.
 *
 * Bug #1: Excusing a point must clear all expiration columns.
 * Bug #3: NCNS rows must never be GBRO-eligible (and the data-repair
 *         migration must backfill historical rows).
 * Bug #4: markAsExpired() must preserve `expiration_type='none'`
 *         for NCNS / FTN rows (no relabel to 'sro').
 */
class AttendancePointAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }

    #[Test]
    public function excusing_a_gbro_expired_point_clears_all_expiration_columns(): void
    {
        $user = User::factory()->create();

        $point = AttendancePoint::factory()
            ->tardy()
            ->expiredGbro('20260101000000')
            ->for($user)
            ->create();

        $this->assertTrue($point->is_expired);
        $this->assertEquals('gbro', $point->expiration_type);
        $this->assertNotNull($point->gbro_applied_at);

        $this->actingAs($this->admin)
            ->post(route('attendance-points.excuse', $point), [
                'excuse_reason' => 'Approved retroactively',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $point->refresh();

        $this->assertTrue($point->is_excused);
        $this->assertFalse($point->is_expired, 'Excused point must not be expired');
        $this->assertNull($point->expired_at);
        $this->assertNull($point->gbro_applied_at);
        $this->assertNull($point->gbro_batch_id);
        $this->assertEquals('sro', $point->expiration_type);
    }

    #[Test]
    public function excusing_an_ncns_point_resets_expiration_type_to_none(): void
    {
        $user = User::factory()->create();

        $point = AttendancePoint::factory()
            ->ncns()
            ->expiredGbro()
            ->for($user)
            ->create();

        $this->actingAs($this->admin)
            ->post(route('attendance-points.excuse', $point), [
                'excuse_reason' => 'Doctor note received',
            ]);

        $point->refresh();

        $this->assertTrue($point->is_excused);
        $this->assertFalse($point->is_expired);
        $this->assertEquals('none', $point->expiration_type);
    }

    #[Test]
    public function mark_as_expired_preserves_none_for_ncns(): void
    {
        $point = AttendancePoint::factory()->ncns()->create();

        $point->markAsExpired('sro');

        $point->refresh();

        $this->assertTrue($point->is_expired);
        $this->assertEquals('none', $point->expiration_type, 'NCNS must not be relabeled as SRO');
    }

    #[Test]
    public function mark_as_expired_uses_sro_for_standard_violations(): void
    {
        $point = AttendancePoint::factory()->tardy()->create();

        $point->markAsExpired('sro');

        $point->refresh();

        $this->assertTrue($point->is_expired);
        $this->assertEquals('sro', $point->expiration_type);
    }

    #[Test]
    public function mark_as_expired_uses_gbro_when_explicitly_passed(): void
    {
        $point = AttendancePoint::factory()->tardy()->create();

        $point->markAsExpired('gbro');

        $point->refresh();

        $this->assertEquals('gbro', $point->expiration_type);
    }

    #[Test]
    public function ncns_factory_marks_point_as_not_eligible_for_gbro(): void
    {
        $point = AttendancePoint::factory()->ncns()->create();

        $this->assertFalse((bool) $point->eligible_for_gbro);
        $this->assertEquals('none', $point->expiration_type);
    }
}
