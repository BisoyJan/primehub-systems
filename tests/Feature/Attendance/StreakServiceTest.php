<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePoint;
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

        $this->service = app(StreakService::class);
        $this->user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        Cache::flush();
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
    public function user_with_no_attendance_returns_zero_streak(): void
    {
        $result = $this->service->getUserStreak($this->user);

        $this->assertSame(0, $result['current_streak']);
        $this->assertSame(0, $result['longest_streak']);
        $this->assertNull($result['badge']);
        $this->assertNotNull($result['next_badge']);
        $this->assertSame(7, $result['next_badge']['days']);
    }

    #[Test]
    public function clean_consecutive_workdays_build_full_streak(): void
    {
        foreach ($this->workdays(10) as $date) {
            $this->attendOn($date);
        }

        $result = $this->service->getUserStreak($this->user);

        $this->assertSame(10, $result['current_streak']);
        $this->assertSame(10, $result['longest_streak']);
        $this->assertSame('Week Warrior', $result['badge']['label']);
        $this->assertSame(30, $result['next_badge']['days']);
    }

    #[Test]
    public function non_excused_violation_breaks_current_streak(): void
    {
        $dates = $this->workdays(5);
        // Newest → oldest: today, t-1 (violation), t-2..t-4
        foreach ($dates as $date) {
            $this->attendOn($date);
        }
        $this->pointOn($dates[1], 'tardy', excused: false);

        $result = $this->service->getUserStreak($this->user);

        $this->assertSame(1, $result['current_streak']); // only today is clean
        $this->assertSame(3, $result['longest_streak']); // t-2..t-4 longer historical run
        $this->assertSame($dates[1], $result['last_violation_date']);
    }

    #[Test]
    public function excused_violation_does_not_break_streak(): void
    {
        $dates = $this->workdays(5);
        foreach ($dates as $date) {
            $this->attendOn($date);
        }
        $this->pointOn($dates[2], 'tardy', excused: true);

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
    public function leaderboard_returns_users_sorted_by_current_streak(): void
    {
        // user A: 10 days clean
        $userA = $this->user;
        foreach ($this->workdays(10) as $date) {
            Attendance::factory()->create([
                'user_id' => $userA->id,
                'shift_date' => $date,
                'status' => 'on_time',
            ]);
        }

        // user B: 5 days clean
        $userB = User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'approved_at' => now()]);
        foreach ($this->workdays(5) as $date) {
            Attendance::factory()->create([
                'user_id' => $userB->id,
                'shift_date' => $date,
                'status' => 'on_time',
            ]);
        }

        // user C: violation breaks streak to 0
        $userC = User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'approved_at' => now()]);
        $datesC = $this->workdays(3);
        foreach ($datesC as $date) {
            Attendance::factory()->create([
                'user_id' => $userC->id,
                'shift_date' => $date,
                'status' => 'on_time',
            ]);
        }
        AttendancePoint::factory()->create([
            'user_id' => $userC->id,
            'attendance_id' => null,
            'shift_date' => $datesC[0],
            'point_type' => 'tardy',
            'is_excused' => false,
            'is_expired' => false,
        ]);

        $board = $this->service->getLeaderboard(10);

        $this->assertCount(2, $board); // userC excluded (streak = 0)
        $this->assertSame($userA->id, $board[0]['user_id']);
        $this->assertSame(10, $board[0]['current_streak']);
        $this->assertSame($userB->id, $board[1]['user_id']);
        $this->assertSame(5, $board[1]['current_streak']);
    }

    #[Test]
    public function observer_invalidates_cache_on_point_change(): void
    {
        foreach ($this->workdays(5) as $date) {
            $this->attendOn($date);
        }
        $first = $this->service->getUserStreak($this->user);
        $this->assertSame(5, $first['current_streak']);

        // Adding a violation must invalidate cache and yield smaller streak.
        $this->pointOn($this->workdays(1)[0], 'tardy', excused: false);

        $after = $this->service->getUserStreak($this->user);
        $this->assertSame(0, $after['current_streak']);
    }

    #[Test]
    public function streak_endpoint_returns_inertia_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        foreach ($this->workdays(8) as $date) {
            $this->attendOn($date);
        }

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
        );
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
