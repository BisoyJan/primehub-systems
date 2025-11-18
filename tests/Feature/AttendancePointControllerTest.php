<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\AttendancePoint;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class AttendancePointControllerTest extends TestCase
{
    use RefreshDatabase;


    #[Test]
    public function it_shows_user_attendance_points_index()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(3)->create();

        $response = $this->actingAs($user)->get(route('attendance-points.index', $targetUser));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Attendance/Points/Index')
                ->has('points.data', 3)
        );
    }

    #[Test]
    public function it_filters_points_by_status()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(2)->create();
        AttendancePoint::factory()->tardy()->expiredSro()->for($targetUser)->count(1)->create();

        $response = $this->actingAs($user)->get(route('attendance-points.index', [
            'user' => $targetUser,
            'status' => 'active'
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->has('points.data', 2)
        );
    }

    #[Test]
    public function it_filters_points_by_type()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(2)->create();
        AttendancePoint::factory()->undertime()->for($targetUser)->count(1)->create();

        $response = $this->actingAs($user)->get(route('attendance-points.index', [
            'user' => $targetUser,
            'point_type' => 'tardy'
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->has('points.data', 2)
        );
    }

    #[Test]
    public function it_filters_points_by_date_range()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->create([
            'shift_date' => Carbon::parse('2025-01-15')
        ]);
        AttendancePoint::factory()->tardy()->for($targetUser)->create([
            'shift_date' => Carbon::parse('2025-02-15')
        ]);

        $response = $this->actingAs($user)->get(route('attendance-points.index', [
            'user_id' => $targetUser->id,
            'date_from' => '2025-02-01',
            'date_to' => '2025-02-28'
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->has('points.data', 1)
        );
    }

    #[Test]
    public function it_shows_individual_point_details()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $targetUser = User::factory()->create();
        $point = AttendancePoint::factory()->tardy()->for($targetUser)->create();

        $response = $this->actingAs($user)->get(route('attendance-points.show', $targetUser));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Attendance/Points/Show')
                ->has('points')
                ->has('user')
        );
    }

    #[Test]
    public function it_excuses_a_point()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $point = AttendancePoint::factory()->tardy()->create();

        $response = $this->actingAs($user)->post(route('attendance-points.excuse', $point), [
            'excuse_reason' => 'Approved by HR due to emergency'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $point->refresh();
        $this->assertTrue($point->is_excused);
        $this->assertEquals($user->id, $point->excused_by);
        $this->assertEquals('Approved by HR due to emergency', $point->excuse_reason);
    }

    #[Test]
    public function it_validates_excuse_reason_is_required()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $point = AttendancePoint::factory()->tardy()->create();

        $response = $this->actingAs($user)->post(route('attendance-points.excuse', $point), [
            'excuse_reason' => ''
        ]);

        $response->assertSessionHasErrors('excuse_reason');
    }

    #[Test]
    public function it_unexcuses_a_point()
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $point = AttendancePoint::factory()->tardy()->excused($admin, 'Previous excuse')->create();

        $response = $this->actingAs($admin)->delete(route('attendance-points.unexcuse', $point));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $point->refresh();
        $this->assertFalse($point->is_excused);
        $this->assertNull($point->excused_by);
        $this->assertNull($point->excuse_reason);
    }

    #[Test]
    public function it_shows_user_point_statistics()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(2)->create();
        AttendancePoint::factory()->undertime()->for($targetUser)->count(1)->create();
        AttendancePoint::factory()->tardy()->expiredSro()->for($targetUser)->count(1)->create();

        $response = $this->actingAs($user)->get(route('attendance-points.statistics', $targetUser));

        $response->assertStatus(200);
        $response->assertJson([
            'total_points' => 0.75, // 2 tardy (0.25 each) + 1 undertime (0.25)
            'active_points' => 3,
            'expired_points' => 1,
        ]);
    }

    #[Test]
    public function it_prevents_non_admin_from_excusing_points()
    {
        $regularUser = User::factory()->create(['role' => 'Agent']);
        $point = AttendancePoint::factory()->tardy()->create();

        $response = $this->actingAs($regularUser)->post(route('attendance-points.excuse', $point), [
            'excuse_reason' => 'Should not be allowed'
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function it_prevents_non_admin_from_unexcusing_points()
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $regularUser = User::factory()->create(['role' => 'Agent']);
        $point = AttendancePoint::factory()->tardy()->excused($admin, 'Previous excuse')->create();

        $response = $this->actingAs($regularUser)->delete(route('attendance-points.unexcuse', $point));

        $response->assertStatus(403);
    }

    #[Test]
    public function it_allows_user_to_view_their_own_points()
    {
        $user = User::factory()->create();
        AttendancePoint::factory()->tardy()->for($user)->count(2)->create();

        $response = $this->actingAs($user)->get(route('attendance-points.index', $user));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->has('points.data', 2)
        );
    }

    #[Test]
    public function it_prevents_user_from_viewing_other_users_points()
    {
        $user1 = User::factory()->create(['role' => 'Agent']); // Non-admin role
        $user2 = User::factory()->create(['role' => 'Agent']);
        AttendancePoint::factory()->tardy()->for($user2)->count(2)->create();

        $response = $this->actingAs($user1)->get(route('attendance-points.show', $user2));

        $response->assertStatus(403);
    }

    #[Test]
    public function it_exports_user_points_to_excel()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(3)->create();

        $response = $this->actingAs($user)->get(route('attendance-points.export', $targetUser));

        $response->assertStatus(200);
        $response->assertDownload();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_shows_points_expiring_soon()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $targetUser = User::factory()->create();

        // Point expiring in 10 days
        AttendancePoint::factory()->tardy()->for($targetUser)->create([
            'expires_at' => Carbon::now()->addDays(10)
        ]);

        // Point expiring in 60 days
        AttendancePoint::factory()->tardy()->for($targetUser)->create([
            'expires_at' => Carbon::now()->addDays(60)
        ]);

        $response = $this->actingAs($user)->get(route('attendance-points.index', [
            'user' => $targetUser,
            'expiring_soon' => true
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->has('points.data', 1) // Only the one expiring in 10 days
        );
    }

    #[Test]
    public function it_shows_gbro_eligible_points()
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->eligibleForGbro()->for($targetUser)->count(2)->create();
        AttendancePoint::factory()->ncns()->for($targetUser)->count(1)->create(); // Not eligible

        $response = $this->actingAs($user)->get(route('attendance-points.index', [
            'user' => $targetUser,
            'gbro_eligible' => true
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->has('points.data', 2)
        );
    }
}
