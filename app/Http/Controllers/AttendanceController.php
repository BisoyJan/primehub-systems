<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceUpload;
use App\Models\AttendancePoint;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\Site;
use App\Services\AttendanceProcessor;
use App\Services\LeaveCreditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    protected AttendanceProcessor $processor;
    protected NotificationService $notificationService;
    protected LeaveCreditService $leaveCreditService;

    public function __construct(
        AttendanceProcessor $processor,
        NotificationService $notificationService,
        LeaveCreditService $leaveCreditService
    ) {
        $this->processor = $processor;
        $this->notificationService = $notificationService;
        $this->leaveCreditService = $leaveCreditService;
    }

    /**
     * Display a listing of attendance records.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Attendance::class);

        $query = Attendance::with([
            'user',
            'employeeSchedule.site',
            'bioInSite',
            'bioOutSite'
        ]);

        // Restrict certain roles to only view their own attendance records
        $restrictedRoles = ['Agent', 'IT', 'Utility'];
        if (in_array(auth()->user()->role, $restrictedRoles)) {
            $query->where('user_id', auth()->id());
        } else {
            // Search by employee name (only for non-restricted roles)
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere(\DB::raw("CONCAT(first_name, ' ', last_name)"), 'like', "%{$search}%");
                });
            }

            // Allow user_id filter for non-restricted roles
            if ($request->has('user_id') && $request->user_id !== 'all') {
                $query->where('user_id', $request->user_id);
            }
        }

        // Filters (available to all roles)
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        if ($request->has('needs_verification') && $request->needs_verification) {
            $query->needsVerification();
        }

        // Filter by site (via employee schedule)
        if ($request->has('site_id') && $request->site_id !== 'all' && $request->site_id) {
            $query->whereHas('employeeSchedule', function ($q) use ($request) {
                $q->where('site_id', $request->site_id);
            });
        }

        $attendances = $query->orderBy('shift_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(25)
            ->withQueryString();

        // Get all users for employee filter dropdown
        $users = User::select('id', 'first_name', 'last_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->first_name . ' ' . $user->last_name,
            ]);

        // Get all sites for site filter dropdown
        $sites = Site::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/Main/Index', [
            'attendances' => $attendances,
            'users' => $users,
            'sites' => $sites,
            'filters' => $request->only(['search', 'status', 'start_date', 'end_date', 'user_id', 'site_id', 'needs_verification']),
        ]);
    }

    /**
     * Display calendar view for employee attendance.
     */
    public function calendar(Request $request, $userId = null)
    {
        // Get month and year from request or use current
        $month = $request->input('month', now()->month);
        $year = $request->input('year', now()->year);
        $verificationFilter = $request->input('verification_filter', 'all'); // all, verified, non_verified

        // Restrict certain roles to only view their own attendance records
        $restrictedRoles = ['Agent', 'IT', 'Utility'];
        if (in_array(auth()->user()->role, $restrictedRoles)) {
            $userId = auth()->id();
        } else if (!$userId && $request->has('user_id')) {
            $userId = $request->user_id;
        }

        // Get all users for selection (if user has permission)
        $users = [];
        if (!in_array(auth()->user()->role, $restrictedRoles)) {
            $users = User::select('id', 'first_name', 'last_name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->first_name . ' ' . $user->last_name,
                    ];
                });
        }

        // Get selected user info
        $selectedUser = null;
        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $selectedUser = [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                ];
            }
        }

        // Build date range for the month
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = Carbon::create($year, $month, 1)->endOfMonth();

        // Get attendance records for the month
        $attendances = [];
        if ($userId) {
            $query = Attendance::with([
                'user',
                'employeeSchedule.site',
                'bioInSite',
                'bioOutSite'
            ])
            ->where('user_id', $userId)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

            // Apply verification filter
            if ($verificationFilter === 'verified') {
                $query->where('admin_verified', 1);
            } elseif ($verificationFilter === 'non_verified') {
                $query->where('admin_verified', 0);
            }
            // 'all' means no filter

            $records = $query->orderBy('shift_date', 'asc')->get();

            // Convert to associative array keyed by date (Y-m-d format only)
            foreach ($records as $record) {
                $dateKey = Carbon::parse($record->shift_date)->format('Y-m-d');
                $attendances[$dateKey] = $record;
            }
        }

        return Inertia::render('Attendance/Main/Calendar', [
            'attendances' => (object) $attendances, // Cast to object so it's treated as associative array in JS
            'users' => $users,
            'selectedUser' => $selectedUser,
            'month' => (int) $month,
            'year' => (int) $year,
            'verificationFilter' => $verificationFilter,
        ]);
    }

    /**
     * Store a manually created attendance record.
     */
    public function create()
    {
        // Get all users with their active schedules
        $users = User::select('id', 'first_name', 'last_name', 'email')
            ->with(['employeeSchedules' => function ($query) {
                $query->where('is_active', true)
                    ->where('effective_date', '<=', now())
                    ->where(function ($q) {
                        $q->whereNull('end_date')
                            ->orWhere('end_date', '>=', now());
                    })
                    ->select('id', 'user_id', 'shift_type', 'scheduled_time_in', 'scheduled_time_out', 'site_id', 'campaign_id', 'grace_period_minutes')
                    ->with(['site:id,name', 'campaign:id,name']);
            }])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($user) {
                $schedule = $user->employeeSchedules->first();
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                    'schedule' => $schedule ? [
                        'shift_type' => $schedule->shift_type,
                        'scheduled_time_in' => $schedule->scheduled_time_in,
                        'scheduled_time_out' => $schedule->scheduled_time_out,
                        'site_name' => $schedule->site?->name,
                        'campaign_id' => $schedule->campaign_id,
                        'campaign_name' => $schedule->campaign?->name,
                        'grace_period_minutes' => $schedule->grace_period_minutes ?? 15,
                    ] : null,
                ];
            });

        // Get all campaigns for filter
        $campaigns = \App\Models\Campaign::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/Main/Create', [
            'users' => $users,
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * Store a manually created attendance record.
     */
    public function store(Request $request)
    {
        // Combine date and time fields on the backend if they exist
        if ($request->has('actual_time_in_date') && $request->has('actual_time_in_time') && $request->actual_time_in_date && $request->actual_time_in_time) {
            $request->merge([
                'actual_time_in' => $request->actual_time_in_date . 'T' . $request->actual_time_in_time
            ]);
        }

        if ($request->has('actual_time_out_date') && $request->has('actual_time_out_time') && $request->actual_time_out_date && $request->actual_time_out_time) {
            $request->merge([
                'actual_time_out' => $request->actual_time_out_date . 'T' . $request->actual_time_out_time
            ]);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'shift_date' => 'required|date',
            'status' => 'nullable|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,failed_bio_in,failed_bio_out,present_no_bio,non_work_day,on_leave',
            'actual_time_in' => 'nullable|date_format:Y-m-d\\TH:i',
            'actual_time_out' => 'nullable|date_format:Y-m-d\\TH:i',
            'notes' => 'nullable|string|max:500',
        ]);

        // Get employee schedule for the date
        $schedule = \App\Models\EmployeeSchedule::where('user_id', $validated['user_id'])
            ->where('is_active', true)
            ->where('effective_date', '<=', $validated['shift_date'])
            ->where(function ($query) use ($validated) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $validated['shift_date']);
            })
            ->first();

        // Convert datetime strings to Carbon instances
        $actualTimeIn = $validated['actual_time_in'] ? Carbon::parse($validated['actual_time_in']) : null;
        $actualTimeOut = $validated['actual_time_out'] ? Carbon::parse($validated['actual_time_out']) : null;

        // Check for approved leave request on this date
        $approvedLeave = \App\Models\LeaveRequest::where('user_id', $validated['user_id'])
            ->where('status', 'approved')
            ->where('start_date', '<=', $validated['shift_date'])
            ->where('end_date', '>=', $validated['shift_date'])
            ->first();

        // Flag if this is a leave conflict (attendance during approved leave)
        $hasLeaveConflict = $approvedLeave && ($validated['actual_time_in'] || $validated['actual_time_out']);

        // Calculate tardy, undertime, overtime if schedule exists and times are provided
        $tardyMinutes = null;
        $undertimeMinutes = null;
        $overtimeMinutes = null;
        $status = $validated['status'] ?? null;
        $secondaryStatus = null;

        // Auto-set status to on_leave if there's an approved leave request
        if ($approvedLeave && !$status) {
            $status = 'on_leave';
        } else if (!$status) {
            // Auto-determine status based on actual times and schedule
            $hasBioIn = (bool) $actualTimeIn;
            $hasBioOut = (bool) $actualTimeOut;
            $isTardy = false;
            $hasUndertime = false;

            if ($schedule && $hasBioIn && $hasBioOut) {
                $shiftDate = Carbon::parse($validated['shift_date']);
                $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
                $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);

                // Handle night shift (time out is next day)
                if ($schedule->shift_type === 'night_shift' && $scheduledTimeOut->lt($scheduledTimeIn)) {
                    $scheduledTimeOut->addDay();
                }

                // Calculate tardy (late arrival)
                $gracePeriod = $schedule->grace_period_minutes ?? 0;
                if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                    $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                    $isTardy = true;
                }

                // Calculate undertime (early leave) and overtime (late leave)
                if ($actualTimeOut->lt($scheduledTimeOut)) {
                    $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
                    $hasUndertime = true;
                } else if ($actualTimeOut->gt($scheduledTimeOut)) {
                    $overtimeMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut);
                }
            } else if ($schedule && $hasBioIn) {
                // Has schedule and time in, check if tardy
                $shiftDate = Carbon::parse($validated['shift_date']);
                $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
                $gracePeriod = $schedule->grace_period_minutes ?? 0;
                if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                    $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                    $isTardy = true;
                }
            }

            // Determine status based on violations (can be combined)
            if (!$hasBioIn && !$hasBioOut) {
                $status = 'present_no_bio';
            } else if (!$hasBioIn && $hasBioOut) {
                $status = 'failed_bio_in';
            } else if ($hasBioIn && !$hasBioOut) {
                // Check if also tardy - if tardy, that becomes primary status
                if ($isTardy) {
                    $status = 'tardy';
                    $secondaryStatus = 'failed_bio_out';
                } else {
                    $status = 'failed_bio_out';
                }
            } else if ($isTardy && $hasUndertime) {
                $status = 'tardy'; // Both violations, tardy takes precedence
                $secondaryStatus = 'undertime';
            } else if ($isTardy) {
                $status = 'tardy';
            } else if ($hasUndertime) {
                $status = 'undertime';
            } else {
                $status = 'on_time';
            }
        } else if ($schedule && $actualTimeIn && $actualTimeOut) {
            // Status was manually provided, but still calculate tardiness/undertime/overtime
            $shiftDate = Carbon::parse($validated['shift_date']);
            $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
            $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);

            // Handle night shift (time out is next day)
            if ($schedule->shift_type === 'night_shift' && $scheduledTimeOut->lt($scheduledTimeIn)) {
                $scheduledTimeOut->addDay();
            }

            // Calculate tardy (late arrival)
            $gracePeriod = $schedule->grace_period_minutes ?? 0;
            if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
            }

            // Calculate undertime (early leave) and overtime (late leave)
            if ($actualTimeOut->lt($scheduledTimeOut)) {
                $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
            } else if ($actualTimeOut->gt($scheduledTimeOut)) {
                $overtimeMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut);
            }
        }

        $attendance = Attendance::create([
            'user_id' => $validated['user_id'],
            'employee_schedule_id' => $schedule?->id,
            'shift_date' => $validated['shift_date'],
            'scheduled_time_in' => $schedule?->scheduled_time_in,
            'scheduled_time_out' => $schedule?->scheduled_time_out,
            'actual_time_in' => $actualTimeIn,
            'actual_time_out' => $actualTimeOut,
            'status' => $status,
            'secondary_status' => $secondaryStatus,
            'tardy_minutes' => $tardyMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'admin_verified' => !$hasLeaveConflict, // Requires approval if leave conflict
            'leave_request_id' => $approvedLeave?->id,
            'verification_notes' => $hasLeaveConflict
                ? 'Manual entry during approved leave - requires HR review. Created by ' . auth()->user()->name
                : 'Manually created by ' . auth()->user()->name,
            'notes' => $validated['notes'],
            'remarks' => $hasLeaveConflict
                ? 'Leave conflict: Employee on approved leave but has attendance entry. Pending HR review.'
                : null,
        ]);

        // Notify HR if there's a leave conflict
        if ($hasLeaveConflict) {
            $notificationService = app(NotificationService::class);
            $employee = User::find($validated['user_id']);
            $workDuration = $actualTimeIn && $actualTimeOut
                ? round($actualTimeIn->diffInMinutes($actualTimeOut) / 60, 2)
                : 0;

            $notificationService->notifyLeaveAttendanceConflict(
                $employee,
                $approvedLeave,
                Carbon::parse($validated['shift_date']),
                $actualTimeIn && $actualTimeOut ? 2 : 1, // scan count approximation
                $actualTimeIn ? $actualTimeIn->format('H:i') . ($actualTimeOut ? ', ' . $actualTimeOut->format('H:i') : '') : 'N/A',
                $workDuration,
                $approvedLeave->start_date != $approvedLeave->end_date
            );
        }

        return redirect()->route('attendance.index')->with('success',
            $hasLeaveConflict
                ? 'Attendance record created. Requires HR approval due to leave conflict.'
                : 'Attendance record created successfully.'
        );
    }

    /**
     * Store bulk manually created attendance records.
     */
    public function bulkStore(Request $request)
    {
        // Combine date and time fields on the backend if they exist
        if ($request->has('actual_time_in_date') && $request->has('actual_time_in_time') && $request->actual_time_in_date && $request->actual_time_in_time) {
            $request->merge([
                'actual_time_in' => $request->actual_time_in_date . 'T' . $request->actual_time_in_time
            ]);
        }

        if ($request->has('actual_time_out_date') && $request->has('actual_time_out_time') && $request->actual_time_out_date && $request->actual_time_out_time) {
            $request->merge([
                'actual_time_out' => $request->actual_time_out_date . 'T' . $request->actual_time_out_time
            ]);
        }

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required|exists:users,id',
            'shift_date' => 'required|date',
            'status' => 'nullable|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,failed_bio_in,failed_bio_out,present_no_bio,non_work_day,on_leave',
            'actual_time_in' => 'nullable|date_format:Y-m-d\\TH:i',
            'actual_time_out' => 'nullable|date_format:Y-m-d\\TH:i',
            'notes' => 'nullable|string|max:500',
        ]);

        // Convert datetime strings to Carbon instances once
        $actualTimeIn = $validated['actual_time_in'] ? Carbon::parse($validated['actual_time_in']) : null;
        $actualTimeOut = $validated['actual_time_out'] ? Carbon::parse($validated['actual_time_out']) : null;

        $createdCount = 0;
        $errors = [];

        foreach ($validated['user_ids'] as $userId) {
            try {
                // Get employee schedule for the date
                $schedule = \App\Models\EmployeeSchedule::where('user_id', $userId)
                    ->where('is_active', true)
                    ->where('effective_date', '<=', $validated['shift_date'])
                    ->where(function ($query) use ($validated) {
                        $query->whereNull('end_date')
                            ->orWhere('end_date', '>=', $validated['shift_date']);
                    })
                    ->first();

                // Check for approved leave request on this date
                $approvedLeave = \App\Models\LeaveRequest::where('user_id', $userId)
                    ->where('status', 'approved')
                    ->where('start_date', '<=', $validated['shift_date'])
                    ->where('end_date', '>=', $validated['shift_date'])
                    ->first();

                // Calculate tardy, undertime, overtime if schedule exists and times are provided
                $tardyMinutes = null;
                $undertimeMinutes = null;
                $overtimeMinutes = null;
                $status = $validated['status'] ?? null;
                $secondaryStatus = null;

                // Auto-set status to on_leave if there's an approved leave request
                if ($approvedLeave && !$status) {
                    $status = 'on_leave';
                } else if (!$status) {
                    // Auto-determine status based on actual times and schedule
                    $hasBioIn = (bool) $actualTimeIn;
                    $hasBioOut = (bool) $actualTimeOut;
                    $isTardy = false;
                    $hasUndertime = false;

                    if ($schedule && $hasBioIn && $hasBioOut) {
                        $shiftDate = Carbon::parse($validated['shift_date']);
                        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
                        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);

                        // Handle night shift (time out is next day)
                        if ($schedule->shift_type === 'night_shift' && $scheduledTimeOut->lt($scheduledTimeIn)) {
                            $scheduledTimeOut->addDay();
                        }

                        // Calculate tardy (late arrival)
                        $gracePeriod = $schedule->grace_period_minutes ?? 0;
                        if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                            $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                            $isTardy = true;
                        }

                        // Calculate undertime (early leave) and overtime (late leave)
                        if ($actualTimeOut->lt($scheduledTimeOut)) {
                            $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
                            $hasUndertime = true;
                        } else if ($actualTimeOut->gt($scheduledTimeOut)) {
                            $overtimeMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut);
                        }
                    } else if ($schedule && $hasBioIn) {
                        // Has schedule and time in, check if tardy
                        $shiftDate = Carbon::parse($validated['shift_date']);
                        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
                        $gracePeriod = $schedule->grace_period_minutes ?? 0;
                        if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                            $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                            $isTardy = true;
                        }
                    }

                    // Determine status based on violations (can be combined)
                    if (!$hasBioIn && !$hasBioOut) {
                        $status = 'present_no_bio';
                    } else if (!$hasBioIn && $hasBioOut) {
                        $status = 'failed_bio_in';
                    } else if ($hasBioIn && !$hasBioOut) {
                        // Check if also tardy - if tardy, that becomes primary status
                        if ($isTardy) {
                            $status = 'tardy';
                            $secondaryStatus = 'failed_bio_out';
                        } else {
                            $status = 'failed_bio_out';
                        }
                    } else if ($isTardy && $hasUndertime) {
                        $status = 'tardy'; // Both violations, tardy takes precedence
                        $secondaryStatus = 'undertime';
                    } else if ($isTardy) {
                        $status = 'tardy';
                    } else if ($hasUndertime) {
                        $status = 'undertime';
                    } else {
                        $status = 'on_time';
                    }
                } else if ($schedule && $actualTimeIn && $actualTimeOut) {
                    // Status was manually provided, but still calculate tardiness/undertime/overtime
                    $shiftDate = Carbon::parse($validated['shift_date']);
                    $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
                    $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);

                    // Handle night shift (time out is next day)
                    if ($schedule->shift_type === 'night_shift' && $scheduledTimeOut->lt($scheduledTimeIn)) {
                        $scheduledTimeOut->addDay();
                    }

                    // Calculate tardy (late arrival)
                    $gracePeriod = $schedule->grace_period_minutes ?? 0;
                    if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                        $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                    }

                    // Calculate undertime (early leave) and overtime (late leave)
                    if ($actualTimeOut->lt($scheduledTimeOut)) {
                        $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
                    } else if ($actualTimeOut->gt($scheduledTimeOut)) {
                        $overtimeMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut);
                    }
                }

                Attendance::create([
                    'user_id' => $userId,
                    'employee_schedule_id' => $schedule?->id,
                    'shift_date' => $validated['shift_date'],
                    'scheduled_time_in' => $schedule?->scheduled_time_in,
                    'scheduled_time_out' => $schedule?->scheduled_time_out,
                    'actual_time_in' => $actualTimeIn,
                    'actual_time_out' => $actualTimeOut,
                    'status' => $status,
                    'secondary_status' => $secondaryStatus,
                    'tardy_minutes' => $tardyMinutes,
                    'undertime_minutes' => $undertimeMinutes,
                    'overtime_minutes' => $overtimeMinutes,
                    'admin_verified' => true, // Manual entries are pre-verified
                    'verification_notes' => 'Manually created by ' . auth()->user()->name,
                    'notes' => $validated['notes'],
                ]);

                $createdCount++;
            } catch (\Exception $e) {
                $user = User::find($userId);
                $errors[] = "Failed to create attendance for {$user->name}: {$e->getMessage()}";
            }
        }

        if ($createdCount > 0) {
            $message = "{$createdCount} attendance record(s) created successfully.";
            if (count($errors) > 0) {
                $message .= " However, " . count($errors) . " record(s) failed.";
            }
            return redirect()->route('attendance.index')->with('success', $message);
        }

        return redirect()->route('attendance.index')->withErrors(['error' => 'Failed to create any attendance records.']);
    }

    public function import()
    {
        $recentUploads = AttendanceUpload::with(['uploader', 'biometricSite'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $sites = Site::orderBy('name')->get();

        return Inertia::render('Attendance/Main/Import', [
            'recentUploads' => $recentUploads,
            'sites' => $sites,
        ]);
    }

    /**
     * Handle the file upload and processing.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:txt|max:10240', // Max 10MB
            'date_from' => 'required|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'biometric_site_id' => 'required|exists:sites,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Store the file
        $file = $request->file('file');
        $filename = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('attendance_uploads', $filename);

        // Create upload record
        $upload = AttendanceUpload::create([
            'uploaded_by' => auth()->id(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $filename,
            'date_from' => $request->date_from,
            'date_to' => $request->date_to ?? $request->date_from, // If null, use date_from (single day)
            'shift_date' => $request->date_from, // Keep for backward compatibility
            'biometric_site_id' => $request->biometric_site_id,
            'notes' => $request->notes,
            'status' => 'pending',
        ]);

        try {
            // Process the file
            $filePath = Storage::path($path);
            $stats = $this->processor->processUpload($upload, $filePath);

            // Prepare success message with more details
            $message = sprintf(
                'Attendance file processed successfully. Total records: %d, Matched: %d employees, Unmatched: %d names',
                $stats['total_records'],
                $stats['matched_employees'],
                count($stats['unmatched_names'])
            );

            // Add date validation warnings if any
            if (!empty($stats['date_warnings'])) {
                $warningMessage = 'Date Validation Warnings: ' . implode(' ', $stats['date_warnings']);
                session()->flash('warning', $warningMessage);
            }

            // Add unmatched names to flash message for debugging
            if (!empty($stats['unmatched_names'])) {
                $unmatchedList = implode(', ', array_slice($stats['unmatched_names'], 0, 10));
                if (count($stats['unmatched_names']) > 10) {
                    $unmatchedList .= '... and ' . (count($stats['unmatched_names']) - 10) . ' more';
                }
                $message .= '. Unmatched: ' . $unmatchedList;
            }

            // Return JSON if requested via fetch/API
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'upload_id' => $upload->id,
                ]);
            }

            return redirect()->route('attendance.import')
                ->with('success', $message);

        } catch (\Exception $e) {
            // Update upload record to show failure
            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            \Log::error('Attendance upload failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return JSON error if requested via fetch/API
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process attendance file: ' . $e->getMessage(),
                ], 422);
            }

            return redirect()->back()
                ->with('error', 'Failed to process attendance file: ' . $e->getMessage());
        }
    }

    /**
     * Show records that need verification.
     */
    public function review(Request $request)
    {
        $query = Attendance::with([
            'user.activeSchedule.site', // Include user's active schedule as fallback
            'employeeSchedule.site',
            'bioInSite',
            'bioOutSite',
            'leaveRequest' // Include leave request info
        ]);

        // Filter by verification status
        // Default behavior: show all records (matching frontend default "All Records")
        if ($request->has('verified') && $request->verified !== '') {
            if ($request->verified === 'verified') {
                $query->where('admin_verified', true);
            } elseif ($request->verified === 'pending') {
                // Show ALL unverified records, not just those with specific statuses
                $query->where('admin_verified', false);
            }
            // Empty string means 'all' - no filter applied, show everything
        }

        // Filter by specific employee
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('shift_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('shift_date', '<=', $request->date_to);
        }

        // Filter by site (via employee schedule)
        if ($request->filled('site_id')) {
            $query->whereHas('employeeSchedule', function ($q) use ($request) {
                $q->where('site_id', $request->site_id);
            });
        }

        $attendances = $query->orderBy('shift_date', 'desc')->paginate(50)->withQueryString();

        // Get all employees for the dropdown
        $employees = User::select('id', 'first_name', 'last_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->first_name . ' ' . $user->last_name,
            ]);

        // Get all sites for site filter dropdown
        $sites = Site::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/Main/Review', [
            'attendances' => $attendances,
            'employees' => $employees,
            'sites' => $sites,
            'filters' => [
                'user_id' => $request->user_id,
                'status' => $request->status,
                'verified' => $request->verified,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'site_id' => $request->site_id,
            ],
        ]);
    }

    /**
     * Verify and update an attendance record.
     */
    public function verify(Request $request, Attendance $attendance)
    {
        // Load employee schedule and leave request for checking
        $attendance->load(['employeeSchedule', 'leaveRequest']);

        $request->validate([
            'status' => 'required|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,failed_bio_in,failed_bio_out,present_no_bio,non_work_day,on_leave',
            'actual_time_in' => 'nullable|date',
            'actual_time_out' => 'nullable|date',
            'verification_notes' => 'required|string|max:1000',
            'overtime_approved' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
            'adjust_leave' => 'nullable|boolean', // Flag to confirm leave adjustment
        ]);

        // Note: Allow re-verification of already verified records
        // This is intentional - admins can update verified records through this interface

        $oldStatus = $attendance->status;
        $leaveAdjusted = false;
        $leaveAdjustmentMessage = '';

        // Check if this attendance conflicts with an approved leave
        // If status is not 'on_leave' (meaning they actually worked), handle leave adjustment
        if ($attendance->leave_request_id &&
            $attendance->leaveRequest &&
            $attendance->leaveRequest->status === 'approved' &&
            $request->status !== 'on_leave') {

            $leaveResult = $this->adjustLeaveForWorkDay($attendance, $request);
            $leaveAdjusted = $leaveResult['adjusted'];
            $leaveAdjustmentMessage = $leaveResult['message'];
        }

        $updates = [
            'status' => $request->status,
            'actual_time_in' => $request->actual_time_in,
            'actual_time_out' => $request->actual_time_out,
            'admin_verified' => true,
            'verification_notes' => $request->verification_notes,
            'notes' => $request->notes,
        ];

        // Clear leave_request_id if employee actually worked (not on_leave status)
        if ($request->status !== 'on_leave' && $attendance->leave_request_id) {
            $updates['leave_request_id'] = null;
        }

        // Set is_advised flag for advised_absence status
        if ($request->status === 'advised_absence') {
            $updates['is_advised'] = true;
        }

        // Handle overtime approval
        if ($request->has('overtime_approved')) {
            $updates['overtime_approved'] = $request->overtime_approved;
            if ($request->overtime_approved) {
                $updates['overtime_approved_at'] = now();
                $updates['overtime_approved_by'] = auth()->id();
            } else {
                $updates['overtime_approved_at'] = null;
                $updates['overtime_approved_by'] = null;
            }
        }

        $attendance->update($updates);

        // Skip time calculations for non-work days
        if ($request->status === 'non_work_day') {
            $attendance->update([
                'tardy_minutes' => null,
                'undertime_minutes' => null,
                'overtime_minutes' => null,
            ]);
        } else {
            // Recalculate tardy/undertime/overtime if times provided
            if ($request->actual_time_in && $attendance->scheduled_time_in) {
                $shiftDate = Carbon::parse($attendance->shift_date);
                $scheduled = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attendance->scheduled_time_in);
                $actual = Carbon::parse($request->actual_time_in);
                $tardyMinutes = $scheduled->diffInMinutes($actual, false);

                if ($tardyMinutes > 0) {
                    $attendance->update(['tardy_minutes' => $tardyMinutes]);
                } else {
                    $attendance->update(['tardy_minutes' => null]);
                }
            }

            // Recalculate undertime and overtime if time out provided
            if ($request->actual_time_out && $attendance->scheduled_time_out) {
            $actualTimeOut = Carbon::parse($request->actual_time_out);

            // Build scheduled time out based on shift date and scheduled time
            $shiftDate = Carbon::parse($attendance->shift_date);
            $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attendance->scheduled_time_out);

            // Handle night shift - if scheduled time out is earlier than scheduled time in,
            // it means the shift ends the next day
            if ($attendance->scheduled_time_in && $attendance->scheduled_time_out) {
                $scheduledIn = Carbon::parse($attendance->scheduled_time_in);
                $scheduledOut = Carbon::parse($attendance->scheduled_time_out);

                // If time out is before time in (e.g., 07:00 < 22:00), shift crosses midnight
                if ($scheduledOut->format('H:i:s') < $scheduledIn->format('H:i:s')) {
                    $scheduledTimeOut->addDay();
                }
            }

            // Calculate difference: positive means overtime (left late), negative means undertime (left early)
            // Using scheduledTimeOut->diffInMinutes($actualTimeOut):
            // - Positive if actualTimeOut > scheduledTimeOut (overtime)
            // - Negative if actualTimeOut < scheduledTimeOut (undertime)
            $timeDiff = $scheduledTimeOut->diffInMinutes($actualTimeOut, false);

            // If negative and more than 60 minutes (left early), it's undertime
            if ($timeDiff < -60) {
                $attendance->update([
                    'undertime_minutes' => abs($timeDiff),
                    'overtime_minutes' => null,
                ]);
            }
            // If positive and more than 60 minutes (left late), it's overtime
            elseif ($timeDiff > 60) {
                $attendance->update([
                    'undertime_minutes' => null,
                    'overtime_minutes' => $timeDiff,
                ]);
            }
            // If within threshold (-60 to 60), clear both
            else {
                $attendance->update([
                    'undertime_minutes' => null,
                    'overtime_minutes' => null,
                ]);
            }
        }
        } // End of non_work_day check

        // Regenerate attendance points after verification
        // Delete existing points for this attendance record
        AttendancePoint::where('attendance_id', $attendance->id)->delete();

        // Generate points if the status requires them (and record is now verified)
        if (in_array($request->status, ['ncns', 'half_day_absence', 'tardy', 'undertime', 'advised_absence'])) {
            $this->processor->regeneratePointsForAttendance($attendance);
        }

        // Fetch points if any
        $pointRecord = AttendancePoint::where('attendance_id', $attendance->id)->first();
        $points = $pointRecord ? $pointRecord->points : null;

        // Notify user if status is not on_time
        if ($request->status !== 'on_time') {
            $this->notificationService->notifyAttendanceStatus(
                $attendance->user_id,
                $request->status,
                Carbon::parse($attendance->shift_date)->format('M d, Y'),
                $points
            );
        }

        $successMessage = 'Attendance record verified and updated successfully.';
        if ($leaveAdjusted && $leaveAdjustmentMessage) {
            $successMessage .= ' ' . $leaveAdjustmentMessage;
        }

        return redirect()->back()
            ->with('success', $successMessage);
    }

    /**
     * Batch verify multiple attendance records.
     */
    public function batchVerify(Request $request)
    {
        $validated = $request->validate([
            'record_ids' => 'required|array|min:1',
            'record_ids.*' => 'required|exists:attendances,id',
            'status' => 'required|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,failed_bio_in,failed_bio_out,present_no_bio,non_work_day,on_leave',
            'verification_notes' => 'required|string|max:1000',
            'overtime_approved' => 'nullable|boolean',
        ]);

        $recordIds = $validated['record_ids'];
        $verifiedCount = 0;

        foreach ($recordIds as $id) {
            $attendance = Attendance::with('employeeSchedule')->find($id);
            if (!$attendance) {
                continue;
            }

            $oldStatus = $attendance->status;

            $updates = [
                'status' => $validated['status'],
                'admin_verified' => true,
                'verification_notes' => $validated['verification_notes'],
            ];

            // Set is_advised flag for advised_absence status
            if ($validated['status'] === 'advised_absence') {
                $updates['is_advised'] = true;
            }

            // Handle overtime approval
            if (isset($validated['overtime_approved'])) {
                $updates['overtime_approved'] = $validated['overtime_approved'];
                if ($validated['overtime_approved']) {
                    $updates['overtime_approved_at'] = now();
                    $updates['overtime_approved_by'] = auth()->id();
                } else {
                    $updates['overtime_approved_at'] = null;
                    $updates['overtime_approved_by'] = null;
                }
            }

            $attendance->update($updates);

            // Regenerate attendance points after verification
            // Delete existing points for this attendance record
            AttendancePoint::where('attendance_id', $attendance->id)->delete();

            // Generate points if the status requires them (and record is now verified)
            if (in_array($validated['status'], ['ncns', 'half_day_absence', 'tardy', 'undertime', 'advised_absence'])) {
                $this->processor->regeneratePointsForAttendance($attendance);
            }

            // Fetch points if any
            $pointRecord = AttendancePoint::where('attendance_id', $attendance->id)->first();
            $points = $pointRecord ? $pointRecord->points : null;

            // Notify user if status is not on_time
            if ($validated['status'] !== 'on_time') {
                $this->notificationService->notifyAttendanceStatus(
                    $attendance->user_id,
                    $validated['status'],
                    Carbon::parse($attendance->shift_date)->format('M d, Y'),
                    $points
                );
            }

            $verifiedCount++;
        }

        return redirect()->back()
            ->with('success', "Successfully verified {$verifiedCount} attendance record" . ($verifiedCount === 1 ? '' : 's') . ".");
    }

    /**
     * Mark an attendance as advised absence.
     */
    public function markAdvised(Request $request, Attendance $attendance)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $attendance->update([
            'status' => 'advised_absence',
            'is_advised' => true,
            'admin_verified' => true,
            'verification_notes' => $request->notes,
        ]);

        return redirect()->back()
            ->with('success', 'Attendance marked as advised absence.');
    }

    /**
     * Quick approve an on-time attendance record without overtime issues.
     */
    public function quickApprove(Request $request, Attendance $attendance)
    {
        // Validate that the record is eligible for quick approval
        if ($attendance->status !== 'on_time') {
            return redirect()->back()
                ->with('error', 'Only on-time records can be quick approved.');
        }

        if ($attendance->admin_verified) {
            return redirect()->back()
                ->with('error', 'This record has already been verified.');
        }

        // Check for unapproved overtime
        if ($attendance->overtime_minutes && $attendance->overtime_minutes > 0 && !$attendance->overtime_approved) {
            return redirect()->back()
                ->with('error', 'Records with unapproved overtime need manual review.');
        }

        $attendance->update([
            'admin_verified' => true,
            'verification_notes' => 'Quick approved by admin',
        ]);

        return redirect()->back()
            ->with('success', 'Attendance record approved successfully.');
    }

    /**
     * Bulk quick approve multiple on-time attendance records without overtime issues.
     */
    public function bulkQuickApprove(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:attendances,id',
        ]);

        $attendances = Attendance::whereIn('id', $request->ids)->get();

        $approved = 0;
        $skipped = 0;
        $skippedReasons = [];

        foreach ($attendances as $attendance) {
            // Check eligibility
            if ($attendance->status !== 'on_time') {
                $skipped++;
                $skippedReasons[] = "{$attendance->user->name} - Not on-time status";
                continue;
            }

            if ($attendance->admin_verified) {
                $skipped++;
                continue;
            }

            if ($attendance->overtime_minutes && $attendance->overtime_minutes > 0 && !$attendance->overtime_approved) {
                $skipped++;
                $skippedReasons[] = "{$attendance->user->name} - Has unapproved overtime";
                continue;
            }

            // Approve the record
            $attendance->update([
                'admin_verified' => true,
                'verification_notes' => 'Bulk quick approved by admin',
            ]);

            $approved++;
        }

        $message = "Successfully approved {$approved} record(s).";
        if ($skipped > 0) {
            $message .= " Skipped {$skipped} ineligible record(s).";
            if (!empty($skippedReasons)) {
                $message .= " Reasons: " . implode('; ', $skippedReasons);
            }
        }

        return redirect()->back()
            ->with('success', $message);
    }

    /**
     * Get attendance statistics.
     */
    public function statistics(Request $request)
    {
        $startDate = $request->input('start_date', now()->startOfMonth());
        $endDate = $request->input('end_date', now()->endOfMonth());

        $stats = [
            'total' => Attendance::dateRange($startDate, $endDate)->count(),
            'on_time' => Attendance::dateRange($startDate, $endDate)->byStatus('on_time')->count(),
            'tardy' => Attendance::dateRange($startDate, $endDate)->byStatus('tardy')->count(),
            'half_day' => Attendance::dateRange($startDate, $endDate)->byStatus('half_day_absence')->count(),
            'ncns' => Attendance::dateRange($startDate, $endDate)->byStatus('ncns')->count(),
            'advised' => Attendance::dateRange($startDate, $endDate)->byStatus('advised_absence')->count(),
            'needs_verification' => Attendance::dateRange($startDate, $endDate)->needsVerification()->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Delete multiple attendance records.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:attendances,id',
        ]);

        $count = Attendance::whereIn('id', $request->ids)->delete();

        return redirect()->back()
            ->with('success', "Successfully deleted {$count} attendance record" . ($count === 1 ? '' : 's') . '.');
    }

    /**
     * Adjust leave request when employee reports to work during approved leave.
     *
     * Scenarios:
     * 1. Single-day leave: Cancel the leave entirely, restore full credit
     * 2. Multi-day leave, work on first day: Adjust start date forward
     * 3. Multi-day leave, work on last day: Adjust end date backward
     * 4. Multi-day leave, work in middle: Split or adjust (currently just adjust end date to day before)
     *
     * @param Attendance $attendance The attendance record being verified
     * @param Request $request The verification request
     * @return array ['adjusted' => bool, 'message' => string]
     */
    private function adjustLeaveForWorkDay(Attendance $attendance, Request $request): array
    {
        $leaveRequest = $attendance->leaveRequest;
        $workDate = Carbon::parse($attendance->shift_date);
        $leaveStart = Carbon::parse($leaveRequest->start_date);
        $leaveEnd = Carbon::parse($leaveRequest->end_date);

        // Calculate original leave days (this should match what was approved)
        $originalLeaveDays = $leaveRequest->days_requested;
        $creditsDeducted = $leaveRequest->credits_deducted ?? $originalLeaveDays;

        // Check if credits can be restored based on year
        // Credits can only be restored if current year matches the credits_year
        // (e.g., 2025 credits used in Jan/Feb 2026 cannot be restored in 2026)
        $currentYear = now()->year;
        $creditsYear = $leaveRequest->credits_year;
        $canRestoreCredits = $creditsYear && $currentYear === $creditsYear;

        try {
            DB::beginTransaction();

            // Single day leave - cancel entirely
            if ($leaveStart->isSameDay($leaveEnd)) {
                // Only restore credits if year matches
                if ($canRestoreCredits) {
                    $this->leaveCreditService->restoreCredits($leaveRequest);
                }

                // Clear leave_request_id from all related attendance records
                $relatedAttendances = Attendance::where('user_id', $leaveRequest->user_id)
                    ->where('leave_request_id', $leaveRequest->id)
                    ->get();

                foreach ($relatedAttendances as $relatedAtt) {
                    $relatedAtt->update([
                        'leave_request_id' => null,
                        'status' => 'needs_manual_review',
                        'notes' => ($relatedAtt->notes ? $relatedAtt->notes . "\n" : '') . 'Leave was cancelled - requires review.',
                    ]);
                }

                // Build cancellation reason message
                $creditMessage = $canRestoreCredits
                    ? 'Original leave credit restored.'
                    : 'Credits not restored (year mismatch: credits from ' . $creditsYear . ', current year ' . $currentYear . ').';

                // Update leave request status to cancelled
                $leaveRequest->update([
                    'status' => 'cancelled',
                    'auto_cancelled' => true,
                    'auto_cancelled_at' => now(),
                    'auto_cancelled_reason' => 'Employee reported to work on ' . $workDate->format('M d, Y') . '. ' . $creditMessage,
                ]);

                // Notify the employee
                $this->notificationService->notifyLeaveRequest(
                    $leaveRequest->user_id,
                    $attendance->user->full_name ?? 'Employee',
                    'cancelled',
                    $leaveRequest->id
                );

                DB::commit();

                Log::info('Leave cancelled due to work day', [
                    'leave_request_id' => $leaveRequest->id,
                    'user_id' => $leaveRequest->user_id,
                    'work_date' => $workDate->format('Y-m-d'),
                    'credits_restored' => $canRestoreCredits ? $creditsDeducted : 0,
                    'can_restore_credits' => $canRestoreCredits,
                ]);

                $resultMessage = $canRestoreCredits
                    ? "Leave request cancelled. {$creditsDeducted} day(s) of {$leaveRequest->leave_type} credit restored."
                    : "Leave request cancelled. Credits not restored (year mismatch).";

                return [
                    'adjusted' => true,
                    'message' => $resultMessage,
                ];
            }

            // Multi-day leave - determine how to adjust
            $creditsToRestore = 0;
            $newStart = $leaveStart;
            $newEnd = $leaveEnd;
            $adjustmentType = '';

            // Work on first day of leave
            if ($workDate->isSameDay($leaveStart)) {
                $newStart = $leaveStart->copy()->addDay();
                $creditsToRestore = 1;
                $adjustmentType = 'start_adjusted';
            }
            // Work on last day of leave
            elseif ($workDate->isSameDay($leaveEnd)) {
                $newEnd = $leaveEnd->copy()->subDay();
                $creditsToRestore = 1;
                $adjustmentType = 'end_adjusted';
            }
            // Work in the middle of leave - adjust end date to day before work
            else {
                // Calculate days from work date to original end
                $daysToRestore = $workDate->diffInDays($leaveEnd) + 1; // +1 to include work day
                $newEnd = $workDate->copy()->subDay();
                $creditsToRestore = $daysToRestore;
                $adjustmentType = 'middle_adjusted';
            }

            // Calculate new leave days
            $newLeaveDays = $newStart->diffInDays($newEnd) + 1;

            // If adjustment results in no leave days (shouldn't happen but safety check)
            if ($newLeaveDays <= 0 || $newStart->gt($newEnd)) {
                // Cancel the leave entirely - only restore credits if year matches
                if ($canRestoreCredits) {
                    $this->leaveCreditService->restoreCredits($leaveRequest);
                }

                // Clear leave_request_id from all related attendance records
                $relatedAttendances = Attendance::where('user_id', $leaveRequest->user_id)
                    ->where('leave_request_id', $leaveRequest->id)
                    ->get();

                foreach ($relatedAttendances as $relatedAtt) {
                    $relatedAtt->update([
                        'leave_request_id' => null,
                        'status' => 'needs_manual_review',
                        'notes' => ($relatedAtt->notes ? $relatedAtt->notes . "\n" : '') . 'Leave was cancelled - requires review.',
                    ]);
                }
                
                $creditMessage = $canRestoreCredits 
                    ? 'Credits restored.' 
                    : 'Credits not restored (year mismatch).';

                $leaveRequest->update([
                    'status' => 'cancelled',
                    'auto_cancelled' => true,
                    'auto_cancelled_at' => now(),
                    'auto_cancelled_reason' => 'Employee worked on leave dates. ' . $creditMessage,
                ]);

                $this->notificationService->notifyLeaveRequest(
                    $leaveRequest->user_id,
                    $attendance->user->full_name ?? 'Employee',
                    'cancelled',
                    $leaveRequest->id
                );

                DB::commit();
                
                $resultMessage = $canRestoreCredits
                    ? "Leave request cancelled. {$creditsDeducted} day(s) of {$leaveRequest->leave_type} credit restored."
                    : "Leave request cancelled. Credits not restored (year mismatch).";

                return [
                    'adjusted' => true,
                    'message' => $resultMessage,
                ];
            }

            // Update the leave request with new dates
            // Only adjust credits_deducted if we can restore credits
            $newCreditsDeducted = $canRestoreCredits 
                ? max(0, $creditsDeducted - $creditsToRestore)
                : $creditsDeducted; // Keep original if can't restore
            
            $leaveRequest->update([
                'start_date' => $newStart->format('Y-m-d'),
                'end_date' => $newEnd->format('Y-m-d'),
                'days_requested' => $newLeaveDays,
                'credits_deducted' => $newCreditsDeducted,
                'original_start_date' => $leaveRequest->original_start_date ?? $leaveStart->format('Y-m-d'),
                'original_end_date' => $leaveRequest->original_end_date ?? $leaveEnd->format('Y-m-d'),
                'date_modified_by' => auth()->id(),
                'date_modified_at' => now(),
                'date_modification_reason' => 'Auto-adjusted: Employee reported to work on ' . $workDate->format('M d, Y') .
                    '. Leave dates changed from ' . $leaveStart->format('M d') . '-' . $leaveEnd->format('M d, Y') .
                    ' to ' . $newStart->format('M d') . '-' . $newEnd->format('M d, Y') . '.',
            ]);

            // Update other attendance records that are now outside the adjusted leave dates
            // Clear leave_request_id and set status to needs_manual_review for dates > newEnd
            $affectedAttendances = Attendance::where('user_id', $leaveRequest->user_id)
                ->where('leave_request_id', $leaveRequest->id)
                ->where('shift_date', '>', $newEnd->format('Y-m-d'))
                ->where('shift_date', '<=', $leaveEnd->format('Y-m-d'))
                ->get();

            foreach ($affectedAttendances as $affectedAtt) {
                $affectedAtt->update([
                    'leave_request_id' => null,
                    'status' => 'needs_manual_review',
                    'notes' => ($affectedAtt->notes ? $affectedAtt->notes . "\n" : '') .
                        'Leave was adjusted - this date is no longer covered by leave.',
                ]);
            }

            // Also update attendance records for dates < newStart (if start date was adjusted)
            if ($adjustmentType === 'start_adjusted') {
                $beforeStartAttendances = Attendance::where('user_id', $leaveRequest->user_id)
                    ->where('leave_request_id', $leaveRequest->id)
                    ->where('shift_date', '>=', $leaveStart->format('Y-m-d'))
                    ->where('shift_date', '<', $newStart->format('Y-m-d'))
                    ->get();

                foreach ($beforeStartAttendances as $affectedAtt) {
                    $affectedAtt->update([
                        'leave_request_id' => null,
                        'status' => 'needs_manual_review',
                        'notes' => ($affectedAtt->notes ? $affectedAtt->notes . "\n" : '') .
                            'Leave was adjusted - this date is no longer covered by leave.',
                    ]);
                }
            }

            // Restore partial credit using the service method (only if year matches)
            if ($creditsToRestore > 0 && $canRestoreCredits) {
                $this->leaveCreditService->restorePartialCredits(
                    $leaveRequest,
                    $creditsToRestore,
                    'Partial leave credit restored - Employee worked on ' . $workDate->format('M d, Y')
                );
            }
            
            // Build notification message based on whether credits were restored
            $creditNotificationMsg = $canRestoreCredits
                ? '. ' . $creditsToRestore . ' day(s) of leave credit has been restored.'
                : '. Credits not restored (year mismatch - credits from ' . $creditsYear . ', current year ' . $currentYear . ').';

            // Notify the employee about leave adjustment
            $this->notificationService->notifySystemMessage(
                $leaveRequest->user_id,
                'Leave Dates Adjusted',
                'Your ' . $leaveRequest->leave_type . ' leave has been adjusted from ' .
                    $leaveStart->format('M d') . '-' . $leaveEnd->format('M d, Y') . ' to ' .
                    $newStart->format('M d') . '-' . $newEnd->format('M d, Y') .
                    ' because you reported to work on ' . $workDate->format('M d, Y') . $creditNotificationMsg,
                ['leave_request_id' => $leaveRequest->id]
            );

            DB::commit();

            Log::info('Leave adjusted due to work day', [
                'leave_request_id' => $leaveRequest->id,
                'user_id' => $leaveRequest->user_id,
                'work_date' => $workDate->format('Y-m-d'),
                'adjustment_type' => $adjustmentType,
                'original_dates' => $leaveStart->format('Y-m-d') . ' to ' . $leaveEnd->format('Y-m-d'),
                'new_dates' => $newStart->format('Y-m-d') . ' to ' . $newEnd->format('Y-m-d'),
                'credits_restored' => $canRestoreCredits ? $creditsToRestore : 0,
                'can_restore_credits' => $canRestoreCredits,
            ]);
            
            $resultMessage = $canRestoreCredits
                ? "Leave adjusted to {$newStart->format('M d')}-{$newEnd->format('M d, Y')}. {$creditsToRestore} day(s) of {$leaveRequest->leave_type} credit restored."
                : "Leave adjusted to {$newStart->format('M d')}-{$newEnd->format('M d, Y')}. Credits not restored (year mismatch).";

            return [
                'adjusted' => true,
                'message' => $resultMessage,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to adjust leave for work day: ' . $e->getMessage(), [
                'leave_request_id' => $leaveRequest->id,
                'attendance_id' => $attendance->id,
                'work_date' => $workDate->format('Y-m-d'),
            ]);

            return [
                'adjusted' => false,
                'message' => 'Failed to adjust leave: ' . $e->getMessage(),
            ];
        }
    }
}
