<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceImportRequest;
use App\Models\AttendanceLog;
use App\Models\Attendance;
use App\Models\User;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;

class AttendanceImportController extends Controller
{
    /**
     * IMPORTANT: Multi-Device Biometric Support
     *
     * This controller handles attendance logs from MULTIPLE biometric devices.
     * Key considerations:
     * - UserIds are NOT consistent across devices (same employee = different UserIds)
     * - Employee NAMES are the reliable identifier (but may vary in case: CAPSLOCK, lowercase, Title Case)
     * - All matching is done using CASE-INSENSITIVE name comparison
     * - LOWER(TRIM(employee_name)) is used as the normalized key
     *
     * Workflow:
     * 1. Import daily .txt file → Store raw logs in attendance_logs table
     * 2. Normalize employee names (case-insensitive)
     * 3. Match time-in (evening) with time-out (next morning) by normalized name
     * 4. Create/update attendance records
     */

    /**
     * Show the import page
     */
    public function create(): Response
    {
        $sites = Site::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Attendance/Import', [
            'sites' => $sites,
        ]);
    }

    /**
     * Process the uploaded attendance file
     */
    public function store(AttendanceImportRequest $request)
    {
        try {
            DB::beginTransaction();

            $file = $request->file('file');
            $fileDate = Carbon::parse($request->input('file_date'));
            $siteId = $request->input('site_id');

            // Read and parse the file
            $content = file_get_contents($file->getRealPath());
            $lines = explode("\n", $content);

            $logs = [];
            $skippedLines = 0;

            foreach ($lines as $index => $line) {
                // Skip header line
                if ($index === 0 || trim($line) === '') {
                    continue;
                }

                // Parse tab-separated values
                $columns = preg_split('/\t/', trim($line));

                if (count($columns) < 6) {
                    $skippedLines++;
                    continue;
                }

                try {
                    // Parse datetime with potential double spaces
                    $dateTimeStr = preg_replace('/\s{2,}/', ' ', $columns[5]);
                    $logDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $dateTimeStr);

                    $logs[] = [
                        'device_no' => $columns[1] ?? null,
                        'user_id_from_file' => $columns[2] ?? 'UNKNOWN',
                        'employee_name' => $columns[3] ?? 'Unknown Employee',
                        'mode' => $columns[4] ?? 'FP',
                        'log_datetime' => $logDateTime,
                        'file_date' => $fileDate,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } catch (\Exception $e) {
                    $skippedLines++;
                    continue;
                }
            }

            // Insert logs
            if (!empty($logs)) {
                AttendanceLog::insert($logs);
            }

            // Process attendance matching
            $matchedCount = $this->processAttendanceMatching($fileDate, $siteId);

            DB::commit();

            return redirect()->route('attendance.index')
                ->with('success', sprintf(
                    'Successfully imported %d log entries. %d attendance records created/updated. %d lines skipped.',
                    count($logs),
                    $matchedCount,
                    $skippedLines
                ));

        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()
                ->withErrors(['file' => 'Error processing file: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Match time-in and time-out to create attendance records
     * Uses case-insensitive name matching to handle multiple biometric devices
     */
    protected function processAttendanceMatching($fileDate, $siteId = null): int
    {
        $matchedCount = 0;

        // Get all unique employees from logs by NORMALIZED name (case-insensitive)
        $employees = AttendanceLog::selectRaw('LOWER(TRIM(employee_name)) as normalized_name, MAX(employee_name) as employee_name')
            ->groupBy(DB::raw('LOWER(TRIM(employee_name))'))
            ->get();

        foreach ($employees as $employee) {
            $normalizedName = $employee->normalized_name;
            $displayName = $employee->employee_name;

            // Find user in users table (case-insensitive name matching)
            $user = User::whereRaw("LOWER(CONCAT(first_name, ' ', COALESCE(CONCAT(middle_name, ' '), ''), last_name)) LIKE ?",
                ["%{$normalizedName}%"])
                ->first();

            // Get time-in from current file date (evening entries) - match by normalized name
            $timeIn = AttendanceLog::whereRaw('LOWER(TRIM(employee_name)) = ?', [$normalizedName])
                ->whereDate('log_datetime', $fileDate)
                ->whereTime('log_datetime', '>=', '18:00:00') // Evening shift start (after 6pm)
                ->orderBy('log_datetime', 'asc')
                ->first();

            // Get time-out from next day (morning entries) - match by normalized name
            $nextDay = $fileDate->copy()->addDay();
            $timeOut = AttendanceLog::whereRaw('LOWER(TRIM(employee_name)) = ?', [$normalizedName])
                ->whereDate('log_datetime', $nextDay)
                ->whereTime('log_datetime', '<=', '12:00:00') // Morning shift end (before noon)
                ->orderBy('log_datetime', 'asc')
                ->first();

            // Create or update attendance if we have time-in
            if ($timeIn) {
                $durationMinutes = null;
                $status = 'present';

                if ($timeOut) {
                    $durationMinutes = $timeIn->log_datetime->diffInMinutes($timeOut->log_datetime);
                } else {
                    $status = 'incomplete'; // No time-out yet
                }

                // Use normalized name + time_in as unique identifier
                Attendance::updateOrCreate(
                    [
                        'employee_name' => $displayName, // Use display name for storage
                        'time_in' => $timeIn->log_datetime,
                    ],
                    [
                        'user_id' => $user?->id,
                        'user_id_from_file' => $timeIn->user_id_from_file, // Store the UserId from latest log
                        'site_id' => $siteId,
                        'shift' => 'night',
                        'status' => $status,
                        'time_out' => $timeOut?->log_datetime,
                        'duration_minutes' => $durationMinutes,
                    ]
                );

                $matchedCount++;
            }
        }

        return $matchedCount;
    }

    /**
     * Helper: Find potential duplicate employee names across devices
     * Useful for debugging name variations
     */
    protected function findNameVariations(): array
    {
        $variations = AttendanceLog::selectRaw('
                employee_name,
                user_id_from_file,
                device_no,
                LOWER(TRIM(employee_name)) as normalized_name
            ')
            ->distinct()
            ->get()
            ->groupBy('normalized_name')
            ->filter(fn($group) => $group->count() > 1) // Only names with variations
            ->map(fn($group) => $group->pluck('employee_name', 'user_id_from_file')->toArray())
            ->toArray();

        return $variations;
    }
}
