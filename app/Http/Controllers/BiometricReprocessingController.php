<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\BiometricRecord;
use App\Models\User;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BiometricReprocessingController extends Controller
{
    protected AttendanceProcessor $processor;

    public function __construct(AttendanceProcessor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Display the reprocessing interface
     */
    public function index()
    {
        return Inertia::render('Attendance/BiometricRecords/Reprocessing', [
            'stats' => [
                'total_records' => BiometricRecord::count(),
                'oldest_record' => BiometricRecord::orderBy('datetime')->first()?->datetime,
                'newest_record' => BiometricRecord::orderBy('datetime', 'desc')->first()?->datetime,
            ]
        ]);
    }

    /**
     * Preview what will be reprocessed
     */
    public function preview(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

    $startDate = Carbon::parse($request->start_date)->format('Y-m-d');
    $endDate = Carbon::parse($request->end_date)->format('Y-m-d');

    // record_date is stored as Y-m-d strings - compare using Y-m-d strings to avoid type/format mismatch
    $query = BiometricRecord::whereBetween('record_date', [$startDate, $endDate]);

        if ($request->user_ids) {
            $query->whereIn('user_id', $request->user_ids);
        }

        $records = $query->with('user:id,first_name,last_name')->get();

        $affectedUsers = $records->pluck('user')->unique('id')->values();
        $affectedDates = $records->pluck('record_date')->unique()->sort()->values();

        $preview = [
            'total_records' => $records->count(),
            'affected_users' => $affectedUsers->count(),
            'affected_dates' => $affectedDates->count(),
            'date_range' => [
                'start' => $affectedDates->first(),
                'end' => $affectedDates->last(),
            ],
            'users' => $affectedUsers->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->first_name . ' ' . $u->last_name,
                'record_count' => $records->where('user_id', $u->id)->count(),
            ])->toArray(),
        ];

        // Return the same Inertia page with preview data in props so the frontend can read page.props.preview
        return Inertia::render('Attendance/BiometricRecords/Reprocessing', [
            'stats' => [
                'total_records' => BiometricRecord::count(),
                'oldest_record' => BiometricRecord::orderBy('datetime')->first()?->datetime,
                'newest_record' => BiometricRecord::orderBy('datetime', 'desc')->first()?->datetime,
            ],
            'preview' => $preview,
        ]);
    }

    /**
     * Execute reprocessing
     */
    public function reprocess(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
            'delete_existing' => 'boolean',
        ]);

    $startDate = Carbon::parse($request->start_date)->format('Y-m-d');
    $endDate = Carbon::parse($request->end_date)->format('Y-m-d');
        $deleteExisting = $request->boolean('delete_existing', true);

        // Get affected users
        $userIds = $request->user_ids ?: BiometricRecord::whereBetween('record_date', [$startDate, $endDate])
            ->distinct()
            ->pluck('user_id')
            ->filter()
            ->toArray();

        $users = User::whereIn('id', $userIds)->get();

        $results = [
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
            'details' => [],
        ];

        foreach ($users as $user) {
            try {
                // Get biometric records for this user in date range
                $records = BiometricRecord::where('user_id', $user->id)
                    ->whereBetween('record_date', [$startDate, $endDate])
                    ->orderBy('datetime')
                    ->get()
                    ->map(fn($r) => [
                        'datetime' => $r->datetime,
                        'normalized_name' => $r->employee_name,
                        'employee_name' => $r->employee_name,
                    ]);

                if ($records->isEmpty()) {
                    continue;
                }

                // Delete existing attendance if requested
                if ($deleteExisting) {
                    $deleted = Attendance::where('user_id', $user->id)
                        ->whereBetween('shift_date', [$startDate, $endDate])
                        ->delete();
                }

                // Group and process records by shift date
                $reflection = new \ReflectionClass($this->processor);

                $groupMethod = $reflection->getMethod('groupRecordsByShiftDate');
                $groupMethod->setAccessible(true);
                $grouped = $groupMethod->invoke($this->processor, $records, $user);

                $processMethod = $reflection->getMethod('processShift');
                $processMethod->setAccessible(true);

                $processedShifts = 0;
                foreach ($grouped as $shiftDate => $shiftRecords) {
                    $result = $processMethod->invoke($this->processor, $user, $shiftRecords, Carbon::parse($shiftDate));
                    if ($result['processed']) {
                        $processedShifts++;
                    }
                }

                $results['processed']++;
                $results['details'][] = [
                    'user' => $user->first_name . ' ' . $user->last_name,
                    'shifts_processed' => $processedShifts,
                    'records_count' => $records->count(),
                ];

            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'user' => $user->first_name . ' ' . $user->last_name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Return Inertia render so frontend receives results in page.props.results
        return Inertia::render('Attendance/BiometricRecords/Reprocessing', [
            'stats' => [
                'total_records' => BiometricRecord::count(),
                'oldest_record' => BiometricRecord::orderBy('datetime')->first()?->datetime,
                'newest_record' => BiometricRecord::orderBy('datetime', 'desc')->first()?->datetime,
            ],
            'results' => $results,
        ]);
    }

    /**
     * Fix attendance statuses based on existing time in/out data
     */
    public function fixStatuses(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date)->format('Y-m-d');
        $endDate = Carbon::parse($request->end_date)->format('Y-m-d');

        $attendances = Attendance::whereBetween('shift_date', [$startDate, $endDate])
            ->with('employeeSchedule', 'user')
            ->get();

        $updated = 0;
        $details = [];

        foreach ($attendances as $att) {
            if (!$att->employeeSchedule) {
                continue;
            }

            $oldStatus = $att->status;
            $oldSecondaryStatus = $att->secondary_status;
            $hasTimeIn = $att->actual_time_in !== null;
            $hasTimeOut = $att->actual_time_out !== null;

            // Recalculate status based on what we have
            if (!$hasTimeIn && !$hasTimeOut) {
                $att->status = 'ncns';
                $att->secondary_status = null;
            } elseif (!$hasTimeIn && $hasTimeOut) {
                $att->status = 'failed_bio_in';
                $att->secondary_status = null;
            } elseif ($hasTimeIn && !$hasTimeOut) {
                // Calculate tardiness
                $shiftDate = is_string($att->shift_date) ? $att->shift_date : Carbon::parse($att->shift_date)->format('Y-m-d');
                $scheduledIn = Carbon::parse($shiftDate . ' ' . $att->employeeSchedule->scheduled_time_in);
                $actualIn = Carbon::parse($att->actual_time_in);
                $tardyMins = (int) $scheduledIn->diffInMinutes($actualIn, false);

                if ($tardyMins <= 0 || $actualIn->lessThanOrEqualTo($scheduledIn)) {
                    $att->status = 'failed_bio_out'; // On time but missing out
                    $att->secondary_status = null;
                    $att->tardy_minutes = null;
                } elseif ($tardyMins >= 1 && $tardyMins <= 15) {
                    $att->status = 'tardy'; // Keep tardy as primary
                    $att->secondary_status = 'failed_bio_out'; // Add missing out as secondary
                    $att->tardy_minutes = $tardyMins;
                } else {
                    $att->status = 'half_day_absence'; // Keep half day as primary
                    $att->secondary_status = 'failed_bio_out'; // Add missing out as secondary
                    $att->tardy_minutes = $tardyMins;
                }
            } elseif ($hasTimeIn && $hasTimeOut) {
                // Both exist, recalculate full status
                $shiftDate = is_string($att->shift_date) ? $att->shift_date : Carbon::parse($att->shift_date)->format('Y-m-d');
                $scheduledIn = Carbon::parse($shiftDate . ' ' . $att->employeeSchedule->scheduled_time_in);
                $actualIn = Carbon::parse($att->actual_time_in);
                $tardyMins = (int) $scheduledIn->diffInMinutes($actualIn, false);

                if ($tardyMins <= 0 || $actualIn->lessThanOrEqualTo($scheduledIn)) {
                    $att->status = 'on_time';
                    $att->tardy_minutes = null;
                } elseif ($tardyMins >= 1 && $tardyMins <= 15) {
                    $att->status = 'tardy';
                    $att->tardy_minutes = $tardyMins;
                } else {
                    $att->status = 'half_day_absence';
                    $att->tardy_minutes = $tardyMins;
                }

                $att->secondary_status = null;
            }

            if ($oldStatus !== $att->status || $oldSecondaryStatus !== $att->secondary_status) {
                $att->save();
                $updated++;
                $details[] = [
                    'user' => $att->user->first_name . ' ' . $att->user->last_name,
                    'date' => $att->shift_date->format('Y-m-d'),
                    'old_status' => $oldStatus,
                    'new_status' => $att->status,
                    'secondary_status' => $att->secondary_status,
                ];
            }
        }

        return Inertia::render('Attendance/BiometricRecords/Reprocessing', [
            'stats' => [
                'total_records' => BiometricRecord::count(),
                'oldest_record' => BiometricRecord::orderBy('datetime')->first()?->datetime,
                'newest_record' => BiometricRecord::orderBy('datetime', 'desc')->first()?->datetime,
            ],
            'fixResults' => [
                'updated' => $updated,
                'total_checked' => $attendances->count(),
                'details' => $details,
            ],
        ]);
    }
}

