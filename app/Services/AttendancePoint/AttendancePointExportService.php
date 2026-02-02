<?php

namespace App\Services\AttendancePoint;

use App\Jobs\GenerateAllAttendancePointsExportExcel;
use App\Jobs\GenerateAttendancePointsExportExcel;
use App\Models\AttendancePoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Service responsible for attendance point export operations.
 */
class AttendancePointExportService
{
    private const CACHE_KEY_SINGLE = 'attendance_points_export:';

    private const CACHE_KEY_ALL = 'attendance_points_export_all:';

    /**
     * Export attendance points for a specific user as CSV.
     */
    public function exportCsv(User $user): array
    {
        $points = AttendancePoint::where('user_id', $user->id)
            ->with(['attendance', 'excusedBy'])
            ->orderBy('shift_date', 'desc')
            ->get();

        $filename = "attendance-points-{$user->id}-".now()->format('Y-m-d').'.csv';

        $handle = fopen('php://temp', 'w');

        // Headers
        fputcsv($handle, [
            'Date',
            'Type',
            'Points',
            'Status',
            'Violation Details',
            'Expires At',
            'Expiration Type',
            'Is Expired',
            'Expired At',
            'Is Excused',
            'Excuse Reason',
            'Excused By',
            'Excused At',
            'Tardy Minutes',
            'Undertime Minutes',
            'GBRO Eligible',
        ]);

        // Data
        foreach ($points as $point) {
            fputcsv($handle, [
                $point->shift_date,
                $point->point_type,
                $point->points,
                $point->is_expired ? 'Expired' : ($point->is_excused ? 'Excused' : 'Active'),
                $point->violation_details,
                $point->expires_at ? Carbon::parse($point->expires_at)->format('Y-m-d') : '',
                $point->expiration_type ?? '',
                $point->is_expired ? 'Yes' : 'No',
                $point->expired_at ? Carbon::parse($point->expired_at)->format('Y-m-d') : '',
                $point->is_excused ? 'Yes' : 'No',
                $point->excuse_reason ?? '',
                $point->excusedBy ? $point->excusedBy->name : '',
                $point->excused_at ? Carbon::parse($point->excused_at)->format('Y-m-d H:i:s') : '',
                $point->tardy_minutes ?? '',
                $point->undertime_minutes ?? '',
                $point->eligible_for_gbro ? 'Yes' : 'No',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return [
            'content' => $csv,
            'filename' => $filename,
        ];
    }

    /**
     * Start Excel export job for a specific user.
     */
    public function startUserExportExcel(int $userId): array
    {
        $jobId = Str::uuid()->toString();

        $job = new GenerateAttendancePointsExportExcel($jobId, $userId);

        if (config('queue.default') === 'sync') {
            Bus::dispatchSync($job);
        } else {
            Bus::dispatch($job);
        }

        return [
            'jobId' => $jobId,
            'message' => 'Export job started',
        ];
    }

    /**
     * Check single user export job progress.
     */
    public function checkUserExportStatus(string $jobId): array
    {
        $progress = Cache::get(self::CACHE_KEY_SINGLE.$jobId);

        if (! $progress) {
            return [
                'percent' => 0,
                'status' => 'Initializing...',
                'finished' => false,
                'error' => false,
            ];
        }

        return $progress;
    }

    /**
     * Get download info for user export.
     */
    public function getUserExportDownload(string $jobId): ?array
    {
        $cacheKey = self::CACHE_KEY_SINGLE.$jobId;
        $progress = Cache::get($cacheKey);

        if (! $progress || ! $progress['finished'] || empty($progress['downloadUrl'])) {
            return null;
        }

        $tempDir = storage_path('app/temp');
        $files = glob($tempDir.'/'.$jobId.'_*.xlsx');

        if (empty($files)) {
            Cache::forget($cacheKey);

            return null;
        }

        $filePath = $files[0];
        $filename = $progress['filename'] ?? basename($filePath);

        Cache::forget($cacheKey);

        return [
            'filePath' => $filePath,
            'filename' => $filename,
        ];
    }

    /**
     * Start Excel export job for all attendance points (with filters).
     */
    public function startAllExportExcel(array $filters): array
    {
        // Check if there are any matching records
        $query = AttendancePoint::query();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['point_type'])) {
            $query->where('point_type', $filters['point_type']);
        }

        if (! empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->where('is_excused', false)->where('is_expired', false);
            } elseif ($filters['status'] === 'excused') {
                $query->where('is_excused', true);
            } elseif ($filters['status'] === 'expired') {
                $query->where('is_expired', true);
            }
        }

        if (! empty($filters['date_from'])) {
            $query->where('shift_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('shift_date', '<=', $filters['date_to']);
        }

        $recordCount = $query->count();

        if ($recordCount === 0) {
            return [
                'error' => true,
                'message' => 'No attendance points found matching your selected filters.',
            ];
        }

        $jobId = Str::uuid()->toString();

        $job = new GenerateAllAttendancePointsExportExcel($jobId, $filters);

        if (config('queue.default') === 'sync') {
            Bus::dispatchSync($job);
        } else {
            Bus::dispatch($job);
        }

        return [
            'jobId' => $jobId,
            'message' => 'Export job started',
        ];
    }

    /**
     * Check all users export job progress.
     */
    public function checkAllExportStatus(string $jobId): array
    {
        $progress = Cache::get(self::CACHE_KEY_ALL.$jobId);

        if (! $progress) {
            return [
                'percent' => 0,
                'status' => 'Initializing...',
                'finished' => false,
                'error' => false,
            ];
        }

        return $progress;
    }

    /**
     * Get download info for all users export.
     */
    public function getAllExportDownload(string $jobId): ?array
    {
        $cacheKey = self::CACHE_KEY_ALL.$jobId;
        $progress = Cache::get($cacheKey);

        if (! $progress || ! $progress['finished'] || empty($progress['downloadUrl'])) {
            return null;
        }

        $tempDir = storage_path('app/temp');
        $files = glob($tempDir.'/'.$jobId.'_*.xlsx');

        if (empty($files)) {
            Cache::forget($cacheKey);

            return null;
        }

        $filePath = $files[0];
        $filename = $progress['filename'] ?? basename($filePath);

        Cache::forget($cacheKey);

        return [
            'filePath' => $filePath,
            'filename' => $filename,
        ];
    }
}
