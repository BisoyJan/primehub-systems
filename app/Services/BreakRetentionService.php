<?php

namespace App\Services;

use App\Models\BreakRetentionPolicy;
use App\Models\BreakSession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class BreakRetentionService
{
    private const AGE_RANGES = [
        ['label' => '0-3 months', 'start' => 0, 'end' => 3],
        ['label' => '3-6 months', 'start' => 3, 'end' => 6],
        ['label' => '6-12 months', 'start' => 6, 'end' => 12],
        ['label' => '12-24 months', 'start' => 12, 'end' => 24],
        ['label' => '24+ months', 'start' => 24, 'end' => null],
    ];

    /**
     * Sessions older than this shift date are past the given retention period.
     */
    public function cutoffDate(int $retentionMonths): Carbon
    {
        return Carbon::now()->subMonths($retentionMonths)->startOfDay();
    }

    /**
     * Break sessions belonging to a retention break type ('all' for every type).
     */
    public function sessions(string $breakType = 'all'): Builder
    {
        return BreakSession::query()->when(
            $breakType !== 'all',
            fn (Builder $query) => $query->whereIn('type', BreakRetentionPolicy::BREAK_TYPES[$breakType]['session_types'])
        );
    }

    /**
     * Sessions past the cutoff. Active and paused sessions are never included,
     * so a session that is still running is left for investigation, not purged.
     */
    public function expiredSessions(string $breakType, Carbon $cutoffDate): Builder
    {
        return $this->sessions($breakType)
            ->where('shift_date', '<', $cutoffDate->toDateString())
            ->whereNotIn('status', ['active', 'paused']);
    }

    /**
     * Sessions that become past the cutoff within the next given number of days.
     */
    public function expiringSessions(string $breakType, Carbon $cutoffDate, int $warningDays): Builder
    {
        return $this->sessions($breakType)
            ->whereBetween('shift_date', [
                $cutoffDate->toDateString(),
                $cutoffDate->copy()->addDays($warningDays)->toDateString(),
            ])
            ->whereNotIn('status', ['active', 'paused']);
    }

    /**
     * Limit sessions to users scheduled at the site, or to users without a site when null.
     */
    public function scopeToSite(Builder $query, ?int $siteId): Builder
    {
        if ($siteId) {
            return $query->whereHas('user.activeSchedule', function (Builder $schedule) use ($siteId) {
                $schedule->where('site_id', $siteId);
            });
        }

        return $query->where(function (Builder $unassigned) {
            $unassigned->whereDoesntHave('user.activeSchedule')
                ->orWhereHas('user.activeSchedule', function (Builder $schedule) {
                    $schedule->whereNull('site_id');
                });
        });
    }

    /**
     * Session counts per break type, bucketed by age of the shift date.
     *
     * @return array<string, array{label: string, total: int, byAge: list<array{range: string, count: int}>}>
     */
    public function stats(): array
    {
        $today = Carbon::now()->startOfDay();
        $stats = [];

        foreach (BreakRetentionPolicy::BREAK_TYPES as $breakType => $config) {
            $byAge = [];

            foreach (self::AGE_RANGES as $range) {
                $query = $this->sessions($breakType)
                    ->where('shift_date', '<=', $today->copy()->subMonths($range['start'])->toDateString());

                if ($range['end'] !== null) {
                    $query->where('shift_date', '>', $today->copy()->subMonths($range['end'])->toDateString());
                }

                $byAge[] = ['range' => $range['label'], 'count' => $query->count()];
            }

            $stats[$breakType] = [
                'label' => $config['label'],
                'total' => $this->sessions($breakType)->count(),
                'byAge' => $byAge,
            ];
        }

        return $stats;
    }

    /**
     * Sessions a policy would delete if it were the one applied to its scope.
     *
     * @return array{policy: array<string, mixed>, cutoff_date: string, preview: list<array<string, mixed>>, total_affected: int}
     */
    public function preview(BreakRetentionPolicy $policy): array
    {
        $cutoffDate = $this->cutoffDate($policy->retention_months);
        $breakTypes = $policy->break_type === 'all'
            ? array_keys(BreakRetentionPolicy::BREAK_TYPES)
            : [$policy->break_type];

        $preview = [];

        foreach ($breakTypes as $breakType) {
            $query = $this->expiredSessions($breakType, $cutoffDate);

            if ($policy->applies_to_type === 'site' && $policy->applies_to_id) {
                $this->scopeToSite($query, $policy->applies_to_id);
            }

            $preview[] = [
                'break_type' => $breakType,
                'label' => BreakRetentionPolicy::BREAK_TYPES[$breakType]['label'],
                'count' => $query->count(),
                'oldest_date' => $query->min('shift_date'),
                'newest_date' => $query->max('shift_date'),
            ];
        }

        return [
            'policy' => $policy->only(['id', 'name', 'retention_months', 'applies_to_type', 'break_type']),
            'cutoff_date' => $cutoffDate->toDateString(),
            'preview' => $preview,
            'total_affected' => array_sum(array_column($preview, 'count')),
        ];
    }
}
