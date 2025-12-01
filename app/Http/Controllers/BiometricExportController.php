<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAttendanceExportExcel;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
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
        // Get all users who have attendance records with their campaign assignments
        $users = User::whereHas('attendances')
            ->select('id', 'first_name', 'last_name')
            ->with(['employeeSchedules' => function ($query) {
                $query->select('id', 'user_id', 'campaign_id', 'site_id')
                    ->whereHas('attendances');
            }])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($user) {
                // Get unique campaign IDs for this user
                $campaignIds = $user->employeeSchedules->pluck('campaign_id')->unique()->filter()->values()->toArray();
                // Get unique site IDs for this user
                $siteIds = $user->employeeSchedules->pluck('site_id')->unique()->filter()->values()->toArray();

                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'employee_number' => (string) $user->id,
                    'campaign_ids' => $campaignIds,
                    'site_ids' => $siteIds,
                ];
            });

        // Get all sites that have attendance records with their campaign associations
        $sites = Site::whereHas('employeeSchedules.attendances')
            ->select('id', 'name')
            ->with(['employeeSchedules' => function ($query) {
                $query->select('id', 'site_id', 'campaign_id')
                    ->whereHas('attendances');
            }])
            ->orderBy('name')
            ->get()
            ->map(function ($site) {
                $campaignIds = $site->employeeSchedules->pluck('campaign_id')->unique()->filter()->values()->toArray();
                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'campaign_ids' => $campaignIds,
                ];
            });

        // Get all campaigns that have employee schedules with attendance records
        $campaignIds = EmployeeSchedule::whereHas('attendances')
            ->distinct()
            ->pluck('campaign_id')
            ->filter();

        $campaigns = Campaign::whereIn('id', $campaignIds)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('Attendance/BiometricRecords/Export', [
            'users' => $users,
            'sites' => $sites,
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * Start an Excel export job
     */
    public function startExport(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
            'site_ids' => 'nullable|array',
            'site_ids.*' => 'exists:sites,id',
            'campaign_ids' => 'nullable|array',
            'campaign_ids.*' => 'exists:campaigns,id',
        ]);

        $jobId = (string) Str::uuid();

        // Get filter arrays
        $userIds = array_filter($request->input('user_ids', []), fn($id) => !empty($id));
        $siteIds = array_filter($request->input('site_ids', []), fn($id) => !empty($id));
        $campaignIds = array_filter($request->input('campaign_ids', []), fn($id) => !empty($id));

        Bus::dispatch(new GenerateAttendanceExportExcel(
            $jobId,
            $request->input('start_date'),
            $request->input('end_date'),
            array_values($userIds),
            array_values($siteIds),
            array_values($campaignIds)
        ));

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
        // Find the file - look for files matching the pattern
        $tempDir = storage_path('app/temp');
        $pattern = $tempDir . "/attendance_export_*_{$jobId}.xlsx";
        $files = glob($pattern);

        if (empty($files)) {
            abort(404, 'Export file not found');
        }

        $filePath = $files[0];
        $filename = basename($filePath);

        return response()->download($filePath, $filename)->deleteFileAfterSend(true);
    }
}
