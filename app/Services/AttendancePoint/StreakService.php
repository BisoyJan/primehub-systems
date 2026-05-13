<?php

namespace App\Services\AttendancePoint;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\LeaveRequest;
use App\Models\User;
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
        // Streak rankings are cached (expensive per-user computation).
        $allRanked = Cache::remember(
            "streak_leaderboard:{$limit}",
            self::CACHE_TTL,
            fn () => $this->computeLeaderboard($limit)
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

        return array_values(
            array_filter($allRanked, fn ($row) => ! isset($onLeaveIds[$row['user_id']]))
        );
    }

    /**
     * Invalidate cached streak for a user (called by AttendancePointObserver).
     */
    public function clearUserCache(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
        // Leaderboard rebuilds on next read.
        Cache::forget('streak_leaderboard:10');
        Cache::forget('streak_leaderboard:25');
        Cache::forget('streak_leaderboard:50');
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
        return "user_tardy_free_streak:{$userId}";
    }

    /**
     * Compute streak by walking the user's attendance history newest → oldest.
     */
    private function computeUserStreak(User $user): array
    {
        // All workdays for the user, newest first.
        $attendanceDates = Attendance::query()
            ->where('user_id', $user->id)
            ->orderByDesc('shift_date')
            ->pluck('shift_date')
            ->map(fn ($d) => $d->toDateString())
            ->unique()
            ->values()
            ->all();

        if (empty($attendanceDates)) {
            return $this->emptyResult();
        }

        // All non-excused negative point dates for the user (excused & expired-but-was-issued).
        // For streak purposes a violation breaks the streak even if later excused/expired
        // — we honour `is_excused` (administratively forgiven => doesn't break streak).
        $violationDates = AttendancePoint::query()
            ->where('user_id', $user->id)
            ->where('is_excused', false)
            ->pluck('shift_date')
            ->map(fn ($d) => $d->toDateString())
            ->unique()
            ->flip();

        $currentStreak = 0;
        $longestStreak = 0;
        $running = 0;
        $streakStart = null;
        $lastViolation = null;
        $currentClosed = false;

        foreach ($attendanceDates as $date) {
            $isViolation = isset($violationDates[$date]);

            if ($isViolation) {
                if (! $currentClosed) {
                    $currentStreak = $running;
                    $currentClosed = true;
                }
                if ($lastViolation === null || $date > $lastViolation) {
                    $lastViolation = $date;
                }
                $longestStreak = max($longestStreak, $running);
                $running = 0;

                continue;
            }

            $running++;
            if (! $currentClosed) {
                $streakStart = $date; // Walking newest→oldest; final value is earliest date in run.
            }
            $longestStreak = max($longestStreak, $running);
        }

        if (! $currentClosed) {
            $currentStreak = $running;
        }

        return [
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'last_violation_date' => $lastViolation,
            'streak_start_date' => $currentStreak > 0 ? $streakStart : null,
            'total_workdays_evaluated' => count($attendanceDates),
            'badge' => $this->badgeFor($currentStreak),
            'next_badge' => $this->nextBadgeFor($currentStreak),
        ];
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function computeLeaderboard(int $limit): array
    {
        // Pull users who have at least one attendance row to keep the set bounded.
        $userIds = Attendance::query()
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('is_active', true)
            ->select(['id', 'first_name', 'middle_name', 'last_name', 'role'])
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

        return array_slice($rows, 0, $limit);
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
