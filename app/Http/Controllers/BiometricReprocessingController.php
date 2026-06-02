<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\BiometricRecord;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BiometricReprocessingController extends Controller
{
    public function __construct(protected AttendanceProcessor $processor) {}

    /**
     * Display the reprocessing interface
     */
    public function index()
    {
        // Get employees who have biometric records (query via BiometricRecord)
        $employeeIds = BiometricRecord::distinct()->pluck('user_id')->filter()->toArray();

        // Get employees with their active schedule's campaign
        $employees = User::select('id', 'first_name', 'last_name')
            ->whereIn('id', $employeeIds)
            ->with(['activeSchedule:id,user_id,campaign_id'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->first_name.' '.$u->last_name,
                'campaign_id' => $u->activeSchedule?->campaign_id,
            ]);

        // Get campaigns
        $campaigns = Campaign::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/BiometricRecords/Reprocessing', [
            'stats' => [
                'total_records' => BiometricRecord::count(),
                'oldest_record' => BiometricRecord::orderBy('datetime')->first()?->datetime,
                'newest_record' => BiometricRecord::orderBy('datetime', 'desc')->first()?->datetime,
            ],
            'employees' => $employees,
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * Get filtered employees based on date range and campaign
     */
    public function getFilteredEmployees(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'campaign_ids' => 'nullable|string', // Comma-separated IDs
        ]);

        $query = BiometricRecord::query();

        // Filter by date range if provided
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);
            $query->whereBetween('record_date', [$startDate, $endDate]);
        }

        // Get distinct user IDs from filtered biometric records
        $employeeIds = $query->distinct()->pluck('user_id')->filter()->toArray();

        // Get employees with their active schedule's campaign
        $employeesQuery = User::select('id', 'first_name', 'last_name')
            ->whereIn('id', $employeeIds)
            ->with(['activeSchedule:id,user_id,campaign_id'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        // If campaign filter is provided, filter by users in those campaigns
        if ($request->filled('campaign_ids')) {
            $campaignIds = array_filter(explode(',', $request->campaign_ids));
            if (count($campaignIds) > 0) {
                $campaignUserIds = EmployeeSchedule::whereIn('campaign_id', $campaignIds)
                    ->pluck('user_id')
                    ->unique()
                    ->toArray();
                $employeesQuery->whereIn('id', $campaignUserIds);
            }
        }

        $employees = $employeesQuery->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->first_name.' '.$u->last_name,
            'campaign_id' => $u->activeSchedule?->campaign_id,
        ]);

        return response()->json([
            'employees' => $employees,
            'count' => $employees->count(),
        ]);
    }

    /**
     * Preview what will be reprocessed
     */
    public function preview(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
                function (string $attr, mixed $value, \Closure $fail) use ($request): void {
                    if (Carbon::parse($value)->diffInDays(Carbon::parse($request->start_date)) > 365) {
                        $fail('Reprocess date range cannot exceed 1 year.');
                    }
                },
            ],
            'user_ids' => 'nullable|array|max:500',
            'user_ids.*' => 'exists:users,id',
            'campaign_ids' => 'nullable|array|max:500',
            'campaign_ids.*' => 'exists:campaigns,id',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $query = BiometricRecord::whereBetween('record_date', [$startDate, $endDate]);

        // Filter by specific user IDs if provided
        if ($request->user_ids && count($request->user_ids) > 0) {
            $query->whereIn('user_id', $request->user_ids);
        }
        // Filter by campaigns if provided (get users from those campaigns' schedules)
        elseif ($request->campaign_ids && count($request->campaign_ids) > 0) {
            $campaignUserIds = EmployeeSchedule::whereIn('campaign_id', $request->campaign_ids)
                ->pluck('user_id')
                ->unique()
                ->toArray();
            $query->whereIn('user_id', $campaignUserIds);
        }

        $records = $query->with('user:id,first_name,last_name')->get();

        $affectedUsers = $records->pluck('user')->unique('id')->values();
        $affectedDates = $records->pluck('record_date')->unique()->sort()->values();

        return back()->with([
            'preview' => [
                'total_records' => $records->count(),
                'affected_users' => $affectedUsers->count(),
                'affected_dates' => $affectedDates->count(),
                'date_range' => [
                    'start' => $affectedDates->first(),
                    'end' => $affectedDates->last(),
                ],
                'users' => $affectedUsers->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->first_name.' '.$u->last_name,
                    'record_count' => $records->where('user_id', $u->id)->count(),
                ])->toArray(),
            ],
        ]);
    }

    /**
     * Execute reprocessing
     */
    public function reprocess(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
                function (string $attr, mixed $value, \Closure $fail) use ($request): void {
                    if (Carbon::parse($value)->diffInDays(Carbon::parse($request->start_date)) > 365) {
                        $fail('Reprocess date range cannot exceed 1 year.');
                    }
                },
            ],
            'user_ids' => 'nullable|array|max:500',
            'user_ids.*' => 'exists:users,id',
            'campaign_ids' => 'nullable|array|max:500',
            'campaign_ids.*' => 'exists:campaigns,id',
            'delete_existing' => 'boolean',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $deleteExisting = $request->boolean('delete_existing', true);

        // Get affected users
        $userIds = null;

        // Priority: specific user IDs > campaigns > all users with biometric records
        if ($request->user_ids && count($request->user_ids) > 0) {
            $userIds = $request->user_ids;
        } elseif ($request->campaign_ids && count($request->campaign_ids) > 0) {
            $userIds = EmployeeSchedule::whereIn('campaign_id', $request->campaign_ids)
                ->pluck('user_id')
                ->unique()
                ->toArray();
        } else {
            $userIds = BiometricRecord::whereBetween('record_date', [$startDate, $endDate])
                ->distinct()
                ->pluck('user_id')
                ->filter()
                ->toArray();
        }

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
                    ->map(fn ($r) => [
                        'datetime' => $r->datetime,
                        'name' => $user->last_name, // Use standardized name format
                    ]);

                if ($records->isEmpty()) {
                    continue;
                }

                // Delete existing attendance if requested, but preserve admin-verified records.
                // IMPORTANT: also delete any child AttendancePoint rows for those
                // attendances. Without this cascade, the points (NCNS / FTN / tardy / etc.)
                // become orphans that keep counting against the user's totals while
                // referencing a deleted attendance_id.
                if ($deleteExisting) {
                    $deletedAttendanceIds = Attendance::where('user_id', $user->id)
                        ->whereBetween('shift_date', [$startDate, $endDate])
                        ->where('admin_verified', false)
                        ->pluck('id');

                    if ($deletedAttendanceIds->isNotEmpty()) {
                        AttendancePoint::whereIn('attendance_id', $deletedAttendanceIds)->delete();

                        Attendance::whereIn('id', $deletedAttendanceIds)->delete();
                    }
                }

                // Normalize the user's name for matching (same as upload process).
                //
                // CRITICAL: include the first initial — using last_name alone is ambiguous
                // when two employees share a surname (e.g. "Villanueva H" + "Villanueva J").
                // Pattern 1 ("villanueva") returns BOTH users, and disambiguation by shift
                // hour collapses to whichever has the lower id, silently writing one
                // employee's scans under the other's user_id and dropping the loser entirely.
                // The "lastname + initial" form (Pattern 3) is unique per employee.
                $normalizedName = strtolower(trim($user->last_name))
                    .' '.strtolower(substr(trim($user->first_name), 0, 1));

                // Process ALL records at once (like upload does)
                // This allows groupRecordsByShiftDate() to see all records and properly detect shift patterns
                // Use the start date as reference date (like upload uses shift_date)
                $result = $this->processor->reprocessEmployeeRecords(
                    $normalizedName,
                    $records,
                    $startDate  // Use start date as reference
                );

                $results['processed']++;
                $results['details'][] = [
                    'user' => $user->first_name.' '.$user->last_name,
                    'shifts_processed' => $result['records_processed'],
                    'records_count' => $records->count(),
                ];

            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'user' => $user->first_name.' '.$user->last_name,
                    'error' => $e->getMessage(),
                ];

                Log::error('Reprocessing failed for user', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // After processing individual employees, detect absences across the date range.
        // This creates NCNS records for scheduled employees who had no biometric scans —
        // something the per-user loop above cannot do because it only iterates users who
        // have existing BiometricRecord rows for the range.
        //
        // Pass $userIds when a specific subset was selected; null means check all active employees.
        $filterForAbsent = ($request->user_ids && count($request->user_ids) > 0)
            ? $request->user_ids
            : (($request->campaign_ids && count($request->campaign_ids) > 0) ? $userIds : null);

        try {
            $this->processor->detectAbsentEmployeesForDateRange($startDate, $endDate, $filterForAbsent);
        } catch (\Exception $e) {
            Log::error('Absent detection failed during reprocessing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return back()->with([
            'success' => "Reprocessed {$results['processed']} employees successfully",
            'results' => $results,
        ]);
    }

    /**
     * Fix attendance statuses based on actual time in/out records
     */
    public function fixStatuses(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $results = [
            'updated' => 0,
            'total_checked' => 0,
            'details' => [],
        ];

        // Get all attendance records in the date range with schedule
        // Skip admin-verified records as they have been manually reviewed and approved
        $attendances = Attendance::whereBetween('shift_date', [$startDate, $endDate])
            ->where('admin_verified', false)
            ->with('employeeSchedule', 'user')
            ->get();

        $results['total_checked'] = $attendances->count();

        foreach ($attendances as $att) {
            if (! $att->employeeSchedule) {
                continue;
            }

            $oldStatus = $att->status;
            $oldSecondaryStatus = $att->secondary_status;
            $hasTimeIn = $att->actual_time_in !== null;
            $hasTimeOut = $att->actual_time_out !== null;

            // Recalculate status based on what we have
            if (! $hasTimeIn && ! $hasTimeOut) {
                $att->status = 'ncns';
                $att->secondary_status = null;
            } elseif (! $hasTimeIn && $hasTimeOut) {
                $att->status = 'failed_bio_in';
                $att->secondary_status = null;
            } elseif ($hasTimeIn && ! $hasTimeOut) {
                // Calculate tardiness honoring the schedule's grace period as a
                // forgiveness window. Mirrors AttendanceProcessor::
                // determineTimeInStatus(): tardy_minutes <= grace ⇒ on_time;
                // beyond grace ⇒ tardy. half_day_absence is never auto-applied.
                $shiftDate = is_string($att->shift_date) ? $att->shift_date : $att->shift_date->format('Y-m-d');
                $scheduledIn = Carbon::parse($shiftDate.' '.$att->employeeSchedule->scheduled_time_in);
                $actualIn = Carbon::parse($att->actual_time_in);
                $tardyMins = (int) $scheduledIn->diffInMinutes($actualIn, false);
                $gracePeriod = (int) ($att->employeeSchedule->grace_period_minutes ?? 0);

                if ($tardyMins <= 0 || $actualIn->lessThanOrEqualTo($scheduledIn)) {
                    $att->status = 'failed_bio_out';
                    $att->secondary_status = null;
                    $att->tardy_minutes = null;
                } elseif ($tardyMins > $gracePeriod) {
                    $att->status = 'tardy';
                    $att->secondary_status = 'failed_bio_out';
                    $att->tardy_minutes = $tardyMins;
                } else {
                    // Within grace window — no penalty, but bio-out still missing.
                    $att->status = 'failed_bio_out';
                    $att->secondary_status = null;
                    $att->tardy_minutes = $tardyMins;
                }
            } elseif ($hasTimeIn && $hasTimeOut) {
                // Both exist, recalculate full status honoring grace as forgiveness.
                $shiftDate = is_string($att->shift_date) ? $att->shift_date : Carbon::parse($att->shift_date)->format('Y-m-d');
                $scheduledIn = Carbon::parse($shiftDate.' '.$att->employeeSchedule->scheduled_time_in);
                $actualIn = Carbon::parse($att->actual_time_in);
                $tardyMins = (int) $scheduledIn->diffInMinutes($actualIn, false);
                $gracePeriod = (int) ($att->employeeSchedule->grace_period_minutes ?? 0);

                if ($tardyMins <= 0 || $actualIn->lessThanOrEqualTo($scheduledIn)) {
                    $att->status = 'on_time';
                    $att->tardy_minutes = null;
                } elseif ($tardyMins > $gracePeriod) {
                    $att->status = 'tardy';
                    $att->tardy_minutes = $tardyMins;
                } else {
                    // Within grace — record raw lateness, but no penalty.
                    $att->status = 'on_time';
                    $att->tardy_minutes = $tardyMins;
                }

                $att->secondary_status = null;
            }

            if ($oldStatus !== $att->status || $oldSecondaryStatus !== $att->secondary_status) {
                $att->save();
                $results['updated']++;

                $oldStatusText = $oldStatus.($oldSecondaryStatus ? " + {$oldSecondaryStatus}" : '');
                $newStatusText = $att->status.($att->secondary_status ? " + {$att->secondary_status}" : '');

                $results['details'][] = [
                    'user' => $att->user->first_name.' '.$att->user->last_name,
                    'date' => $att->shift_date->format('M d, Y'),
                    'old_status' => $oldStatusText,
                    'new_status' => $att->status,
                    'secondary_status' => $att->secondary_status,
                ];
            }
        }

        return back()->with([
            'fixResults' => $results,
        ]);
    }
}
