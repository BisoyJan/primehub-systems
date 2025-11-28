<?php

namespace App\Services;

use App\Models\BiometricRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BiometricAnomalyDetector
{
    /**
     * Get database-specific date format expression for minute grouping
     */
    protected function getMinuteGroupExpression(): string
    {
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");

        if ($connection === 'sqlite') {
            return "strftime('%Y-%m-%d %H:%M', datetime)";
        }

        // MySQL/MariaDB
        return 'DATE_FORMAT(datetime, "%Y-%m-%d %H:%i")';
    }

    /**
     * Get database-specific hour extraction expression
     */
    protected function getHourExpression(): string
    {
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");

        if ($connection === 'sqlite') {
            return "CAST(strftime('%H', datetime) AS INTEGER)";
        }

        // MySQL/MariaDB
        return 'HOUR(datetime)';
    }

    /**
     * Detect all anomalies for a given date range
     */
    public function detectAnomalies(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? Carbon::now()->subDays(7);
        $endDate = $endDate ?? Carbon::now();

        return [
            'simultaneous_sites' => $this->detectSimultaneousSites($startDate, $endDate),
            'impossible_gaps' => $this->detectImpossibleTimeGaps($startDate, $endDate),
            'duplicate_scans' => $this->detectDuplicateScans($startDate, $endDate),
            'unusual_hours' => $this->detectUnusualHours($startDate, $endDate),
            'excessive_scans' => $this->detectExcessiveScans($startDate, $endDate),
        ];
    }

    /**
     * Detect scans at multiple sites within impossible timeframe
     */
    protected function detectSimultaneousSites(Carbon $startDate, Carbon $endDate): array
    {
        $maxTravelMinutes = 30; // Assume 30 minutes to travel between sites

        $records = BiometricRecord::whereBetween('record_date', [$startDate, $endDate])
            ->whereNotNull('user_id')
            ->whereNotNull('site_id')
            ->orderBy('user_id')
            ->orderBy('datetime')
            ->get();

        $anomalies = [];
        $groupedByUser = $records->groupBy('user_id');

        foreach ($groupedByUser as $userId => $userRecords) {
            for ($i = 0; $i < $userRecords->count() - 1; $i++) {
                $current = $userRecords[$i];
                $next = $userRecords[$i + 1];

                // Different sites?
                if ($current->site_id !== $next->site_id) {
                    $minutesApart = $current->datetime->diffInMinutes($next->datetime);

                    if ($minutesApart < $maxTravelMinutes) {
                        $anomalies[] = [
                            'type' => 'simultaneous_sites',
                            'severity' => $minutesApart < 10 ? 'high' : 'medium',
                            'user_id' => $userId,
                            'user_name' => $current->user?->name ?? 'Unknown',
                            'record_1' => [
                                'id' => $current->id,
                                'datetime' => $current->datetime,
                                'site' => $current->site?->name ?? "Site {$current->site_id}",
                            ],
                            'record_2' => [
                                'id' => $next->id,
                                'datetime' => $next->datetime,
                                'site' => $next->site?->name ?? "Site {$next->site_id}",
                            ],
                            'minutes_apart' => $minutesApart,
                            'description' => "Bio at {$minutesApart} minutes apart at different sites",
                        ];
                    }
                }
            }
        }

        return $anomalies;
    }

    /**
     * Detect impossible time gaps (e.g., time in at 8am, time out at 7am same day)
     */
    protected function detectImpossibleTimeGaps(Carbon $startDate, Carbon $endDate): array
    {
        $records = BiometricRecord::whereBetween('record_date', [$startDate, $endDate])
            ->whereNotNull('user_id')
            ->orderBy('user_id')
            ->orderBy('datetime')
            ->get();

        $anomalies = [];
        $groupedByUser = $records->groupBy('user_id');

        foreach ($groupedByUser as $userId => $userRecords) {
            $dailyRecords = $userRecords->groupBy(fn($r) => $r->datetime->format('Y-m-d'));

            foreach ($dailyRecords as $date => $dayRecords) {
                if ($dayRecords->count() >= 2) {
                    $sorted = $dayRecords->sortBy('datetime');
                    $first = $sorted->first();
                    $last = $sorted->last();

                    // Check for time going backwards on same calendar date
                    if ($first->datetime->hour > $last->datetime->hour) {
                        $anomalies[] = [
                            'type' => 'impossible_gap',
                            'severity' => 'high',
                            'user_id' => $userId,
                            'user_name' => $first->user?->name ?? 'Unknown',
                            'date' => $date,
                            'first_scan' => $first->datetime,
                            'last_scan' => $last->datetime,
                            'description' => "Time appears to go backwards on {$date}",
                        ];
                    }
                }
            }
        }

        return $anomalies;
    }

    /**
     * Detect duplicate scans (same employee, same minute)
     */
    protected function detectDuplicateScans(Carbon $startDate, Carbon $endDate): array
    {
        $minuteGroupExpr = $this->getMinuteGroupExpression();

        $duplicates = BiometricRecord::select(
                'user_id',
                DB::raw("{$minuteGroupExpr} as minute_group"),
                DB::raw('COUNT(*) as scan_count')
            )
            ->whereBetween('record_date', [$startDate, $endDate])
            ->whereNotNull('user_id')
            ->groupBy('user_id', 'minute_group')
            ->having('scan_count', '>', 1)
            ->get();

        $anomalies = [];

        foreach ($duplicates as $dup) {
            $records = BiometricRecord::where('user_id', $dup->user_id)
                ->whereRaw("{$minuteGroupExpr} = ?", [$dup->minute_group])
                ->with('user')
                ->get();

            $anomalies[] = [
                'type' => 'duplicate_scans',
                'severity' => $dup->scan_count > 3 ? 'high' : 'low',
                'user_id' => $dup->user_id,
                'user_name' => $records->first()->user?->name ?? 'Unknown',
                'datetime' => Carbon::parse($dup->minute_group),
                'scan_count' => $dup->scan_count,
                'record_ids' => $records->pluck('id'),
                'description' => "{$dup->scan_count} scans within same minute",
            ];
        }

        return $anomalies;
    }

    /**
     * Detect scans at unusual hours (e.g., 2am-5am)
     */
    protected function detectUnusualHours(Carbon $startDate, Carbon $endDate): array
    {
        $unusualHourStart = 2; // 2 AM
        $unusualHourEnd = 5;   // 5 AM

        $hourExpr = $this->getHourExpression();

        $records = BiometricRecord::whereBetween('record_date', [$startDate, $endDate])
            ->whereNotNull('user_id')
            ->whereRaw("{$hourExpr} >= ? AND {$hourExpr} < ?", [$unusualHourStart, $unusualHourEnd])
            ->with('user')
            ->get();

        $anomalies = [];

        foreach ($records as $record) {
            $anomalies[] = [
                'type' => 'unusual_hours',
                'severity' => 'low',
                'user_id' => $record->user_id,
                'user_name' => $record->user?->name ?? 'Unknown',
                'datetime' => $record->datetime,
                'record_id' => $record->id,
                'description' => "Scan at unusual hour ({$record->datetime->format('H:i')})",
            ];
        }

        return $anomalies;
    }

    /**
     * Detect excessive daily scans (more than expected)
     */
    protected function detectExcessiveScans(Carbon $startDate, Carbon $endDate): array
    {
        $maxScansPerDay = 6; // Normal: 2-4 scans (time in/out × lunch break)

        $dailyScans = BiometricRecord::select(
                'user_id',
                'record_date',
                DB::raw('COUNT(*) as scan_count')
            )
            ->whereBetween('record_date', [$startDate, $endDate])
            ->whereNotNull('user_id')
            ->groupBy('user_id', 'record_date')
            ->having('scan_count', '>', $maxScansPerDay)
            ->with('user')
            ->get();

        $anomalies = [];

        foreach ($dailyScans as $day) {
            $records = BiometricRecord::where('user_id', $day->user_id)
                ->where('record_date', $day->record_date)
                ->get();

            $anomalies[] = [
                'type' => 'excessive_scans',
                'severity' => $day->scan_count > 10 ? 'high' : 'medium',
                'user_id' => $day->user_id,
                'user_name' => $records->first()->user?->name ?? 'Unknown',
                'date' => $day->record_date,
                'scan_count' => $day->scan_count,
                'scans' => $records->map(fn($r) => [
                    'id' => $r->id,
                    'datetime' => $r->datetime,
                    'site' => $r->site?->name ?? "Site {$r->site_id}",
                ]),
                'description' => "{$day->scan_count} scans on {$day->record_date} (expected ≤{$maxScansPerDay})",
            ];
        }

        return $anomalies;
    }

    /**
     * Get anomaly statistics
     */
    public function getStatistics(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $anomalies = $this->detectAnomalies($startDate, $endDate);

        return [
            'total_anomalies' => array_sum(array_map('count', $anomalies)),
            'by_type' => array_map('count', $anomalies),
            'by_severity' => [
                'high' => $this->countBySeverity($anomalies, 'high'),
                'medium' => $this->countBySeverity($anomalies, 'medium'),
                'low' => $this->countBySeverity($anomalies, 'low'),
            ],
        ];
    }

    protected function countBySeverity(array $anomalies, string $severity): int
    {
        $count = 0;
        foreach ($anomalies as $type => $items) {
            $count += collect($items)->where('severity', $severity)->count();
        }
        return $count;
    }
}
