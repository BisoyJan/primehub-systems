<?php

namespace App\Services\AttendancePoint;

use App\Models\AttendancePoint;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Service responsible for attendance point statistics calculations.
 */
class AttendancePointStatsService
{
    /**
     * Calculate attendance point statistics with optional filters.
     */
    public function calculateStats(Request $request, ?int $userId = null): array
    {
        $query = AttendancePoint::query();

        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->dateRange($request->date_from, $request->date_to);
        }

        $allPoints = $query->get();

        return [
            'total_points' => $allPoints->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'excused_points' => $allPoints->where('is_excused', true)->sum('points'),
            'expired_points' => $allPoints->where('is_expired', true)->sum('points'),
            'total_violations' => $allPoints->where('is_excused', false)->where('is_expired', false)->count(),
            'by_type' => $this->calculateStatsByType($allPoints),
            'high_points_employees' => $this->getHighPointsEmployees(),
        ];
    }

    /**
     * Calculate statistics grouped by point type.
     */
    private function calculateStatsByType(Collection $points): array
    {
        $types = ['whole_day_absence', 'half_day_absence', 'undertime', 'undertime_more_than_hour', 'tardy'];

        return collect($types)->mapWithKeys(function ($type) use ($points) {
            return [$type => $points->where('point_type', $type)
                ->where('is_excused', false)
                ->where('is_expired', false)
                ->sum('points')];
        })->toArray();
    }

    /**
     * Calculate totals for a user's points collection.
     */
    public function calculateTotals(Collection $points): array
    {
        $types = ['whole_day_absence', 'half_day_absence', 'undertime', 'undertime_more_than_hour', 'tardy'];

        $byType = collect($types)->mapWithKeys(function ($type) use ($points) {
            return [$type => $points->where('point_type', $type)
                ->where('is_excused', false)
                ->where('is_expired', false)
                ->sum('points')];
        })->toArray();

        $countByType = collect($types)->mapWithKeys(function ($type) use ($points) {
            return [$type => $points->where('point_type', $type)
                ->where('is_excused', false)
                ->where('is_expired', false)
                ->count()];
        })->toArray();

        return [
            'total_points' => $points->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'excused_points' => $points->where('is_excused', true)->sum('points'),
            'expired_points' => $points->where('is_expired', true)->sum('points'),
            'by_type' => $byType,
            'count_by_type' => $countByType,
        ];
    }

    /**
     * Get employees with 6 or more active attendance points.
     */
    public function getHighPointsEmployees(): array
    {
        $highPointsUserIds = AttendancePoint::where('is_excused', false)
            ->where('is_expired', false)
            ->selectRaw('user_id, SUM(points) as total_points, COUNT(*) as violations_count')
            ->groupBy('user_id')
            ->havingRaw('SUM(points) >= 6')
            ->orderByDesc('total_points')
            ->pluck('user_id')
            ->toArray();

        if (empty($highPointsUserIds)) {
            return [];
        }

        $allPoints = AttendancePoint::with('user')
            ->whereIn('user_id', $highPointsUserIds)
            ->where('is_excused', false)
            ->where('is_expired', false)
            ->orderBy('shift_date', 'desc')
            ->get();

        return collect($highPointsUserIds)->map(function ($userId) use ($allPoints) {
            $userPoints = $allPoints->where('user_id', $userId);
            $user = $userPoints->first()?->user;

            return [
                'user_id' => $userId,
                'user_name' => $user ? ($user->last_name.', '.$user->first_name) : 'Unknown',
                'total_points' => round($userPoints->sum('points'), 2),
                'violations_count' => $userPoints->count(),
                'points' => $userPoints->map(function ($point) {
                    return [
                        'id' => $point->id,
                        'shift_date' => $point->shift_date,
                        'point_type' => $point->point_type,
                        'points' => $point->points,
                        'violation_details' => $point->violation_details,
                        'expires_at' => $point->expires_at,
                    ];
                })->values()->toArray(),
            ];
        })->sortByDesc('total_points')->values()->toArray();
    }

    /**
     * Get user statistics for JSON API response.
     */
    public function getUserStatistics(int $userId): array
    {
        $points = AttendancePoint::where('user_id', $userId)->get();

        $byType = [
            'whole_day_absence' => $points->where('point_type', 'whole_day_absence')->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'half_day_absence' => $points->where('point_type', 'half_day_absence')->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'undertime' => $points->where('point_type', 'undertime')->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'undertime_more_than_hour' => $points->where('point_type', 'undertime_more_than_hour')->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'tardy' => $points->where('point_type', 'tardy')->where('is_excused', false)->where('is_expired', false)->sum('points'),
        ];

        return [
            'total_points' => $points->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'active_points' => $points->where('is_excused', false)->where('is_expired', false)->count(),
            'expired_points' => $points->where('is_expired', true)->count(),
            'excused_points' => $points->where('is_excused', true)->count(),
            'by_type' => $byType,
        ];
    }
}
