<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\AttendanceUpload;
use App\Models\LeaveRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\AttendancePoint\GbroCalculationService;
use App\Services\AttendanceProcessor;
use App\Services\LeaveCreditService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    protected AttendanceProcessor $processor;

    protected NotificationService $notificationService;

    protected LeaveCreditService $leaveCreditService;

    protected GbroCalculationService $gbroService;

    public function __construct(
        AttendanceProcessor $processor,
        NotificationService $notificationService,
        LeaveCreditService $leaveCreditService,
        GbroCalculationService $gbroService
    ) {
        $this->processor = $processor;
        $this->notificationService = $notificationService;
        $this->leaveCreditService = $leaveCreditService;
        $this->gbroService = $gbroService;
    }

    /**
     * Display the attendance hub page for non-restricted roles.
     * Restricted roles (Agent, IT, Utility) are redirected to the main index.
     */
    public function hub()
    {
        $this->authorize('viewAny', \App\Models\Attendance::class);

        $user = auth()->user();
        $restrictedRoles = ['Agent', 'IT', 'Utility'];

        // Restricted roles go directly to the main attendance index
        if (in_array($user->role, $restrictedRoles)) {
            return redirect()->route('attendance.index');
        }

        return Inertia::render('Attendance/Main/Hub');
    }

    /**
     * Recalculate GBRO expiration dates for a user after points have been modified.
     * This ensures GBRO dates are always accurate when points are added/removed.
     */
    protected function recalculateGbroForUser(int $userId): void
    {
        try {
            $this->gbroService->cascadeRecalculateGbro($userId);
        } catch (\Exception $e) {
            Log::error('AttendanceController recalculateGbroForUser Error: '.$e->getMessage());
        }
    }

    /**
     * Check if attendance points need to be regenerated based on changes.
     * Returns true if points need regeneration, false if no changes affect points.
     *
     * @param  Attendance  $attendance  The attendance record (with updated values)
     * @param  string|null  $oldStatus  The previous status before update
     * @param  string|null  $oldSecondaryStatus  The previous secondary status
     * @param  bool  $oldIsSetHome  The previous is_set_home value
     * @param  bool  $oldIsAdvised  The previous is_advised value
     * @return bool Whether points need regeneration
     */
    protected function needsPointRegeneration(
        Attendance $attendance,
        ?string $oldStatus,
        ?string $oldSecondaryStatus,
        bool $oldIsSetHome,
        bool $oldIsAdvised
    ): bool {
        // Statuses that generate points
        $pointableStatuses = ['ncns', 'half_day_absence', 'tardy', 'undertime', 'undertime_more_than_hour', 'advised_absence'];

        $newStatus = $attendance->status;
        $newSecondaryStatus = $attendance->secondary_status;
        $newIsSetHome = $attendance->is_set_home ?? false;
        $newIsAdvised = $attendance->is_advised ?? false;

        // Check if old or new status generates points
        $oldGeneratesPoints = in_array($oldStatus, $pointableStatuses) || in_array($oldSecondaryStatus, $pointableStatuses);
        $newGeneratesPoints = in_array($newStatus, $pointableStatuses) || in_array($newSecondaryStatus, $pointableStatuses);

        // If neither generates points, no regeneration needed
        if (! $oldGeneratesPoints && ! $newGeneratesPoints) {
            return false;
        }

        // If one generates points and the other doesn't, regeneration needed
        if ($oldGeneratesPoints !== $newGeneratesPoints) {
            return true;
        }

        // NEW: Check if points exist for this attendance record
        // If status requires points but no points exist yet, regeneration is needed
        // This handles first-time verification where status may not have changed
        if ($newGeneratesPoints) {
            $existingPoints = AttendancePoint::where('attendance_id', $attendance->id)->exists();
            if (! $existingPoints) {
                return true;
            }
        }

        // Both generate points - check if the values that affect point calculation changed
        if ($oldStatus !== $newStatus) {
            return true;
        }

        if ($oldSecondaryStatus !== $newSecondaryStatus) {
            return true;
        }

        if ($oldIsSetHome !== $newIsSetHome) {
            return true;
        }

        // is_advised affects expiration type for whole_day_absence
        if ($oldIsAdvised !== $newIsAdvised && $newStatus === 'ncns') {
            return true;
        }

        // No changes that affect points
        return false;
    }

    /**
     * Regenerate attendance points for a record if needed.
     * Uses smart comparison to avoid unnecessary regeneration.
     *
     * @param  Attendance  $attendance  The attendance record
     * @param  string|null  $oldStatus  Previous status (null for new records)
     * @param  string|null  $oldSecondaryStatus  Previous secondary status
     * @param  bool  $oldIsSetHome  Previous is_set_home value
     * @param  bool  $oldIsAdvised  Previous is_advised value
     * @param  bool  $forceRegenerate  Force regeneration regardless of changes
     * @return bool Whether points were regenerated
     */
    protected function regeneratePointsIfNeeded(
        Attendance $attendance,
        ?string $oldStatus = null,
        ?string $oldSecondaryStatus = null,
        bool $oldIsSetHome = false,
        bool $oldIsAdvised = false,
        bool $forceRegenerate = false
    ): bool {
        $pointableStatuses = ['ncns', 'half_day_absence', 'tardy', 'undertime', 'undertime_more_than_hour', 'advised_absence'];

        // For new records, always regenerate if status requires points
        $isNewRecord = $oldStatus === null;

        // Check if regeneration is needed
        $needsRegeneration = $forceRegenerate || $isNewRecord || $this->needsPointRegeneration(
            $attendance,
            $oldStatus,
            $oldSecondaryStatus,
            $oldIsSetHome,
            $oldIsAdvised
        );

        if (! $needsRegeneration) {
            Log::info('AttendanceController: Skipping point regeneration - no relevant changes', [
                'attendance_id' => $attendance->id,
                'status' => $attendance->status,
            ]);

            return false;
        }

        // Delete existing points for this attendance record OR for the same user and date
        AttendancePoint::where(function ($query) use ($attendance) {
            $query->where('attendance_id', $attendance->id)
                ->orWhere(function ($q) use ($attendance) {
                    $q->where('user_id', $attendance->user_id)
                        ->where('shift_date', $attendance->shift_date);
                });
        })->delete();

        // Generate new points if the status requires them
        if (in_array($attendance->status, $pointableStatuses) ||
            in_array($attendance->secondary_status, $pointableStatuses)) {
            $this->processor->regeneratePointsForAttendance($attendance);
        }

        // Recalculate GBRO dates
        $this->recalculateGbroForUser($attendance->user_id);

        Log::info('AttendanceController: Points regenerated', [
            'attendance_id' => $attendance->id,
            'status' => $attendance->status,
            'is_set_home' => $attendance->is_set_home,
        ]);

        return true;
    }

    /**
     * Display a listing of attendance records.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Attendance::class);

        $user = auth()->user();

        // Determine Team Lead's campaign (if applicable)
        $teamLeadCampaignId = null;
        if ($user->role === 'Team Lead') {
            $activeSchedule = $user->activeSchedule;
            if ($activeSchedule && $activeSchedule->campaign_id) {
                $teamLeadCampaignId = $activeSchedule->campaign_id;
            }
        }

        $query = Attendance::with([
            'user.activeSchedule.site', // Fallback for site
            'user.activeSchedule.campaign', // Fallback for campaign
            'employeeSchedule.site',
            'employeeSchedule.campaign',
            'bioInSite',
            'bioOutSite',
            'leaveRequest', // Include leave request info
        ]);

        // Restrict certain roles to only view their own attendance records
        $restrictedRoles = ['Agent', 'IT', 'Utility'];
        if (in_array($user->role, $restrictedRoles)) {
            $query->where('user_id', auth()->id());
        } elseif ($user->role === 'Team Lead' && $teamLeadCampaignId) {
            // Team Leads see only their campaign's attendance (unless they manually filter)
            $campaignFilter = $request->input('campaign_id');
            if (! $campaignFilter || $campaignFilter === 'all') {
                // Default to Team Lead's campaign if no filter specified
                $query->whereHas('employeeSchedule', function ($q) use ($teamLeadCampaignId) {
                    $q->where('campaign_id', $teamLeadCampaignId);
                });
            }

            // Search by employee name
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere(\DB::raw("CONCAT(first_name, ' ', last_name)"), 'like', "%{$search}%");
                });
            }

            // Allow user_id filter (supports multiple IDs comma-separated)
            if ($request->has('user_id') && $request->user_id !== 'all' && $request->user_id) {
                $userIds = is_array($request->user_id)
                    ? $request->user_id
                    : array_filter(explode(',', $request->user_id));
                if (count($userIds) > 0) {
                    $query->whereIn('user_id', $userIds);
                }
            }
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

            // Allow user_id filter for non-restricted roles (supports multiple IDs comma-separated)
            if ($request->has('user_id') && $request->user_id !== 'all' && $request->user_id) {
                $userIds = is_array($request->user_id)
                    ? $request->user_id
                    : array_filter(explode(',', $request->user_id));
                if (count($userIds) > 0) {
                    $query->whereIn('user_id', $userIds);
                }
            }
        }

        // Filters (available to all roles)
        // Status filter (supports multiple statuses comma-separated)
        if ($request->has('status') && $request->status !== 'all' && $request->status) {
            $statuses = is_array($request->status)
                ? $request->status
                : array_filter(explode(',', $request->status));
            if (count($statuses) > 0) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        if ($request->has('needs_verification') && $request->needs_verification) {
            $query->needsVerification();
        }

        // Filter by verification status
        if ($request->has('verified_status') && $request->verified_status !== 'all' && $request->verified_status) {
            if ($request->verified_status === 'verified') {
                $query->where('admin_verified', true);
            } elseif ($request->verified_status === 'pending') {
                $query->where('admin_verified', false);
            }
        }

        // Filter by site (via employee schedule)
        if ($request->has('site_id') && $request->site_id !== 'all' && $request->site_id) {
            $query->whereHas('employeeSchedule', function ($q) use ($request) {
                $q->where('site_id', $request->site_id);
            });
        }

        // Filter by campaign (via employee schedule) - supports multiple IDs comma-separated
        if ($request->has('campaign_id') && $request->campaign_id !== 'all' && $request->campaign_id) {
            $campaignIds = is_array($request->campaign_id)
                ? $request->campaign_id
                : array_filter(explode(',', $request->campaign_id));

            if (count($campaignIds) > 0) {
                // For Team Leads, only allow filtering within their campaign
                if ($user->role === 'Team Lead' && ! in_array($teamLeadCampaignId, $campaignIds)) {
                    // Team Lead trying to filter outside their campaign - ignore
                } else {
                    $query->whereHas('employeeSchedule', function ($q) use ($campaignIds) {
                        $q->whereIn('campaign_id', $campaignIds);
                    });
                }
            }
        }

        $attendances = $query
            ->join('users', 'attendances.user_id', '=', 'users.id')
            ->select('attendances.*')
            ->orderBy('attendances.shift_date', 'desc')
            ->orderBy('users.last_name', 'asc')
            ->paginate(60)
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
                'name' => $user->first_name.' '.$user->last_name,
            ]);

        // Get all sites for site filter dropdown
        $sites = Site::orderBy('name')->get(['id', 'name']);

        // Get all campaigns for campaign filter dropdown
        $campaigns = \App\Models\Campaign::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/Main/Index', [
            'attendances' => $attendances,
            'users' => $users,
            'sites' => $sites,
            'campaigns' => $campaigns,
            'teamLeadCampaignId' => $teamLeadCampaignId,
            'filters' => $request->only(['search', 'status', 'start_date', 'end_date', 'user_id', 'site_id', 'campaign_id', 'needs_verification', 'verified_status']),
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
        $campaignFilter = $request->input('campaign_id', '');

        $authUser = auth()->user();

        // Detect Team Lead's campaign for auto-filter
        $teamLeadCampaignId = null;
        if ($authUser->role === 'Team Lead') {
            $activeSchedule = $authUser->activeSchedule;
            if ($activeSchedule && $activeSchedule->campaign_id) {
                $teamLeadCampaignId = $activeSchedule->campaign_id;
            }
        }

        // Auto-filter campaign for Team Leads when no campaign is specified
        $campaignIdToFilter = $campaignFilter ?: null;
        if (! $campaignIdToFilter && $teamLeadCampaignId) {
            $campaignIdToFilter = $teamLeadCampaignId;
        }

        // Restrict certain roles to only view their own attendance records
        $restrictedRoles = ['Agent', 'IT', 'Utility'];
        if (in_array($authUser->role, $restrictedRoles)) {
            $userId = auth()->id();
        } elseif (! $userId && $request->has('user_id')) {
            $userId = $request->user_id;
        }

        // Get all users for selection (if user has permission)
        $users = [];
        if (! in_array($authUser->role, $restrictedRoles)) {
            $usersQuery = User::select('id', 'first_name', 'last_name')
                ->orderBy('first_name')
                ->orderBy('last_name');

            // Filter users by campaign if specified
            if ($campaignIdToFilter) {
                $usersQuery->whereHas('activeSchedule', function ($q) use ($campaignIdToFilter) {
                    $q->where('campaign_id', $campaignIdToFilter);
                });
            }

            $users = $usersQuery->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->first_name.' '.$user->last_name,
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
                    'name' => $user->first_name.' '.$user->last_name,
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
                'bioOutSite',
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

        // Get campaigns for filter dropdown
        $campaigns = \App\Models\Campaign::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/Main/Calendar', [
            'attendances' => (object) $attendances, // Cast to object so it's treated as associative array in JS
            'users' => $users,
            'selectedUser' => $selectedUser,
            'campaigns' => $campaigns,
            'teamLeadCampaignId' => $teamLeadCampaignId,
            'month' => (int) $month,
            'year' => (int) $year,
            'verificationFilter' => $verificationFilter,
            'campaignFilter' => $campaignFilter,
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
                    'name' => $user->first_name.' '.$user->last_name,
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
                'actual_time_in' => $request->actual_time_in_date.'T'.$request->actual_time_in_time,
            ]);
        }

        if ($request->has('actual_time_out_date') && $request->has('actual_time_out_time') && $request->actual_time_out_date && $request->actual_time_out_time) {
            $request->merge([
                'actual_time_out' => $request->actual_time_out_date.'T'.$request->actual_time_out_time,
            ]);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'shift_date' => 'required|date',
            'status' => 'nullable|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,undertime_more_than_hour,failed_bio_in,failed_bio_out,present_no_bio,non_work_day,on_leave',
            'secondary_status' => 'nullable|in:undertime,undertime_more_than_hour,failed_bio_in,failed_bio_out',
            'actual_time_in' => 'nullable|date_format:Y-m-d\\TH:i',
            'actual_time_out' => 'nullable|date_format:Y-m-d\\TH:i',
            'notes' => 'nullable|string|max:500',
            'is_set_home' => 'nullable|boolean',
            'undertime_approval_status' => 'nullable|in:approved',
            'undertime_approval_reason' => 'nullable|in:generate_points,skip_points,lunch_used',
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
        $secondaryStatus = $validated['secondary_status'] ?? null;

        // Auto-set status to on_leave if there's an approved leave request
        if ($approvedLeave && ! $status) {
            $status = 'on_leave';
        } elseif (! $status) {
            // Auto-determine status based on actual times and schedule
            $hasBioIn = (bool) $actualTimeIn;
            $hasBioOut = (bool) $actualTimeOut;
            $isTardy = false;
            $hasUndertime = false;

            if ($schedule && $hasBioIn && $hasBioOut) {
                $shiftDate = Carbon::parse($validated['shift_date']);
                $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
                $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);

                // Handle night shift (time out is next day)
                if ($schedule->shift_type === 'night_shift' && $scheduledTimeOut->lt($scheduledTimeIn)) {
                    $scheduledTimeOut->addDay();
                }

                // Calculate tardy (late arrival) - always calculate minutes, grace period only affects status
                $gracePeriod = $schedule->grace_period_minutes ?? 0;
                if ($actualTimeIn->gt($scheduledTimeIn)) {
                    $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                    // Only mark as tardy if beyond grace period
                    if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                        $isTardy = true;
                    }
                }

                // Calculate undertime (early leave) and overtime (late leave)
                if ($actualTimeOut->lt($scheduledTimeOut)) {
                    $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
                    $hasUndertime = true;
                } elseif ($actualTimeOut->gt($scheduledTimeOut)) {
                    $overtimeMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut);
                }
            } elseif ($schedule && $hasBioIn) {
                // Has schedule and time in, check if tardy
                $shiftDate = Carbon::parse($validated['shift_date']);
                $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
                $gracePeriod = $schedule->grace_period_minutes ?? 0;
                // Always calculate minutes, grace period only affects status
                if ($actualTimeIn->gt($scheduledTimeIn)) {
                    $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                    // Only mark as tardy if beyond grace period
                    if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                        $isTardy = true;
                    }
                }
            }

            // Determine status based on violations (can be combined)
            if (! $hasBioIn && ! $hasBioOut) {
                $status = 'present_no_bio';
            } elseif (! $hasBioIn && $hasBioOut) {
                $status = 'failed_bio_in';
            } elseif ($hasBioIn && ! $hasBioOut) {
                // Check if also tardy - if tardy, that becomes primary status
                if ($isTardy) {
                    $status = 'tardy';
                    $secondaryStatus = 'failed_bio_out';
                } else {
                    $status = 'failed_bio_out';
                }
            } elseif ($isTardy && $hasUndertime) {
                $status = 'tardy'; // Both violations, tardy takes precedence
                $secondaryStatus = 'undertime';
            } elseif ($isTardy) {
                $status = 'tardy';
            } elseif ($hasUndertime) {
                $status = 'undertime';
            } else {
                $status = 'on_time';
            }
        } elseif ($schedule && $actualTimeIn && $actualTimeOut) {
            // Status was manually provided, but still calculate tardiness/undertime/overtime
            $shiftDate = Carbon::parse($validated['shift_date']);
            $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
            $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);

            // Handle night shift (time out is next day)
            if ($schedule->shift_type === 'night_shift' && $scheduledTimeOut->lt($scheduledTimeIn)) {
                $scheduledTimeOut->addDay();
            }

            // Calculate tardy minutes (actual late arrival - no grace period check since status is already determined)
            if ($actualTimeIn->gt($scheduledTimeIn)) {
                $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
            }

            // Calculate undertime (early leave) and overtime (late leave)
            if ($actualTimeOut->lt($scheduledTimeOut)) {
                $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
            } elseif ($actualTimeOut->gt($scheduledTimeOut)) {
                $timeBeyondSchedule = $scheduledTimeOut->diffInMinutes($actualTimeOut);
                // Only count overtime if more than 30 minutes beyond scheduled time out
                if ($timeBeyondSchedule > 30) {
                    $overtimeMinutes = $timeBeyondSchedule;
                }
            }
        } elseif ($schedule && $actualTimeIn) {
            // Status was manually provided with only time in - still calculate tardiness
            $shiftDate = Carbon::parse($validated['shift_date']);
            $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);

            // Calculate tardy minutes (actual late arrival - no grace period check since status is already determined)
            if ($actualTimeIn->gt($scheduledTimeIn)) {
                $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
            }
        } elseif ($schedule && $actualTimeOut) {
            // Status was manually provided with only time out - still calculate undertime/overtime
            $shiftDate = Carbon::parse($validated['shift_date']);
            $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);

            // Handle night shift (time out is next day)
            if ($schedule->shift_type === 'night_shift' && $schedule->scheduled_time_in) {
                $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
                if ($scheduledTimeOut->lt($scheduledTimeIn)) {
                    $scheduledTimeOut->addDay();
                }
            }

            // Calculate undertime (early leave) and overtime (late leave)
            if ($actualTimeOut->lt($scheduledTimeOut)) {
                $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
            } elseif ($actualTimeOut->gt($scheduledTimeOut)) {
                $timeBeyondSchedule = $scheduledTimeOut->diffInMinutes($actualTimeOut);
                // Only count overtime if more than 30 minutes beyond scheduled time out
                if ($timeBeyondSchedule > 30) {
                    $overtimeMinutes = $timeBeyondSchedule;
                }
            }
        }

        // Determine if undertime approval was pre-approved during creation
        $undertimeApprovalStatus = null;
        $undertimeApprovalReason = null;
        $undertimeApprovalBy = null;
        $undertimeApprovalAt = null;

        if (! empty($validated['undertime_approval_status']) && $validated['undertime_approval_status'] === 'approved') {
            // Only allow pre-approval if user has permission
            if ($this->permissionService->userHasPermission(auth()->user(), 'attendance.approve_undertime')) {
                $undertimeApprovalStatus = 'approved';
                $undertimeApprovalReason = $validated['undertime_approval_reason'] ?? 'generate_points';
                $undertimeApprovalBy = auth()->id();
                $undertimeApprovalAt = now();

                // If lunch_used, reduce undertime by 60 minutes
                if ($undertimeApprovalReason === 'lunch_used' && $undertimeMinutes !== null) {
                    $undertimeMinutes = max(0, $undertimeMinutes - 60);

                    // Update status if undertime is now 0 or reduced
                    if ($undertimeMinutes === 0) {
                        if ($status === 'undertime' || $status === 'undertime_more_than_hour') {
                            $status = 'on_time';
                        } elseif ($secondaryStatus === 'undertime' || $secondaryStatus === 'undertime_more_than_hour') {
                            $secondaryStatus = null;
                        }
                    } elseif ($undertimeMinutes > 0 && $undertimeMinutes <= 60) {
                        if ($status === 'undertime_more_than_hour') {
                            $status = 'undertime';
                        } elseif ($secondaryStatus === 'undertime_more_than_hour') {
                            $secondaryStatus = 'undertime';
                        }
                    }
                }
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
            'is_advised' => $status === 'advised_absence', // Set is_advised flag for advised absence
            'is_set_home' => $request->boolean('is_set_home'),
            'admin_verified' => ! $hasLeaveConflict, // Requires approval if leave conflict
            'leave_request_id' => $approvedLeave?->id,
            'verification_notes' => $hasLeaveConflict
                ? 'Manual entry during approved leave - requires HR review. Created by '.auth()->user()->name
                : 'Manually created by '.auth()->user()->name,
            'notes' => $validated['notes'],
            'remarks' => $hasLeaveConflict
                ? 'Leave conflict: Employee on approved leave but has attendance entry. Pending HR review.'
                : null,
            'undertime_approval_status' => $undertimeApprovalStatus,
            'undertime_approval_reason' => $undertimeApprovalReason,
            'undertime_approved_by' => $undertimeApprovalBy,
            'undertime_approved_at' => $undertimeApprovalAt,
        ]);

        // If lunch_used, recalculate total minutes worked (no lunch deduction)
        if ($undertimeApprovalReason === 'lunch_used') {
            $this->processor->recalculateTotalMinutesWorked($attendance);
        }

        // Generate attendance points if the record is admin_verified and has a violation status
        if ($attendance->admin_verified) {
            // For new records, force regeneration (handles deletion of any existing points for same user+date)
            $this->regeneratePointsIfNeeded($attendance, null, null, false, false, true);
        }

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
                $actualTimeIn ? $actualTimeIn->format('H:i').($actualTimeOut ? ', '.$actualTimeOut->format('H:i') : '') : 'N/A',
                $workDuration,
                $approvedLeave->start_date != $approvedLeave->end_date
            );
        }

        return redirect()->route('attendance.index')
            ->with('message', $hasLeaveConflict
                ? 'Attendance record created. Requires HR approval due to leave conflict.'
                : 'Attendance record created successfully.')
            ->with('type', 'success');
    }

    /**
     * Store bulk manually created attendance records.
     */
    public function bulkStore(Request $request)
    {
        // Combine date and time fields on the backend if they exist
        if ($request->has('actual_time_in_date') && $request->has('actual_time_in_time') && $request->actual_time_in_date && $request->actual_time_in_time) {
            $request->merge([
                'actual_time_in' => $request->actual_time_in_date.'T'.$request->actual_time_in_time,
            ]);
        }

        if ($request->has('actual_time_out_date') && $request->has('actual_time_out_time') && $request->actual_time_out_date && $request->actual_time_out_time) {
            $request->merge([
                'actual_time_out' => $request->actual_time_out_date.'T'.$request->actual_time_out_time,
            ]);
        }

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required|exists:users,id',
            'shift_date' => 'required|date',
            'status' => 'nullable|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,undertime_more_than_hour,failed_bio_in,failed_bio_out,present_no_bio,non_work_day,on_leave',
            'secondary_status' => 'nullable|in:undertime,undertime_more_than_hour,failed_bio_in,failed_bio_out',
            'actual_time_in' => 'nullable|date_format:Y-m-d\\TH:i',
            'actual_time_out' => 'nullable|date_format:Y-m-d\\TH:i',
            'notes' => 'nullable|string|max:500',
            'is_set_home' => 'nullable|boolean',
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
                $secondaryStatus = $validated['secondary_status'] ?? null;

                // Auto-set status to on_leave if there's an approved leave request
                if ($approvedLeave && ! $status) {
                    $status = 'on_leave';
                } elseif (! $status) {
                    // Auto-determine status based on actual times and schedule
                    $hasBioIn = (bool) $actualTimeIn;
                    $hasBioOut = (bool) $actualTimeOut;
                    $isTardy = false;
                    $hasUndertime = false;

                    if ($schedule && $hasBioIn && $hasBioOut) {
                        $shiftDate = Carbon::parse($validated['shift_date']);
                        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
                        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);

                        // Handle night shift (time out is next day)
                        if ($schedule->shift_type === 'night_shift' && $scheduledTimeOut->lt($scheduledTimeIn)) {
                            $scheduledTimeOut->addDay();
                        }

                        // Calculate tardy (late arrival) - always calculate minutes, grace period only affects status
                        $gracePeriod = $schedule->grace_period_minutes ?? 0;
                        if ($actualTimeIn->gt($scheduledTimeIn)) {
                            $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                            // Only mark as tardy if beyond grace period
                            if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                                $isTardy = true;
                            }
                        }

                        // Calculate undertime (early leave) and overtime (late leave)
                        if ($actualTimeOut->lt($scheduledTimeOut)) {
                            $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
                            $hasUndertime = true;
                        } elseif ($actualTimeOut->gt($scheduledTimeOut)) {
                            $timeBeyondSchedule = $scheduledTimeOut->diffInMinutes($actualTimeOut);
                            // Only count overtime if more than 30 minutes beyond scheduled time out
                            if ($timeBeyondSchedule > 30) {
                                $overtimeMinutes = $timeBeyondSchedule;
                            }
                        }
                    } elseif ($schedule && $hasBioIn) {
                        // Has schedule and time in, check if tardy
                        $shiftDate = Carbon::parse($validated['shift_date']);
                        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
                        $gracePeriod = $schedule->grace_period_minutes ?? 0;
                        // Always calculate minutes, grace period only affects status
                        if ($actualTimeIn->gt($scheduledTimeIn)) {
                            $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                            // Only mark as tardy if beyond grace period
                            if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                                $isTardy = true;
                            }
                        }
                    }

                    // Determine status based on violations (can be combined)
                    if (! $hasBioIn && ! $hasBioOut) {
                        $status = 'present_no_bio';
                    } elseif (! $hasBioIn && $hasBioOut) {
                        $status = 'failed_bio_in';
                    } elseif ($hasBioIn && ! $hasBioOut) {
                        // Check if also tardy - if tardy, that becomes primary status
                        if ($isTardy) {
                            $status = 'tardy';
                            $secondaryStatus = 'failed_bio_out';
                        } else {
                            $status = 'failed_bio_out';
                        }
                    } elseif ($isTardy && $hasUndertime) {
                        $status = 'tardy'; // Both violations, tardy takes precedence
                        $secondaryStatus = 'undertime';
                    } elseif ($isTardy) {
                        $status = 'tardy';
                    } elseif ($hasUndertime) {
                        $status = 'undertime';
                    } else {
                        $status = 'on_time';
                    }
                } elseif ($schedule && $actualTimeIn && $actualTimeOut) {
                    // Status was manually provided, but still calculate tardiness/undertime/overtime
                    $shiftDate = Carbon::parse($validated['shift_date']);
                    $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
                    $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);

                    // Handle night shift (time out is next day)
                    if ($schedule->shift_type === 'night_shift' && $scheduledTimeOut->lt($scheduledTimeIn)) {
                        $scheduledTimeOut->addDay();
                    }

                    // Calculate tardy minutes (actual late arrival - no grace period check since status is already determined)
                    if ($actualTimeIn->gt($scheduledTimeIn)) {
                        $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                    }

                    // Calculate undertime (early leave) and overtime (late leave)
                    if ($actualTimeOut->lt($scheduledTimeOut)) {
                        $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
                    } elseif ($actualTimeOut->gt($scheduledTimeOut)) {
                        $timeBeyondSchedule = $scheduledTimeOut->diffInMinutes($actualTimeOut);
                        // Only count overtime if more than 30 minutes beyond scheduled time out
                        if ($timeBeyondSchedule > 30) {
                            $overtimeMinutes = $timeBeyondSchedule;
                        }
                    }
                } elseif ($schedule && $actualTimeIn) {
                    // Status was manually provided with only time in - still calculate tardiness
                    $shiftDate = Carbon::parse($validated['shift_date']);
                    $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);

                    // Calculate tardy minutes (actual late arrival - no grace period check since status is already determined)
                    if ($actualTimeIn->gt($scheduledTimeIn)) {
                        $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
                    }
                } elseif ($schedule && $actualTimeOut) {
                    // Status was manually provided with only time out - still calculate undertime/overtime
                    $shiftDate = Carbon::parse($validated['shift_date']);
                    $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);

                    // Handle night shift (time out is next day)
                    if ($schedule->shift_type === 'night_shift' && $schedule->scheduled_time_in) {
                        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
                        if ($scheduledTimeOut->lt($scheduledTimeIn)) {
                            $scheduledTimeOut->addDay();
                        }
                    }

                    // Calculate undertime (early leave) and overtime (late leave)
                    if ($actualTimeOut->lt($scheduledTimeOut)) {
                        $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
                    } elseif ($actualTimeOut->gt($scheduledTimeOut)) {
                        $timeBeyondSchedule = $scheduledTimeOut->diffInMinutes($actualTimeOut);
                        // Only count overtime if more than 30 minutes beyond scheduled time out
                        if ($timeBeyondSchedule > 30) {
                            $overtimeMinutes = $timeBeyondSchedule;
                        }
                    }
                }

                $attendance = Attendance::create([
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
                    'is_advised' => $status === 'advised_absence', // Set is_advised flag for advised absence
                    'is_set_home' => $request->boolean('is_set_home'),
                    'admin_verified' => true, // Manual entries are pre-verified
                    'verification_notes' => 'Manually created by '.auth()->user()->name,
                    'notes' => $validated['notes'],
                ]);

                // Generate attendance points for new record - force regeneration
                $this->regeneratePointsIfNeeded($attendance, null, null, false, false, true);

                $createdCount++;
            } catch (\Exception $e) {
                $user = User::find($userId);
                $errors[] = "Failed to create attendance for {$user->name}: {$e->getMessage()}";
            }
        }

        if ($createdCount > 0) {
            $message = "{$createdCount} attendance record(s) created successfully.";
            if (count($errors) > 0) {
                $message .= ' However, '.count($errors).' record(s) failed.';
            }

            return redirect()->route('attendance.index')->with('message', $message)->with('type', 'success');
        }

        return redirect()->route('attendance.index')
            ->with('message', 'Failed to create any attendance records.')
            ->with('type', 'error');
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
     * Preview file upload - analyze records and show what will be imported.
     * Returns breakdown of records within/outside date range and potential duplicates.
     */
    public function previewUpload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:txt|max:10240',
            'date_from' => 'required|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'biometric_site_id' => 'required|exists:sites,id',
        ]);

        try {
            $file = $request->file('file');
            $filePath = $file->getRealPath();

            // Parse the file
            $parser = app(\App\Services\AttendanceFileParser::class);
            $records = $parser->parse($filePath);

            if ($records->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid records found in the file.',
                ], 422);
            }

            $dateFrom = Carbon::parse($request->date_from);
            $dateTo = Carbon::parse($request->date_to ?? $request->date_from);

            // Filter records by date range
            $filtered = $parser->filterByDateRange($records, $dateFrom, $dateTo);

            // Check for potential duplicates (existing attendance records)
            $duplicateCheck = $this->checkForDuplicateRecords(
                $filtered['within_range'],
                $dateFrom,
                $dateTo
            );

            // Get unique employee names in the file
            $employeeNames = $filtered['within_range']->pluck('name')->unique()->values()->toArray();
            $outsideRangeEmployees = $filtered['outside_range']->pluck('name')->unique()->values()->toArray();

            // Get dates found in the file
            $datesInRange = $filtered['within_range']
                ->pluck('datetime')
                ->map(fn ($dt) => $dt->format('Y-m-d'))
                ->unique()
                ->sort()
                ->values()
                ->toArray();

            $datesOutsideRange = $filtered['outside_range']
                ->pluck('datetime')
                ->map(fn ($dt) => $dt->format('Y-m-d'))
                ->unique()
                ->sort()
                ->values()
                ->toArray();

            return response()->json([
                'success' => true,
                'preview' => [
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'total_records' => $records->count(),
                    'within_range' => [
                        'count' => $filtered['within_range']->count(),
                        'unique_employees' => count($employeeNames),
                        'dates' => $datesInRange,
                        'employees' => array_slice($employeeNames, 0, 20), // Limit to 20 for preview
                    ],
                    'outside_range' => [
                        'count' => $filtered['outside_range']->count(),
                        'unique_employees' => count($outsideRangeEmployees),
                        'dates' => $datesOutsideRange,
                        'employees' => array_slice($outsideRangeEmployees, 0, 10),
                    ],
                    'duplicates' => $duplicateCheck,
                    'date_range' => [
                        'from' => $dateFrom->format('M d, Y'),
                        'to' => $dateTo->format('M d, Y'),
                        'extended_to' => $dateTo->copy()->addDay()->format('M d, Y'),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Preview upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to parse file: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check for potential duplicate attendance records.
     */
    protected function checkForDuplicateRecords($records, Carbon $dateFrom, Carbon $dateTo): array
    {
        // Get unique employee-date combinations from the file
        $employeeDates = $records->map(function ($record) {
            return $record['normalized_name'].'_'.$record['datetime']->format('Y-m-d');
        })->unique()->values();

        // Get unique dates
        $dates = $records->pluck('datetime')
            ->map(fn ($dt) => $dt->format('Y-m-d'))
            ->unique()
            ->toArray();

        if (empty($dates)) {
            return [
                'count' => 0,
                'message' => 'No records to check',
            ];
        }

        // Check existing attendance records in the date range
        $existingAttendances = Attendance::whereIn('shift_date', $dates)
            ->with('user')
            ->get();

        $potentialDuplicates = [];
        foreach ($existingAttendances as $attendance) {
            if ($attendance->user) {
                $userName = strtolower($attendance->user->first_name.' '.$attendance->user->last_name);
                $key = $userName.'_'.$attendance->shift_date;

                // Simplified check - mark as potential duplicate if attendance exists for that date
                $potentialDuplicates[] = [
                    'employee' => $attendance->user->first_name.' '.$attendance->user->last_name,
                    'date' => $attendance->shift_date,
                    'status' => $attendance->status,
                    'verified' => $attendance->admin_verified,
                ];
            }
        }

        return [
            'count' => count($potentialDuplicates),
            'records' => array_slice($potentialDuplicates, 0, 10), // Limit to 10 for preview
            'message' => count($potentialDuplicates) > 0
                ? 'Existing attendance records found. Unverified records will be updated; verified records will be skipped.'
                : 'No existing attendance records found for this period.',
        ];
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
            'filter_by_date' => 'nullable|boolean', // Whether to filter records by date range
        ]);

        // Determine if we should filter by date range (default: true for hybrid approach)
        $filterByDate = $request->boolean('filter_by_date', true);

        // Store the file
        $file = $request->file('file');
        $filename = time().'_'.$file->getClientOriginalName();
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
            // Process the file with date filtering option
            $filePath = Storage::path($path);
            $stats = $this->processor->processUpload($upload, $filePath, $filterByDate);

            // Prepare success message with more details
            $processedCount = $stats['filtered_records'] ?? $stats['total_records'];
            $skippedCount = $stats['skipped_records'] ?? 0;

            $message = sprintf(
                'Attendance file processed successfully. Records: %d processed, %d matched employees, %d unmatched names',
                $processedCount,
                $stats['matched_employees'],
                count($stats['unmatched_names'])
            );

            // Add skipped records info if filtering was applied
            if ($filterByDate && $skippedCount > 0) {
                $message .= sprintf('. %d records outside date range were skipped.', $skippedCount);
            }

            // Add date validation warnings if any
            if (! empty($stats['date_warnings'])) {
                $warningMessage = 'Date Validation Warnings: '.implode(' ', $stats['date_warnings']);
                session()->flash('warning', $warningMessage);
            }

            // Add unmatched names to flash message for debugging
            if (! empty($stats['unmatched_names'])) {
                $unmatchedList = implode(', ', array_slice($stats['unmatched_names'], 0, 10));
                if (count($stats['unmatched_names']) > 10) {
                    $unmatchedList .= '... and '.(count($stats['unmatched_names']) - 10).' more';
                }
                $message .= '. Unmatched: '.$unmatchedList;
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
                ->with('message', $message)
                ->with('type', 'success');

        } catch (\Exception $e) {
            // Update upload record to show failure
            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            \Log::error('Attendance upload failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return JSON error if requested via fetch/API
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process attendance file: '.$e->getMessage(),
                ], 422);
            }

            return redirect()->back()
                ->with('message', 'Failed to process attendance file: '.$e->getMessage())
                ->with('type', 'error');
        }
    }

    /**
     * Show records that need verification.
     */
    public function review(Request $request)
    {
        $user = auth()->user();

        // Determine Team Lead's campaign (if applicable)
        $teamLeadCampaignId = null;
        if ($user->role === 'Team Lead') {
            $activeSchedule = $user->activeSchedule;
            if ($activeSchedule && $activeSchedule->campaign_id) {
                $teamLeadCampaignId = $activeSchedule->campaign_id;
            }
        }

        $query = Attendance::with([
            'user.activeSchedule.site', // Include user's active schedule as fallback
            'user.activeSchedule.campaign', // Include user's active schedule campaign as fallback
            'employeeSchedule.site',
            'employeeSchedule.campaign',
            'bioInSite',
            'bioOutSite',
            'leaveRequest', // Include leave request info
        ]);

        // Auto-filter for Team Leads by their campaign
        if ($user->role === 'Team Lead' && $teamLeadCampaignId) {
            $campaignFilter = $request->input('campaign_id');
            if (! $campaignFilter || $campaignFilter === 'all') {
                // Default to Team Lead's campaign if no filter specified
                $query->whereHas('employeeSchedule', function ($q) use ($teamLeadCampaignId) {
                    $q->where('campaign_id', $teamLeadCampaignId);
                });
            }
        }

        // Filter by verification status
        // Default behavior: show all records (matching frontend default "All Records")
        if ($request->has('verified') && $request->verified !== '') {
            if ($request->verified === 'verified') {
                $query->where('admin_verified', true)
                    ->where('is_partially_verified', false);
            } elseif ($request->verified === 'pending') {
                // Show ALL unverified records, not just those with specific statuses
                $query->where('admin_verified', false);
            } elseif ($request->verified === 'partially_verified') {
                // Show records that are partially approved (time out pending)
                $query->where('admin_verified', true)
                    ->where('is_partially_verified', true);
            }
            // Empty string means 'all' - no filter applied, show everything
        }

        // Filter by specific employee (supports multiple IDs comma-separated)
        if ($request->filled('user_id')) {
            $userIds = is_array($request->user_id)
                ? $request->user_id
                : array_filter(explode(',', $request->user_id));
            if (count($userIds) > 0) {
                $query->whereIn('user_id', $userIds);
            }
        }

        // Filter by status (supports multiple statuses comma-separated)
        if ($request->filled('status')) {
            $statuses = is_array($request->status)
                ? $request->status
                : array_filter(explode(',', $request->status));
            if (count($statuses) > 0) {
                $query->whereIn('status', $statuses);
            }
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

        // Filter by campaign (via employee schedule) - supports multiple IDs comma-separated
        if ($request->filled('campaign_id')) {
            $campaignIds = is_array($request->campaign_id)
                ? $request->campaign_id
                : array_filter(explode(',', $request->campaign_id));
            if (count($campaignIds) > 0) {
                $query->whereHas('employeeSchedule', function ($q) use ($campaignIds) {
                    $q->whereIn('campaign_id', $campaignIds);
                });
            }
        }

        // Filter by leave conflict (employee has biometric activity during approved leave)
        if ($request->filled('leave_conflict') && $request->leave_conflict === 'yes') {
            $query->whereNotNull('leave_request_id')
                ->where('status', '!=', 'on_leave')
                ->where(function ($q) {
                    $q->whereNotNull('actual_time_in')
                        ->orWhereNotNull('actual_time_out');
                });
        }

        // If verify parameter is provided, filter to show only that specific record
        // This ensures the record is visible when clicking "Verify" from the attendance list
        $verifyAttendanceId = null;
        if ($request->filled('verify')) {
            $verifyAttendanceId = (int) $request->verify;
            $query->where('attendances.id', $verifyAttendanceId);
        }

        $attendances = $query
            ->join('users', 'attendances.user_id', '=', 'users.id')
            ->select('attendances.*')
            ->orderBy('attendances.shift_date', 'desc')
            ->orderBy('users.last_name', 'asc')
            ->paginate(60)
            ->withQueryString();

        // Get all employees for the dropdown
        $employees = User::select('id', 'first_name', 'last_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->first_name.' '.$user->last_name,
            ]);

        // Get all sites for site filter dropdown
        $sites = Site::orderBy('name')->get(['id', 'name']);

        // Get all campaigns for campaign filter dropdown
        $campaigns = \App\Models\Campaign::orderBy('name')->get(['id', 'name']);

        // Build a base query with shared filters (date, employee, site, campaign, team lead)
        // Used for status summary counts so they reflect the same filter context
        $baseFilteredQuery = Attendance::query();

        // Apply Team Lead auto-filter to summary counts
        if ($user->role === 'Team Lead' && $teamLeadCampaignId) {
            $campaignFilter = $request->input('campaign_id');
            if (! $campaignFilter || $campaignFilter === 'all') {
                $baseFilteredQuery->whereHas('employeeSchedule', function ($q) use ($teamLeadCampaignId) {
                    $q->where('campaign_id', $teamLeadCampaignId);
                });
            }
        }

        if ($request->filled('user_id')) {
            $userIds = is_array($request->user_id)
                ? $request->user_id
                : array_filter(explode(',', $request->user_id));
            if (count($userIds) > 0) {
                $baseFilteredQuery->whereIn('user_id', $userIds);
            }
        }

        if ($request->filled('date_from')) {
            $baseFilteredQuery->where('shift_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $baseFilteredQuery->where('shift_date', '<=', $request->date_to);
        }

        if ($request->filled('site_id')) {
            $baseFilteredQuery->whereHas('employeeSchedule', function ($q) use ($request) {
                $q->where('site_id', $request->site_id);
            });
        }

        if ($request->filled('campaign_id')) {
            $campaignIds = is_array($request->campaign_id)
                ? $request->campaign_id
                : array_filter(explode(',', $request->campaign_id));
            if (count($campaignIds) > 0) {
                $baseFilteredQuery->whereHas('employeeSchedule', function ($q) use ($campaignIds) {
                    $q->whereIn('campaign_id', $campaignIds);
                });
            }
        }

        if ($request->filled('leave_conflict') && $request->leave_conflict === 'yes') {
            $baseFilteredQuery->whereNotNull('leave_request_id')
                ->where('status', '!=', 'on_leave')
                ->where(function ($q) {
                    $q->whereNotNull('actual_time_in')
                        ->orWhereNotNull('actual_time_out');
                });
        }

        // Count leave conflicts (records where employee has biometric activity during approved leave)
        $leaveConflictCount = (clone $baseFilteredQuery)
            ->whereNotNull('leave_request_id')
            ->where('status', '!=', 'on_leave')
            ->where('admin_verified', false)
            ->where(function ($q) {
                $q->whereNotNull('actual_time_in')
                    ->orWhereNotNull('actual_time_out');
            })
            ->count();

        // Count partially verified records (approved but time out pending)
        $partiallyVerifiedCount = (clone $baseFilteredQuery)
            ->where('admin_verified', true)
            ->where('is_partially_verified', true)
            ->count();

        // Status summary counts for the review page cards (respects active filters)
        $statusCounts = (clone $baseFilteredQuery)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        // Verification-based counts (respects active filters)
        $verificationCounts = [
            'pending' => (clone $baseFilteredQuery)->where('admin_verified', false)->count(),
            'verified' => (clone $baseFilteredQuery)->where('admin_verified', true)->where('is_partially_verified', false)->count(),
            'partially_verified' => $partiallyVerifiedCount,
        ];

        return Inertia::render('Attendance/Main/Review', [
            'attendances' => $attendances,
            'employees' => $employees,
            'sites' => $sites,
            'campaigns' => $campaigns,
            'teamLeadCampaignId' => $teamLeadCampaignId,
            'verifyAttendanceId' => $verifyAttendanceId,
            'leaveConflictCount' => $leaveConflictCount,
            'partiallyVerifiedCount' => $partiallyVerifiedCount,
            'statusCounts' => $statusCounts,
            'verificationCounts' => $verificationCounts,
            'filters' => [
                'user_id' => $request->user_id,
                'status' => $request->status,
                'verified' => $request->verified,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'site_id' => $request->site_id,
                'campaign_id' => $request->campaign_id,
                'leave_conflict' => $request->leave_conflict,
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
            'status' => 'required|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,undertime_more_than_hour,failed_bio_in,failed_bio_out,present_no_bio,non_work_day,on_leave',
            'secondary_status' => 'nullable|in:undertime,undertime_more_than_hour,failed_bio_out',
            'actual_time_in' => 'nullable|date',
            'actual_time_out' => 'nullable|date',
            'verification_notes' => 'nullable|string|max:1000',
            'overtime_approved' => 'nullable|boolean',
            'is_set_home' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
            'adjust_leave' => 'nullable|boolean', // Flag to confirm leave adjustment
        ]);

        // Note: Allow re-verification of already verified records
        // This is intentional - admins can update verified records through this interface

        // Capture old values for smart point regeneration comparison
        $oldStatus = $attendance->status;
        $oldSecondaryStatus = $attendance->secondary_status;
        $oldIsSetHome = $attendance->is_set_home ?? false;
        $oldIsAdvised = $attendance->is_advised ?? false;

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
            'secondary_status' => $request->secondary_status,
            'actual_time_in' => $request->actual_time_in,
            'actual_time_out' => $request->actual_time_out,
            'admin_verified' => true,
            'verification_notes' => $request->verification_notes,
            'notes' => $request->notes,
            'is_set_home' => $request->boolean('is_set_home'),
        ];

        // If this was a partially verified record and now has time out, mark as fully verified
        if ($attendance->is_partially_verified) {
            $updates['is_partially_verified'] = $request->actual_time_out ? false : true;
        }

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

        // Skip all time calculations for non-work days
        if ($request->status === 'non_work_day') {
            $attendance->update([
                'tardy_minutes' => null,
                'undertime_minutes' => null,
                'overtime_minutes' => null,
            ]);
        } else {
            // Recalculate tardy if time in is provided (not applicable for failed_bio_in)
            if ($request->actual_time_in && $attendance->scheduled_time_in) {
                $shiftDate = Carbon::parse($attendance->shift_date);

                // Build scheduled time in datetime
                $scheduledIn = Carbon::parse($attendance->scheduled_time_in);
                $schedInHour = $scheduledIn->hour;

                // Check for graveyard shift pattern (00:00-04:59 start time)
                // For graveyard shifts, scheduled time is on the NEXT calendar day from shift_date
                $isGraveyardShift = $schedInHour >= 0 && $schedInHour < 5;

                $scheduled = Carbon::parse($shiftDate->format('Y-m-d').' '.$attendance->scheduled_time_in);
                if ($isGraveyardShift) {
                    $scheduled->addDay();
                }

                $actual = Carbon::parse($request->actual_time_in);
                $tardyMinutes = $scheduled->diffInMinutes($actual, false);

                if ($tardyMinutes > 0) {
                    $attendance->update(['tardy_minutes' => $tardyMinutes]);
                } else {
                    $attendance->update(['tardy_minutes' => null]);
                }
            } else {
                // No time in - clear tardy (can't calculate without time in)
                $attendance->update(['tardy_minutes' => null]);
            }

            // Recalculate undertime and overtime if time out is provided
            // Undertime/overtime are based on actual_time_out vs scheduled_time_out
            // This is valid even for failed_bio_in (no time_in) since it's about when they left
            if ($request->actual_time_out && $attendance->scheduled_time_out) {
                $actualTimeOut = Carbon::parse($request->actual_time_out);

                // Build scheduled time out based on shift date and scheduled time
                $shiftDate = Carbon::parse($attendance->shift_date);
                $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$attendance->scheduled_time_out);

                // Handle shift crossing midnight or next-day scenarios
                if ($attendance->scheduled_time_in && $attendance->scheduled_time_out) {
                    $scheduledIn = Carbon::parse($attendance->scheduled_time_in);
                    $scheduledOut = Carbon::parse($attendance->scheduled_time_out);
                    $schedInHour = $scheduledIn->hour;

                    // Check for graveyard shift pattern (00:00-04:59 start time)
                    // For graveyard shifts, scheduled time out is on the NEXT calendar day from shift_date
                    $isGraveyardShift = $schedInHour >= 0 && $schedInHour < 5;

                    if ($isGraveyardShift) {
                        // Graveyard shift: both time in and time out are on next calendar day
                        $scheduledTimeOut = Carbon::parse($shiftDate->copy()->addDay()->format('Y-m-d').' '.$attendance->scheduled_time_out);
                    } elseif ($scheduledOut->format('H:i:s') < $scheduledIn->format('H:i:s')) {
                        // Night shift: time out is before time in (e.g., 22:00-07:00), shift crosses midnight
                        $scheduledTimeOut->addDay();
                    }
                }

                // Calculate difference: positive means overtime (left late), negative means undertime (left early)
                // Using scheduledTimeOut->diffInMinutes($actualTimeOut):
                // - Positive if actualTimeOut > scheduledTimeOut (overtime)
                // - Negative if actualTimeOut < scheduledTimeOut (undertime)
                $timeDiff = $scheduledTimeOut->diffInMinutes($actualTimeOut, false);

                // If negative (left early), it's undertime
                if ($timeDiff < 0) {
                    $undertimeMinutes = (int) abs($timeDiff);
                    $undertimeStatus = $undertimeMinutes > 60 ? 'undertime_more_than_hour' : 'undertime';

                    $updateData = [
                        'undertime_minutes' => $undertimeMinutes,
                        'overtime_minutes' => null,
                    ];

                    // For statuses like failed_bio_in that aren't pointable,
                    // set undertime as secondary_status so points can be generated
                    $nonPointableStatuses = ['failed_bio_in', 'failed_bio_out', 'on_time', 'present_no_bio'];
                    if (in_array($attendance->status, $nonPointableStatuses)) {
                        $updateData['secondary_status'] = $undertimeStatus;
                    }

                    $attendance->update($updateData);
                }
                // If positive and more than 30 minutes (left late), it's overtime
                elseif ($timeDiff > 30) {
                    $attendance->update([
                        'undertime_minutes' => null,
                        'overtime_minutes' => $timeDiff,
                    ]);
                }
                // If within threshold (0 to 30), clear both
                else {
                    $attendance->update([
                        'undertime_minutes' => null,
                        'overtime_minutes' => null,
                    ]);
                }
            } else {
                // No time out - clear undertime/overtime
                $attendance->update([
                    'undertime_minutes' => null,
                    'overtime_minutes' => null,
                ]);
            }
        } // End of non_work_day check

        // Recalculate total minutes worked based on overtime approval status
        // If overtime exists but is not approved, work hours are capped at scheduled time out
        $attendance->refresh();
        $this->processor->recalculateTotalMinutesWorked($attendance);

        // Smart point regeneration - only regenerate if changes affect points
        $attendance->refresh();
        $this->regeneratePointsIfNeeded(
            $attendance,
            $oldStatus,
            $oldSecondaryStatus,
            $oldIsSetHome,
            $oldIsAdvised
        );

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
            $successMessage .= ' '.$leaveAdjustmentMessage;
        }

        return redirect()->back()
            ->with('message', $successMessage)
            ->with('type', 'success');
    }

    /**
     * Batch verify multiple attendance records.
     */
    public function batchVerify(Request $request)
    {
        $validated = $request->validate([
            'record_ids' => 'required|array|min:1',
            'record_ids.*' => 'required|exists:attendances,id',
            'status' => 'required|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,undertime_more_than_hour,failed_bio_in,failed_bio_out,present_no_bio,non_work_day,on_leave',
            'secondary_status' => 'nullable|in:undertime,undertime_more_than_hour,failed_bio_out',
            'verification_notes' => 'nullable|string|max:1000',
            'overtime_approved' => 'nullable|boolean',
            'is_set_home' => 'nullable|boolean',
        ]);

        $recordIds = $validated['record_ids'];
        $verifiedCount = 0;

        foreach ($recordIds as $id) {
            $attendance = Attendance::with('employeeSchedule')->find($id);
            if (! $attendance) {
                continue;
            }

            // Capture old values for smart point regeneration comparison
            $oldStatus = $attendance->status;
            $oldSecondaryStatus = $attendance->secondary_status;
            $oldIsSetHome = $attendance->is_set_home ?? false;
            $oldIsAdvised = $attendance->is_advised ?? false;

            $updates = [
                'status' => $validated['status'],
                'secondary_status' => $validated['secondary_status'] ?? null,
                'admin_verified' => true,
                'verification_notes' => $validated['verification_notes'],
                'is_set_home' => $validated['is_set_home'] ?? false,
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

            // Recalculate total minutes worked based on overtime approval status
            // If overtime exists but is not approved, work hours are capped at scheduled time out
            $attendance->refresh();
            $this->processor->recalculateTotalMinutesWorked($attendance);

            // Smart point regeneration - only regenerate if changes affect points
            $attendance->refresh();
            $this->regeneratePointsIfNeeded(
                $attendance,
                $oldStatus,
                $oldSecondaryStatus,
                $oldIsSetHome,
                $oldIsAdvised
            );

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
            ->with('message', "Successfully verified {$verifiedCount} attendance record".($verifiedCount === 1 ? '' : 's').'.')
            ->with('type', 'success');
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
            ->with('message', 'Attendance marked as advised absence.')
            ->with('type', 'success');
    }

    /**
     * Quick approve an on-time attendance record without overtime issues.
     */
    public function quickApprove(Request $request, Attendance $attendance)
    {
        // Validate that the record is eligible for quick approval
        if ($attendance->status !== 'on_time') {
            return redirect()->back()
                ->with('message', 'Only on-time records can be quick approved.')
                ->with('type', 'error');
        }

        if ($attendance->admin_verified) {
            return redirect()->back()
                ->with('message', 'This record has already been verified.')
                ->with('type', 'error');
        }

        // Check for unapproved overtime
        if ($attendance->overtime_minutes && $attendance->overtime_minutes > 0 && ! $attendance->overtime_approved) {
            return redirect()->back()
                ->with('message', 'Records with unapproved overtime need manual review.')
                ->with('type', 'error');
        }

        $attendance->update([
            'admin_verified' => true,
            'verification_notes' => 'Quick approved by admin',
        ]);

        return redirect()->back()
            ->with('message', 'Attendance record approved successfully.')
            ->with('type', 'success');
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

            if ($attendance->overtime_minutes && $attendance->overtime_minutes > 0 && ! $attendance->overtime_approved) {
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
            if (! empty($skippedReasons)) {
                $message .= ' Reasons: '.implode('; ', $skippedReasons);
            }
        }

        return redirect()->back()
            ->with('message', $message)
            ->with('type', 'success');
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
            ->with('message', "Successfully deleted {$count} attendance record".($count === 1 ? '' : 's').'.')
            ->with('type', 'success');
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
     * @param  Attendance  $attendance  The attendance record being verified
     * @param  Request  $request  The verification request
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
        // Credits can only be restored if current year matches the credits_year (the year when leave was approved)
        //
        // HOW IT WORKS:
        // - credits_year is set to the leave's start_date year when credits are deducted
        // - For a leave in Jan 2026 using 2025 rollover credits:
        //   → credits_year = 2026 (the leave year)
        //   → Rollover credits are stored in leave_credits table with year=2026, month=0
        //   → Can restore because current year (2026) matches credits_year (2026)
        //
        // EDGE CASE - Year mismatch prevents restoration:
        // - Leave approved in Dec 2025, employee works during leave in Jan 2026
        //   → credits_year = 2025 (when leave was approved)
        //   → Can't restore because current year (2026) != credits_year (2025)
        //   → This prevents cross-year accounting issues
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
                        'notes' => ($relatedAtt->notes ? $relatedAtt->notes."\n" : '').'Leave was cancelled - requires review.',
                    ]);
                }

                // Build cancellation reason message
                $creditMessage = $canRestoreCredits
                    ? 'Original leave credit restored.'
                    : 'Credits not restored (year mismatch: credits from '.$creditsYear.', current year '.$currentYear.').';

                // Update leave request status to cancelled
                $leaveRequest->update([
                    'status' => 'cancelled',
                    'auto_cancelled' => true,
                    'auto_cancelled_at' => now(),
                    'auto_cancelled_reason' => 'Employee reported to work on '.$workDate->format('M d, Y').'. '.$creditMessage,
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
                    : 'Leave request cancelled. Credits not restored (year mismatch).';

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
                        'notes' => ($relatedAtt->notes ? $relatedAtt->notes."\n" : '').'Leave was cancelled - requires review.',
                    ]);
                }

                $creditMessage = $canRestoreCredits
                    ? 'Credits restored.'
                    : 'Credits not restored (year mismatch).';

                $leaveRequest->update([
                    'status' => 'cancelled',
                    'auto_cancelled' => true,
                    'auto_cancelled_at' => now(),
                    'auto_cancelled_reason' => 'Employee worked on leave dates. '.$creditMessage,
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
                    : 'Leave request cancelled. Credits not restored (year mismatch).';

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
                'date_modification_reason' => 'Auto-adjusted: Employee reported to work on '.$workDate->format('M d, Y').
                    '. Leave dates changed from '.$leaveStart->format('M d').'-'.$leaveEnd->format('M d, Y').
                    ' to '.$newStart->format('M d').'-'.$newEnd->format('M d, Y').'.',
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
                    'notes' => ($affectedAtt->notes ? $affectedAtt->notes."\n" : '').
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
                        'notes' => ($affectedAtt->notes ? $affectedAtt->notes."\n" : '').
                            'Leave was adjusted - this date is no longer covered by leave.',
                    ]);
                }
            }

            // Restore partial credit using the service method (only if year matches)
            if ($creditsToRestore > 0 && $canRestoreCredits) {
                $this->leaveCreditService->restorePartialCredits(
                    $leaveRequest,
                    $creditsToRestore,
                    'Partial leave credit restored - Employee worked on '.$workDate->format('M d, Y')
                );
            }

            // Build notification message based on whether credits were restored
            $creditNotificationMsg = $canRestoreCredits
                ? '. '.$creditsToRestore.' day(s) of leave credit has been restored.'
                : '. Credits not restored (year mismatch - credits from '.$creditsYear.', current year '.$currentYear.').';

            // Notify the employee about leave adjustment
            $this->notificationService->notifySystemMessage(
                $leaveRequest->user_id,
                'Leave Dates Adjusted',
                'Your '.$leaveRequest->leave_type.' leave has been adjusted from '.
                    $leaveStart->format('M d').'-'.$leaveEnd->format('M d, Y').' to '.
                    $newStart->format('M d').'-'.$newEnd->format('M d, Y').
                    ' because you reported to work on '.$workDate->format('M d, Y').$creditNotificationMsg,
                ['leave_request_id' => $leaveRequest->id]
            );

            DB::commit();

            Log::info('Leave adjusted due to work day', [
                'leave_request_id' => $leaveRequest->id,
                'user_id' => $leaveRequest->user_id,
                'work_date' => $workDate->format('Y-m-d'),
                'adjustment_type' => $adjustmentType,
                'original_dates' => $leaveStart->format('Y-m-d').' to '.$leaveEnd->format('Y-m-d'),
                'new_dates' => $newStart->format('Y-m-d').' to '.$newEnd->format('Y-m-d'),
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
            Log::error('Failed to adjust leave for work day: '.$e->getMessage(), [
                'leave_request_id' => $leaveRequest->id,
                'attendance_id' => $attendance->id,
                'work_date' => $workDate->format('Y-m-d'),
            ]);

            return [
                'adjusted' => false,
                'message' => 'Failed to adjust leave: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Display daily roster - employees expected to work on a given date based on their schedules.
     */
    public function dailyRoster(Request $request)
    {
        $this->authorize('viewAny', Attendance::class);

        $user = auth()->user();

        // Determine Team Lead's campaign (if applicable)
        $teamLeadCampaignId = null;
        if ($user->role === 'Team Lead') {
            $activeSchedule = $user->activeSchedule;
            if ($activeSchedule && $activeSchedule->campaign_id) {
                $teamLeadCampaignId = $activeSchedule->campaign_id;
            }
        }

        // Use provided date or default to today
        $selectedDate = $request->filled('date')
            ? Carbon::parse($request->date)
            : Carbon::today();
        $dayName = strtolower($selectedDate->format('l')); // monday, tuesday, etc.

        // Get all active employees with schedules that include the selected date
        $employees = User::with(['employeeSchedules' => function ($query) use ($selectedDate) {
            $query->where('is_active', true)
                ->where('effective_date', '<=', $selectedDate)
                ->where(function ($q) use ($selectedDate) {
                    $q->whereNull('end_date')
                        ->orWhere('end_date', '>=', $selectedDate);
                });
        }, 'employeeSchedules.site', 'employeeSchedules.campaign'])
            ->whereHas('employeeSchedules', function ($query) use ($selectedDate) {
                $query->where('is_active', true)
                    ->where('effective_date', '<=', $selectedDate)
                    ->where(function ($q) use ($selectedDate) {
                        $q->whereNull('end_date')
                            ->orWhere('end_date', '>=', $selectedDate);
                    });
            })
            // Search filter
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                });
            })
            ->get()
            ->filter(function ($user) use ($dayName) {
                // Filter to only employees who work on the selected day
                $schedule = $user->employeeSchedules->first();

                return $schedule && $schedule->worksOnDay($dayName);
            })
            ->map(function ($user) use ($selectedDate) {
                $schedule = $user->employeeSchedules->first();

                // Check if attendance already exists for selected date
                $existingAttendance = Attendance::where('user_id', $user->id)
                    ->where('shift_date', $selectedDate->format('Y-m-d'))
                    ->first();

                // Check for approved leave
                $approvedLeave = LeaveRequest::where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->where('start_date', '<=', $selectedDate)
                    ->where('end_date', '>=', $selectedDate)
                    ->first();

                return [
                    'id' => $user->id,
                    'name' => $user->first_name.' '.$user->last_name,
                    'email' => $user->email,
                    'schedule' => $schedule ? [
                        'id' => $schedule->id,
                        'shift_type' => $schedule->shift_type,
                        'scheduled_time_in' => $schedule->scheduled_time_in,
                        'scheduled_time_out' => $schedule->scheduled_time_out,
                        'site_id' => $schedule->site_id,
                        'site_name' => $schedule->site?->name,
                        'campaign_id' => $schedule->campaign_id,
                        'campaign_name' => $schedule->campaign?->name,
                        'grace_period_minutes' => $schedule->grace_period_minutes ?? 15,
                        'work_days' => $schedule->work_days,
                    ] : null,
                    'existing_attendance' => $existingAttendance ? [
                        'id' => $existingAttendance->id,
                        'status' => $existingAttendance->status,
                        'secondary_status' => $existingAttendance->secondary_status,
                        'actual_time_in' => $existingAttendance->actual_time_in?->format('Y-m-d\TH:i'),
                        'actual_time_out' => $existingAttendance->actual_time_out?->format('Y-m-d\TH:i'),
                        'admin_verified' => $existingAttendance->admin_verified,
                        'notes' => $existingAttendance->notes,
                        'verification_notes' => $existingAttendance->verification_notes,
                        'overtime_approved' => $existingAttendance->overtime_approved,
                    ] : null,
                    'on_leave' => $approvedLeave ? [
                        'id' => $approvedLeave->id,
                        'leave_type' => $approvedLeave->leave_type,
                    ] : null,
                ];
            })
            ->values();

        // Get sites and campaigns for filter
        $sites = Site::orderBy('name')->get(['id', 'name']);
        $campaigns = \App\Models\Campaign::orderBy('name')->get(['id', 'name']);

        // Filter by site if provided
        if ($request->filled('site_id')) {
            $employees = $employees->filter(function ($emp) use ($request) {
                return $emp['schedule'] && $emp['schedule']['site_id'] == $request->site_id;
            })->values();
        }

        // Filter by campaign if provided OR auto-filter for Team Leads
        $campaignIdToFilter = $request->filled('campaign_id') ? $request->campaign_id : null;
        if (! $campaignIdToFilter && $user->role === 'Team Lead' && $teamLeadCampaignId) {
            // Default to Team Lead's campaign if no filter specified
            $campaignIdToFilter = $teamLeadCampaignId;
        }
        if ($campaignIdToFilter) {
            $employees = $employees->filter(function ($emp) use ($campaignIdToFilter) {
                return $emp['schedule'] && $emp['schedule']['campaign_id'] == $campaignIdToFilter;
            })->values();
        }

        // Filter by status if provided
        if ($request->filled('status')) {
            $employees = $employees->filter(function ($emp) use ($request) {
                if ($request->status === 'pending') {
                    return ! $emp['existing_attendance'] && ! $emp['on_leave'];
                } elseif ($request->status === 'recorded') {
                    return $emp['existing_attendance'] !== null;
                } elseif ($request->status === 'on_leave') {
                    return $emp['on_leave'] !== null;
                }

                return true;
            })->values();
        }

        return Inertia::render('Attendance/Main/DailyRoster', [
            'employees' => $employees,
            'sites' => $sites,
            'campaigns' => $campaigns,
            'teamLeadCampaignId' => $teamLeadCampaignId,
            'selectedDate' => $selectedDate->format('Y-m-d'),
            'dayName' => ucfirst($dayName),
            'filters' => [
                'site_id' => $request->site_id,
                'campaign_id' => $request->campaign_id,
                'status' => $request->status,
                'search' => $request->search,
                'date' => $request->date,
            ],
        ]);
    }

    /**
     * Generate attendance record for an employee from expected today list.
     * Creates unverified record that goes to Review page.
     */
    public function generateAttendance(Request $request)
    {
        $this->authorize('create', Attendance::class);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'shift_date' => 'required|date',
            'actual_time_in' => 'nullable|date_format:Y-m-d\\TH:i',
            'actual_time_out' => 'nullable|date_format:Y-m-d\\TH:i',
            'notes' => 'nullable|string|max:500',
            'status' => 'nullable|string|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,undertime_more_than_hour,failed_bio_in,failed_bio_out,present_no_bio,non_work_day',
            'secondary_status' => 'nullable|string|in:undertime,undertime_more_than_hour,failed_bio_out',
            'verification_notes' => 'nullable|string|max:1000',
            'overtime_approved' => 'nullable|boolean',
            'is_set_home' => 'nullable|boolean',
        ]);

        // Check if attendance already exists for this date
        $existingAttendance = Attendance::where('user_id', $validated['user_id'])
            ->where('shift_date', $validated['shift_date'])
            ->first();

        if ($existingAttendance) {
            return redirect()->back()
                ->with('message', 'Attendance record already exists for this employee on this date.')
                ->with('type', 'error');
        }

        // Get employee schedule
        $schedule = \App\Models\EmployeeSchedule::where('user_id', $validated['user_id'])
            ->where('is_active', true)
            ->where('effective_date', '<=', $validated['shift_date'])
            ->where(function ($query) use ($validated) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $validated['shift_date']);
            })
            ->first();

        if (! $schedule) {
            return redirect()->back()
                ->with('message', 'No active schedule found for this employee.')
                ->with('type', 'error');
        }

        // Parse times
        $actualTimeIn = Carbon::parse($validated['actual_time_in']);
        $actualTimeOut = Carbon::parse($validated['actual_time_out']);
        $shiftDate = Carbon::parse($validated['shift_date']);

        // Build scheduled times
        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);

        // Handle night shift (time out is next day)
        if ($schedule->shift_type === 'night_shift' && $scheduledTimeOut->lt($scheduledTimeIn)) {
            $scheduledTimeOut->addDay();
        }

        // Calculate violations
        $tardyMinutes = null;
        $undertimeMinutes = null;
        $overtimeMinutes = null;
        $calculatedStatus = 'on_time';
        $calculatedSecondaryStatus = null;
        $gracePeriod = $schedule->grace_period_minutes ?? 15;

        // Calculate tardy (late arrival)
        if ($actualTimeIn->gt($scheduledTimeIn)) {
            $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn);
            // More than grace period = half day absence
            if ($tardyMinutes > $gracePeriod) {
                $calculatedStatus = 'half_day_absence';
            } elseif ($tardyMinutes >= 1) {
                $calculatedStatus = 'tardy';
            }
        }

        // Calculate undertime (early leave) or overtime (late leave)
        if ($actualTimeOut->lt($scheduledTimeOut)) {
            $undertimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
            if ($undertimeMinutes > 60) {
                if ($calculatedStatus === 'on_time') {
                    $calculatedStatus = 'undertime_more_than_hour';
                } else {
                    $calculatedSecondaryStatus = 'undertime_more_than_hour';
                }
            } elseif ($undertimeMinutes > 0) {
                if ($calculatedStatus === 'on_time') {
                    $calculatedStatus = 'undertime';
                } else {
                    $calculatedSecondaryStatus = 'undertime';
                }
            }
        } elseif ($actualTimeOut->gt($scheduledTimeOut)) {
            $overtimeMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut);
        }

        // Use provided status or calculated status
        $status = $validated['status'] ?? $calculatedStatus;
        $secondaryStatus = $validated['secondary_status'] ?? $calculatedSecondaryStatus;

        // Create attendance record - VERIFIED immediately
        $attendance = Attendance::create([
            'user_id' => $validated['user_id'],
            'employee_schedule_id' => $schedule->id,
            'shift_date' => $validated['shift_date'],
            'scheduled_time_in' => $schedule->scheduled_time_in,
            'scheduled_time_out' => $schedule->scheduled_time_out,
            'actual_time_in' => $actualTimeIn,
            'actual_time_out' => $actualTimeOut,
            'status' => $status,
            'secondary_status' => $secondaryStatus,
            'tardy_minutes' => $tardyMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_approved' => $validated['overtime_approved'] ?? false,
            'overtime_approved_at' => ($validated['overtime_approved'] ?? false) ? now() : null,
            'overtime_approved_by' => ($validated['overtime_approved'] ?? false) ? auth()->id() : null,
            'is_set_home' => $request->boolean('is_set_home'),
            'admin_verified' => true, // Auto-verified
            'verification_notes' => $validated['verification_notes'],
            'notes' => $validated['notes'],
        ]);

        // Generate attendance points for new record - force regeneration
        $this->regeneratePointsIfNeeded($attendance->fresh(), null, null, false, false, true);

        // Notify user of their attendance status
        $user = User::find($validated['user_id']);
        if ($status !== 'on_time') {
            $pointRecord = AttendancePoint::where('attendance_id', $attendance->id)->first();
            $points = $pointRecord ? $pointRecord->points : null;
            $this->notificationService->notifyAttendanceStatus(
                $attendance->user_id,
                $status,
                $shiftDate->format('M d, Y'),
                $points
            );
        }

        return redirect()->back()
            ->with('message', "Attendance record created and verified for {$user->first_name} {$user->last_name}.")
            ->with('type', 'success');
    }

    /**
     * Partially approve a night shift attendance when time out is not yet available.
     * Sets admin_verified=true and is_partially_verified=true, generates points based on time-in status.
     * When time out becomes available later, the verify() method will complete the record.
     */
    public function partialApprove(Request $request, Attendance $attendance)
    {
        $this->authorize('update', $attendance);

        // Load relationships
        $attendance->load('employeeSchedule');

        if (! $attendance->actual_time_in) {
            return redirect()->back()
                ->with('message', 'Cannot partially approve - no time in recorded.')
                ->with('type', 'error');
        }

        if ($attendance->actual_time_out) {
            return redirect()->back()
                ->with('message', 'This attendance record already has a time out. Use full verification instead.')
                ->with('type', 'error');
        }

        // Capture old values for smart point regeneration comparison
        $oldStatus = $attendance->status;
        $oldSecondaryStatus = $attendance->secondary_status;
        $oldIsSetHome = $attendance->is_set_home ?? false;
        $oldIsAdvised = $attendance->is_advised ?? false;

        $validated = $request->validate([
            'status' => 'nullable|in:on_time,tardy,half_day_absence,undertime,undertime_more_than_hour,failed_bio_out',
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        // Determine status - keep current status if not overridden
        $explicitStatus = $validated['status'] ?? null;
        $status = $explicitStatus ?? $attendance->status;

        // If no explicit status and current status needs recalculation, determine from tardy info
        $recalculableStatuses = ['failed_bio_out', 'needs_manual_review'];
        if (! $explicitStatus && ! in_array($attendance->status, $recalculableStatuses)) {
            // If record has tardy info from time-in, preserve that status
            if ($attendance->tardy_minutes && $attendance->tardy_minutes > 0) {
                $schedule = $attendance->employeeSchedule;
                $gracePeriod = $schedule->grace_period_minutes ?? 15;
                $status = $attendance->tardy_minutes > $gracePeriod ? 'half_day_absence' : 'tardy';
            }
        } elseif (! $explicitStatus && $attendance->status === 'needs_manual_review') {
            // For needs_manual_review, always recalculate from tardy info if available
            if ($attendance->tardy_minutes && $attendance->tardy_minutes > 0) {
                $schedule = $attendance->employeeSchedule;
                $gracePeriod = $schedule->grace_period_minutes ?? 15;
                $status = $attendance->tardy_minutes > $gracePeriod ? 'half_day_absence' : 'tardy';
            } else {
                // Default to failed_bio_out since time-out is missing
                $status = 'failed_bio_out';
            }
        }

        // Build verification note with partial approval context
        $notes = $validated['verification_notes'] ?? 'Partially approved - time out pending.';

        // Clear secondary_status if it duplicates the new primary status,
        // or if it's no longer relevant (time-out hasn't arrived yet)
        $secondaryStatus = $attendance->secondary_status;
        if ($secondaryStatus === $status || in_array($secondaryStatus, ['failed_bio_out'])) {
            $secondaryStatus = null;
        }

        // Update the attendance record
        $attendance->update([
            'status' => $status,
            'secondary_status' => $secondaryStatus,
            'admin_verified' => true,
            'is_partially_verified' => true,
            'verification_notes' => $notes,
        ]);

        // Smart point regeneration - generate points based on current status
        $attendance->refresh();
        $this->regeneratePointsIfNeeded(
            $attendance,
            $oldStatus,
            $oldSecondaryStatus,
            $oldIsSetHome,
            $oldIsAdvised
        );

        // Fetch points if any
        $pointRecord = AttendancePoint::where('attendance_id', $attendance->id)->first();
        $points = $pointRecord ? $pointRecord->points : null;

        // Notify user if status is not on_time or failed_bio_out
        if (! in_array($status, ['on_time', 'failed_bio_out'])) {
            $this->notificationService->notifyAttendanceStatus(
                $attendance->user_id,
                $status,
                Carbon::parse($attendance->shift_date)->format('M d, Y'),
                $points
            );
        }

        return redirect()->back()
            ->with('message', 'Attendance partially approved. Time out will be verified when available.')
            ->with('type', 'success');
    }

    /**
     * Batch partially approve multiple attendance records that are missing time out.
     */
    public function batchPartialApprove(Request $request)
    {
        $validated = $request->validate([
            'record_ids' => 'required|array|min:1',
            'record_ids.*' => 'required|exists:attendances,id',
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        $recordIds = $validated['record_ids'];
        $approvedCount = 0;
        $skippedCount = 0;

        foreach ($recordIds as $id) {
            $attendance = Attendance::with('employeeSchedule')->find($id);
            if (! $attendance) {
                $skippedCount++;

                continue;
            }

            // Skip records that don't qualify (no time-in, already has time-out, already verified)
            if (! $attendance->actual_time_in || $attendance->actual_time_out || $attendance->admin_verified) {
                $skippedCount++;

                continue;
            }

            // Capture old values for smart point regeneration comparison
            $oldStatus = $attendance->status;
            $oldSecondaryStatus = $attendance->secondary_status;
            $oldIsSetHome = $attendance->is_set_home ?? false;
            $oldIsAdvised = $attendance->is_advised ?? false;

            // Determine status - preserve tardy if applicable
            $status = $attendance->status;
            if (! in_array($status, ['failed_bio_out'])) {
                if ($attendance->tardy_minutes && $attendance->tardy_minutes > 0) {
                    $schedule = $attendance->employeeSchedule;
                    $gracePeriod = $schedule->grace_period_minutes ?? 15;
                    $status = $attendance->tardy_minutes > $gracePeriod ? 'half_day_absence' : 'tardy';
                }
            }

            $notes = $validated['verification_notes'] ?? 'Batch partially approved - time out pending.';

            $attendance->update([
                'status' => $status,
                'admin_verified' => true,
                'is_partially_verified' => true,
                'verification_notes' => $notes,
            ]);

            // Smart point regeneration
            $attendance->refresh();
            $this->regeneratePointsIfNeeded(
                $attendance,
                $oldStatus,
                $oldSecondaryStatus,
                $oldIsSetHome,
                $oldIsAdvised
            );

            // Notify user if status generates points
            if (! in_array($status, ['on_time', 'failed_bio_out'])) {
                $pointRecord = AttendancePoint::where('attendance_id', $attendance->id)->first();
                $points = $pointRecord ? $pointRecord->points : null;

                $this->notificationService->notifyAttendanceStatus(
                    $attendance->user_id,
                    $status,
                    Carbon::parse($attendance->shift_date)->format('M d, Y'),
                    $points
                );
            }

            $approvedCount++;
        }

        $message = "Successfully partially approved {$approvedCount} attendance record".($approvedCount === 1 ? '' : 's').'.';
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} record".($skippedCount === 1 ? ' was' : 's were').' skipped (not eligible).';
        }

        return redirect()->back()
            ->with('message', $message)
            ->with('type', 'success');
    }

    /**
     * Request undertime approval (called by Team Lead when verifying).
     */
    public function requestUndertimeApproval(Request $request, Attendance $attendance)
    {
        $this->authorize('update', $attendance);

        $validated = $request->validate([
            'suggested_reason' => 'nullable|in:generate_points,skip_points,lunch_used',
        ]);

        // Validate that attendance has undertime
        if (! $attendance->undertime_minutes || $attendance->undertime_minutes <= 0) {
            return redirect()->back()
                ->with('message', 'This attendance record does not have undertime.')
                ->with('type', 'error');
        }

        // Check if already pending or approved
        if ($attendance->undertime_approval_status === 'pending') {
            return redirect()->back()
                ->with('message', 'Undertime approval is already pending.')
                ->with('type', 'warning');
        }

        if ($attendance->undertime_approval_status === 'approved') {
            return redirect()->back()
                ->with('message', 'Undertime has already been approved.')
                ->with('type', 'warning');
        }

        try {
            DB::transaction(function () use ($attendance, $validated) {
                $attendance->update([
                    'undertime_approval_status' => 'pending',
                    'undertime_approval_reason' => $validated['suggested_reason'] ?? null,
                    'undertime_approval_requested_by' => auth()->id(),
                    'undertime_approval_requested_at' => now(),
                ]);

                // Send notification to Super Admin, Admin, HR
                $attendance->load('user');
                $this->notificationService->notifyUndertimeApprovalRequest(
                    $attendance,
                    auth()->user(),
                    $attendance->user
                );
            });

            return redirect()->back()
                ->with('message', 'Undertime approval request sent to administrators.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('Undertime approval request error: '.$e->getMessage());

            return redirect()->back()
                ->with('message', 'Failed to send undertime approval request.')
                ->with('type', 'error');
        }
    }

    /**
     * Approve or reject undertime (called by Super Admin/Admin/HR).
     */
    public function approveUndertime(Request $request, Attendance $attendance)
    {
        $this->authorize('approveUndertime', Attendance::class);

        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
            'reason' => 'required_if:status,approved|in:generate_points,skip_points,lunch_used',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Validate that attendance is eligible for approval (pending or null - direct approval by Admin/HR)
        if ($attendance->undertime_approval_status !== null && $attendance->undertime_approval_status !== 'pending') {
            return redirect()->back()
                ->with('message', 'This undertime has already been processed.')
                ->with('type', 'warning');
        }

        try {
            DB::transaction(function () use ($attendance, $validated) {
                $updates = [
                    'undertime_approval_status' => $validated['status'],
                    'undertime_approved_by' => auth()->id(),
                    'undertime_approved_at' => now(),
                    'undertime_approval_notes' => $validated['notes'] ?? null,
                ];

                if ($validated['status'] === 'approved') {
                    $updates['undertime_approval_reason'] = $validated['reason'];

                    // If lunch_used, reduce undertime by 60 minutes
                    if ($validated['reason'] === 'lunch_used') {
                        $newUndertime = max(0, $attendance->undertime_minutes - 60);
                        $updates['undertime_minutes'] = $newUndertime;

                        // If undertime is now 0, update status
                        if ($newUndertime === 0) {
                            if ($attendance->status === 'undertime' || $attendance->status === 'undertime_more_than_hour') {
                                $updates['status'] = 'on_time';
                            } elseif ($attendance->secondary_status === 'undertime' || $attendance->secondary_status === 'undertime_more_than_hour') {
                                $updates['secondary_status'] = null;
                            }
                        } elseif ($newUndertime > 0 && $newUndertime <= 60) {
                            // Update status to reflect new undertime amount
                            if ($attendance->status === 'undertime_more_than_hour') {
                                $updates['status'] = 'undertime';
                            } elseif ($attendance->secondary_status === 'undertime_more_than_hour') {
                                $updates['secondary_status'] = 'undertime';
                            }
                        }
                    }
                }

                $attendance->update($updates);
                $attendance->refresh();

                // If lunch_used, recalculate total minutes worked (no lunch deduction)
                if ($validated['status'] === 'approved' && $validated['reason'] === 'lunch_used') {
                    $this->processor->recalculateTotalMinutesWorked($attendance);
                }

                // Handle point generation based on reason
                if ($validated['status'] === 'approved') {
                    // Delete existing undertime points for this attendance
                    $existingPoint = AttendancePoint::where('attendance_id', $attendance->id)
                        ->whereIn('point_type', ['undertime', 'undertime_more_than_hour'])
                        ->first();

                    if ($validated['reason'] === 'generate_points') {
                        // Regenerate points normally
                        $this->regeneratePointsIfNeeded(
                            $attendance,
                            $attendance->status,
                            $attendance->secondary_status,
                            $attendance->is_set_home ?? false,
                            $attendance->is_advised ?? false
                        );
                    } elseif ($validated['reason'] === 'skip_points' || $validated['reason'] === 'lunch_used') {
                        // Delete undertime points if any exist
                        if ($existingPoint) {
                            $existingPoint->delete();
                            // Recalculate GBRO for the user
                            $this->recalculateGbroForUser($attendance->user_id);
                        }

                        // If lunch_used reduced undertime to 0, regenerate to clear any remaining points
                        if ($validated['reason'] === 'lunch_used' && $attendance->undertime_minutes === 0) {
                            $this->regeneratePointsIfNeeded(
                                $attendance,
                                $attendance->status,
                                $attendance->secondary_status,
                                $attendance->is_set_home ?? false,
                                $attendance->is_advised ?? false
                            );
                        }
                    }
                }

                // Send notification to requester and employee
                $attendance->load(['user', 'undertimeApprovalRequestedBy']);
                $this->notificationService->notifyUndertimeApprovalDecision(
                    $attendance,
                    auth()->user(),
                    $attendance->user,
                    $attendance->undertimeApprovalRequestedBy ?? $attendance->user,
                    $validated['status'],
                    $validated['reason'] ?? 'rejected',
                    $validated['notes'] ?? null
                );
            });

            $statusText = $validated['status'] === 'approved' ? 'approved' : 'rejected';

            return redirect()->back()
                ->with('message', "Undertime has been {$statusText}.")
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('Undertime approval error: '.$e->getMessage());

            return redirect()->back()
                ->with('message', 'Failed to process undertime approval.')
                ->with('type', 'error');
        }
    }
}
