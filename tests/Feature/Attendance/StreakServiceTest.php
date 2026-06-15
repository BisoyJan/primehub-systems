<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\AttendancePointLeaderboardExclusion;
use App\Models\User;
use App\Policies\AttendancePointPolicy;
use App\Services\AttendancePoint\StreakService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StreakServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StreakService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze "now" so user.created_at (the streak baseline) is deterministic.
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 12));

        $this->service = app(StreakService::class);
        $this->user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<int, string> last $count workdays as Y-m-d, newest first. */
    private function workdays(int $count, ?Carbon $from = null): array
    {
        $from ??= Carbon::create(2026, 5, 1);
        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $from->copy()->subDays($i)->toDateString();
        }

        return $dates;
    }

    private function attendOn(string $date): Attendance
    {
        return Attendance::factory()->create([
            'user_id' => $this->user->id,
            'shift_date' => $date,
            'status' => 'on_time',
        ]);
    }

    private function pointOn(string $date, string $type = 'tardy', bool $excused = false): AttendancePoint
    {
        return AttendancePoint::factory()->create([
            'user_id' => $this->user->id,
            'attendance_id' => null,
            'shift_date' => $date,
            'point_type' => $type,
            'points' => AttendancePoint::POINT_VALUES[$type] ?? 0.25,
            'is_excused' => $excused,
            'is_expired' => false,
        ]);
    }

    #[Test]
    public function user_with_no_violations_streak_equals_days_since_creation(): void
    {
        // Baseline = created_at = today (May 1) → streak counts today alone.
        $result = $this->service->getUserStreak($this->user);

        $this->assertSame(1, $result['current_streak']);
        $this->assertSame(1, $result['longest_streak']);
        $this->assertNull($result['badge']);
        $this->assertNotNull($result['next_badge']);
        $this->assertSame(7, $result['next_badge']['days']);
    }

    #[Test]
    public function no_violations_yields_full_streak_since_creation(): void
    {
        // Created May 1, today May 10 → 10 clean calendar days (no attendance rows required).
        Carbon::setTestNow(Carbon::create(2026, 5, 10, 12));

        $result = $this->service->getUserStreak($this->user);

        $this->assertSame(10, $result['current_streak']);
        $this->assertSame(10, $result['longest_streak']);
        $this->assertSame('Week Warrior', $result['badge']['label']);
        $this->assertSame(30, $result['next_badge']['days']);
    }

    #[Test]
    public function non_excused_violation_breaks_current_streak(): void
    {
        // Created May 1, today May 5. Violation on May 4 → today (May 5) is a 1-day streak.
        Carbon::setTestNow(Carbon::create(2026, 5, 5, 12));
        $this->pointOn('2026-05-04', 'tardy', excused: false);

        $result = $this->service->getUserStreak($this->user);

        $this->assertSame(1, $result['current_streak']); // only today is clean
        $this->assertSame(3, $result['longest_streak']); // May 1..May 3 clean run
        $this->assertSame('2026-05-04', $result['last_violation_date']);
    }

    #[Test]
    public function excused_violation_does_not_break_streak(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 5, 12));
        $this->pointOn('2026-05-03', 'tardy', excused: true);

        $result = $this->service->getUserStreak($this->user);

        $this->assertSame(5, $result['current_streak']);
        $this->assertNull($result['last_violation_date']);
    }

    #[Test]
    public function badge_thresholds_resolve_correctly(): void
    {
        $this->assertNull($this->service->badgeFor(0));
        $this->assertNull($this->service->badgeFor(6));
        $this->assertSame('Week Warrior', $this->service->badgeFor(7)['label']);
        $this->assertSame('Week Warrior', $this->service->badgeFor(29)['label']);
        $this->assertSame('Month Master', $this->service->badgeFor(30)['label']);
        $this->assertSame('Quarter Champion', $this->service->badgeFor(90)['label']);
        $this->assertSame('Half-Year Hero', $this->service->badgeFor(180)['label']);
        $this->assertSame('Year-Round Legend', $this->service->badgeFor(365)['label']);
        $this->assertSame('Year-Round Legend', $this->service->badgeFor(9999)['label']);
    }

    #[Test]
    public function next_badge_returns_progress_towards_next_tier(): void
    {
        $next = $this->service->nextBadgeFor(5);
        $this->assertSame(7, $next['days']);
        $this->assertSame(2, $next['days_remaining']);

        $next30 = $this->service->nextBadgeFor(7);
        $this->assertSame(30, $next30['days']);
        $this->assertSame(23, $next30['days_remaining']);

        $this->assertNull($this->service->nextBadgeFor(365));
    }

    #[Test]
    public function leaderboard_includes_users_with_no_attendance_points(): void
    {
        // Brand-new agent — zero Attendance rows, zero AttendancePoint rows.
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 12));
        $fresh = User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'approved_at' => now()]);

        Carbon::setTestNow(Carbon::create(2026, 5, 8, 12));
        $board = $this->service->getLeaderboard(10);

        $row = collect($board)->firstWhere('user_id', $fresh->id);
        $this->assertNotNull($row, 'Agent with no AttendancePoint records should appear on the leaderboard.');
        $this->assertSame(8, $row['current_streak']); // May 1..May 8 inclusive
    }

    #[Test]
    public function leaderboard_returns_users_sorted_by_current_streak(): void
    {
        // userA created May 1, no violations → streak from May 1 to today.
        $userA = $this->user;

        // userB created May 3 → shorter baseline.
        Carbon::setTestNow(Carbon::create(2026, 5, 3, 12));
        $userB = User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'approved_at' => now()]);

        // userC created May 1 but has a violation today → current_streak = 0.
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 12));
        $userC = User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'approved_at' => now()]);

        // Advance "today" to May 5.
        Carbon::setTestNow(Carbon::create(2026, 5, 5, 12));
        AttendancePoint::factory()->create([
            'user_id' => $userC->id,
            'attendance_id' => null,
            'shift_date' => '2026-05-05',
            'point_type' => 'tardy',
            'is_excused' => false,
            'is_expired' => false,
        ]);

        $board = $this->service->getLeaderboard(10);

        // userA: May 1..May 5 = 5, userB: May 3..May 5 = 3, userC excluded.
        $this->assertCount(2, $board);
        $this->assertSame($userA->id, $board[0]['user_id']);
        $this->assertSame(5, $board[0]['current_streak']);
        $this->assertSame($userB->id, $board[1]['user_id']);
        $this->assertSame(3, $board[1]['current_streak']);
    }

    #[Test]
    public function leaderboard_excludes_admin_curated_users(): void
    {
        // Both users created May 1, no violations.
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 12));
        $other = User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'approved_at' => now()]);

        Carbon::setTestNow(Carbon::create(2026, 5, 5, 12));

        // Sanity: both appear before exclusion.
        $before = $this->service->getLeaderboard(10);
        $this->assertCount(2, $before);

        // Exclude $other.
        AttendancePointLeaderboardExclusion::create([
            'user_id' => $other->id,
            'excluded_by' => $this->user->id,
            'reason' => 'Resigned',
        ]);

        $after = $this->service->getLeaderboard(10);
        $this->assertCount(1, $after);
        $this->assertSame($this->user->id, $after[0]['user_id']);

        // Excluded list reflects metadata.
        $list = $this->service->getExcludedUsers();
        $this->assertCount(1, $list);
        $this->assertSame($other->id, $list[0]['user_id']);
        $this->assertSame('Resigned', $list[0]['reason']);
    }

    #[Test]
    public function observer_invalidates_cache_on_point_change(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 5, 12));

        $first = $this->service->getUserStreak($this->user);
        $this->assertSame(5, $first['current_streak']); // May 1..May 5, no violations

        // Adding a violation today must invalidate cache and yield a 0-day streak.
        $this->pointOn('2026-05-05', 'tardy', excused: false);

        $after = $this->service->getUserStreak($this->user);
        $this->assertSame(0, $after['current_streak']);
    }

    #[Test]
    public function streak_endpoint_returns_inertia_page(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 12));
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        // Advance to May 8 → 8 clean calendar days for $this->user (created May 1).
        Carbon::setTestNow(Carbon::create(2026, 5, 8, 12));

        $response = $this->actingAs($admin)
            ->get(route('attendance-points.streak', ['user' => $this->user->id]));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('Attendance/Points/Streak')
                ->has('streak', fn ($s) => $s->where('current_streak', 8)
                    ->where('badge.label', 'Week Warrior')
                    ->etc())
                ->has('user')
                ->has('badges')
        );
    }

    #[Test]
    public function leaderboard_endpoint_returns_inertia_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('attendance-points.leaderboard'));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('Attendance/Points/Leaderboard')
                ->where('limit', 10)
                ->has('leaderboard')
                ->has('badges')
                ->where('canManage', true)
                ->has('excluded')
        );
    }

    #[Test]
    public function admin_can_exclude_and_restore_user_from_leaderboard(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
        $target = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        // Exclude
        $this->actingAs($admin)
            ->post(route('attendance-points.leaderboard.exclude', ['user' => $target->id]), [
                'reason' => 'Resigned',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('attendance_point_leaderboard_exclusions', [
            'user_id' => $target->id,
            'excluded_by' => $admin->id,
            'reason' => 'Resigned',
        ]);

        // Restore
        $this->actingAs($admin)
            ->delete(route('attendance-points.leaderboard.restore', ['user' => $target->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('attendance_point_leaderboard_exclusions', [
            'user_id' => $target->id,
        ]);
    }

    #[Test]
    public function non_admin_cannot_exclude_user_from_leaderboard(): void
    {
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
        $target = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($agent)
            ->post(route('attendance-points.leaderboard.exclude', ['user' => $target->id]));

        // Permission middleware rejects (403) or redirects — either way no DB write.
        $this->assertContains($response->status(), [302, 403]);
        $this->assertDatabaseMissing('attendance_point_leaderboard_exclusions', [
            'user_id' => $target->id,
        ]);
    }

    #[Test]
    public function policy_allows_self_view_for_restricted_role(): void
    {
        $self = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $policy = new AttendancePointPolicy(app(PermissionService::class));
        $this->assertTrue($policy->viewUserPoints($self, $self));
    }

    #[Test]
    public function policy_blocks_restricted_role_from_viewing_other_user(): void
    {
        $self = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
        $other = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $policy = new AttendancePointPolicy(app(PermissionService::class));
        $this->assertFalse($policy->viewUserPoints($self, $other));
    }
}
