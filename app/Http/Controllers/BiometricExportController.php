<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAttendanceExportExcel;
use App\Models\Attendance;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

class BiometricExportController extends Controller
{
    /**
     * Display the export interface
     */
    public function index()
    {
        // Single query to build user→campaign/site and site→campaign mappings from employee_schedules
        $schedules = EmployeeSchedule::select('user_id', 'site_id', 'campaign_id')
            ->whereHas('attendances')
            ->get();

        $userScheduleMap = $schedules->groupBy('user_id');
        $siteScheduleMap = $schedules->groupBy('site_id');
        $activeCampaignIds = $schedules->pluck('campaign_id')->unique()->filter();

        // Users with attendance records
        $users = User::whereHas('attendances')
            ->select('id', 'first_name', 'last_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($user) use ($userScheduleMap) {
                $rows = $userScheduleMap->get($user->id, collect());

                return [
                    'id' => $user->id,
                    'name' => $user->first_name.' '.$user->last_name,
                    'employee_number' => (string) $user->id,
                    'campaign_ids' => $rows->pluck('campaign_id')->unique()->filter()->values()->toArray(),
                    'site_ids' => $rows->pluck('site_id')->unique()->filter()->values()->toArray(),
                ];
            });

        // Sites linked to attendance-bearing schedules
        $sites = Site::whereHas('employeeSchedules.attendances')
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($site) use ($siteScheduleMap) {
                $rows = $siteScheduleMap->get($site->id, collect());

                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'campaign_ids' => $rows->pluck('campaign_id')->unique()->filter()->values()->toArray(),
                ];
            });

        $campaigns = Campaign::whereIn('id', $activeCampaignIds)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        // Get all users who have attendance points
        $pointsUsers = User::whereHas('attendancePoints')
            ->select('id', 'first_name', 'last_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name.' '.$user->last_name,
                ];
            });

        // Get point types from the model constant
        $pointTypes = [
            ['value' => 'whole_day_absence', 'label' => 'Whole Day Absence'],
            ['value' => 'half_day_absence', 'label' => 'Half Day Absence'],
            ['value' => 'tardy', 'label' => 'Tardy'],
            ['value' => 'undertime', 'label' => 'Undertime'],
        ];

        // Get status options
        $pointStatuses = [
            ['value' => 'active', 'label' => 'Active'],
            ['value' => 'excused', 'label' => 'Excused'],
            ['value' => 'expired', 'label' => 'Expired'],
        ];

        return Inertia::render('Attendance/BiometricRecords/Export', [
            'users' => $users,
            'sites' => $sites,
            'campaigns' => $campaigns,
            'pointsUsers' => $pointsUsers,
            'pointTypes' => $pointTypes,
            'pointStatuses' => $pointStatuses,
        ]);
    }

    /**
     * Start an Excel export job
     */
    public function startExport(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
                function (string $attr, mixed $value, \Closure $fail) use ($request): void {
                    if ($request->start_date && Carbon::parse($value)->diffInDays(Carbon::parse($request->start_date)) > 365) {
                        $fail('Export date range cannot exceed 1 year.');
                    }
                },
            ],
            'user_ids' => 'nullable|array|max:500',
            'user_ids.*' => 'exists:users,id',
            'site_ids' => 'nullable|array|max:500',
            'site_ids.*' => 'exists:sites,id',
            'campaign_ids' => 'nullable|array|max:500',
            'campaign_ids.*' => 'exists:campaigns,id',
        ]);

        // Get filter arrays
        $userIds = array_filter($request->input('user_ids', []), fn ($id) => ! empty($id));
        $siteIds = array_filter($request->input('site_ids', []), fn ($id) => ! empty($id));
        $campaignIds = array_filter($request->input('campaign_ids', []), fn ($id) => ! empty($id));

        // Check if there are any matching records before starting the export
        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : null;
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : null;

        $query = Attendance::query();

        if ($startDate && $endDate) {
            $query->whereBetween('shift_date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ]);
        } elseif ($startDate) {
            $query->where('shift_date', '>=', $startDate->toDateString());
        } elseif ($endDate) {
            $query->where('shift_date', '<=', $endDate->toDateString());
        }

        if (! empty($userIds)) {
            $query->whereIn('user_id', $userIds);
        }

        if (! empty($siteIds)) {
            $query->where(function ($q) use ($siteIds) {
                $q->whereIn('bio_in_site_id', $siteIds)
                    ->orWhereIn('bio_out_site_id', $siteIds)
                    ->orWhereHas('employeeSchedule', function ($subQ) use ($siteIds) {
                        $subQ->whereIn('site_id', $siteIds);
                    });
            });
        }

        if (! empty($campaignIds)) {
            $query->whereHas('employeeSchedule', function ($subQ) use ($campaignIds) {
                $subQ->whereIn('campaign_id', $campaignIds);
            });
        }

        $recordCount = $query->count();

        if ($recordCount === 0) {
            return response()->json([
                'error' => true,
                'message' => 'No attendance records found matching your selected filters.',
            ], 422);
        }

        $jobId = (string) Str::uuid();

        $job = new GenerateAttendanceExportExcel(
            $jobId,
            $request->input('start_date'),
            $request->input('end_date'),
            array_values($userIds),
            array_values($siteIds),
            array_values($campaignIds)
        );

        // Use dispatchSync for immediate execution, or dispatch for queue
        if (config('queue.default') === 'sync') {
            Bus::dispatchSync($job);
        } else {
            Bus::dispatch($job);
        }

        return response()->json(['jobId' => $jobId]);
    }

    /**
     * Check export job progress
     */
    public function exportProgress($jobId)
    {
        $cacheKey = "attendance_export_job:{$jobId}";
        $progress = Cache::get($cacheKey, [
            'percent' => 0,
            'status' => 'Not started',
            'finished' => false,
            'downloadUrl' => null,
        ]);

        return response()->json($progress);
    }

    /**
     * Download the generated Excel file
     */
    public function downloadExport($jobId)
    {
        $cacheKey = "attendance_export_job:{$jobId}";

        // Find the file by scanning the temp directory.
        // We avoid glob() to keep filename pattern matching explicit.
        $tempDir = storage_path('app/temp');
        $suffix = "_{$jobId}.xlsx";
        $files = is_dir($tempDir) ? array_values(array_filter(
            scandir($tempDir) ?: [],
            static fn (string $name): bool => str_starts_with($name, 'attendance_export_')
                && str_ends_with($name, $suffix)
        )) : [];

        if (empty($files)) {
            // Clear cache since file no longer exists
            Cache::forget($cacheKey);
            abort(404, 'Export file not found. Please generate a new export.');
        }

        $filePath = $tempDir.DIRECTORY_SEPARATOR.$files[0];
        $filename = $files[0];

        // Clear cache after download since file will be deleted
        Cache::forget($cacheKey);

        return response()->download($filePath, $filename)->deleteFileAfterSend(true);
    }
}
