<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class NotificationAnalyticsController extends Controller
{
    /**
     * Display the notification analytics dashboard.
     */
    public function index()
    {
        $data = Cache::remember('notification_analytics', 300, function () {
            return [
                'summary' => $this->getSummary(),
                'typeDistribution' => $this->getTypeDistribution(),
                'monthlyTrends' => $this->getMonthlyTrends(),
                'readRateByType' => $this->getReadRateByType(),
            ];
        });

        return Inertia::render('Notifications/Analytics', $data);
    }

    /**
     * Get summary statistics.
     *
     * @return array{total: int, read: int, unread: int, read_rate: float, scheduled_pending: int, last_30_days: int}
     */
    private function getSummary(): array
    {
        $total = Notification::count();
        $read = Notification::whereNotNull('read_at')->count();
        $unread = $total - $read;
        $scheduledPending = Notification::scheduledPending()->count();
        $last30Days = Notification::where('created_at', '>=', now()->subDays(30))->count();

        return [
            'total' => $total,
            'read' => $read,
            'unread' => $unread,
            'read_rate' => $total > 0 ? round(($read / $total) * 100, 1) : 0,
            'scheduled_pending' => $scheduledPending,
            'last_30_days' => $last30Days,
        ];
    }

    /**
     * Get notification count by type.
     *
     * @return array<int, array{type: string, count: int}>
     */
    private function getTypeDistribution(): array
    {
        return Notification::query()
            ->select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->orderByDesc('count')
            ->get()
            ->toArray();
    }

    /**
     * Get monthly notification trends for the last 6 months.
     *
     * @return array<int, array{month: string, label: string, total: int, read: int, unread: int}>
     */
    private function getMonthlyTrends(): array
    {
        $trends = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $month = $date->format('Y-m');
            $label = $date->format('M Y');

            $total = Notification::whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->count();

            $read = Notification::whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->whereNotNull('read_at')
                ->count();

            $trends[] = [
                'month' => $month,
                'label' => $label,
                'total' => $total,
                'read' => $read,
                'unread' => $total - $read,
            ];
        }

        return $trends;
    }

    /**
     * Get read rate broken down by notification type.
     *
     * @return array<int, array{type: string, total: int, read: int, read_rate: float}>
     */
    private function getReadRateByType(): array
    {
        return Notification::query()
            ->select(
                'type',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as read_count')
            )
            ->groupBy('type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type,
                'total' => $row->total,
                'read' => (int) $row->read_count,
                'read_rate' => $row->total > 0 ? round(((int) $row->read_count / $row->total) * 100, 1) : 0,
            ])
            ->toArray();
    }
}
