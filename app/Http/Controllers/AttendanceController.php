<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Events\AttendanceSpreadsheetUpdated;
use App\Http\Requests\BatchVerifyAttendanceRequest;
use App\Http\Requests\BulkStoreAttendanceRequest;
use App\Http\Requests\CreateSpreadsheetCellAttendanceRequest;
use App\Http\Requests\GenerateAttendanceRequest;
use App\Http\Requests\StoreAttendanceRequest;
use App\Http\Requests\VerifyAttendanceRequest;
use App\Jobs\ProcessAttendanceUpload;
use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\AttendanceUpload;
use App\Models\AttendanceWeekTotal;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\Site;
use App\Models\User;
use App\Services\AttendanceFileParser;
use App\Services\AttendancePoint\GbroCalculationService;
use App\Services\AttendancePoint\PartialDaySlExcuseService;
use App\Services\AttendanceProcessor;
use App\Services\AttendanceWriteService;
use App\Services\LeaveConflictResolver;
use App\Services\LeaveCreditService;
use App\Services\NotificationService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceProcessor $processor,
        protected NotificationService $notificationService,
        protected LeaveCreditService $leaveCreditService,
        protected GbroCalculationService $gbroService,
        protected PermissionService $permissionService,
        protected LeaveConflictResolver $leaveConflictResolver,
        protected AttendanceWriteService $attendanceWriteService,
        protected PartialDaySlExcuseService $partialDaySlExcuseService,
    ) {}

    /**
     * Display the attendance hub page for non-restricted roles.
     * Restricted roles (Agent, IT, Utility) are redirected to the main index.
     */
    public function hub()
    {
        $this->authorize('viewAny', Attendance::class);

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

        // Check if points exist for this attendance record
        if ($newGeneratesPoints) {
            $existingPoints = AttendancePoint::where('attendance_id', $attendance->id)->exists();
            if (! $existingPoints) {
                // Status requires points but none exist yet — generate them
                return true;
            }
            // is_set_home acts like skip_points: if enabled and points exist, they must be deleted
            if ($newIsSetHome) {
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

        // Delete existing points strictly tied to this attendance record. The
        // previous OR-on (user_id, shift_date) clause was unsafe: a single
        // shift_date can have multiple attendance rows (e.g. split bio events,
        // schedule corrections), and wiping points for siblings caused
        // legitimate point history to disappear during edits.
        AttendancePoint::where('attendance_id', $attendance->id)->delete();

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

        // Determine Team Lead's campaigns (if applicable)
        $teamLeadCampaignIds = $user->role === 'Team Lead' ? $user->getCampaignIds() : [];

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
        } elseif ($user->role === 'Team Lead' && ! empty($teamLeadCampaignIds)) {
            // Team Leads see only their campaigns' attendance (unless they manually filter)
            $campaignFilter = $request->input('campaign_id');
            if (! $campaignFilter || $campaignFilter === 'all') {
                // Default to Team Lead's campaigns if no filter specified
                $query->whereHas('employeeSchedule', function ($q) use ($teamLeadCampaignIds) {
                    $q->whereIn('campaign_id', $teamLeadCampaignIds);
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

        // Default date range: yesterday to today if not specified
        $startDate = $request->input('start_date', Carbon::yesterday()->toDateString());
        $endDate = $request->input('end_date', Carbon::today()->toDateString());
        $query->dateRange($startDate, $endDate);

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

        // Filter by site (via employee schedule) - supports multiple IDs comma-separated
        if ($request->has('site_id') && $request->site_id !== 'all' && $request->site_id) {
            $siteIds = is_array($request->site_id)
                ? $request->site_id
                : array_filter(explode(',', $request->site_id));
            if (count($siteIds) > 0) {
                $query->whereHas('employeeSchedule', function ($q) use ($siteIds) {
                    $q->whereIn('site_id', $siteIds);
                });
            }
        }

        // Filter by campaign (via employee schedule) - supports multiple IDs comma-separated
        if ($request->has('campaign_id') && $request->campaign_id !== 'all' && $request->campaign_id) {
            $campaignIds = is_array($request->campaign_id)
                ? $request->campaign_id
                : array_filter(explode(',', $request->campaign_id));

            if (count($campaignIds) > 0) {
                // For Team Leads, only allow filtering within their campaigns
                if ($user->role === 'Team Lead' && ! empty($teamLeadCampaignIds) && empty(array_intersect($teamLeadCampaignIds, $campaignIds))) {
                    // Team Lead trying to filter outside their campaigns - ignore
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
            ->orderBy('attendances.shift_date', 'asc')
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
        $campaigns = ! empty($teamLeadCampaignIds)
            ? Campaign::whereIn('id', $teamLeadCampaignIds)->orderBy('name')->get(['id', 'name'])
            : Campaign::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/Main/Index', [
            'attendances' => $attendances,
            'users' => $users,
            'sites' => $sites,
            'campaigns' => $campaigns,
            'teamLeadCampaignIds' => $teamLeadCampaignIds,
            'filters' => array_merge(
                $request->only(['search', 'status', 'user_id', 'site_id', 'campaign_id', 'needs_verification', 'verified_status']),
                ['start_date' => $startDate, 'end_date' => $endDate]
            ),
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

        // Detect Team Lead's campaigns for auto-filter
        $teamLeadCampaignIds = $authUser->role === 'Team Lead' ? $authUser->getCampaignIds() : [];

        // Auto-filter campaign for Team Leads when no campaign is specified
        $campaignIdToFilter = $campaignFilter ?: null;
        if (! $campaignIdToFilter && ! empty($teamLeadCampaignIds)) {
            $campaignIdToFilter = $teamLeadCampaignIds;
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
                $campaignIdsToFilter = is_array($campaignIdToFilter) ? $campaignIdToFilter : [$campaignIdToFilter];
                $usersQuery->whereHas('activeSchedule', function ($q) use ($campaignIdsToFilter) {
                    $q->whereIn('campaign_id', $campaignIdsToFilter);
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
        $campaigns = ! empty($teamLeadCampaignIds)
            ? Campaign::whereIn('id', $teamLeadCampaignIds)->orderBy('name')->get(['id', 'name'])
            : Campaign::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/Main/Calendar', [
            'attendances' => (object) $attendances, // Cast to object so it's treated as associative array in JS
            'users' => $users,
            'selectedUser' => $selectedUser,
            'campaigns' => $campaigns,
            'teamLeadCampaignIds' => $teamLeadCampaignIds,
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
                        'grace_period_minutes' => $schedule->grace_period_minutes ?? 0,
                    ] : null,
                ];
            });

        // Get all campaigns for filter
        $campaigns = Campaign::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/Main/Create', [
            'users' => $users,
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * Store a manually created attendance record.
     */
    public function store(StoreAttendanceRequest $request)
    {
        $validated = $request->validated();

        // Get employee schedule for the date
        $schedule = EmployeeSchedule::where('user_id', $validated['user_id'])
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

        // Resolve leave conflicts (approved-leave detection + pending-leave
        // auto-cancel/HR-review notifications). Centralized so Manual,
        // Daily Roster, and Spreadsheet all apply the exact same rules.
        $leaveResolution = $this->leaveConflictResolver->resolveOnAttendanceWrite(
            (int) $validated['user_id'],
            $validated['shift_date'],
            $actualTimeIn,
            $actualTimeOut,
            'Manual',
        );
        $approvedLeave = $leaveResolution['approvedLeave'];
        $hasLeaveConflict = $leaveResolution['hasApprovedConflict'];

        // Calculate status, tardy/undertime/overtime minutes from schedule and actual times
        $metrics = $this->processor->calculateManualAttendanceMetrics(
            $schedule,
            $actualTimeIn,
            $actualTimeOut,
            $validated['shift_date'],
            $validated['status'] ?? null,
            $validated['secondary_status'] ?? null,
            $approvedLeave,
        );
        $status = $metrics['status'];
        $secondaryStatus = $metrics['secondary_status'];
        $tardyMinutes = $metrics['tardy_minutes'];
        $undertimeMinutes = $metrics['undertime_minutes'];
        $overtimeMinutes = $metrics['overtime_minutes'];

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

        // Detect partial creation (one of in/out missing) — same rule as
        // Daily Roster + Spreadsheet so all 3 write surfaces agree on
        // is_partially_verified.
        $isPartial = ($actualTimeIn xor $actualTimeOut);

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
            'is_partially_verified' => $isPartial && ! $hasLeaveConflict,
            'leave_request_id' => $approvedLeave?->id,
            'verification_notes' => $hasLeaveConflict
                ? $leaveResolution['verificationNote']
                : ($isPartial
                    ? 'Time-in recorded — time-out pending next shift. Created by '.auth()->user()->name
                    : 'Manually created by '.auth()->user()->name),
            'notes' => $hasLeaveConflict
                ? $leaveResolution['conflictNote']
                : ($validated['notes'] ?? null),
            'undertime_approval_status' => $undertimeApprovalStatus,
            'undertime_approval_reason' => $undertimeApprovalReason,
            'undertime_approved_by' => $undertimeApprovalBy,
            'undertime_approved_at' => $undertimeApprovalAt,
        ]);

        // If lunch_used, recalculate total minutes worked (no lunch deduction)
        if ($undertimeApprovalReason === 'lunch_used') {
            $this->processor->recalculateTotalMinutesWorked($attendance);
        }

        // Run the shared finalize pipeline: recalc totals, regenerate points
        // (when admin_verified), and notify the employee.
        $this->attendanceWriteService->finalizeManualWrite($attendance, $validated['shift_date']);

        // (Leave-conflict notifications are emitted by LeaveConflictResolver above.)

        return redirect()->route('attendance.index')
            ->with('message', $hasLeaveConflict
                ? 'Attendance record created. Requires HR approval due to leave conflict.'
                : 'Attendance record created successfully.')
            ->with('type', 'success');
    }

    /**
     * Store bulk manually created attendance records.
     */
    public function bulkStore(BulkStoreAttendanceRequest $request)
    {
        $validated = $request->validated();

        // Convert datetime strings to Carbon instances once
        $actualTimeIn = $validated['actual_time_in'] ? Carbon::parse($validated['actual_time_in']) : null;
        $actualTimeOut = $validated['actual_time_out'] ? Carbon::parse($validated['actual_time_out']) : null;

        $createdCount = 0;
        $errors = [];

        foreach ($validated['user_ids'] as $userId) {
            try {
                // Get employee schedule for the date
                $schedule = EmployeeSchedule::where('user_id', $userId)
                    ->where('is_active', true)
                    ->where('effective_date', '<=', $validated['shift_date'])
                    ->where(function ($query) use ($validated) {
                        $query->whereNull('end_date')
                            ->orWhere('end_date', '>=', $validated['shift_date']);
                    })
                    ->first();

                // Resolve any approved/pending leave conflicts using the shared
                // service so all 4 write surfaces apply the same rule.
                $leaveResolution = $this->leaveConflictResolver->resolveOnAttendanceWrite(
                    (int) $userId,
                    $validated['shift_date'],
                    $actualTimeIn,
                    $actualTimeOut,
                    'Bulk',
                );
                $approvedLeave = $leaveResolution['approvedLeave'];
                $hasLeaveConflict = $leaveResolution['hasApprovedConflict'];

                // Calculate status, tardy/undertime/overtime minutes from schedule and actual times
                $metrics = $this->processor->calculateManualAttendanceMetrics(
                    $schedule,
                    $actualTimeIn,
                    $actualTimeOut,
                    $validated['shift_date'],
                    $validated['status'] ?? null,
                    $validated['secondary_status'] ?? null,
                    $approvedLeave,
                );
                $status = $metrics['status'];
                $secondaryStatus = $metrics['secondary_status'];
                $tardyMinutes = $metrics['tardy_minutes'];
                $undertimeMinutes = $metrics['undertime_minutes'];
                $overtimeMinutes = $metrics['overtime_minutes'];

                // Detect partial creation (one of in/out missing) — same rule
                // as Manual / Roster / Spreadsheet.
                $isPartial = ($actualTimeIn xor $actualTimeOut);

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
                    'admin_verified' => ! $hasLeaveConflict,
                    'is_partially_verified' => $isPartial && ! $hasLeaveConflict,
                    'leave_request_id' => $approvedLeave?->id,
                    'verification_notes' => $hasLeaveConflict
                        ? $leaveResolution['verificationNote']
                        : ($isPartial
                            ? 'Time-in recorded — time-out pending next shift. Created via Bulk by '.auth()->user()->name
                            : 'Manually created by '.auth()->user()->name),
                    'notes' => $hasLeaveConflict
                        ? $leaveResolution['conflictNote']
                        : ($validated['notes'] ?? null),
                ]);

                // Run the shared finalize pipeline: recalc totals, regenerate points
                // (when admin_verified), and notify the employee.
                $this->attendanceWriteService->finalizeManualWrite($attendance, $validated['shift_date']);

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
            $parser = app(AttendanceFileParser::class);
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
                    'unparseable_lines' => [
                        'count' => count($parser->getUnparseableLines()),
                        'examples' => array_slice($parser->getUnparseableLines(), 0, 5),
                    ],
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

        // Build a set of normalized names present in the file for O(1) lookup
        $normalizedNamesInFile = $records->pluck('normalized_name')->unique()->values();

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
            if (! $attendance->user) {
                continue;
            }

            // Only flag if this user actually appears in the uploaded file.
            // Normalise the user's last name the same way the parser does, then check
            // whether it is a substring of any file record's normalized_name.
            $normalizedUserLastName = strtolower(trim(
                str_replace(['.', '-'], ['', ' '], $attendance->user->last_name)
            ));

            $isInFile = $normalizedNamesInFile->contains(
                fn ($name) => str_contains($name, $normalizedUserLastName)
            );

            if (! $isInFile) {
                continue;
            }

            $potentialDuplicates[] = [
                'employee' => $attendance->user->first_name.' '.$attendance->user->last_name,
                'date' => $attendance->shift_date,
                'status' => $attendance->status,
                'verified' => $attendance->admin_verified,
            ];
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
        $filename = Str::uuid().'_'.$file->getClientOriginalName();
        $path = $file->storeAs('attendance_uploads', $filename);

        // Create upload record
        $upload = AttendanceUpload::create([
            'uploaded_by' => auth()->id(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $filename,
            'date_from' => $request->date_from,
            'date_to' => $request->date_to ?? $request->date_from, // If null, use date_from (single day)
            'biometric_site_id' => $request->biometric_site_id,
            'notes' => $request->notes,
            'status' => 'pending',
        ]);

        // Large files are dispatched to the job queue to prevent HTTP timeouts.
        // Small files are processed inline so the response includes full stats.
        $queueThreshold = config('attendance.queue_upload_size_bytes', 204800);

        if ($file->getSize() > $queueThreshold) {
            ProcessAttendanceUpload::dispatch($upload, $filterByDate);

            $queueMessage = sprintf(
                'File "%s" (%s KB) is queued for processing. Check the Uploads page for status.',
                $file->getClientOriginalName(),
                number_format($file->getSize() / 1024, 1)
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'queued' => true,
                    'message' => $queueMessage,
                    'upload_id' => $upload->id,
                ]);
            }

            return redirect()->route('attendance.import')
                ->with('message', $queueMessage)
                ->with('type', 'info');
        }

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

            // Add unparseable lines info if any
            if (! empty($stats['unparseable_lines'])) {
                $message .= sprintf('. %d row(s) could not be parsed and were skipped (check server logs for details).', count($stats['unparseable_lines']));
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

            Log::error('Attendance upload failed', [
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

        // Determine Team Lead's campaigns (if applicable)
        $teamLeadCampaignIds = $user->role === 'Team Lead' ? $user->getCampaignIds() : [];

        $query = Attendance::with([
            'user.activeSchedule.site', // Include user's active schedule as fallback
            'user.activeSchedule.campaign', // Include user's active schedule campaign as fallback
            'employeeSchedule.site',
            'employeeSchedule.campaign',
            'bioInSite',
            'bioOutSite',
            'leaveRequest', // Include leave request info
        ]);

        // Auto-filter for Team Leads by their campaigns
        if ($user->role === 'Team Lead' && ! empty($teamLeadCampaignIds)) {
            $campaignFilter = $request->input('campaign_id');
            if (! $campaignFilter || $campaignFilter === 'all') {
                // Default to Team Lead's campaigns if no filter specified
                $query->whereHas('employeeSchedule', function ($q) use ($teamLeadCampaignIds) {
                    $q->whereIn('campaign_id', $teamLeadCampaignIds);
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

        // Default date range: yesterday to today if not specified
        // When verify param is set, skip date range so the record is always found
        $dateFrom = $request->input('date_from', Carbon::yesterday()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());
        if (! $request->filled('verify')) {
            $query->where('shift_date', '>=', $dateFrom);
            $query->where('shift_date', '<=', $dateTo);
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

            // Align the date range to the record's shift_date so filters display correctly
            $verifyRecord = Attendance::find($verifyAttendanceId);
            if ($verifyRecord) {
                $dateFrom = $verifyRecord->shift_date;
                $dateTo = $verifyRecord->shift_date;
            }
        }

        $attendances = $query
            ->join('users', 'attendances.user_id', '=', 'users.id')
            ->select('attendances.*')
            ->orderBy('attendances.shift_date', 'asc')
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
        $campaigns = ! empty($teamLeadCampaignIds)
            ? Campaign::whereIn('id', $teamLeadCampaignIds)->orderBy('name')->get(['id', 'name'])
            : Campaign::orderBy('name')->get(['id', 'name']);

        // Build a base query with shared filters (date, employee, site, campaign, team lead)
        // Used for status summary counts so they reflect the same filter context
        $baseFilteredQuery = Attendance::query();

        // Apply Team Lead auto-filter to summary counts
        if ($user->role === 'Team Lead' && ! empty($teamLeadCampaignIds)) {
            $campaignFilter = $request->input('campaign_id');
            if (! $campaignFilter || $campaignFilter === 'all') {
                $baseFilteredQuery->whereHas('employeeSchedule', function ($q) use ($teamLeadCampaignIds) {
                    $q->whereIn('campaign_id', $teamLeadCampaignIds);
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

        $baseFilteredQuery->where('shift_date', '>=', $dateFrom);
        $baseFilteredQuery->where('shift_date', '<=', $dateTo);

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
            'teamLeadCampaignIds' => $teamLeadCampaignIds,
            'verifyAttendanceId' => $verifyAttendanceId,
            'leaveConflictCount' => $leaveConflictCount,
            'partiallyVerifiedCount' => $partiallyVerifiedCount,
            'statusCounts' => $statusCounts,
            'verificationCounts' => $verificationCounts,
            'filters' => [
                'user_id' => $request->user_id,
                'status' => $request->status,
                'verified' => $request->verified,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'site_id' => $request->site_id,
                'campaign_id' => $request->campaign_id,
                'leave_conflict' => $request->leave_conflict,
            ],
        ]);
    }

    /**
     * Verify and update an attendance record.
     */
    public function verify(VerifyAttendanceRequest $request, Attendance $attendance)
    {
        // Load employee schedule and leave request for checking
        $attendance->load(['employeeSchedule', 'leaveRequest']);

        // Note: Allow re-verification of already verified records
        // This is intentional - admins can update verified records through this interface

        // Capture old values for smart point regeneration comparison
        $oldStatus = $attendance->status;
        $oldSecondaryStatus = $attendance->secondary_status;
        $oldIsSetHome = $attendance->is_set_home ?? false;
        $oldIsAdvised = $attendance->is_advised ?? false;

        $leaveAdjusted = false;
        $leaveAdjustmentMessage = '';

        // Wrap the full multi-step verification in a single transaction so that
        // a failure mid-sequence (status update, leave adjustment, tardy/UT/OT
        // recalculation, point regeneration) does NOT leave the record in a
        // partially-updated state. Nested transactions inside
        // adjustLeaveForWorkDay are handled via MySQL savepoints by Laravel.
        try {
            DB::transaction(function () use (
                $request,
                $attendance,
                &$leaveAdjusted,
                &$leaveAdjustmentMessage,
                $oldStatus,
                $oldSecondaryStatus,
                $oldIsSetHome,
                $oldIsAdvised
            ) {
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

                $this->recalculateVerifyTimeFields($attendance, $request);

                // Recalculate total minutes worked based on overtime approval status
                // If overtime exists but is not approved, work hours are capped at scheduled time out
                $attendance->refresh();
                $this->processor->recalculateTotalMinutesWorked($attendance);

                // Handle undertime approval action submitted with the verify form
                $forceUndertimeRegen = false;
                if ($request->filled('undertime_approval_action')) {
                    $action = $request->undertime_approval_action;
                    $reason = $request->undertime_approval_reason ?? 'generate_points';
                    $authUser = auth()->user();

                    if (in_array($action, ['approve', 'reject'], true) &&
                        $this->permissionService->userHasPermission($authUser, 'attendance.approve_undertime')) {
                        $attendance->update([
                            'undertime_approval_status' => $action === 'approve' ? 'approved' : 'rejected',
                            'undertime_approval_reason' => $action === 'approve' ? $reason : 'generate_points',
                            'undertime_approved_by' => $authUser->id,
                            'undertime_approved_at' => now(),
                        ]);
                        // Force regeneration so the processor can apply/remove the approval:
                        // approve → delete existing undertime points; reject → recreate them.
                        $forceUndertimeRegen = true;
                    } elseif ($action === 'request' &&
                        $this->permissionService->userHasPermission($authUser, 'attendance.request_undertime_approval')) {
                        $attendance->update([
                            'undertime_approval_status' => 'pending',
                            'undertime_approval_reason' => $reason,
                            'undertime_approval_requested_by' => $authUser->id,
                            'undertime_approval_requested_at' => now(),
                        ]);
                    }
                }

                // Smart point regeneration - only regenerate if changes affect points
                $attendance->refresh();
                $this->regeneratePointsIfNeeded(
                    $attendance,
                    $oldStatus,
                    $oldSecondaryStatus,
                    $oldIsSetHome,
                    $oldIsAdvised,
                    $forceUndertimeRegen
                );
            });
        } catch (\Throwable $e) {
            Log::error('AttendanceController verify Error: '.$e->getMessage(), [
                'attendance_id' => $attendance->id,
                'user_id' => $attendance->user_id,
            ]);

            return redirect()->back()
                ->with('message', 'Failed to verify attendance record. Changes were rolled back.')
                ->with('type', 'error');
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
            $successMessage .= ' '.$leaveAdjustmentMessage;
        }

        return redirect()->back()
            ->with('message', $successMessage)
            ->with('type', 'success');
    }

    /**
     * Recalculate tardy/undertime/overtime fields for an attendance during
     * verification. Extracted from {@see verify()} for atomic transaction wrapping.
     */
    private function recalculateVerifyTimeFields(Attendance $attendance, Request $request): void
    {
        // Skip all time calculations for non-work days
        if ($request->status === 'non_work_day') {
            $attendance->update([
                'tardy_minutes' => null,
                'undertime_minutes' => null,
                'overtime_minutes' => null,
            ]);

            return;
        }

        // Recalculate tardy if time in is provided (not applicable for failed_bio_in)
        if ($request->actual_time_in && $attendance->scheduled_time_in) {
            $shiftDate = Carbon::parse($attendance->shift_date);

            // Build scheduled time in datetime

            // Check for graveyard shift pattern (00:00-04:59 start time)
            // For graveyard shifts, scheduled time is on the NEXT calendar day from shift_date
            $isGraveyardShift = $attendance->employeeSchedule?->isGraveyardShift()
                ?? Carbon::parse($attendance->scheduled_time_in)->hour < 5;

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

                // Check for graveyard shift pattern (00:00-04:59 start time)
                // For graveyard shifts, scheduled time out is on the NEXT calendar day from shift_date
                $isGraveyardShift = $attendance->employeeSchedule?->isGraveyardShift()
                    ?? Carbon::parse($attendance->scheduled_time_in)->hour < 5;

                if ($isGraveyardShift) {
                    // Graveyard shift: both time in and time out are on next calendar day
                    $scheduledTimeOut = Carbon::parse($shiftDate->copy()->addDay()->format('Y-m-d').' '.$attendance->scheduled_time_out);
                } elseif ($scheduledOut->format('H:i:s') < $scheduledIn->format('H:i:s')) {
                    // Night shift: time out is before time in (e.g., 22:00-07:00), shift crosses midnight
                    $scheduledTimeOut->addDay();
                }
            }

            // Calculate difference: positive means overtime (left late), negative means undertime (left early)
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
    }

    /**
     * Batch verify multiple attendance records.
     */
    public function batchVerify(BatchVerifyAttendanceRequest $request)
    {
        $validated = $request->validated();

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
            $splitLeaveRequest = null; // populated only for middle-day work

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
            // Work in the MIDDLE of leave - split the leave into two segments so the
            // employee only loses credit for the single worked day instead of having
            // every post-work day truncated and refunded (the previous behavior).
            //
            // Strategy:
            //  - Shrink the original LeaveRequest to cover [leaveStart, workDate-1].
            //  - Create a sibling LeaveRequest covering [workDate+1, leaveEnd] that
            //    inherits all approval state from the original (already approved).
            //  - Refund exactly 1 credit (the worked day). The split segment keeps
            //    its own portion of the originally deducted credits.
            else {
                $segmentBeforeEnd = $workDate->copy()->subDay();
                $segmentAfterStart = $workDate->copy()->addDay();

                $newEnd = $segmentBeforeEnd; // first segment becomes leaveStart..workDate-1
                $creditsToRestore = 1;       // only the worked day is refunded
                $adjustmentType = 'middle_split';

                $secondSegmentDays = $segmentAfterStart->diffInDays($leaveEnd) + 1;

                // Distribute originally-deducted credits between the two segments
                // proportionally to their days. Worked-day credit (1) is refunded
                // separately above; the remainder splits across the two segments.
                $remainingCredits = max(0, ((int) $creditsDeducted) - 1);
                $firstSegmentDays = $leaveStart->diffInDays($segmentBeforeEnd) + 1;
                $totalRemainingDays = $firstSegmentDays + $secondSegmentDays;

                if ($totalRemainingDays > 0) {
                    $secondSegmentCredits = (int) round(
                        ($secondSegmentDays / $totalRemainingDays) * $remainingCredits
                    );
                } else {
                    $secondSegmentCredits = 0;
                }

                // Build the sibling leave request covering the post-work segment.
                $splitLeaveRequest = $leaveRequest->replicate([
                    'auto_cancelled', 'auto_cancelled_at', 'auto_cancelled_reason',
                    'date_modified_by', 'date_modified_at', 'date_modification_reason',
                    'original_start_date', 'original_end_date',
                ]);
                $splitLeaveRequest->fill([
                    'start_date' => $segmentAfterStart->format('Y-m-d'),
                    'end_date' => $leaveEnd->format('Y-m-d'),
                    'days_requested' => $secondSegmentDays,
                    'credits_deducted' => $secondSegmentCredits,
                    'status' => 'approved',
                    'date_modified_by' => auth()->id(),
                    'date_modified_at' => now(),
                    'date_modification_reason' => 'Auto-split from leave #'.$leaveRequest->id.
                        ' because employee worked on '.$workDate->format('M d, Y').'.',
                    'original_start_date' => $leaveRequest->original_start_date ?? $leaveStart->format('Y-m-d'),
                    'original_end_date' => $leaveRequest->original_end_date ?? $leaveEnd->format('Y-m-d'),
                ]);
                $splitLeaveRequest->save();

                // Re-link the post-work attendance rows to the new sibling leave so
                // they remain marked `on_leave` instead of being orphaned to
                // `needs_manual_review` by the truncation block below.
                Attendance::where('user_id', $leaveRequest->user_id)
                    ->where('leave_request_id', $leaveRequest->id)
                    ->whereBetween('shift_date', [
                        $segmentAfterStart->format('Y-m-d'),
                        $leaveEnd->format('Y-m-d'),
                    ])
                    ->update(['leave_request_id' => $splitLeaveRequest->id]);
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
                'split_leave_request_id' => $splitLeaveRequest?->id,
                'credits_restored' => $canRestoreCredits ? $creditsToRestore : 0,
                'can_restore_credits' => $canRestoreCredits,
            ]);

            if ($adjustmentType === 'middle_split') {
                $resultMessage = $canRestoreCredits
                    ? "Leave split into two segments around {$workDate->format('M d, Y')}. 1 day of {$leaveRequest->leave_type} credit restored for the worked day."
                    : "Leave split into two segments around {$workDate->format('M d, Y')}. Credits not restored (year mismatch).";
            } else {
                $resultMessage = $canRestoreCredits
                    ? "Leave adjusted to {$newStart->format('M d')}-{$newEnd->format('M d, Y')}. {$creditsToRestore} day(s) of {$leaveRequest->leave_type} credit restored."
                    : "Leave adjusted to {$newStart->format('M d')}-{$newEnd->format('M d, Y')}. Credits not restored (year mismatch).";
            }

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

        // Determine Team Lead's campaigns (if applicable)
        $teamLeadCampaignIds = $user->role === 'Team Lead' ? $user->getCampaignIds() : [];

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
                    'name' => $user->last_name.', '.$user->first_name.($user->middle_name ? ' '.$user->middle_name : ''),
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
                        'grace_period_minutes' => $schedule->grace_period_minutes ?? 0,
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
        $campaigns = ! empty($teamLeadCampaignIds)
            ? Campaign::whereIn('id', $teamLeadCampaignIds)->orderBy('name')->get(['id', 'name'])
            : Campaign::orderBy('name')->get(['id', 'name']);

        // Filter by site if provided
        if ($request->filled('site_id')) {
            $employees = $employees->filter(function ($emp) use ($request) {
                return $emp['schedule'] && $emp['schedule']['site_id'] == $request->site_id;
            })->values();
        }

        // Filter by campaign if provided OR auto-filter for Team Leads
        $campaignIdToFilter = $request->filled('campaign_id') ? $request->campaign_id : null;
        if (! $campaignIdToFilter && $user->role === 'Team Lead' && ! empty($teamLeadCampaignIds)) {
            // Default to Team Lead's campaigns if no filter specified - filter to any of their campaigns
            $employees = $employees->filter(function ($emp) use ($teamLeadCampaignIds) {
                return $emp['schedule'] && in_array($emp['schedule']['campaign_id'], $teamLeadCampaignIds);
            })->values();
            $campaignIdToFilter = null; // Already filtered
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

        // Pending time-outs: partially-verified records (no time-out yet) for the
        // same scope (campaign / site / team-lead). Surfaced at the top of the
        // roster so the admin completes them BEFORE recording today's time-ins.
        $pendingTimeOutsQuery = Attendance::with(['user:id,first_name,last_name,middle_name,email', 'employeeSchedule:id,user_id,shift_type,scheduled_time_in,scheduled_time_out,site_id,campaign_id,grace_period_minutes', 'employeeSchedule.site:id,name', 'employeeSchedule.campaign:id,name'])
            ->where('is_partially_verified', true)
            ->whereNull('actual_time_out')
            ->whereNotNull('actual_time_in');

        if (! empty($teamLeadCampaignIds)) {
            $pendingTimeOutsQuery->whereHas('employeeSchedule', function ($q) use ($teamLeadCampaignIds) {
                $q->whereIn('campaign_id', $teamLeadCampaignIds);
            });
        }
        if ($request->filled('site_id')) {
            $pendingTimeOutsQuery->whereHas('employeeSchedule', function ($q) use ($request) {
                $q->where('site_id', $request->site_id);
            });
        }
        if ($request->filled('campaign_id')) {
            $pendingTimeOutsQuery->whereHas('employeeSchedule', function ($q) use ($request) {
                $q->where('campaign_id', $request->campaign_id);
            });
        }

        $pendingTimeOuts = $pendingTimeOutsQuery
            ->orderBy('shift_date', 'asc')
            ->get()
            ->map(function ($att) {
                $sched = $att->employeeSchedule;
                $user = $att->user;

                return [
                    'id' => $att->id,
                    'user_id' => $att->user_id,
                    'name' => $user
                        ? $user->last_name.', '.$user->first_name.($user->middle_name ? ' '.$user->middle_name : '')
                        : '—',
                    'email' => $user?->email,
                    'shift_date' => Carbon::parse($att->shift_date)->format('Y-m-d'),
                    'shift_date_formatted' => Carbon::parse($att->shift_date)->format('M d, Y (D)'),
                    'actual_time_in' => $att->actual_time_in?->format('Y-m-d\TH:i'),
                    'status' => $att->status,
                    'secondary_status' => $att->secondary_status,
                    'tardy_minutes' => $att->tardy_minutes,
                    'verification_notes' => $att->verification_notes,
                    'schedule' => $sched ? [
                        'id' => $sched->id,
                        'shift_type' => $sched->shift_type,
                        'scheduled_time_in' => $sched->scheduled_time_in,
                        'scheduled_time_out' => $sched->scheduled_time_out,
                        'site_name' => $sched->site?->name,
                        'campaign_id' => $sched->campaign_id,
                        'campaign_name' => $sched->campaign?->name,
                        'grace_period_minutes' => $sched->grace_period_minutes ?? 0,
                    ] : null,
                ];
            })
            ->values();

        return Inertia::render('Attendance/Main/DailyRoster', [
            'employees' => $employees,
            'sites' => $sites,
            'campaigns' => $campaigns,
            'teamLeadCampaignIds' => $teamLeadCampaignIds,
            'selectedDate' => $selectedDate->format('Y-m-d'),
            'dayName' => ucfirst($dayName),
            'pendingTimeOuts' => $pendingTimeOuts,
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
    public function generateAttendance(GenerateAttendanceRequest $request)
    {
        $this->authorize('create', Attendance::class);

        $validated = $request->validated();

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
        $schedule = EmployeeSchedule::where('user_id', $validated['user_id'])
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

        // Detect partial creation: time-in present, time-out missing.
        // Used for BPO night shifts where the admin records the in-time at the
        // start of shift and completes the out-time on the next shift's morning.
        $isPartial = ! empty($validated['actual_time_in']) && empty($validated['actual_time_out']);

        // Parse times
        $actualTimeIn = ! empty($validated['actual_time_in']) ? Carbon::parse($validated['actual_time_in']) : null;
        $actualTimeOut = ! empty($validated['actual_time_out']) ? Carbon::parse($validated['actual_time_out']) : null;
        $shiftDate = Carbon::parse($validated['shift_date']);

        // Resolve leave conflicts via the centralized service so this surface
        // applies the same rules as Manual create and Spreadsheet.
        $leaveResolution = $this->leaveConflictResolver->resolveOnAttendanceWrite(
            (int) $validated['user_id'],
            $validated['shift_date'],
            $actualTimeIn,
            $actualTimeOut,
            'Daily Roster',
        );
        $approvedLeave = $leaveResolution['approvedLeave'];
        $hasLeaveConflict = $leaveResolution['hasApprovedConflict'];

        // Calculate status, tardy / undertime / overtime via the single
        // canonical calculator used by Manual create and Spreadsheet.
        $metrics = $this->processor->calculateManualAttendanceMetrics(
            $schedule,
            $actualTimeIn,
            $actualTimeOut,
            $validated['shift_date'],
            $validated['status'] ?? null,
            $validated['secondary_status'] ?? null,
            $approvedLeave,
        );

        $tardyMinutes = $metrics['tardy_minutes'];
        $undertimeMinutes = $metrics['undertime_minutes'];
        $overtimeMinutes = $metrics['overtime_minutes'];
        $calculatedStatus = $metrics['status'] ?? 'on_time';
        $calculatedSecondaryStatus = $metrics['secondary_status'];

        // Use provided status or calculated status
        $status = $validated['status'] ?? $calculatedStatus;
        $secondaryStatus = $validated['secondary_status'] ?? $calculatedSecondaryStatus;

        // Create attendance record - VERIFIED immediately (or PARTIALLY VERIFIED if no time-out yet)
        // EXCEPT when an approved leave conflicts → leave it for HR to review.
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
            'admin_verified' => ! $hasLeaveConflict, // Skip auto-verify when conflicting with approved leave
            'is_partially_verified' => $isPartial && ! $hasLeaveConflict,
            'leave_request_id' => $approvedLeave?->id,
            'verification_notes' => $hasLeaveConflict
                ? $leaveResolution['verificationNote']
                : ($isPartial
                    ? ($validated['verification_notes'] ?? 'Time-in recorded — time-out pending next shift.')
                    : ($validated['verification_notes'] ?? null)),
            'notes' => $hasLeaveConflict
                ? $leaveResolution['conflictNote']
                : ($validated['notes'] ?? null),
        ]);

        // Run the shared finalize pipeline: recalc totals, regenerate points
        // (when admin_verified), and notify the employee.
        $this->attendanceWriteService->finalizeManualWrite($attendance, $validated['shift_date']);

        $user = User::find($validated['user_id']);

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
            // If record has tardy info from time-in, preserve that status (forgiveness window).
            if ($attendance->tardy_minutes && $attendance->tardy_minutes > 0) {
                $schedule = $attendance->employeeSchedule;
                $gracePeriod = $schedule->grace_period_minutes ?? 0;
                $status = ($attendance->tardy_minutes > $gracePeriod) ? 'tardy' : 'on_time';
            }
        } elseif (! $explicitStatus && $attendance->status === 'needs_manual_review') {
            // For needs_manual_review, always recalculate from tardy info if available
            if ($attendance->tardy_minutes && $attendance->tardy_minutes > 0) {
                $schedule = $attendance->employeeSchedule;
                $gracePeriod = $schedule->grace_period_minutes ?? 0;
                $status = ($attendance->tardy_minutes > $gracePeriod) ? 'tardy' : 'on_time';
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

            // Determine status - preserve tardy if applicable (forgiveness window).
            $status = $attendance->status;
            if (! in_array($status, ['failed_bio_out'])) {
                if ($attendance->tardy_minutes && $attendance->tardy_minutes > 0) {
                    $schedule = $attendance->employeeSchedule;
                    $gracePeriod = $schedule->grace_period_minutes ?? 0;
                    $status = ($attendance->tardy_minutes > $gracePeriod) ? 'tardy' : 'on_time';
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
                    // Delete existing undertime points for this attendance (avoid duplicates on re-approval)
                    AttendancePoint::where('attendance_id', $attendance->id)
                        ->whereIn('point_type', ['undertime', 'undertime_more_than_hour'])
                        ->delete();

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
                        // Points already deleted above; recalculate GBRO
                        $this->recalculateGbroForUser($attendance->user_id);

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

    /**
     * Spreadsheet view: per-employee × per-day grid, grouped by campaign.
     * Cells show hours worked OR a status/leave code (VL, SL, ABS, ML, LOA, UPTO, ...).
     */
    public function spreadsheet(Request $request)
    {
        $this->authorize('viewAny', Attendance::class);

        $authUser = auth()->user();
        $teamLeadCampaignIds = $authUser->role === 'Team Lead' ? $authUser->getCampaignIds() : [];
        $restrictedRoles = ['Agent', 'IT', 'Utility'];

        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);
        $campaignFilter = $request->input('campaign_id');
        $search = trim((string) $request->input('search', ''));

        // Whole-month calendar view (Sunday → Saturday weeks). Extend the
        // visible range to the surrounding full weeks so month-edge days
        // (e.g. Jun 28–30 + Jul 1–4 for June 2026) are part of a complete
        // week column and roll into the same Saturday week-total.
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();
        $startDate = (clone $monthStart)->startOfWeek(Carbon::SUNDAY);
        $endDate = (clone $monthEnd)->endOfWeek(Carbon::SATURDAY);

        // Resolve which campaigns to include
        $campaignIds = null;
        if ($campaignFilter && $campaignFilter !== 'all') {
            $campaignIds = is_array($campaignFilter)
                ? array_map('intval', $campaignFilter)
                : array_filter(array_map('intval', explode(',', (string) $campaignFilter)));
        }
        // Team Leads are constrained to their own campaigns
        if (! empty($teamLeadCampaignIds)) {
            $campaignIds = $campaignIds
                ? array_values(array_intersect($campaignIds, $teamLeadCampaignIds))
                : $teamLeadCampaignIds;
        }

        // Partial-reload short-circuit: when the client only asks for weekTotals
        // (e.g. after Calc/Recalc/Remove), skip the expensive employee/cell build.
        $partialHeader = $request->header('X-Inertia-Partial-Data');
        $partials = $partialHeader ? array_filter(array_map('trim', explode(',', $partialHeader))) : [];
        $isPartial = ! empty($partials);
        $needsGroups = ! $isPartial || in_array('groups', $partials, true);
        $needsWeekTotals = ! $isPartial || in_array('weekTotals', $partials, true);

        // Cheap employee-id scope query — needed by both groups and weekTotals.
        $scopedIdsQuery = User::query()->select('users.id');
        if (in_array($authUser->role, $restrictedRoles)) {
            $scopedIdsQuery->where('users.id', $authUser->id);
        } elseif ($campaignIds !== null) {
            $scopedIdsQuery->whereHas('activeSchedule', function ($q) use ($campaignIds) {
                $q->whereIn('campaign_id', $campaignIds);
            });
        }
        $scopedIdsQuery->where(function ($q) {
            $q->whereNull('hired_date')->orWhere('is_active', true);
        });
        if ($search !== '') {
            $scopedIdsQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere(\DB::raw("CONCAT(first_name, ' ', last_name)"), 'like', "%{$search}%");
            });
        }
        $scopedEmployeeIds = $scopedIdsQuery->pluck('users.id')->all();

        // Always-cheap props (filters, campaigns, days) get built unconditionally
        // — Inertia only serializes what `only` requests anyway.
        $weekTotalsByUser = $needsWeekTotals
            ? $this->buildWeekTotals($scopedEmployeeIds, $startDate, $endDate)
            : [];

        $grouped = [];
        if ($needsGroups) {
            $grouped = $this->buildSpreadsheetGroups(
                $scopedEmployeeIds,
                $startDate,
                $endDate,
            );
        }

        // Build days metadata for header
        $days = [];
        for ($d = (clone $startDate); $d->lte($endDate); $d->addDay()) {
            $days[] = [
                'date' => $d->format('Y-m-d'),
                'day' => (int) $d->format('j'),
                'weekday' => $d->format('D'),
                'is_weekend' => in_array((int) $d->format('w'), [0, 6]),
                'is_saturday' => (int) $d->format('w') === 6,
                'is_overflow' => $d->lt($monthStart) || $d->gt($monthEnd),
            ];
        }

        // Campaign options for the filter dropdown
        $campaignOptions = ! empty($teamLeadCampaignIds)
            ? Campaign::whereIn('id', $teamLeadCampaignIds)->orderBy('name')->get(['id', 'name'])
            : Campaign::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/Main/Spreadsheet', [
            'groups' => collect($grouped)->map(fn ($emps, $name) => [
                'campaign' => $name,
                'employees' => $emps,
            ])->values(),
            'weekTotals' => empty($weekTotalsByUser) ? new \stdClass : $weekTotalsByUser,
            'days' => $days,
            'month' => $month,
            'year' => $year,
            'campaigns' => $campaignOptions,
            'teamLeadCampaignIds' => $teamLeadCampaignIds,
            'filters' => [
                'campaign_id' => $campaignFilter,
                'search' => $search,
            ],
            'halfDayThreshold' => (int) config('attendance.half_day_absence_tardy_minutes', 15),
        ]);
    }

    /**
     * Build the per-employee × per-day grid grouped by campaign.
     *
     * Extracted so partial Inertia reloads requesting only `weekTotals` (e.g.
     * after Calc/Recalc/Remove) can skip this entirely.
     */
    private function buildSpreadsheetGroups(array $employeeIds, Carbon $startDate, Carbon $endDate): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $employees = User::query()
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.role', 'users.avatar')
            ->with(['activeSchedule.campaign:id,name'])
            ->whereIn('users.id', $employeeIds)
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->get();

        $attendanceRows = DB::table('attendances')
            ->whereIn('user_id', $employeeIds)
            ->whereBetween('shift_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get([
                'id', 'user_id', 'shift_date', 'status', 'secondary_status',
                'total_minutes_worked', 'leave_request_id', 'admin_verified', 'is_advised',
                'actual_time_in', 'actual_time_out',
                'tardy_minutes', 'undertime_minutes', 'overtime_minutes',
                'overtime_approved', 'is_set_home', 'is_partially_verified',
                'undertime_approval_status', 'undertime_approval_reason',
                'employee_schedule_id', 'scheduled_time_in', 'scheduled_time_out',
                'warnings', 'notes', 'verification_notes',
            ]);

        $leaveIds = $attendanceRows->pluck('leave_request_id')->filter()->unique()->all();
        $leaveTypes = empty($leaveIds)
            ? []
            : DB::table('leave_requests')
                ->whereIn('id', $leaveIds)
                ->pluck('leave_type', 'id')
                ->all();

        // Per-day status lookup keyed by "{leave_request_id}|{Y-m-d}". Used so the
        // spreadsheet can distinguish, e.g., a Partial-day Absence (SL with Undertime)
        // day from a normal SL day even when the attendance status is the same.
        $leaveDayStatuses = empty($leaveIds)
            ? []
            : DB::table('leave_request_days')
                ->whereIn('leave_request_id', $leaveIds)
                ->get(['leave_request_id', 'date', 'day_status'])
                ->mapWithKeys(fn ($r) => [
                    $r->leave_request_id.'|'.substr((string) $r->date, 0, 10) => $r->day_status,
                ])
                ->all();

        $scheduleIds = $attendanceRows->pluck('employee_schedule_id')->filter()->unique()->all();
        $shiftTypes = empty($scheduleIds)
            ? []
            : DB::table('employee_schedules')
                ->whereIn('id', $scheduleIds)
                ->pluck('shift_type', 'id')
                ->all();

        $cellMap = [];
        foreach ($attendanceRows as $row) {
            $dateKey = substr((string) $row->shift_date, 0, 10);
            $leaveType = $row->leave_request_id ? ($leaveTypes[$row->leave_request_id] ?? null) : null;
            $shiftType = $row->employee_schedule_id ? ($shiftTypes[$row->employee_schedule_id] ?? null) : null;
            $leaveDayStatus = $row->leave_request_id
                ? ($leaveDayStatuses[$row->leave_request_id.'|'.$dateKey] ?? null)
                : null;
            $built = $this->buildSpreadsheetCell($row, $leaveType, $shiftType, $leaveDayStatus);
            $existing = $cellMap[$row->user_id][$dateKey] ?? null;

            if ($existing === null) {
                $cellMap[$row->user_id][$dateKey] = $built;

                continue;
            }

            $existingHasBio = (bool) ($existing['has_bio'] ?? false);
            $newHasBio = (bool) $built['has_bio'];
            $existingIsNcns = ($existing['status'] ?? null) === 'ncns';
            $newIsNcns = $built['status'] === 'ncns';

            if (($newHasBio && ! $existingHasBio) || ($existingIsNcns && ! $newIsNcns)) {
                $cellMap[$row->user_id][$dateKey] = $built;
            }
        }

        $pointsByUser = AttendancePoint::query()
            ->whereIn('user_id', $employeeIds)
            ->where('is_expired', false)
            ->where('is_excused', false)
            ->selectRaw('user_id, SUM(points) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        $grouped = [];
        foreach ($employees as $emp) {
            $campaignName = $emp->activeSchedule?->campaign?->name ?? 'Unassigned';
            $sched = $emp->activeSchedule;

            $cells = $cellMap[$emp->id] ?? [];
            if ($sched && ! empty($sched->work_days)) {
                foreach ($cells as $dateKey => $cell) {
                    if (($cell['kind'] ?? '') === 'leave') {
                        $dayName = strtolower(Carbon::parse($dateKey)->format('l'));
                        if (! in_array($dayName, $sched->work_days)) {
                            unset($cells[$dateKey]);
                        }
                    }
                }
            }

            $grouped[$campaignName][] = [
                'id' => $emp->id,
                'name' => trim($emp->last_name.', '.$emp->first_name),
                'role' => $emp->role,
                'avatar_url' => $emp->avatar ? Storage::disk('public')->url($emp->avatar) : null,
                'points' => round((float) ($pointsByUser[$emp->id] ?? 0), 2),
                'schedule' => $sched ? [
                    'shift_type' => $sched->shift_type,
                    'scheduled_time_in' => $sched->scheduled_time_in,
                    'scheduled_time_out' => $sched->scheduled_time_out,
                    'work_days' => $sched->work_days,
                    'campaign' => $sched->campaign?->name,
                    'grace_period_minutes' => $sched->grace_period_minutes ?? 0,
                ] : null,
                'cells' => empty($cells) ? new \stdClass : $cells,
            ];
        }
        ksort($grouped);

        return $grouped;
    }

    /**
     * Build per-week payroll totals keyed by [user_id][display_anchor_saturday].
     */
    private function buildWeekTotals(array $employeeIds, Carbon $startDate, Carbon $endDate): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $totals = [];
        AttendanceWeekTotal::query()
            ->whereIn('user_id', $employeeIds)
            ->whereBetween('week_end', [$startDate->toDateString(), $endDate->toDateString()])
            ->get(['user_id', 'total_hours', 'display_group_end'])
            ->each(function ($row) use (&$totals) {
                $anchor = $row->display_group_end->toDateString();
                $totals[$row->user_id][$anchor] = round(
                    ($totals[$row->user_id][$anchor] ?? 0) + (float) $row->total_hours,
                    2,
                );
            });

        return $totals;
    }

    /**
     * Calculate and persist the per-week worked-hours total for a single employee
     * when an admin clicks a Saturday in the spreadsheet.
     *
     * Weeks run Sunday → Saturday (clamped to the month). Clicking a Saturday rolls
     * up every consecutive *uncalculated* prior week into the same display anchor,
     * so pressing week 2 while week 1 is still uncalculated shows the combined
     * week1+week2 total; once week 1 has its own total, week 2 stands alone.
     */
    public function calculateWeekHours(Request $request)
    {
        $this->authorize('create', Attendance::class);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'saturday' => ['required', 'date'],
        ]);

        $userId = (int) $data['user_id'];
        $saturday = Carbon::parse($data['saturday'])->startOfDay();

        if ((int) $saturday->format('w') !== 6) {
            return back()->with('flash', [
                'message' => 'The selected date is not a Saturday.',
                'type' => 'error',
            ]);
        }

        $monthStart = (clone $saturday)->startOfMonth();
        $monthEnd = (clone $saturday)->endOfMonth();

        // Enumerate every Saturday in the month up to and including the clicked one.
        $saturdays = [];
        $cursor = (clone $monthStart);
        while ((int) $cursor->format('w') !== 6) {
            $cursor->addDay();
        }
        while ($cursor->lte($saturday)) {
            $saturdays[] = (clone $cursor);
            $cursor->addDays(7);
        }

        // Saturdays already calculated for this employee in the month.
        $calculatedEnds = AttendanceWeekTotal::query()
            ->where('user_id', $userId)
            ->whereBetween('week_end', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->pluck('week_end')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
        $calculatedSet = array_flip($calculatedEnds);

        // Walk backwards to the most recent already-calculated Saturday; everything
        // after it (and not yet calculated) rolls into this click's display anchor.
        $boundary = (clone $monthStart);
        foreach (array_reverse($saturdays) as $sat) {
            if ($sat->equalTo($saturday)) {
                continue;
            }
            if (isset($calculatedSet[$sat->toDateString()])) {
                $boundary = (clone $sat)->addDay();
                break;
            }
        }

        $included = array_filter(
            $saturdays,
            fn ($sat) => $sat->gte($boundary) && $sat->lte($saturday)
        );

        DB::transaction(function () use ($included, $saturday, $userId) {
            foreach ($included as $sat) {
                // Weeks run Sunday → Saturday and may span the month boundary
                // (e.g. clicking Sat Jul 4 from the June view rolls in Jun 28–30).
                $weekStart = (clone $sat)->startOfWeek(Carbon::SUNDAY);

                $hours = $this->computeWeekHours($userId, $weekStart, $sat);

                // Skip empty weeks (e.g. a month-edge Saturday with no shifts)
                // unless a total was already recorded, so recalculation can still
                // zero out a previously calculated week.
                $existing = AttendanceWeekTotal::query()
                    ->where('user_id', $userId)
                    ->whereDate('week_end', $sat->toDateString())
                    ->exists();

                if ($hours <= 0 && ! $existing) {
                    continue;
                }

                AttendanceWeekTotal::updateOrCreate(
                    ['user_id' => $userId, 'week_end' => $sat->toDateString()],
                    [
                        'week_start' => $weekStart->toDateString(),
                        'total_hours' => $hours,
                        'display_group_end' => $saturday->toDateString(),
                        'calculated_at' => now(),
                        'calculated_by' => auth()->id(),
                    ]
                );
            }
        });

        $this->broadcastSpreadsheetUpdate('week', $userId, $saturday->toDateString());

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Week hours calculated.']);
        }

        return back()->with('flash', [
            'message' => 'Week hours calculated.',
            'type' => 'success',
        ]);
    }

    /**
     * Remove a previously calculated week total. The clicked Saturday is a
     * display anchor (display_group_end); deleting it removes every week that
     * was rolled up under that anchor, clearing the badge entirely.
     */
    public function removeWeekHours(Request $request)
    {
        $this->authorize('create', Attendance::class);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'saturday' => ['required', 'date'],
        ]);

        $userId = (int) $data['user_id'];
        $saturday = Carbon::parse($data['saturday'])->startOfDay();

        $deleted = AttendanceWeekTotal::query()
            ->where('user_id', $userId)
            ->whereDate('display_group_end', $saturday->toDateString())
            ->delete();

        if ($deleted === 0) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'No calculation found to remove.'], 404);
            }

            return back()->with('flash', [
                'message' => 'No calculation found to remove.',
                'type' => 'warning',
            ]);
        }

        $this->broadcastSpreadsheetUpdate('week', $userId, $saturday->toDateString());

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Week hours calculation removed.']);
        }

        return back()->with('flash', [
            'message' => 'Week hours calculation removed.',
            'type' => 'success',
        ]);
    }

    /**
     * Sum worked hours for an employee across an inclusive date range. Duplicate
     * rows for the same day (e.g. an NCNS placeholder plus an imported biometric
     * row) are de-duplicated using the same preference as the grid: rows with
     * biometric data win, otherwise a non-NCNS row wins.
     *
     * Overtime is only paid when approved: a day's worked minutes are capped at
     * 8 hours (480 min) unless that attendance has approved overtime, in which
     * case the full worked time (including overtime) counts. This guards against
     * records where the overtime minutes were never derived but the raw worked
     * time still exceeds the standard shift.
     */
    protected function computeWeekHours(int $userId, Carbon $start, Carbon $end): float
    {
        $rows = DB::table('attendances')
            ->where('user_id', $userId)
            ->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
            ->get(['shift_date', 'status', 'total_minutes_worked', 'actual_time_in', 'actual_time_out', 'overtime_minutes', 'overtime_approved']);

        $byDate = [];
        foreach ($rows as $row) {
            $dateKey = substr((string) $row->shift_date, 0, 10);
            $hasBio = $row->actual_time_in !== null || $row->actual_time_out !== null;
            $isNcns = $row->status === 'ncns';
            $existing = $byDate[$dateKey] ?? null;

            if ($existing === null) {
                $byDate[$dateKey] = $row;

                continue;
            }

            $existingHasBio = $existing->actual_time_in !== null || $existing->actual_time_out !== null;
            $existingIsNcns = $existing->status === 'ncns';

            if (($hasBio && ! $existingHasBio) || ($existingIsNcns && ! $isNcns)) {
                $byDate[$dateKey] = $row;
            }
        }

        $minutes = 0;
        foreach ($byDate as $row) {
            $worked = (int) ($row->total_minutes_worked ?? 0);

            // Cap at 8 hours unless this day's overtime was approved.
            if (! (bool) $row->overtime_approved) {
                $worked = min($worked, 480);
            }

            $minutes += max(0, $worked);
        }

        return round($minutes / 60, 2);
    }

    /**
     * Build a single grid cell from a raw attendance row (stdClass from
     * DB::table). Accepting raw rows + prefetched leave/shift lookups avoids
     * the per-row Eloquent hydration and Carbon-cast cost that previously
     * dominated spreadsheet load time.
     *
     * @param  object  $row  Raw row with attendance columns.
     * @param  string|null  $leaveType  Leave type string (e.g. 'VL') for the linked leave_request, if any.
     * @param  string|null  $shiftType  Shift type string for the linked employee_schedule, if any.
     * @param  string|null  $leaveDayStatus  leave_request_days.day_status for this date, if any.
     */
    protected function buildSpreadsheetCell(object $row, ?string $leaveType = null, ?string $shiftType = null, ?string $leaveDayStatus = null): array
    {
        $totalMinutes = $row->total_minutes_worked !== null ? (int) $row->total_minutes_worked : 0;
        $hours = $totalMinutes ? round($totalMinutes / 60, 2) : null;
        $code = null;
        $kind = 'empty';
        $color = 'default';

        // Raw datetime strings: 'YYYY-MM-DD HH:MM:SS' or null. Avoid Carbon parsing.
        $timeIn = $row->actual_time_in ? substr((string) $row->actual_time_in, 11, 5) : null;
        $timeOut = $row->actual_time_out ? substr((string) $row->actual_time_out, 11, 5) : null;
        $hasBio = $timeIn !== null || $timeOut !== null;
        $adminVerified = (bool) $row->admin_verified;

        $isPartialDaySl = $leaveDayStatus === 'partial_day_absence';

        if ($row->status === 'on_leave' && $leaveType !== null) {
            $code = strtoupper((string) $leaveType);
            $kind = 'leave';
            $color = match ($code) {
                'VL' => 'leave-vl',
                'SL' => 'leave-sl',
                'ML' => 'leave-ml',
                'LOA' => 'leave-loa',
                'UPTO' => 'leave-upto',
                'BL' => 'leave-bl',
                'SPL' => 'leave-spl',
                'LDV' => 'leave-ldv',
                default => 'leave-other',
            };
        } elseif (in_array($row->status, ['ncns', 'advised_absence', 'half_day_absence'], true)) {
            $code = $row->status === 'half_day_absence' ? 'HALF' : 'ABS';
            $kind = 'absent';
            $color = 'absent';
        } elseif ($row->status === 'non_work_day') {
            $kind = 'off';
            $color = 'off';
        } elseif (in_array($row->status, ['failed_bio_in', 'failed_bio_out'], true)) {
            $code = 'BIO';
            $kind = 'bio';
            $color = 'bio';
        } elseif ($row->status === 'needs_manual_review') {
            $code = 'NMR';
            $kind = 'review';
            $color = 'review';
        } elseif ($row->is_partially_verified) {
            $code = 'PART';
            $kind = 'hours';
            $color = 'partial';
        } elseif ($hours !== null) {
            $kind = 'hours';
            $color = $row->status === 'tardy' ? 'hours-tardy' : 'hours-ok';
        } elseif ($hasBio) {
            // Imported biometric data exists but hasn't been verified/processed yet.
            $code = 'BIO';
            $kind = 'bio';
            $color = 'bio';
        } elseif ($isPartialDaySl) {
            // Partial-day Absence (SL with Undertime) approved but no worked hours yet —
            // surface the SL tag so the day isn't blank on the spreadsheet.
            $code = 'P-SL';
            $kind = 'leave';
            $color = 'leave-partial-sl';
        }

        // When the day has actual worked hours, keep the hours number visible —
        // the cyan-tinted background (set below) signals it is a Partial-day SL day.
        if ($isPartialDaySl && $kind === 'hours') {
            $color = 'leave-partial-sl-hours';
        }

        // `warnings` is JSON in the DB; with raw queries we must decode manually.
        $warnings = [];
        if (! empty($row->warnings)) {
            if (is_string($row->warnings)) {
                $decoded = json_decode($row->warnings, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning('Spreadsheet warnings JSON decode failed', [
                        'attendance_id' => $row->id ?? null,
                        'error' => json_last_error_msg(),
                    ]);
                }
            } else {
                $decoded = $row->warnings;
            }
            if (is_array($decoded)) {
                $warnings = $decoded;
            }
        }

        return [
            'attendance_id' => (int) $row->id,
            'kind' => $kind,
            'code' => $code,
            'hours' => $hours,
            'status' => $row->status,
            'secondary_status' => $row->secondary_status,
            'verified' => $adminVerified,
            'color' => $color,
            'actual_time_in' => $timeIn,
            'actual_time_out' => $timeOut,
            'has_bio' => $hasBio,
            'unverified_bio' => $hasBio && ! $adminVerified,
            'tardy_minutes' => (int) ($row->tardy_minutes ?? 0),
            'undertime_minutes' => (int) ($row->undertime_minutes ?? 0),
            'overtime_minutes' => (int) ($row->overtime_minutes ?? 0),
            'overtime_approved' => (bool) $row->overtime_approved,
            'is_set_home' => (bool) $row->is_set_home,
            'is_partially_verified' => (bool) $row->is_partially_verified,
            'undertime_approval_status' => $row->undertime_approval_status,
            'undertime_approval_reason' => $row->undertime_approval_reason,
            'warnings' => $warnings,
            'scheduled_time_in' => $row->scheduled_time_in,
            'scheduled_time_out' => $row->scheduled_time_out,
            'shift_type' => $shiftType,
            'notes' => $row->notes ?? null,
            'verification_notes' => $row->verification_notes ?? null,
            'is_partial_day_sl' => $isPartialDaySl,
        ];
    }

    /**
     * Inline spreadsheet edit. Updates an existing attendance row's hours and/or status.
     * For full leave creation use the leave request workflow. For new shifts use Daily Roster.
     */
    public function updateSpreadsheetCell(Request $request)
    {
        $this->authorize('create', Attendance::class);

        $validated = $request->validate([
            'attendance_id' => ['required', 'integer', 'exists:attendances,id'],
            'status' => ['nullable', AttendanceStatus::validationIn()],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'actual_time_in' => ['nullable', 'date_format:H:i'],
            'actual_time_out' => ['nullable', 'date_format:H:i'],
            'verify' => ['nullable', 'boolean'],
            'overtime_approved' => ['nullable', 'boolean'],
            'is_set_home' => ['nullable', 'boolean'],
            'undertime_approval_action' => ['nullable', 'in:approve,reject,request'],
            'undertime_approval_reason' => ['nullable', 'in:generate_points,skip_points,lunch_used'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'verification_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $attendanceRef = null;
            DB::transaction(function () use ($validated, &$attendanceRef) {
                /** @var Attendance $attendance */
                $attendance = Attendance::findOrFail($validated['attendance_id']);

                // Authorization gate for Team Leads: only their campaigns
                $authUser = auth()->user();
                if ($authUser->role === 'Team Lead') {
                    $campaignIds = $authUser->getCampaignIds();
                    $attendance->loadMissing('employeeSchedule:id,campaign_id');
                    $scheduleCampaign = $attendance->employeeSchedule?->campaign_id;
                    if (! $scheduleCampaign || ! in_array($scheduleCampaign, $campaignIds, true)) {
                        abort(403);
                    }
                }

                $oldStatus = $attendance->status;
                $oldSecondary = $attendance->secondary_status;
                $oldIsSetHome = (bool) $attendance->is_set_home;
                $oldIsAdvised = (bool) $attendance->is_advised;

                if (array_key_exists('hours', $validated) && $validated['hours'] !== null) {
                    $attendance->total_minutes_worked = (int) round(((float) $validated['hours']) * 60);
                }

                if (array_key_exists('actual_time_in', $validated) && $validated['actual_time_in'] !== null) {
                    $attendance->actual_time_in = Carbon::parse($attendance->shift_date->toDateString().' '.$validated['actual_time_in']);
                }
                if (array_key_exists('actual_time_out', $validated) && $validated['actual_time_out'] !== null) {
                    $timeInStr = $validated['actual_time_in'] ?? null;
                    $timeOutStr = $validated['actual_time_out'];
                    // If time_out <= time_in the shift crosses midnight — use next day for time_out
                    $outDate = ($timeInStr && $timeOutStr <= $timeInStr)
                        ? $attendance->shift_date->copy()->addDay()->toDateString()
                        : $attendance->shift_date->toDateString();
                    $attendance->actual_time_out = Carbon::parse($outDate.' '.$timeOutStr);
                }

                if (! empty($validated['status'])) {
                    $attendance->status = $validated['status'];
                    $attendance->is_advised = $validated['status'] === 'advised_absence';
                    // Clearing leave linkage if status moves away from on_leave.
                    // EXCEPTION: a Partial-day Absence day (SL with Undertime) is
                    // intentionally linked to an SL request while keeping a work
                    // status (tardy / present_no_bio / etc.) — never sever that link.
                    if ($validated['status'] !== 'on_leave') {
                        $isPartialDaySl = $attendance->leave_request_id
                            && LeaveRequestDay::where('leave_request_id', $attendance->leave_request_id)
                                ->whereDate('date', $attendance->shift_date->toDateString())
                                ->where('day_status', LeaveRequestDay::STATUS_PARTIAL_DAY_ABSENCE)
                                ->exists();
                        if (! $isPartialDaySl) {
                            $attendance->leave_request_id = null;
                        }
                    }
                    // failed_bio_* is a flag-only status: keep any time-in/out the
                    // user entered so total work hours still get computed.
                }

                if (! empty($validated['verify'])) {
                    $attendance->admin_verified = true;
                    $attendance->is_partially_verified = false;
                }

                // Handle overtime approval
                $overtimeApprovalChanged = false;
                if (array_key_exists('overtime_approved', $validated) && $validated['overtime_approved'] !== null) {
                    $overtimeApprovalChanged = (bool) $validated['overtime_approved'] !== (bool) $attendance->overtime_approved;
                    $attendance->overtime_approved = (bool) $validated['overtime_approved'];
                    if ($validated['overtime_approved']) {
                        $attendance->overtime_approved_at = now();
                        $attendance->overtime_approved_by = auth()->id();
                    } else {
                        $attendance->overtime_approved_at = null;
                        $attendance->overtime_approved_by = null;
                    }
                }

                // Handle sent-home toggle
                if (array_key_exists('is_set_home', $validated) && $validated['is_set_home'] !== null) {
                    $attendance->is_set_home = (bool) $validated['is_set_home'];
                }

                // Handle notes
                if (array_key_exists('notes', $validated)) {
                    $attendance->notes = $validated['notes'];
                }
                if (array_key_exists('verification_notes', $validated)) {
                    $attendance->verification_notes = $validated['verification_notes'];
                }

                // Handle undertime approval action submitted with the cell save
                $forceUndertimeRegen = false;
                if (! empty($validated['undertime_approval_action'])) {
                    $action = $validated['undertime_approval_action'];
                    $reason = $validated['undertime_approval_reason'] ?? 'generate_points';
                    $authUser = auth()->user();

                    if (in_array($action, ['approve', 'reject'], true) &&
                        $this->permissionService->userHasPermission($authUser, 'attendance.approve_undertime')) {
                        $attendance->undertime_approval_status = $action === 'approve' ? 'approved' : 'rejected';
                        $attendance->undertime_approval_reason = $action === 'approve' ? $reason : 'generate_points';
                        $attendance->undertime_approved_by = $authUser->id;
                        $attendance->undertime_approved_at = now();
                        // Force regeneration so the processor can apply/remove the approval:
                        // approve → delete existing undertime points; reject → recreate them.
                        $forceUndertimeRegen = true;
                    } elseif ($action === 'request' &&
                        $this->permissionService->userHasPermission($authUser, 'attendance.request_undertime_approval')) {
                        $attendance->undertime_approval_status = 'pending';
                        $attendance->undertime_approval_reason = $reason;
                        $attendance->undertime_approval_requested_by = $authUser->id;
                        $attendance->undertime_approval_requested_at = now();
                    }
                }

                $attendance->save();

                // Recalculate total hours if OT approval changed and hours weren't manually provided
                $hoursManuallySet = array_key_exists('hours', $validated) && $validated['hours'] !== null;
                if ($overtimeApprovalChanged && ! $hoursManuallySet && $attendance->actual_time_in && $attendance->actual_time_out) {
                    $this->processor->recalculateTotalMinutesWorked($attendance);
                }

                // When the user enters both time-in and time-out without typing
                // an Hours value, derive total_minutes_worked from the times so
                // the week-hours Calc picks them up (covers failed_bio_* edits
                // where the operator filled in the missing biometric manually).
                $timesProvided = array_key_exists('actual_time_in', $validated)
                    && array_key_exists('actual_time_out', $validated)
                    && $validated['actual_time_in'] !== null
                    && $validated['actual_time_out'] !== null;
                if (! $hoursManuallySet && $timesProvided && ! $overtimeApprovalChanged && $attendance->actual_time_in && $attendance->actual_time_out) {
                    $this->processor->recalculateTotalMinutesWorked($attendance);
                }

                // Recompute tardy / undertime / overtime metrics whenever the
                // times change. Without this, a manual edit (e.g. extending
                // actual_time_out beyond the scheduled out) would leave the
                // stored overtime_minutes/undertime_minutes at the stale value.
                if ($timesProvided && $attendance->actual_time_in && $attendance->actual_time_out) {
                    $attendance->loadMissing('employeeSchedule');
                    $metrics = $this->processor->calculateManualAttendanceMetrics(
                        $attendance->employeeSchedule,
                        $attendance->actual_time_in,
                        $attendance->actual_time_out,
                        $attendance->shift_date->toDateString(),
                        $attendance->status,
                        $attendance->secondary_status,
                        null,
                    );
                    $attendance->tardy_minutes = $metrics['tardy_minutes'];
                    $attendance->undertime_minutes = $metrics['undertime_minutes'];
                    $attendance->overtime_minutes = $metrics['overtime_minutes'];
                    $attendance->save();
                }

                $this->regeneratePointsIfNeeded(
                    $attendance,
                    $oldStatus,
                    $oldSecondary,
                    $oldIsSetHome,
                    $oldIsAdvised,
                    $forceUndertimeRegen
                );

                // Partial-day SL: any newly-generated attendance points for this
                // date must be auto-excused if a medical certificate is on file,
                // matching the original approval behavior.
                if ($attendance->leave_request_id) {
                    $this->partialDaySlExcuseService->excuseForAttendance($attendance, auth()->id());
                }

                $attendanceRef = $attendance;
            });

            if ($attendanceRef) {
                $this->broadcastSpreadsheetUpdate(
                    'cell',
                    (int) $attendanceRef->user_id,
                    $attendanceRef->shift_date?->toDateString(),
                );
            }

            return redirect()->back()
                ->with('message', 'Cell updated.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('AttendanceController updateSpreadsheetCell Error: '.$e->getMessage());

            return redirect()->back()
                ->with('message', 'Failed to update cell.')
                ->with('type', 'error');
        }
    }

    /**
     * Create a new attendance row from an empty spreadsheet cell.
     */
    public function createSpreadsheetCell(CreateSpreadsheetCellAttendanceRequest $request)
    {
        $this->authorize('create', Attendance::class);

        $validated = $request->validated();

        try {
            DB::transaction(function () use ($validated) {
                $authUser = auth()->user();

                // Resolve active schedule for this user/date
                $schedule = EmployeeSchedule::where('user_id', $validated['user_id'])
                    ->where('is_active', true)
                    ->where('effective_date', '<=', $validated['shift_date'])
                    ->where(function ($q) use ($validated) {
                        $q->whereNull('end_date')->orWhere('end_date', '>=', $validated['shift_date']);
                    })
                    ->first();

                // Team Lead authorization gate
                if ($authUser->role === 'Team Lead') {
                    $campaignIds = $authUser->getCampaignIds();
                    if (! $schedule || ! in_array($schedule->campaign_id, $campaignIds, true)) {
                        abort(403);
                    }
                }

                // Reject if a row already exists for that user/date.
                // Throw a ValidationException so Inertia surfaces the message
                // via shared flash/errors instead of a raw 422 JSON page
                // (prevents the white error screen on double-click races).
                $existing = Attendance::where('user_id', $validated['user_id'])
                    ->where('shift_date', $validated['shift_date'])
                    ->first();
                if ($existing) {
                    throw ValidationException::withMessages([
                        'shift_date' => 'An attendance record already exists for this date.',
                    ]);
                }

                // Build actual_time_in Carbon — always on shift_date
                $actualTimeIn = ! empty($validated['actual_time_in'])
                    ? Carbon::parse($validated['shift_date'].' '.$validated['actual_time_in'])
                    : null;

                // Build actual_time_out Carbon — next day if crosses midnight (night shift)
                $actualTimeOut = null;
                if (! empty($validated['actual_time_out'])) {
                    $timeInStr = $validated['actual_time_in'] ?? null;
                    $timeOutStr = $validated['actual_time_out'];
                    $outDate = ($timeInStr && $timeOutStr <= $timeInStr)
                        ? Carbon::parse($validated['shift_date'])->addDay()->toDateString()
                        : $validated['shift_date'];
                    $actualTimeOut = Carbon::parse($outDate.' '.$timeOutStr);
                }

                // Resolve leave conflicts so this surface applies the same rules
                // as Manual create and Daily Roster.
                $leaveResolution = $this->leaveConflictResolver->resolveOnAttendanceWrite(
                    (int) $validated['user_id'],
                    $validated['shift_date'],
                    $actualTimeIn,
                    $actualTimeOut,
                    'Spreadsheet',
                );
                $approvedLeave = $leaveResolution['approvedLeave'];
                $hasLeaveConflict = $leaveResolution['hasApprovedConflict'];

                // Auto-determine status, tardy/undertime/overtime using the same logic as Review
                $metrics = $this->processor->calculateManualAttendanceMetrics(
                    $schedule,
                    $actualTimeIn,
                    $actualTimeOut,
                    $validated['shift_date'],
                    null,  // no provided status — let processor decide
                    null,
                    $approvedLeave,
                );

                $status = $metrics['status'] ?? 'non_work_day';
                $secondaryStatus = $metrics['secondary_status'] ?? null;
                $tardyMinutes = $metrics['tardy_minutes'] ?? null;
                $undertimeMinutes = $metrics['undertime_minutes'] ?? null;
                $overtimeMinutes = $metrics['overtime_minutes'] ?? null;

                // Detect partial creation (one of in/out missing) — same rule
                // as Manual create + Daily Roster so all 3 surfaces agree.
                $isPartial = ($actualTimeIn xor $actualTimeOut);

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
                    'total_minutes_worked' => null, // will be recalculated below
                    'overtime_approved' => ! empty($validated['overtime_approved']),
                    'overtime_approved_at' => ! empty($validated['overtime_approved']) ? now() : null,
                    'overtime_approved_by' => ! empty($validated['overtime_approved']) ? auth()->id() : null,
                    'undertime_approval_status' => ! empty($validated['undertime_approval_reason']) ? 'approved' : null,
                    'undertime_approval_reason' => $validated['undertime_approval_reason'] ?? null,
                    'undertime_approved_by' => ! empty($validated['undertime_approval_reason']) ? auth()->id() : null,
                    'undertime_approved_at' => ! empty($validated['undertime_approval_reason']) ? now() : null,
                    'is_set_home' => ! empty($validated['is_set_home']),
                    'admin_verified' => ! $hasLeaveConflict,
                    'is_partially_verified' => $isPartial && ! $hasLeaveConflict,
                    'leave_request_id' => $approvedLeave?->id,
                    'verification_notes' => $hasLeaveConflict
                        ? $leaveResolution['verificationNote']
                        : 'Manually created via spreadsheet by '.auth()->user()->name,
                    'notes' => $hasLeaveConflict ? $leaveResolution['conflictNote'] : ($validated['notes'] ?? null),
                ]);

                // Run the shared finalize pipeline: recalc totals, regenerate points
                // (when admin_verified), and notify the employee.
                $this->attendanceWriteService->finalizeManualWrite($attendance, $validated['shift_date']);
            });

            $this->broadcastSpreadsheetUpdate(
                'cell',
                (int) $validated['user_id'],
                (string) $validated['shift_date'],
            );

            return redirect()->back()
                ->with('message', 'Attendance created.')
                ->with('type', 'success');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('AttendanceController createSpreadsheetCell Error: '.$e->getMessage());

            return redirect()->back()
                ->with('message', 'Failed to create attendance.')
                ->with('type', 'error');
        }
    }

    /**
     * Broadcast a spreadsheet update so other open spreadsheets refresh live.
     */
    protected function broadcastSpreadsheetUpdate(string $type, int $userId, ?string $date): void
    {
        try {
            // toOthers() excludes the socket that triggered this request, using
            // the X-Socket-Id header sent by Echo. That way the originating tab
            // doesn't react to its own broadcast, even when multiple tabs are
            // logged in as the same user.
            broadcast(new AttendanceSpreadsheetUpdated($type, $userId, $date, auth()->id()))->toOthers();
        } catch (\Throwable $e) {
            // Never let a broadcast failure break a mutation request.
            Log::warning('AttendanceSpreadsheetUpdated broadcast failed: '.$e->getMessage());
        }
    }
}
