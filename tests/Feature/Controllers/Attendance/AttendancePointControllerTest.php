<?php

namespace Tests\Feature\Controllers\Attendance;

use Tests\TestCase;
use App\Models\User;
use App\Models\Attendance;
use App\Models\AttendancePoint;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class AttendancePointControllerTest extends TestCase
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
    public function it_shows_user_attendance_points_index()
    {
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(3)->create();

        $response = $this->actingAs($this->admin)->get(route('attendance-points.index', ['user_id' => $targetUser->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Attendance/Points/Index')
                ->has('points.data', 3)
        );
    }

    #[Test]
    public function it_filters_points_by_status()
    {
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(2)->create();
        AttendancePoint::factory()->tardy()->expiredSro()->for($targetUser)->count(1)->create();

        $response = $this->actingAs($this->admin)->get(route('attendance-points.index', [
            'user_id' => $targetUser->id,
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
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(2)->create();
        AttendancePoint::factory()->undertime()->for($targetUser)->count(1)->create();

        $response = $this->actingAs($this->admin)->get(route('attendance-points.index', [
            'user_id' => $targetUser->id,
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
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->create([
            'shift_date' => Carbon::parse('2025-01-15')
        ]);
        AttendancePoint::factory()->tardy()->for($targetUser)->create([
            'shift_date' => Carbon::parse('2025-02-15')
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendance-points.index', [
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
        $targetUser = User::factory()->create();
        $point = AttendancePoint::factory()->tardy()->for($targetUser)->create();

        $response = $this->actingAs($this->admin)->get(route('attendance-points.show', $targetUser));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Attendance/Points/Show')
                ->has('points')
                ->has('user')
                ->has('gbroStats')
        );
    }

    #[Test]
    public function it_excuses_a_point()
    {
        $point = AttendancePoint::factory()->tardy()->create();

        $response = $this->actingAs($this->admin)->post(route('attendance-points.excuse', $point), [
            'excuse_reason' => 'Approved by HR due to emergency'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $point->refresh();
        $this->assertTrue($point->is_excused);
        $this->assertEquals($this->admin->id, $point->excused_by);
        $this->assertEquals('Approved by HR due to emergency', $point->excuse_reason);
    }

    #[Test]
    public function it_validates_excuse_reason_is_required()
    {
        $point = AttendancePoint::factory()->tardy()->create();

        $response = $this->actingAs($this->admin)->post(route('attendance-points.excuse', $point), [
            'excuse_reason' => ''
        ]);

        $response->assertSessionHasErrors('excuse_reason');
    }

    #[Test]
    public function it_unexcuses_a_point()
    {
        $point = AttendancePoint::factory()->tardy()->excused($this->admin, 'Previous excuse')->create();

        $response = $this->actingAs($this->admin)->post(route('attendance-points.unexcuse', $point));

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
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(2)->create();
        AttendancePoint::factory()->undertime()->for($targetUser)->count(1)->create();
        AttendancePoint::factory()->tardy()->expiredSro()->for($targetUser)->count(1)->create();

        $response = $this->actingAs($this->admin)->get(route('attendance-points.statistics', $targetUser));

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
        $regularUser = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
        $point = AttendancePoint::factory()->tardy()->create();

        $response = $this->actingAs($regularUser)->post(route('attendance-points.excuse', $point), [
            'excuse_reason' => 'Should not be allowed'
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function it_prevents_non_admin_from_unexcusing_points()
    {
        $regularUser = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
        $point = AttendancePoint::factory()->tardy()->excused($this->admin, 'Previous excuse')->create();

        $response = $this->actingAs($regularUser)->post(route('attendance-points.unexcuse', $point));

        $response->assertStatus(403);
    }

    #[Test]
    public function it_allows_user_to_view_their_own_points()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
        
        // Create points within the current month so they are returned by the date range filter
        AttendancePoint::factory()->tardy()->for($user)->count(2)->create([
            'shift_date' => Carbon::now()->startOfMonth()->addDays(5),
        ]);

        // Restricted users are redirected to show page from index
        $response = $this->actingAs($user)->get(route('attendance-points.index'));
        $response->assertRedirect(route('attendance-points.show', $user));

        // Accessing show page directly
        $response = $this->actingAs($user)->get(route('attendance-points.show', $user));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->has('points', 2)
        );
    }

    #[Test]
    public function it_prevents_user_from_viewing_other_users_points()
    {
        $user1 = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]); // Non-admin role
        $user2 = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
        AttendancePoint::factory()->tardy()->for($user2)->count(2)->create();

        $response = $this->actingAs($user1)->get(route('attendance-points.show', $user2));

        $response->assertStatus(403);
    }

    #[Test]
    public function it_exports_user_points_to_csv()
    {
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(3)->create();

        $response = $this->actingAs($this->admin)->get(route('attendance-points.export', $targetUser));

        $response->assertStatus(200);
        $response->assertDownload();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_exports_user_points_to_excel()
    {
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->for($targetUser)->count(3)->create();

        $response = $this->actingAs($this->admin)->get(route('attendance-points.export-excel', $targetUser));

        $response->assertStatus(200);
        $response->assertDownload();
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_shows_points_expiring_soon()
    {
        $targetUser = User::factory()->create();

        // Point expiring in 10 days
        AttendancePoint::factory()->tardy()->for($targetUser)->create([
            'expires_at' => Carbon::now()->addDays(10)
        ]);

        // Point expiring in 60 days
        AttendancePoint::factory()->tardy()->for($targetUser)->create([
            'expires_at' => Carbon::now()->addDays(60)
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendance-points.index', [
            'user_id' => $targetUser->id,
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
        $targetUser = User::factory()->create();

        AttendancePoint::factory()->tardy()->eligibleForGbro()->for($targetUser)->count(2)->create();
        AttendancePoint::factory()->ncns()->for($targetUser)->count(1)->create(); // Not eligible

        $response = $this->actingAs($this->admin)->get(route('attendance-points.index', [
            'user_id' => $targetUser->id,
            'gbro_eligible' => true
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->has('points.data', 2)
        );
    }

    #[Test]
    public function it_redirects_restricted_users_to_show_page()
    {
        $restrictedUser = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($restrictedUser)->get(route('attendance-points.index'));

        $response->assertRedirect(route('attendance-points.show', $restrictedUser));
    }

    #[Test]
    public function it_rescans_attendance_points()
    {
        $user = User::factory()->create();
        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'status' => 'tardy',
            'tardy_minutes' => 15,
            'admin_verified' => true,
            'shift_date' => now()->subDays(1)->format('Y-m-d'),
        ]);

        // Ensure no point exists initially
        $this->assertDatabaseMissing('attendance_points', ['attendance_id' => $attendance->id]);

        $response = $this->actingAs($this->admin)->post(route('attendance-points.rescan'), [
            'date_from' => now()->subDays(2)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_points', [
            'attendance_id' => $attendance->id,
            'point_type' => 'tardy',
            'points' => 0.25,
        ]);
    }

    #[Test]
    public function it_exports_all_points_to_csv()
    {
        AttendancePoint::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)->get(route('attendance-points.export-all'));

        $response->assertStatus(200);
        $response->assertDownload();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_exports_all_points_to_excel()
    {
        AttendancePoint::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)->get(route('attendance-points.export-all-excel'));

        $response->assertStatus(200);
        $response->assertDownload();
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
    }
}
