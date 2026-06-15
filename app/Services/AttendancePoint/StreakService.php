<?php

namespace App\Services\AttendancePoint;

use App\Models\AttendancePoint;
use App\Models\AttendancePointLeaderboardExclusion;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * StreakService — Tardy-free streak badges (Audit feature 5.2).
 *
 * A "tardy-free workday" is any day on which the employee has an Attendance
 * record AND no non-excused AttendancePoint of any negative type
 * (tardy, undertime, undertime_more_than_hour, half_day_absence, whole_day_absence)
 * was issued. The current streak counts consecutive workdays ending on the
 * employee's most recent attendance date.
 *
 * Cached per-user for 6 hours; invalidated by AttendancePointObserver.
 */
class StreakService
{
    /** Cache TTL: 6 hours. */
    public const CACHE_TTL = 21600;

    /**
     * Badge thresholds (current_streak ≥ days → label).
     * Ordered descending for first-match wins.
     *
     * @var array<int, array{days:int,label:string,tier:string}>
     */
    public const BADGES = [
        ['days' => 365, 'label' => 'Year-Round Legend', 'tier' => 'platinum'],
        ['days' => 180, 'label' => 'Half-Year Hero',    'tier' => 'gold'],
        ['days' => 90,  'label' => 'Quarter Champion',  'tier' => 'silver'],
        ['days' => 30,  'label' => 'Month Master',      'tier' => 'bronze'],
        ['days' => 7,   'label' => 'Week Warrior',      'tier' => 'starter'],
    ];

    /**
     * Get the cached streak summary for a user.
     *
     * @return array{
     *   current_streak:int,
     *   longest_streak:int,
     *   last_violation_date:?string,
     *   streak_start_date:?string,
     *   total_workdays_evaluated:int,
     *   badge:?array{days:int,label:string,tier:string},
     *   next_badge:?array{days:int,label:string,tier:string,days_remaining:int}
     * }
     */
    public function getUserStreak(User $user): array
    {
        return Cache::remember(
            $this->cacheKey($user->id),
            self::CACHE_TTL,
            fn () => $this->computeUserStreak($user)
        );
    }

    /**
     * Get a leaderboard of top streaks across the workforce.
     * On-leave users are filtered in real-time (never cached) to stay current.
     *
     * @return array<int, array{user_id:int,name:string,campaign:?string,current_streak:int,badge:?array<string,mixed>}>
     */
    public function getLeaderboard(int $limit = 10): array
    {
        // Cache the FULL ranked list once — filters (leave/exclusions) and the
        // $limit slice are applied after, so exclusions don't shrink the
        // displayed top-N.
        $allRanked = Cache::remember(
            'streak_leaderboard_v3:all',
            self::CACHE_TTL,
            fn () => $this->computeLeaderboard()
        );

        // Exclude users on approved leave right now — always evaluated fresh, never cached,
        // so that LOA / ML / VL employees disappear from the board the moment their leave is active.
        $today = now()->toDateString();
        $onLeaveIds = LeaveRequest::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->pluck('user_id')
            ->flip()
            ->all();

        // Admin-curated exclusion list — always fresh so toggles are immediate.
        $excludedIds = AttendancePointLeaderboardExclusion::query()
            ->pluck('user_id')
            ->flip()
            ->all();

        $filtered = array_values(
            array_filter(
                $allRanked,
                fn ($row) => ! isset($onLeaveIds[$row['user_id']])
                    && ! isset($excludedIds[$row['user_id']])
            )
        );

        return array_slice($filtered, 0, $limit);
    }

    /**
     * Returns the admin-curated exclusion list with user + actor metadata.
     *
     * @return array<int, array{
     *   id:int,
     *   user_id:int,
     *   name:string,
     *   campaign:?string,
     *   reason:?string,
     *   excluded_by_name:?string,
     *   excluded_at:string
     * }>
     */
    public function getExcludedUsers(): array
    {
        return AttendancePointLeaderboardExclusion::query()
            ->with([
                'user:id,first_name,middle_name,last_name',
                'user.activeSchedule.campaign:id,name',
                'excludedBy:id,first_name,middle_name,last_name',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'name' => $row->user?->name ?? '—',
                'campaign' => $row->user?->activeSchedule?->campaign?->name,
                'reason' => $row->reason,
                'excluded_by_name' => $row->excludedBy?->name,
                'excluded_at' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Invalidate cached streak for a user (called by AttendancePointObserver).
     */
    public function clearUserCache(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
        // Leaderboard rebuilds on next read.
        Cache::forget('streak_leaderboard_v3:all');
    }

    /**
     * Resolve the badge for a given streak length.
     *
     * @return ?array{days:int,label:string,tier:string}
     */
    public function badgeFor(int $streak): ?array
    {
        foreach (self::BADGES as $badge) {
            if ($streak >= $badge['days']) {
                return $badge;
            }
        }

        return null;
    }

    /**
     * Resolve the next achievable badge above the given streak length.
     *
     * @return ?array{days:int,label:string,tier:string,days_remaining:int}
     */
    public function nextBadgeFor(int $streak): ?array
    {
        // BADGES is sorted desc; reverse to find smallest unmet threshold.
        foreach (array_reverse(self::BADGES) as $badge) {
            if ($streak < $badge['days']) {
                return [
                    'days' => $badge['days'],
                    'label' => $badge['label'],
                    'tier' => $badge['tier'],
                    'days_remaining' => $badge['days'] - $streak,
                ];
            }
        }

        return null;
    }

    private function cacheKey(int $userId): string
    {
        return "user_tardy_free_streak_v2:{$userId}";
    }

    /**
     * Compute streak from unexcused AttendancePoint records alone — no Attendance
     * record is required for a day to count. Baseline is the user's account
     * creation date; the streak counts calendar days since the most recent
     * unexcused violation up to today.
     */
    private function computeUserStreak(User $user): array
    {
        $today = now()->startOfDay();
        $baseline = ($user->created_at ?? $today)->copy()->startOfDay();
        if ($baseline->gt($today)) {
            return $this->emptyResult();
        }

        // All distinct unexcused violation dates ascending. Excused points do
        // not break the streak (administratively forgiven).
        $violationDates = AttendancePoint::query()
            ->where('user_id', $user->id)
            ->where('is_excused', false)
            ->orderBy('shift_date')
            ->pluck('shift_date')
            ->map(fn ($d) => Carbon::parse($d)->startOfDay())
            ->unique(fn (Carbon $d) => $d->toDateString())
            ->values();

        // Drop any violations dated outside [baseline, today] to keep math clean.
        $violationDates = $violationDates
            ->filter(fn (Carbon $d) => $d->between($baseline, $today))
            ->values();

        $lastViolation = $violationDates->last();

        // Current streak: days from (last_violation + 1) to today, inclusive.
        if ($lastViolation) {
            $startOfStreak = $lastViolation->copy()->addDay();
            if ($startOfStreak->gt($today)) {
                $currentStreak = 0;
                $streakStart = null;
            } else {
                $currentStreak = (int) $startOfStreak->diffInDays($today) + 1;
                $streakStart = $startOfStreak->toDateString();
            }
        } else {
            $currentStreak = (int) $baseline->diffInDays($today) + 1;
            $streakStart = $baseline->toDateString();
        }

        // Longest streak: walk violations ascending and measure the gap before,
        // between, and after each violation.
        $longestStreak = 0;
        $cursor = $baseline->copy();
        foreach ($violationDates as $vd) {
            if ($vd->gt($cursor)) {
                $gap = (int) $cursor->diffInDays($vd); // days strictly before $vd
                $longestStreak = max($longestStreak, $gap);
            }
            $cursor = $vd->copy()->addDay();
        }
        if ($cursor->lte($today)) {
            $tail = (int) $cursor->diffInDays($today) + 1;
            $longestStreak = max($longestStreak, $tail);
        }

        return [
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'last_violation_date' => $lastViolation?->toDateString(),
            'streak_start_date' => $currentStreak > 0 ? $streakStart : null,
            'total_workdays_evaluated' => (int) $baseline->diffInDays($today) + 1,
            'badge' => $this->badgeFor($currentStreak),
            'next_badge' => $this->nextBadgeFor($currentStreak),
        ];
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function computeLeaderboard(): array
    {
        // Every active user is eligible — the streak no longer depends on
        // having Attendance rows.
        $users = User::query()
            ->where('is_active', true)
            ->select(['id', 'first_name', 'middle_name', 'last_name', 'role', 'created_at'])
            ->with(['activeSchedule.campaign'])
            ->get();

        $rows = [];
        foreach ($users as $user) {
            $summary = $this->getUserStreak($user);
            if ($summary['current_streak'] <= 0) {
                continue;
            }
            $rows[] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'campaign' => $user->activeSchedule?->campaign?->name,
                'current_streak' => $summary['current_streak'],
                'longest_streak' => $summary['longest_streak'],
                'badge' => $summary['badge'],
            ];
        }

        usort($rows, fn ($a, $b) => $b['current_streak'] <=> $a['current_streak']);

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyResult(): array
    {
        return [
            'current_streak' => 0,
            'longest_streak' => 0,
            'last_violation_date' => null,
            'streak_start_date' => null,
            'total_workdays_evaluated' => 0,
            'badge' => null,
            'next_badge' => $this->nextBadgeFor(0),
        ];
    }
}
