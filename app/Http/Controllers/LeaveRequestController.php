<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignDayStatusesRequest;
use App\Http\Requests\LeaveRequestRequest;
use App\Mail\LeaveRequestStatusUpdated;
use App\Mail\LeaveRequestSubmitted;
use App\Mail\LeaveRequestTLStatusUpdated;
use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveRequestDeniedDate;
use App\Models\User;
use App\Services\AttendancePoint\GbroCalculationService;
use App\Services\LeaveCreditService;
use App\Services\NotificationService;
use App\Services\PermissionService;
use App\Services\SplCreditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LeaveRequestController extends Controller
{
    protected $leaveCreditService;

    protected $notificationService;

    protected $splCreditService;

    public function __construct(LeaveCreditService $leaveCreditService, NotificationService $notificationService, SplCreditService $splCreditService)
    {
        $this->leaveCreditService = $leaveCreditService;
        $this->notificationService = $notificationService;
        $this->splCreditService = $splCreditService;
    }

    /**
     * Display a listing of leave requests.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $user = auth()->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin', 'HR']);
        $isTeamLead = $user->role === 'Team Lead';

        // Granular role checks for priority sorting
        $isSuperAdmin = $user->role === 'Super Admin';
        $isAdminRole = $user->role === 'Admin';
        $isHr = $user->role === 'HR';

        // Determine Team Lead's campaigns (if applicable)
        $teamLeadCampaignIds = [];
        $teamLeadCampaignNames = [];
        if ($isTeamLead) {
            $teamLeadCampaignIds = $user->getCampaignIds();
            if (! empty($teamLeadCampaignIds)) {
                $teamLeadCampaignNames = Campaign::whereIn('id', $teamLeadCampaignIds)->pluck('name')->toArray();
            }
        }

        $query = LeaveRequest::with(['user', 'reviewer', 'adminApprover', 'hrApprover', 'tlApprover']);

        // Admins see all requests
        if ($isAdmin) {
            // No filter - see all
        } elseif ($isTeamLead && ! empty($teamLeadCampaignNames)) {
            // Team Leads see their own requests + requests from agents in their campaigns
            $query->where(function ($q) use ($user, $teamLeadCampaignNames) {
                $q->where('user_id', $user->id) // Own requests
                    ->orWhereIn('campaign_department', $teamLeadCampaignNames); // Requests from their campaigns
            });
        } elseif ($isTeamLead) {
            // Team Lead with no campaigns assigned - only see own requests
            $query->forUser($user->id);
        } else {
            // Regular employees see only their own
            $query->forUser($user->id);
        }

        // Filter by leave type
        if ($request->filled('type')) {
            $query->byType($request->type);
        }

        // Filter by period (upcoming, past, this_month, this_week)
        if ($request->filled('period')) {
            $today = now()->toDateString();
            match ($request->period) {
                'upcoming' => $query->where('start_date', '>=', $today),
                'past' => $query->where('end_date', '<', $today),
                'this_month' => $query->where(function ($q) {
                    $monthStart = now()->startOfMonth()->toDateString();
                    $monthEnd = now()->endOfMonth()->toDateString();
                    $q->whereBetween('start_date', [$monthStart, $monthEnd])
                        ->orWhereBetween('end_date', [$monthStart, $monthEnd])
                        ->orWhere(function ($q2) use ($monthStart, $monthEnd) {
                            $q2->where('start_date', '<=', $monthStart)
                                ->where('end_date', '>=', $monthEnd);
                        });
                }),
                'this_week' => $query->where(function ($q) {
                    $weekStart = now()->startOfWeek()->toDateString();
                    $weekEnd = now()->endOfWeek()->toDateString();
                    $q->whereBetween('start_date', [$weekStart, $weekEnd])
                        ->orWhereBetween('end_date', [$weekStart, $weekEnd])
                        ->orWhere(function ($q2) use ($weekStart, $weekEnd) {
                            $q2->where('start_date', '<=', $weekStart)
                                ->where('end_date', '>=', $weekEnd);
                        });
                }),
                default => null,
            };
        }

        // Filter by user (admin only)
        if ($isAdmin && $request->filled('user_id')) {
            $query->forUser($request->user_id);
        }

        // Filter by employee name (admin/TL only)
        if (($isAdmin || $isTeamLead) && $request->filled('employee_name')) {
            $query->whereHas('user', function ($q) use ($request) {
                $searchTerm = '%'.$request->employee_name.'%';
                // Search across first_name, middle_name, and last_name
                // Format: "First M. Last" or "First Last"
                $q->where(function ($q2) use ($searchTerm) {
                    $q2->whereRaw("CONCAT(first_name, ' ', COALESCE(CONCAT(middle_name, '. '), ''), last_name) LIKE ?", [$searchTerm])
                        ->orWhere('first_name', 'like', $searchTerm)
                        ->orWhere('last_name', 'like', $searchTerm)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$searchTerm]);
                });
            });
        }

        // Filter by campaign/department - auto-filter for Team Leads
        $campaignFilter = $request->filled('campaign_department') ? $request->campaign_department : null;
        if ($campaignFilter) {
            $query->where('campaign_department', $campaignFilter);
        } elseif ($isTeamLead && ! empty($teamLeadCampaignNames)) {
            $query->whereIn('campaign_department', $teamLeadCampaignNames);
        }

        // Compute status counts before applying the status filter
        $baseQuery = $query->clone();
        $statusCounts = [
            'all' => $baseQuery->clone()->count(),
            'pending' => $baseQuery->clone()->where('status', 'pending')->count(),
            'approved' => $baseQuery->clone()->where('status', 'approved')->count(),
            'denied' => $baseQuery->clone()->where('status', 'denied')->count(),
            'cancelled' => $baseQuery->clone()->where('status', 'cancelled')->count(),
        ];

        // Filter by status (applied after counts are computed)
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        // Role-aware priority sorting:
        // 1. Items needing the logged-in user's approval appear first
        // 2. Among pending, upcoming leaves (start_date >= today) appear before past
        // 3. Upcoming sorted by soonest start_date, then newest created_at
        $today = now()->toDateString();
        $readyForAdminHr = '(requires_tl_approval = 0 OR tl_approved_by IS NOT NULL)';

        if ($isSuperAdmin) {
            // Super Admin: any pending needing admin OR hr approval
            $query->orderByRaw("
                CASE WHEN status = 'pending' AND (admin_approved_by IS NULL OR hr_approved_by IS NULL) AND {$readyForAdminHr} THEN 0 ELSE 1 END ASC
            ");
        } elseif ($isAdminRole) {
            // Admin: pending needing admin approval specifically
            $query->orderByRaw("
                CASE WHEN status = 'pending' AND admin_approved_by IS NULL AND {$readyForAdminHr} THEN 0 ELSE 1 END ASC
            ");
        } elseif ($isHr) {
            // HR: pending needing HR approval specifically
            $query->orderByRaw("
                CASE WHEN status = 'pending' AND hr_approved_by IS NULL AND {$readyForAdminHr} THEN 0 ELSE 1 END ASC
            ");
        } elseif ($isTeamLead) {
            // Team Lead: pending needing TL approval
            $query->orderByRaw("
                CASE WHEN status = 'pending' AND requires_tl_approval = 1 AND tl_approved_by IS NULL AND tl_rejected = 0 THEN 0 ELSE 1 END ASC
            ");
        }

        // Secondary: upcoming pending before past pending, then non-pending last
        $query->orderByRaw("
            CASE WHEN status = 'pending' AND start_date >= ? THEN 0
                 WHEN status = 'pending' THEN 1
                 ELSE 2 END ASC
        ", [$today]);

        // Tertiary: soonest start_date first among upcoming, then newest created_at
        $query->orderBy('start_date', 'asc')
            ->orderBy('created_at', 'desc');

        $leaveRequests = $query->paginate(25)
            ->withQueryString();

        // Get list of campaigns/departments for filters (unique names from campaigns table)
        $campaigns = Campaign::orderBy('name')->pluck('name')->toArray();

        // Get all employees who have leave requests (for admin/TL employee search dropdown)
        $allEmployees = [];
        if ($isAdmin || $isTeamLead) {
            $employeeQuery = User::whereHas('leaveRequests');

            // Team Leads only see employees whose requests they can view
            if ($isTeamLead) {
                $employeeQuery->where(function ($q) use ($user) {
                    $q->where('id', $user->id)
                        ->orWhereHas('leaveRequests', fn ($lq) => $lq->where('requires_tl_approval', true));
                });
            }

            $allEmployees = $employeeQuery
                ->orderByRaw("CONCAT(first_name, ' ', last_name)")
                ->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                ])
                ->toArray();
        }

        // Check if current user has pending leave requests
        $hasPendingRequests = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        return Inertia::render('FormRequest/Leave/Index', [
            'leaveRequests' => $leaveRequests,
            'filters' => $request->only(['status', 'type', 'period', 'user_id', 'employee_name', 'campaign_department']),
            'statusCounts' => $statusCounts,
            'campaigns' => $campaigns,
            'allEmployees' => $allEmployees,
            'isAdmin' => $isAdmin,
            'isTeamLead' => $isTeamLead,
            'teamLeadCampaignNames' => $teamLeadCampaignNames,
            'hasPendingRequests' => $hasPendingRequests,
        ]);
    }

    /**
     * Display the leave calendar page.
     */
    public function calendar(Request $request)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $user = auth()->user();
        $isRestrictedRole = in_array($user->role, ['Agent', 'Utility']);

        // Detect Team Lead's campaigns for auto-filter
        $teamLeadCampaignIds = [];
        if ($user->role === 'Team Lead') {
            $teamLeadCampaignIds = $user->getCampaignIds();
        }

        // Get filters
        $month = $request->input('month', now()->format('Y-m'));
        $campaignId = $request->input('campaign_id');
        $leaveType = $request->input('leave_type');
        $status = $request->input('status'); // 'approved', 'pending', or null for both
        $viewMode = $request->input('view_mode', 'single'); // 'single' or 'multi'

        // Auto-filter campaign for Team Leads when no campaign is specified
        // When no campaign is selected, we leave $campaignId null and use whereIn below

        // Parse month to get date range
        $calendarDate = Carbon::parse($month.'-01');

        // For multi-month view, show 3 months (prev, current, next)
        if ($viewMode === 'multi') {
            $startOfRange = $calendarDate->copy()->subMonth()->startOfMonth();
            $endOfRange = $calendarDate->copy()->addMonth()->endOfMonth();
        } else {
            $startOfRange = $calendarDate->copy()->startOfMonth();
            $endOfRange = $calendarDate->copy()->endOfMonth();
        }

        // Build query for approved and pending leaves
        $query = LeaveRequest::with(['user:id,first_name,last_name,role', 'user.employeeSchedules' => function ($q) {
            $q->where('is_active', true)->with('campaign:id,name');
        }])
            ->when($status, function ($q) use ($status) {
                $q->where('status', $status);
            }, function ($q) {
                $q->whereIn('status', ['approved', 'pending']);
            })
            ->where(function ($q) use ($startOfRange, $endOfRange) {
                $q->whereBetween('start_date', [$startOfRange, $endOfRange])
                    ->orWhereBetween('end_date', [$startOfRange, $endOfRange])
                    ->orWhere(function ($inner) use ($startOfRange, $endOfRange) {
                        $inner->where('start_date', '<=', $startOfRange)
                            ->where('end_date', '>=', $endOfRange);
                    });
            });

        // For agents, filter by their campaign
        if ($isRestrictedRole) {
            $activeSchedule = $user->employeeSchedules()
                ->where('is_active', true)
                ->first();
            if ($activeSchedule?->campaign_id) {
                $query->whereHas('user.employeeSchedules', function ($q) use ($activeSchedule) {
                    $q->where('campaign_id', $activeSchedule->campaign_id)
                        ->where('is_active', true);
                });
            }
        } elseif ($campaignId) {
            // Filter by specific campaign
            $query->whereHas('user.employeeSchedules', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId)
                    ->where('is_active', true);
            });
        } elseif (! empty($teamLeadCampaignIds)) {
            // Team Lead with no campaign selected - show all their campaigns
            $query->whereHas('user.employeeSchedules', function ($q) use ($teamLeadCampaignIds) {
                $q->whereIn('campaign_id', $teamLeadCampaignIds)
                    ->where('is_active', true);
            });
        }

        // Filter by leave type
        if ($leaveType) {
            $query->where('leave_type', $leaveType);
        }

        $leaves = $query->orderBy('created_at')->get()->map(function ($leave) {
            $activeSchedule = $leave->user->employeeSchedules->first();

            return [
                'id' => $leave->id,
                'user_id' => $leave->user_id,
                'user_name' => $leave->user->last_name.', '.$leave->user->first_name,
                'user_role' => $leave->user->role,
                'campaign_name' => $activeSchedule?->campaign?->name ?? 'No Campaign',
                'leave_type' => $leave->leave_type,
                'start_date' => $leave->start_date->format('Y-m-d'),
                'end_date' => $leave->end_date->format('Y-m-d'),
                'days_requested' => $leave->days_requested,
                'reason' => $leave->reason,
                'status' => $leave->status,
                'requested_at' => $leave->created_at->format('Y-m-d H:i'),
            ];
        });

        // Get campaigns for filter (only for non-restricted roles)
        $campaigns = null;
        if (! $isRestrictedRole) {
            $campaigns = Campaign::select('id', 'name')->orderBy('name')->get();
        }

        return Inertia::render('FormRequest/Leave/Calendar', [
            'leaves' => $leaves,
            'campaigns' => $campaigns,
            'teamLeadCampaignIds' => $teamLeadCampaignIds,
            'filters' => [
                'month' => $month,
                'campaign_id' => $campaignId,
                'leave_type' => $leaveType,
                'status' => $status,
                'view_mode' => $viewMode,
            ],
            'isRestrictedRole' => $isRestrictedRole,
        ]);
    }

    /**
     * Show the form for creating a new leave request.
     */
    public function create(Request $request)
    {
        $this->authorize('create', LeaveRequest::class);

        $user = auth()->user();
        $leaveCreditService = $this->leaveCreditService;
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
        $isTeamLead = $user->role === 'Team Lead';
        $canFileForOthers = $isAdmin || $isTeamLead;

        // For admins/team leads creating leave for employees, check if employee_id is provided
        $targetUser = $user;
        if ($canFileForOthers && $request->filled('employee_id') && (int) $request->employee_id !== $user->id) {
            $targetUser = User::findOrFail($request->employee_id);

            // Team Leads can only file for agents in their campaign
            if ($isTeamLead) {
                $teamLeadCampaignIds = $user->getCampaignIds();
                $targetCampaignId = $targetUser->activeSchedule?->campaign_id;

                if (empty($teamLeadCampaignIds) || ! in_array($targetCampaignId, $teamLeadCampaignIds) || $targetUser->role !== 'Agent') {
                    abort(403, 'You can only file leave requests for agents in your campaign.');
                }
            }
        }

        // Check if user has pending leave requests
        $hasPendingRequests = LeaveRequest::where('user_id', $targetUser->id)
            ->where('status', 'pending')
            ->exists();

        // Auto-backfill missing credits if any
        $leaveCreditService->backfillCredits($targetUser);

        // Get leave credits summary
        $creditsSummary = $leaveCreditService->getSummary($targetUser);

        // Get attendance points total
        $attendancePoints = $leaveCreditService->getAttendancePoints($targetUser);

        // Get detailed attendance violations
        $attendanceViolations = AttendancePoint::where('user_id', $targetUser->id)
            ->where('is_expired', false)
            ->where('is_excused', false)
            ->orderBy('shift_date', 'desc')
            ->get()
            ->map(function ($point) {
                return [
                    'id' => $point->id,
                    'shift_date' => $point->shift_date,
                    'point_type' => $point->point_type,
                    'points' => $point->points,
                    'violation_details' => $point->violation_details,
                    'expires_at' => $point->expires_at,
                ];
            });

        // Check if user has recent absence
        $hasRecentAbsence = $leaveCreditService->hasRecentAbsence($targetUser);
        $nextEligibleLeaveDate = $hasRecentAbsence
            ? $leaveCreditService->getNextEligibleLeaveDate($targetUser)
            : null;

        // Get last absence date for 30-day window prompt
        $lastAbsenceDate = $leaveCreditService->getLastAbsenceDate($targetUser);

        // Campaign/Department options - fetch from database + add Management
        $campaignsFromDb = Campaign::orderBy('name')->pluck('name')->toArray();
        $campaigns = array_merge(['Management (For TL/Admin)'], $campaignsFromDb);

        // Get employee's campaign from active schedule
        $selectedCampaign = null;
        $activeSchedule = $targetUser->activeSchedule;
        if ($activeSchedule && $activeSchedule->campaign) {
            $selectedCampaign = $activeSchedule->campaign->name;
        }

        // Get employees for admin/team lead selection
        $employees = [];
        if ($isAdmin) {
            $employees = User::select('id', 'first_name', 'middle_name', 'last_name', 'email')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(function ($emp) {
                    return [
                        'id' => $emp->id,
                        'name' => $emp->name,
                        'email' => $emp->email,
                    ];
                });
        } elseif ($isTeamLead) {
            // Team Leads can file for themselves + agents in their campaigns
            $teamLeadCampaignIds = $user->getCampaignIds();

            // Always include the Team Lead themselves first
            $selfEntry = collect([[
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]]);

            if (! empty($teamLeadCampaignIds)) {
                $agents = User::select('id', 'first_name', 'middle_name', 'last_name', 'email')
                    ->where('role', 'Agent')
                    ->where('is_approved', true)
                    ->whereHas('activeSchedule', function ($query) use ($teamLeadCampaignIds) {
                        $query->whereIn('campaign_id', $teamLeadCampaignIds);
                    })
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get()
                    ->map(function ($emp) {
                        return [
                            'id' => $emp->id,
                            'name' => $emp->name,
                            'email' => $emp->email,
                        ];
                    });

                $employees = $selfEntry->merge($agents);
            } else {
                $employees = $selfEntry;
            }
        }

        // Calculate two weeks from now for 2-week notice validation
        $twoWeeksFromNow = now()->addWeeks(2)->format('Y-m-d');

        // Check if user can override short notice (Admin/Super Admin only)
        $canOverrideShortNotice = in_array($user->role, ['Super Admin', 'Admin']);

        // Get existing pending/approved leave requests for overlap validation
        $existingLeaveRequests = LeaveRequest::where('user_id', $targetUser->id)
            ->whereIn('status', ['pending', 'approved'])
            ->get(['id', 'leave_type', 'start_date', 'end_date', 'status'])
            ->map(function ($req) {
                return [
                    'id' => $req->id,
                    'leave_type' => $req->leave_type,
                    'start_date' => $req->start_date->format('Y-m-d'),
                    'end_date' => $req->end_date->format('Y-m-d'),
                    'status' => $req->status,
                ];
            });

        return Inertia::render('FormRequest/Leave/Create', [
            'creditsSummary' => $creditsSummary,
            'attendancePoints' => $attendancePoints,
            'attendanceViolations' => $attendanceViolations,
            'hasRecentAbsence' => $hasRecentAbsence,
            'hasPendingRequests' => $hasPendingRequests,
            'nextEligibleLeaveDate' => $nextEligibleLeaveDate,
            'lastAbsenceDate' => $lastAbsenceDate?->format('Y-m-d'),
            'campaigns' => $campaigns,
            'selectedCampaign' => $selectedCampaign,
            'twoWeeksFromNow' => $twoWeeksFromNow,
            'canFileForOthers' => $canFileForOthers,
            'employees' => $employees,
            'selectedEmployeeId' => $targetUser->id,
            'canOverrideShortNotice' => $canOverrideShortNotice,
            'existingLeaveRequests' => $existingLeaveRequests,
            'splCreditsSummary' => $targetUser->is_solo_parent ? $this->splCreditService->getSummary($targetUser) : null,
            'isSoloParent' => (bool) $targetUser->is_solo_parent,
        ]);
    }

    /**
     * Store a newly created leave request.
     */
    public function store(LeaveRequestRequest $request)
    {
        $user = auth()->user();
        $leaveCreditService = $this->leaveCreditService;

        // For admins/team leads, allow creating leave for other employees
        $targetUser = $user;
        if (in_array($user->role, ['Super Admin', 'Admin', 'Team Lead']) && $request->filled('employee_id') && (int) $request->employee_id !== $user->id) {
            $targetUser = User::findOrFail($request->employee_id);

            // Team Leads can only file for agents in their campaign
            if ($user->role === 'Team Lead') {
                $teamLeadCampaignIds = $user->getCampaignIds();
                $targetCampaignId = $targetUser->activeSchedule?->campaign_id;

                if (empty($teamLeadCampaignIds) || ! in_array($targetCampaignId, $teamLeadCampaignIds) || $targetUser->role !== 'Agent') {
                    return back()->withErrors(['error' => 'You can only file leave requests for agents in your campaign.'])->withInput();
                }
            }
        }

        // Check if user has a duplicate pending leave request
        // (same dates, same leave type, same person)
        $hasDuplicateRequest = LeaveRequest::where('user_id', $targetUser->id)
            ->where('status', 'pending')
            ->where('leave_type', $request->leave_type)
            ->where('start_date', $request->start_date)
            ->where('end_date', $request->end_date)
            ->exists();

        if ($hasDuplicateRequest) {
            return back()->withErrors(['error' => 'You already have a pending leave request with the same dates and leave type. Please edit or cancel the existing request instead.'])->withInput();
        }

        // Check for overlapping dates with existing pending or approved leave requests
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $overlappingRequest = LeaveRequest::where('user_id', $targetUser->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($query) use ($startDate, $endDate) {
                // Check if any existing request overlaps with the new date range
                $query->where(function ($q) use ($startDate, $endDate) {
                    // New request starts during existing request
                    $q->where('start_date', '<=', $endDate)
                        ->where('end_date', '>=', $startDate);
                });
            })
            ->first();

        if ($overlappingRequest) {
            $existingStart = Carbon::parse($overlappingRequest->start_date)->format('M d, Y');
            $existingEnd = Carbon::parse($overlappingRequest->end_date)->format('M d, Y');
            $status = ucfirst($overlappingRequest->status);

            return back()->withErrors(['error' => "The selected dates overlap with an existing {$status} leave request ({$overlappingRequest->leave_type}: {$existingStart} to {$existingEnd}). Please choose different dates."])->withInput();
        }

        // Calculate days
        $daysRequested = $leaveCreditService->calculateDays($startDate, $endDate);

        // For SPL: validate solo parent status and calculate days accounting for half-days
        if ($request->leave_type === 'SPL') {
            $splErrors = $this->splCreditService->validateSplRequest($targetUser);
            if (! empty($splErrors)) {
                return back()->withErrors(['validation' => $splErrors])->withInput();
            }

            // If SPL day settings provided, recalculate days_requested with half-day support
            if ($request->has('spl_day_settings') && is_array($request->input('spl_day_settings'))) {
                $splDaySettings = $request->input('spl_day_settings');
                $daysRequested = 0;
                foreach ($splDaySettings as $daySetting) {
                    $daysRequested += ! empty($daySetting['is_half_day']) ? 0.5 : 1.0;
                }
            }
        }

        // Get current attendance points
        $attendancePoints = $leaveCreditService->getAttendancePoints($targetUser);

        // Check if short notice override is requested (Admin/Super Admin only)
        $shortNoticeOverride = false;
        $shortNoticeOverrideBy = null;
        if ($request->boolean('short_notice_override') && in_array($user->role, ['Super Admin', 'Admin'])) {
            $shortNoticeOverride = true;
            $shortNoticeOverrideBy = $user->id;
        }

        // Prepare data for validation
        $validationData = array_merge($request->validated(), [
            'days_requested' => $daysRequested,
            'credits_year' => now()->year,
            'short_notice_override' => $shortNoticeOverride,
        ]);

        // Validate business rules
        $validation = $leaveCreditService->validateLeaveRequest($targetUser, $validationData);

        if (! $validation['valid']) {
            return back()->withErrors(['validation' => $validation['errors']])->withInput();
        }

        DB::beginTransaction();
        try {
            // Determine if this request requires Team Lead approval
            // Agents need TL approval only if their campaign has a Team Lead
            $requiresTlApproval = false;
            $campaignHasTeamLead = false;

            if ($targetUser->role === 'Agent') {
                $agentSchedule = $targetUser->activeSchedule;
                if ($agentSchedule && $agentSchedule->campaign_id) {
                    // Check if there's a Team Lead in the agent's campaign
                    $campaignHasTeamLead = EmployeeSchedule::where('campaign_id', $agentSchedule->campaign_id)
                        ->where('is_active', true)
                        ->whereHas('user', function ($q) {
                            $q->where('role', 'Team Lead')->where('is_approved', true);
                        })
                        ->exists();

                    if ($campaignHasTeamLead) {
                        $requiresTlApproval = true;
                    }
                }
            }

            // Handle medical certificate/document file upload for Sick Leave, Bereavement Leave, and UPTO
            $medicalCertPath = null;
            if (in_array($request->leave_type, ['SL', 'BL', 'UPTO']) && $request->hasFile('medical_cert_file')) {
                $file = $request->file('medical_cert_file');
                $filename = 'medcert_'.$targetUser->id.'_'.time().'.'.$file->getClientOriginalExtension();
                $medicalCertPath = $file->storeAs('medical_certificates', $filename, 'local');
            }

            // Create leave request
            // Store credits_year at creation time so it's preserved through approval
            $leaveRequest = LeaveRequest::create([
                'user_id' => $targetUser->id,
                'leave_type' => $request->leave_type,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days_requested' => $daysRequested,
                'reason' => $request->reason,
                'campaign_department' => $request->campaign_department,
                'medical_cert_submitted' => $request->boolean('medical_cert_submitted', false),
                'medical_cert_path' => $medicalCertPath,
                'status' => 'pending',
                'attendance_points_at_request' => $attendancePoints,
                'requires_tl_approval' => $requiresTlApproval,
                'credits_year' => now()->year,
                // Short notice override tracking
                'short_notice_override' => $shortNoticeOverride,
                'short_notice_override_by' => $shortNoticeOverrideBy,
                'short_notice_override_at' => $shortNoticeOverride ? now() : null,
            ]);

            // For SPL: create per-day records at submission time with is_half_day setting
            if ($request->leave_type === 'SPL' && $request->has('spl_day_settings') && is_array($request->input('spl_day_settings'))) {
                foreach ($request->input('spl_day_settings') as $daySetting) {
                    LeaveRequestDay::create([
                        'leave_request_id' => $leaveRequest->id,
                        'date' => $daySetting['date'],
                        'day_status' => 'pending',
                        'is_half_day' => ! empty($daySetting['is_half_day']),
                    ]);
                }
            }

            // Notify based on whether TL approval is required
            if ($requiresTlApproval) {
                // Notify all Team Leads (any TL can approve the request)
                $allTeamLeads = User::where('role', 'Team Lead')
                    ->where('is_approved', true)
                    ->get();

                foreach ($allTeamLeads as $teamLead) {
                    $this->notificationService->notifyTeamLeadAboutNewLeaveRequest(
                        $teamLead->id,
                        $targetUser->name,
                        $request->leave_type,
                        $startDate->format('Y-m-d'),
                        $endDate->format('Y-m-d'),
                        $leaveRequest->id
                    );
                }
            } else {
                // Notify HR/Admin users about new leave request
                // This happens when: non-agent submits, or agent's campaign has no Team Lead
                $this->notificationService->notifyHrRolesAboutNewLeaveRequest(
                    $targetUser->name,
                    $request->leave_type,
                    $startDate->format('Y-m-d'),
                    $endDate->format('Y-m-d'),
                    $leaveRequest->id
                );

                // Notify HR/Admin users about new leave request (alternative method)
                $hrAdminUsers = User::whereIn('role', ['Super Admin', 'Admin', 'HR'])->get();
                foreach ($hrAdminUsers as $admin) {
                    $this->notificationService->notifyLeaveRequest(
                        $admin->id,
                        $targetUser->name,
                        $request->leave_type,
                        $leaveRequest->id
                    );
                }
            }

            DB::commit();

            // Notify agent if VL credits are insufficient (informational)
            if ($leaveRequest->leave_type === 'VL' && ($validation['insufficient_vl_credits'] ?? false)) {
                $this->notificationService->notifyLeaveRequestInsufficientVlCredits(
                    $targetUser->id,
                    $daysRequested,
                    $leaveRequest->id
                );
            }

            // Send confirmation email to the employee (outside transaction to prevent rollback on mail failure)
            try {
                if ($targetUser->email && filter_var($targetUser->email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($targetUser->email)->send(new LeaveRequestSubmitted($leaveRequest, $targetUser));
                }
            } catch (\Exception $mailException) {
                \Log::warning('Failed to send leave request confirmation email', [
                    'error' => $mailException->getMessage(),
                    'leave_request_id' => $leaveRequest->id,
                    'user_email' => $targetUser->email,
                ]);
                // Don't fail the request just because email failed
            }

            $flashMessage = 'Leave request submitted successfully.';
            if ($leaveRequest->leave_type === 'VL' && ($validation['insufficient_vl_credits'] ?? false)) {
                $flashMessage .= ' Note: You have insufficient VL credits. Some days may be converted to UPTO (Unpaid Time Off) upon approval.';
            }

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', $flashMessage);
        } catch (\Exception $e) {
            DB::rollBack();

            // Log the actual error for debugging
            \Log::error('Leave request submission failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id,
                'target_user_id' => $targetUser->id,
                'request_data' => $request->all(),
            ]);

            return back()->withErrors(['error' => 'Failed to submit leave request. Please try again.'])->withInput();
        }
    }

    /**
     * Display the specified leave request.
     */
    public function show(LeaveRequest $leaveRequest)
    {
        $this->authorize('view', $leaveRequest);

        $user = auth()->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin', 'HR']);
        $isTeamLead = $user->role === 'Team Lead';

        $leaveRequest->load(['user', 'reviewer', 'adminApprover', 'hrApprover', 'tlApprover', 'deniedDates.denier', 'days.assigner']);

        // Check if current user has already approved
        $hasUserApproved = false;
        if (in_array($user->role, ['Super Admin', 'Admin']) && $leaveRequest->isAdminApproved()) {
            $hasUserApproved = true;
        } elseif ($user->role === 'HR' && $leaveRequest->isHrApproved()) {
            $hasUserApproved = true;
        }

        // Check if Team Lead can approve this request
        $canTlApprove = false;
        if ($isTeamLead && $leaveRequest->requiresTlApproval() && ! $leaveRequest->isTlApproved() && ! $leaveRequest->isTlRejected()) {
            // Any Team Lead can approve agent leave requests
            $canTlApprove = true;
        }

        // Format dates to prevent timezone issues
        $leaveRequestData = $leaveRequest->toArray();
        $leaveRequestData['start_date'] = $leaveRequest->start_date->format('Y-m-d');
        $leaveRequestData['end_date'] = $leaveRequest->end_date->format('Y-m-d');

        // Format original dates when they exist (set during partial denial)
        if ($leaveRequest->original_start_date) {
            $leaveRequestData['original_start_date'] = $leaveRequest->original_start_date->format('Y-m-d');
        }
        if ($leaveRequest->original_end_date) {
            $leaveRequestData['original_end_date'] = $leaveRequest->original_end_date->format('Y-m-d');
        }

        // Get campaign_id from user's active employee schedule
        $activeSchedule = $leaveRequest->user->employeeSchedules()
            ->where('is_active', true)
            ->first();
        $leaveRequestData['campaign_id'] = $activeSchedule?->campaign_id;

        // Check if leave end date has passed (cannot modify past leaves)
        $leaveEndDatePassed = $leaveRequest->end_date && $leaveRequest->end_date->endOfDay()->isPast();

        // Check if privileged roles (Super Admin, Admin, HR, Team Lead) can cancel leave
        // Privileged roles can cancel pending/approved leaves, but NOT fully approved leaves with past end dates
        $isApprovedPastDate = $leaveRequest->status === 'approved' && ! $leaveRequest->has_partial_denial && $leaveEndDatePassed;
        $canAdminCancel = in_array($user->role, ['Super Admin', 'Admin', 'HR', 'Team Lead'])
            && in_array($leaveRequest->status, ['pending', 'approved'])
            && ! $isApprovedPastDate
            && app(PermissionService::class)->userHasPermission($user, 'leave.cancel');

        // Check if Admin/Super Admin can edit approved leave dates (not if dates passed)
        $canEditApproved = in_array($user->role, ['Super Admin', 'Admin'])
            && $leaveRequest->status === 'approved'
            && ! $leaveEndDatePassed;

        // Check if user can view medical certificate (own request OR Admin, HR, Super Admin)
        $canViewMedicalCert = $leaveRequest->user_id === $user->id || in_array($user->role, ['Super Admin', 'Admin', 'HR']);

        // Get earlier conflicts for VL/UPTO (first-come-first-serve)
        $earlierConflicts = $this->getEarlierConflicts($leaveRequest);

        // Check 30-day absence window for VL
        $absenceWindowInfo = null;
        if ($leaveRequest->leave_type === 'VL') {
            $leaveCreditService = app(LeaveCreditService::class);
            $absenceWindowInfo = $leaveCreditService->checkAbsenceWindowForDate(
                $leaveRequest->user,
                $leaveRequest->start_date
            );
        }

        // Get attendance points that were active AT THE TIME of the leave request submission
        // This includes points where:
        // 1. The shift_date (violation date) was before the request was submitted AND
        // 2. Either are still active OR were excused/expired AFTER the request was submitted
        $requestSubmittedAt = $leaveRequest->created_at;

        $activeAttendancePoints = AttendancePoint::where('user_id', $leaveRequest->user_id)
            ->where('shift_date', '<', $requestSubmittedAt)
            ->where(function ($query) use ($requestSubmittedAt) {
                $query->where(function ($q) {
                    // Currently active (not excused, not expired)
                    $q->where('is_excused', false)->where('is_expired', false);
                })
                    ->orWhere(function ($q) use ($requestSubmittedAt) {
                        // Was excused AFTER the request was submitted
                        $q->where('is_excused', true)
                            ->where('excused_at', '>', $requestSubmittedAt);
                    })
                    ->orWhere(function ($q) use ($requestSubmittedAt) {
                        // Was expired AFTER the request was submitted
                        $q->where('is_expired', true)
                            ->where('expired_at', '>', $requestSubmittedAt);
                    });
            })
            ->orderBy('shift_date', 'desc')
            ->get()
            ->map(function ($point) {
                // Determine status at request time
                $wasActiveAtRequest = true;
                $currentStatus = 'active';

                if ($point->is_excused) {
                    $currentStatus = 'excused';
                } elseif ($point->is_expired) {
                    $currentStatus = 'expired';
                }

                return [
                    'id' => $point->id,
                    'shift_date' => $point->shift_date,
                    'point_type' => $point->point_type,
                    'points' => $point->points,
                    'violation_details' => $point->violation_details,
                    'expires_at' => $point->expires_at,
                    'gbro_expires_at' => $point->gbro_expires_at,
                    'eligible_for_gbro' => $point->eligible_for_gbro,
                    'current_status' => $currentStatus,
                    'excused_at' => $point->excused_at,
                    'expired_at' => $point->expired_at,
                ];
            });

        // Credit split preview for VL/SL pending requests (shown in approval dialog)
        $creditPreview = null;
        if ($leaveRequest->status === 'pending' && $isAdmin) {
            $leaveCreditService = app(LeaveCreditService::class);
            if ($leaveRequest->leave_type === 'VL') {
                $creditPreview = $leaveCreditService->checkVlCreditDeduction($leaveRequest->user, $leaveRequest);
            } elseif ($leaveRequest->leave_type === 'SL') {
                $creditPreview = $leaveCreditService->checkSlCreditDeduction($leaveRequest->user, $leaveRequest);
            }
        }

        // SPL credit preview for SPL pending requests
        $splCreditPreview = null;
        $splCreditsSummary = null;
        if ($leaveRequest->leave_type === 'SPL') {
            $splCreditsSummary = $this->splCreditService->getSummary($leaveRequest->user);
            if ($leaveRequest->status === 'pending' && $isAdmin) {
                $splCreditPreview = $this->splCreditService->checkSplCreditDeduction(
                    $leaveRequest->user,
                    (float) $leaveRequest->days_requested
                );
            }
        }

        // Per-day statuses for SL, VL, and SPL requests (existing assigned days)
        $leaveRequestDays = null;
        $suggestedDayStatuses = null;

        if (in_array($leaveRequest->leave_type, ['SL', 'VL', 'SPL'])) {
            // Load existing per-day statuses if any
            $leaveRequestDays = $leaveRequest->days->map(function ($day) {
                return [
                    'id' => $day->id,
                    'date' => $day->date->format('Y-m-d'),
                    'day_status' => $day->day_status,
                    'is_half_day' => (bool) $day->is_half_day,
                    'notes' => $day->notes,
                    'status_label' => $day->getStatusLabel(),
                    'is_paid' => $day->isPaid(),
                    'credit_value' => $day->getCreditValue(),
                    'assigned_by' => $day->assigner?->name,
                    'assigned_by_role' => $day->assigner?->role,
                    'assigned_at' => $day->assigned_at?->format('Y-m-d H:i'),
                ];
            });

            // For pending SL/VL requests, generate suggested day statuses for the approval UI
            // SPL uses auto-FIFO at approval time — no suggested statuses needed
            if ($leaveRequest->status === 'pending' && $isAdmin && in_array($leaveRequest->leave_type, ['SL', 'VL'])) {
                $startDate = Carbon::parse($leaveRequest->start_date);
                $endDate = Carbon::parse($leaveRequest->end_date);

                $deniedDates = [];
                if ($leaveRequest->has_partial_denial) {
                    $deniedDates = $leaveRequest->deniedDates()
                        ->pluck('denied_date')
                        ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                        ->toArray();
                }

                $allDates = [];
                $currentDate = $startDate->copy();
                while ($currentDate->lte($endDate)) {
                    $dateStr = $currentDate->format('Y-m-d');
                    if (! in_array($dateStr, $deniedDates)) {
                        $allDates[] = $dateStr;
                    }
                    $currentDate->addDay();
                }

                $leaveCreditService = app(LeaveCreditService::class);

                if ($leaveRequest->leave_type === 'SL') {
                    $suggestedDayStatuses = $this->autoAssignSlDayStatuses($leaveRequest, $leaveCreditService, $allDates)
                        ->values()
                        ->toArray();
                } elseif ($leaveRequest->leave_type === 'VL') {
                    $suggestedDayStatuses = $this->autoAssignVlDayStatuses($leaveRequest, $leaveCreditService, $allDates)
                        ->values()
                        ->toArray();
                }
            }
        }

        return Inertia::render('FormRequest/Leave/Show', [
            'leaveRequest' => $leaveRequestData,
            'isAdmin' => $isAdmin,
            'isTeamLead' => $isTeamLead,
            'isSuperAdmin' => $user->role === 'Super Admin',
            'canCancel' => ($leaveRequest->canBeCancelled() && $leaveRequest->user_id === $user->id),
            'canAdminCancel' => $canAdminCancel,
            'canEditApproved' => $canEditApproved,
            'hasUserApproved' => $hasUserApproved,
            'canTlApprove' => $canTlApprove,
            'userRole' => $user->role,
            'canViewMedicalCert' => $canViewMedicalCert,
            'earlierConflicts' => $earlierConflicts,
            'absenceWindowInfo' => $absenceWindowInfo,
            'activeAttendancePoints' => $activeAttendancePoints,
            'creditPreview' => $creditPreview,
            'splCreditPreview' => $splCreditPreview,
            'splCreditsSummary' => $splCreditsSummary,
            'leaveRequestDays' => $leaveRequestDays,
            'suggestedDayStatuses' => $suggestedDayStatuses,
        ]);
    }

    /**
     * View medical certificate for a leave request.
     * Only accessible by Admin, HR, Super Admin.
     */
    public function viewMedicalCert(LeaveRequest $leaveRequest)
    {
        $user = auth()->user();

        // Only the request owner OR Admin/HR/Super Admin can view medical certificates
        if ($leaveRequest->user_id !== $user->id && ! in_array($user->role, ['Super Admin', 'Admin', 'HR'])) {
            abort(403, 'Unauthorized to view medical certificate.');
        }

        if (! $leaveRequest->medical_cert_path) {
            abort(404, 'Medical certificate not found.');
        }

        $path = storage_path('app/private/'.$leaveRequest->medical_cert_path);

        if (! file_exists($path)) {
            abort(404, 'Medical certificate file not found.');
        }

        return response()->file($path);
    }

    /**
     * Show the form for editing the specified leave request.
     */
    public function edit(LeaveRequest $leaveRequest)
    {
        $this->authorize('update', $leaveRequest);

        $user = auth()->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
        $isHR = $user->role === 'HR';
        $isApprovedLeave = $leaveRequest->status === 'approved';

        // Only Admin/Super Admin can edit approved leaves
        if ($isApprovedLeave && ! $isAdmin) {
            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('error', 'Only Admin or Super Admin can edit approved leave requests.');
        }

        // For non-admins, only pending requests can be edited
        if (! $isAdmin && $leaveRequest->status !== 'pending') {
            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('error', 'Only pending leave requests can be edited.');
        }

        $targetUser = $leaveRequest->user;
        $leaveCreditService = $this->leaveCreditService;

        // Auto-backfill missing credits if any
        $leaveCreditService->backfillCredits($targetUser);

        // Get leave credits summary
        $creditsSummary = $leaveCreditService->getSummary($targetUser);

        // Get attendance points total
        $attendancePoints = $leaveCreditService->getAttendancePoints($targetUser);

        // Get detailed attendance violations
        $attendanceViolations = AttendancePoint::where('user_id', $targetUser->id)
            ->where('is_expired', false)
            ->where('is_excused', false)
            ->orderBy('shift_date', 'desc')
            ->get()
            ->map(function ($point) {
                return [
                    'id' => $point->id,
                    'shift_date' => $point->shift_date,
                    'point_type' => $point->point_type,
                    'points' => $point->points,
                    'violation_details' => $point->violation_details,
                    'expires_at' => $point->expires_at,
                ];
            });

        // Check if user has recent absence
        $hasRecentAbsence = $leaveCreditService->hasRecentAbsence($targetUser);
        $nextEligibleLeaveDate = $hasRecentAbsence
            ? $leaveCreditService->getNextEligibleLeaveDate($targetUser)
            : null;

        // Get last absence date for 30-day window prompt
        $lastAbsenceDate = $leaveCreditService->getLastAbsenceDate($targetUser);

        // Campaign/Department options
        $campaignsFromDb = Campaign::orderBy('name')->pluck('name')->toArray();
        $campaigns = array_merge(['Management (For TL/Admin)'], $campaignsFromDb);

        // Calculate two weeks from the date the request was filed (created_at), not from today
        // This prevents false short notice warnings when editing an old request
        $twoWeeksFromNow = $leaveRequest->created_at->copy()->addWeeks(2)->format('Y-m-d');

        // Check if user can override short notice (Admin/Super Admin only)
        $canOverrideShortNotice = in_array($user->role, ['Super Admin', 'Admin']);

        $leaveRequest->load('user');

        // Format dates to prevent timezone issues
        $leaveRequestData = $leaveRequest->toArray();
        $leaveRequestData['start_date'] = $leaveRequest->start_date->format('Y-m-d');
        $leaveRequestData['end_date'] = $leaveRequest->end_date->format('Y-m-d');
        if ($leaveRequest->original_start_date) {
            $leaveRequestData['original_start_date'] = $leaveRequest->original_start_date->format('Y-m-d');
        }
        if ($leaveRequest->original_end_date) {
            $leaveRequestData['original_end_date'] = $leaveRequest->original_end_date->format('Y-m-d');
        }

        // Get existing pending/approved leave requests for overlap validation (exclude current request)
        $existingLeaveRequests = LeaveRequest::where('user_id', $targetUser->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where('id', '!=', $leaveRequest->id)
            ->get(['id', 'leave_type', 'start_date', 'end_date', 'status'])
            ->map(function ($req) {
                return [
                    'id' => $req->id,
                    'leave_type' => $req->leave_type,
                    'start_date' => $req->start_date->format('Y-m-d'),
                    'end_date' => $req->end_date->format('Y-m-d'),
                    'status' => $req->status,
                ];
            });

        // Load existing SPL day settings if this is an SPL leave request
        $splDaySettings = [];
        if ($leaveRequest->leave_type === 'SPL') {
            $splDaySettings = $leaveRequest->days()->orderBy('date')->get()->map(function ($day) {
                return [
                    'date' => Carbon::parse($day->date)->format('Y-m-d'),
                    'is_half_day' => (bool) $day->is_half_day,
                ];
            })->values()->toArray();
        }

        return Inertia::render('FormRequest/Leave/Edit', [
            'leaveRequest' => $leaveRequestData,
            'creditsSummary' => $creditsSummary,
            'attendancePoints' => $attendancePoints,
            'attendanceViolations' => $attendanceViolations,
            'hasRecentAbsence' => $hasRecentAbsence,
            'nextEligibleLeaveDate' => $nextEligibleLeaveDate,
            'lastAbsenceDate' => $lastAbsenceDate?->format('Y-m-d'),
            'campaigns' => $campaigns,
            'twoWeeksFromNow' => $twoWeeksFromNow,
            'isAdmin' => $isAdmin,
            'isApprovedLeave' => $isApprovedLeave,
            'canOverrideShortNotice' => $canOverrideShortNotice,
            'existingLeaveRequests' => $existingLeaveRequests,
            'splDaySettings' => $splDaySettings,
        ]);
    }

    /**
     * Update the specified leave request.
     */
    public function update(LeaveRequestRequest $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('update', $leaveRequest);

        $user = auth()->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
        $isApprovedLeave = $leaveRequest->status === 'approved';

        // For approved leaves, only Admin/Super Admin can update (for date changes)
        if ($isApprovedLeave) {
            if (! $isAdmin) {
                return back()->withErrors(['error' => 'Only Admin or Super Admin can edit approved leave requests.']);
            }

            // Redirect to updateApproved method
            return $this->updateApproved($request, $leaveRequest);
        }

        // Check for overlapping dates with existing pending or approved leave requests (exclude current request)
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $overlappingRequest = LeaveRequest::where('user_id', $leaveRequest->user_id)
            ->where('id', '!=', $leaveRequest->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate);
            })
            ->first();

        if ($overlappingRequest) {
            $existingStart = Carbon::parse($overlappingRequest->start_date)->format('M d, Y');
            $existingEnd = Carbon::parse($overlappingRequest->end_date)->format('M d, Y');
            $status = ucfirst($overlappingRequest->status);

            return back()->withErrors(['error' => "The selected dates overlap with an existing {$status} leave request ({$overlappingRequest->leave_type}: {$existingStart} to {$existingEnd}). Please choose different dates."])->withInput();
        }

        // For non-admins, only pending requests can be edited
        if (! $isAdmin && $leaveRequest->status !== 'pending') {
            return back()->withErrors(['error' => 'Only pending leave requests can be edited.']);
        }

        $targetUser = $leaveRequest->user;
        $leaveCreditService = $this->leaveCreditService;

        // Calculate days
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $daysRequested = $leaveCreditService->calculateDays($startDate, $endDate);

        // For SPL: validate solo parent status and calculate days accounting for half-days
        if ($request->leave_type === 'SPL') {
            $splErrors = $this->splCreditService->validateSplRequest($targetUser);
            if (! empty($splErrors)) {
                return back()->withErrors(['validation' => $splErrors])->withInput();
            }

            // If SPL day settings provided, recalculate days_requested with half-day support
            if ($request->has('spl_day_settings') && is_array($request->input('spl_day_settings'))) {
                $splDaySettings = $request->input('spl_day_settings');
                $daysRequested = 0;
                foreach ($splDaySettings as $daySetting) {
                    $daysRequested += ! empty($daySetting['is_half_day']) ? 0.5 : 1.0;
                }
            }
        }

        // Get current attendance points
        $attendancePoints = $leaveCreditService->getAttendancePoints($targetUser);

        // Check if short notice override is requested (Admin/Super Admin only)
        $shortNoticeOverride = false;
        if ($request->boolean('short_notice_override') && $isAdmin) {
            $shortNoticeOverride = true;
        }

        // Prepare data for validation
        // Use the leave request's created_at as the filing date for 2-week notice calculation
        // Use the leave request's existing credits_year to preserve the original credit pool
        $validationData = array_merge($request->validated(), [
            'days_requested' => $daysRequested,
            'credits_year' => $leaveRequest->credits_year ?? now()->year,
            'short_notice_override' => $shortNoticeOverride,
            'filed_at' => $leaveRequest->created_at,
        ]);

        // Validate business rules
        $validation = $leaveCreditService->validateLeaveRequest($targetUser, $validationData);

        if (! $validation['valid']) {
            return back()->withErrors(['validation' => $validation['errors']])->withInput();
        }

        DB::beginTransaction();
        try {
            // Handle medical certificate/document file upload for Sick Leave, Bereavement Leave, and UPTO
            $medicalCertPath = $leaveRequest->medical_cert_path; // Keep existing if not replaced
            if (in_array($request->leave_type, ['SL', 'BL', 'UPTO']) && $request->hasFile('medical_cert_file')) {
                // Delete old file if exists
                if ($leaveRequest->medical_cert_path && Storage::disk('local')->exists($leaveRequest->medical_cert_path)) {
                    Storage::disk('local')->delete($leaveRequest->medical_cert_path);
                }
                $file = $request->file('medical_cert_file');
                $filename = 'medcert_'.$targetUser->id.'_'.time().'.'.$file->getClientOriginalExtension();
                $medicalCertPath = $file->storeAs('medical_certificates', $filename, 'local');
            }

            $updateData = [
                'leave_type' => $request->leave_type,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days_requested' => $daysRequested,
                'reason' => $request->reason,
                'campaign_department' => $request->campaign_department,
                'medical_cert_submitted' => $request->boolean('medical_cert_submitted', false),
                'medical_cert_path' => $medicalCertPath,
                'attendance_points_at_request' => $attendancePoints,
            ];

            // Track short notice override if applied
            if ($shortNoticeOverride && ! $leaveRequest->short_notice_override) {
                $updateData['short_notice_override'] = true;
                $updateData['short_notice_override_by'] = $user->id;
                $updateData['short_notice_override_at'] = now();
            }

            $leaveRequest->update($updateData);

            // For SPL: update per-day records with is_half_day settings
            if ($request->leave_type === 'SPL' && $request->has('spl_day_settings') && is_array($request->input('spl_day_settings'))) {
                $leaveRequest->days()->delete();
                foreach ($request->input('spl_day_settings') as $daySetting) {
                    LeaveRequestDay::create([
                        'leave_request_id' => $leaveRequest->id,
                        'date' => $daySetting['date'],
                        'day_status' => 'pending',
                        'is_half_day' => ! empty($daySetting['is_half_day']),
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', 'Leave request updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Leave request update failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
            ]);

            return back()->withErrors(['error' => 'Failed to update leave request. Please try again.'])->withInput();
        }
    }

    /**
     * Update approved leave request dates (Admin/Super Admin only).
     * This handles Scenario 1: Employee wants to change dates of approved leave.
     */
    public function updateApproved(LeaveRequestRequest $request, LeaveRequest $leaveRequest)
    {
        $user = auth()->user();

        // Double-check authorization
        if (! in_array($user->role, ['Super Admin', 'Admin'])) {
            return back()->withErrors(['error' => 'Only Admin or Super Admin can update approved leave requests.']);
        }

        if ($leaveRequest->status !== 'approved') {
            return back()->withErrors(['error' => 'This method is only for approved leave requests.']);
        }

        $targetUser = $leaveRequest->user;
        $leaveCreditService = $this->leaveCreditService;

        // Store original dates if not already stored
        $originalStartDate = $leaveRequest->original_start_date ?? $leaveRequest->start_date;
        $originalEndDate = $leaveRequest->original_end_date ?? $leaveRequest->end_date;

        // Calculate new days
        $newStartDate = Carbon::parse($request->start_date);
        $newEndDate = Carbon::parse($request->end_date);
        $newDaysRequested = $leaveCreditService->calculateDays($newStartDate, $newEndDate);
        $oldDaysRequested = $leaveRequest->days_requested;

        // Get the reason for modification
        $modificationReason = $request->input('date_modification_reason', 'Date change requested by employee');

        DB::beginTransaction();
        try {
            // 1. Delete old attendance records associated with this leave
            Attendance::where('leave_request_id', $leaveRequest->id)->delete();

            // 2. Handle credit adjustments if days changed and this is a credited leave type
            if ($leaveRequest->requiresCredits() && $leaveRequest->credits_deducted) {
                $creditsDifference = $newDaysRequested - $oldDaysRequested;

                if ($creditsDifference > 0) {
                    // Need to deduct more credits
                    $tempRequest = new LeaveRequest([
                        'user_id' => $targetUser->id,
                        'leave_type' => $leaveRequest->leave_type,
                        'days_requested' => $creditsDifference,
                    ]);
                    $leaveCreditService->deductCredits($tempRequest, $leaveRequest->credits_year);

                    // Update total credits deducted
                    $leaveRequest->credits_deducted = $leaveRequest->credits_deducted + $creditsDifference;
                } elseif ($creditsDifference < 0) {
                    // Need to restore some credits
                    $tempRequest = new LeaveRequest([
                        'user_id' => $targetUser->id,
                        'credits_deducted' => abs($creditsDifference),
                        'credits_year' => $leaveRequest->credits_year,
                    ]);
                    $leaveCreditService->restoreCredits($tempRequest);

                    // Update total credits deducted
                    $leaveRequest->credits_deducted = $leaveRequest->credits_deducted + $creditsDifference;
                }
            }

            // 3. Update leave request with new dates
            $leaveRequest->update([
                'start_date' => $newStartDate,
                'end_date' => $newEndDate,
                'days_requested' => $newDaysRequested,
                'reason' => $request->reason,
                'campaign_department' => $request->campaign_department,
                // Track original dates and modification
                'original_start_date' => $originalStartDate,
                'original_end_date' => $originalEndDate,
                'date_modified_by' => $user->id,
                'date_modified_at' => now(),
                'date_modification_reason' => $modificationReason,
            ]);

            // 4. Create new attendance records for the new date range
            $this->updateAttendanceForApprovedLeave($leaveRequest);

            DB::commit();

            // Notify the employee about the date change
            $this->notificationService->notifyLeaveRequestDateChanged(
                $targetUser->id,
                str_replace('_', ' ', ucfirst($leaveRequest->leave_type)),
                Carbon::parse($originalStartDate)->format('M d, Y'),
                Carbon::parse($originalEndDate)->format('M d, Y'),
                Carbon::parse($newStartDate)->format('M d, Y'),
                Carbon::parse($newEndDate)->format('M d, Y'),
                $user->name,
                $modificationReason,
                $leaveRequest->id
            );

            \Log::info('Approved leave request dates updated', [
                'leave_request_id' => $leaveRequest->id,
                'modified_by' => $user->id,
                'old_dates' => Carbon::parse($originalStartDate)->format('Y-m-d').' to '.Carbon::parse($originalEndDate)->format('Y-m-d'),
                'new_dates' => Carbon::parse($newStartDate)->format('Y-m-d').' to '.Carbon::parse($newEndDate)->format('Y-m-d'),
                'old_days' => $oldDaysRequested,
                'new_days' => $newDaysRequested,
            ]);

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', 'Approved leave request dates updated successfully. Attendance records have been adjusted.');
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Approved leave request update failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
            ]);

            return back()->withErrors(['error' => 'Failed to update approved leave request. Please try again.'])->withInput();
        }
    }

    /**
     * Team Lead approves a leave request from their campaign agent.
     */
    public function approveTL(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('tlApprove', $leaveRequest);

        $user = auth()->user();

        if ($leaveRequest->status !== 'pending') {
            return back()->withErrors(['error' => 'Only pending requests can be approved.']);
        }

        if (! $leaveRequest->requiresTlApproval()) {
            return back()->withErrors(['error' => 'This request does not require Team Lead approval.']);
        }

        if ($leaveRequest->isTlApproved() || $leaveRequest->isTlRejected()) {
            return back()->withErrors(['error' => 'This request has already been reviewed by Team Lead.']);
        }

        $request->validate([
            'review_notes' => 'required|string|min:10',
        ]);

        DB::beginTransaction();
        try {
            $leaveRequest->update([
                'tl_approved_by' => $user->id,
                'tl_approved_at' => now(),
                'tl_review_notes' => $request->review_notes,
            ]);

            // Notify the agent about TL approval
            $this->notificationService->notifyAgentAboutTLApproval(
                $leaveRequest->user_id,
                $leaveRequest->leave_type,
                $user->name,
                $leaveRequest->id
            );

            // Notify Admin/HR that TL has approved
            $this->notificationService->notifyAdminHrAboutTLApproval(
                $leaveRequest->user->name,
                $leaveRequest->leave_type,
                $user->name,
                $leaveRequest->id
            );

            DB::commit();

            // Send email to agent
            try {
                $agent = $leaveRequest->user;
                if ($agent && $agent->email && filter_var($agent->email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($agent->email)->send(new LeaveRequestTLStatusUpdated($leaveRequest, $agent, $user, true));
                }
            } catch (\Exception $mailException) {
                \Log::warning('Failed to send TL approval email', [
                    'error' => $mailException->getMessage(),
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', 'Leave request approved. It is now pending Admin/HR approval.');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Team Lead leave request approval failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
            ]);

            return back()->withErrors(['error' => 'Failed to approve leave request. Please try again.']);
        }
    }

    /**
     * Team Lead denies a leave request from their campaign agent.
     */
    public function denyTL(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('tlApprove', $leaveRequest);

        $user = auth()->user();

        if ($leaveRequest->status !== 'pending') {
            return back()->withErrors(['error' => 'Only pending requests can be denied.']);
        }

        if (! $leaveRequest->requiresTlApproval()) {
            return back()->withErrors(['error' => 'This request does not require Team Lead approval.']);
        }

        if ($leaveRequest->isTlApproved() || $leaveRequest->isTlRejected()) {
            return back()->withErrors(['error' => 'This request has already been reviewed by Team Lead.']);
        }

        $request->validate([
            'review_notes' => 'required|string|min:10',
        ]);

        DB::beginTransaction();
        try {
            $leaveRequest->update([
                'tl_approved_by' => $user->id,
                'tl_approved_at' => now(),
                'tl_review_notes' => $request->review_notes,
                'tl_rejected' => true,
                'status' => 'denied',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => 'Rejected by Team Lead: '.$request->review_notes,
            ]);

            // Notify the agent about TL rejection
            $this->notificationService->notifyAgentAboutTLRejection(
                $leaveRequest->user_id,
                $leaveRequest->leave_type,
                $user->name,
                $leaveRequest->id
            );

            DB::commit();

            // Send email to agent
            try {
                $agent = $leaveRequest->user;
                if ($agent && $agent->email && filter_var($agent->email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($agent->email)->send(new LeaveRequestTLStatusUpdated($leaveRequest, $agent, $user, false));
                }
            } catch (\Exception $mailException) {
                \Log::warning('Failed to send TL rejection email', [
                    'error' => $mailException->getMessage(),
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', 'Leave request has been rejected.');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Team Lead leave request denial failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
            ]);

            return back()->withErrors(['error' => 'Failed to deny leave request. Please try again.']);
        }
    }

    /**
     * Approve a leave request (Admin/HR only).
     * Requires both Admin and HR approval before final approval.
     */
    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('approve', $leaveRequest);

        $user = auth()->user();
        $userRole = $user->role;

        if ($leaveRequest->status !== 'pending') {
            return back()->withErrors(['error' => 'Only pending requests can be approved.']);
        }

        // Check if this request requires TL approval first
        if ($leaveRequest->requiresTlApproval() && ! $leaveRequest->isTlApproved()) {
            return back()->withErrors(['error' => 'This request must be approved by Team Lead first.']);
        }

        // Determine if user is Admin or HR
        $isAdmin = in_array($userRole, ['Super Admin', 'Admin']);
        $isHr = $userRole === 'HR';

        // Check if user has already approved
        if ($isAdmin && $leaveRequest->isAdminApproved()) {
            return back()->withErrors(['error' => 'Admin has already approved this request.']);
        }

        if ($isHr && $leaveRequest->isHrApproved()) {
            return back()->withErrors(['error' => 'HR has already approved this request.']);
        }

        $request->validate([
            'review_notes' => 'required|string|min:10',
            'spl_half_day_overrides' => 'nullable|array',
            'spl_half_day_overrides.*' => 'boolean',
        ]);

        $leaveCreditService = $this->leaveCreditService;

        // Backend credit guard: day_statuses REQUIRED for SL/VL — admin must explicitly assign per-day statuses
        // Exception: if the other approver already pre-stored day statuses, this approver doesn't need to re-send them
        if (in_array($leaveRequest->leave_type, ['SL', 'VL'])) {
            $hasPreStoredDayStatuses = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->exists();

            if (! $request->has('day_statuses') || empty($request->input('day_statuses'))) {
                if (! $hasPreStoredDayStatuses) {
                    return back()->withErrors(['error' => 'Per-day status assignment is required for '.($leaveRequest->leave_type === 'VL' ? 'Vacation' : 'Sick').' Leave requests. Each day must be assigned as credited or UPTO before approving.']);
                }
            } else {
                $dayStatusesInput = $request->input('day_statuses');
                $paidStatus = $leaveRequest->leave_type === 'VL' ? 'vl_credited' : 'sl_credited';
                $creditedDaysCount = collect($dayStatusesInput)->where('status', $paidStatus)->count();
                $pendingDaysCount = collect($dayStatusesInput)->where('status', 'pending')->count();

                if ($pendingDaysCount > 0) {
                    return back()->withErrors(['error' => 'All days must have a status assigned. '.$pendingDaysCount.' day(s) are still pending.']);
                }

                if ($leaveRequest->leave_type === 'VL') {
                    $creditCheck = $leaveCreditService->checkVlCreditDeduction($leaveRequest->user, $leaveRequest);
                } else {
                    $creditCheck = $leaveCreditService->checkSlCreditDeduction($leaveRequest->user, $leaveRequest);
                }

                $availableCredits = (int) floor($creditCheck['credits_to_deduct'] ?? 0);
                if ($creditedDaysCount > $availableCredits) {
                    return back()->withErrors([
                        'error' => "Cannot approve: {$creditedDaysCount} day(s) marked as {$paidStatus} but only {$availableCredits} credit(s) available. Reduce credited days or set excess days to ".($leaveRequest->leave_type === 'VL' ? 'UPTO' : 'Advised Absence').'.',
                    ]);
                }
            }
        }

        // SPL: no credit guard needed — auto-FIFO allocation handles credit assignment at approval time

        DB::beginTransaction();
        try {
            $updateData = [];

            if ($isAdmin) {
                $updateData['admin_approved_by'] = $user->id;
                $updateData['admin_approved_at'] = now();
                $updateData['admin_review_notes'] = $request->review_notes;
            } elseif ($isHr) {
                $updateData['hr_approved_by'] = $user->id;
                $updateData['hr_approved_at'] = now();
                $updateData['hr_review_notes'] = $request->review_notes;
            }

            $leaveRequest->update($updateData);
            $leaveRequest->refresh();

            // For SL/VL: pre-store day statuses when admin provides them (before full approval)
            if (in_array($leaveRequest->leave_type, ['SL', 'VL']) && $request->has('day_statuses') && $request->input('day_statuses')) {
                $dayStatusesInput = $request->input('day_statuses');
                // Delete any existing pre-stored records (in case of re-approval)
                LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                foreach ($dayStatusesInput as $dayData) {
                    LeaveRequestDay::create([
                        'leave_request_id' => $leaveRequest->id,
                        'date' => $dayData['date'],
                        'day_status' => $dayData['status'],
                        'is_half_day' => ! empty($dayData['is_half_day']),
                        'notes' => $dayData['notes'] ?? null,
                        'assigned_by' => $user->id,
                        'assigned_at' => now(),
                    ]);
                }
            }

            // For SPL: persist half-day overrides to pre-stored day records (before full approval)
            if ($leaveRequest->leave_type === 'SPL' && $request->has('spl_half_day_overrides')) {
                $splOverrides = $request->input('spl_half_day_overrides', []);
                foreach ($splOverrides as $date => $isHalf) {
                    LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
                        ->where('date', $date)
                        ->update(['is_half_day' => (bool) $isHalf]);
                }
            }

            // Check if both have now approved
            $isFullyApproved = $leaveRequest->isFullyApproved();

            if ($isFullyApproved) {
                // Both Admin and HR have approved - finalize the approval
                $leaveRequest->update([
                    'status' => 'approved',
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                    'review_notes' => $request->review_notes,
                ]);

                // Handle leave credit deduction based on leave type
                if ($leaveRequest->leave_type === 'SL') {
                    // Sick Leave - per-day status assignment
                    // Use current request's day_statuses, pre-stored records, or auto-assign
                    $dayStatuses = $request->input('day_statuses');
                    if (! $dayStatuses) {
                        // Check for pre-stored day records from earlier approval step
                        $existingDays = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
                            ->orderBy('date')
                            ->get();
                        if ($existingDays->isNotEmpty()) {
                            $dayStatuses = $existingDays->map(fn ($d) => [
                                'date' => $d->date->format('Y-m-d'),
                                'status' => $d->day_status,
                                'notes' => $d->notes,
                            ])->toArray();
                            // Delete pre-stored records since handleSlApproval will re-create them
                            LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                        }
                    } else {
                        // If current request has day_statuses, clean up any pre-stored records
                        LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                    }
                    $this->handleSlApproval($leaveRequest, $leaveCreditService, $dayStatuses);
                } elseif ($leaveRequest->leave_type === 'VL') {
                    // Vacation Leave - per-day status assignment with UPTO conversion
                    $vlDayStatuses = $request->input('day_statuses');
                    if (! $vlDayStatuses) {
                        $existingVlDays = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
                            ->orderBy('date')
                            ->get();
                        if ($existingVlDays->isNotEmpty()) {
                            $vlDayStatuses = $existingVlDays->map(fn ($d) => [
                                'date' => $d->date->format('Y-m-d'),
                                'status' => $d->day_status,
                                'notes' => $d->notes,
                            ])->toArray();
                            LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                        }
                    } else {
                        LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                    }
                    $this->handleVlApproval($leaveRequest, $leaveCreditService, $vlDayStatuses);
                } elseif ($leaveRequest->leave_type === 'SPL') {
                    // Solo Parent Leave - auto-FIFO credit allocation with optional half-day overrides
                    $splHalfDayOverrides = $request->input('spl_half_day_overrides', []);
                    $this->handleSplApproval($leaveRequest, $splHalfDayOverrides);
                } elseif ($leaveRequest->requiresCredits()) {
                    // Other credited leave types - normal deduction
                    $year = $request->input('credits_year', $leaveRequest->created_at->year);

                    if ($leaveRequest->has_partial_denial && $leaveRequest->approved_days !== null) {
                        $originalDays = $leaveRequest->days_requested;
                        $leaveRequest->days_requested = $leaveRequest->approved_days;
                        $leaveCreditService->deductCredits($leaveRequest, $year);
                        $leaveRequest->days_requested = $originalDays;
                        $leaveRequest->update(['credits_deducted' => $leaveRequest->approved_days]);
                    } else {
                        $leaveCreditService->deductCredits($leaveRequest, $year);
                    }

                    $this->updateAttendanceForApprovedLeave($leaveRequest);
                } else {
                    // Non-credited leave types (UPTO, LOA, BL, LDV, ML) - no credit deduction, but still update attendance
                    $this->updateAttendanceForApprovedLeave($leaveRequest);
                }

                // Notify the employee about full approval
                $this->notificationService->notifyLeaveRequestFullyApproved(
                    $leaveRequest->user_id,
                    $leaveRequest->leave_type,
                    $leaveRequest->id
                );

                // If VL was approved with UPTO conversion, send additional notification
                if ($leaveRequest->leave_type === 'VL') {
                    $vlCreditedDays = $leaveRequest->days()->where('day_status', LeaveRequestDay::STATUS_VL_CREDITED)->count();
                    $uptoDays = $leaveRequest->days()->where('day_status', LeaveRequestDay::STATUS_UPTO)->count();
                    $totalDays = $vlCreditedDays + $uptoDays;

                    if ($uptoDays > 0) {
                        $this->notificationService->notifyLeaveRequestApprovedWithUptoConversion(
                            $leaveRequest->user_id,
                            $leaveRequest->leave_type,
                            $vlCreditedDays,
                            $uptoDays,
                            $totalDays,
                            $leaveRequest->id
                        );
                    }
                }

                DB::commit();

                // Send Email Notification to the employee (outside transaction)
                try {
                    $employee = $leaveRequest->user;
                    if ($employee && $employee->email && filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                        Mail::to($employee->email)->send(new LeaveRequestStatusUpdated($leaveRequest, $employee));
                    }
                } catch (\Exception $mailException) {
                    \Log::warning('Failed to send leave request approval email', [
                        'error' => $mailException->getMessage(),
                        'leave_request_id' => $leaveRequest->id,
                    ]);
                }

                $balanceWarning = $this->getPostApprovalBalanceWarning($leaveRequest);
                $approvalMessage = 'Leave request fully approved by both Admin and HR.'.($balanceWarning ? ' '.$balanceWarning : '');

                return redirect()->route('leave-requests.show', $leaveRequest)
                    ->with('message', $approvalMessage)
                    ->with('type', $balanceWarning ? 'warning' : 'success');
            } else {
                // Partial approval - notify the other role
                $requesterName = $leaveRequest->user->name;

                if ($isAdmin) {
                    // Admin approved, notify HR
                    $this->notificationService->notifyHrAboutAdminApproval(
                        $requesterName,
                        $leaveRequest->leave_type,
                        $user->name,
                        $leaveRequest->id
                    );
                } elseif ($isHr) {
                    // HR approved, notify Admin
                    $this->notificationService->notifyAdminAboutHrApproval(
                        $requesterName,
                        $leaveRequest->leave_type,
                        $user->name,
                        $leaveRequest->id
                    );
                }

                DB::commit();

                $otherRole = $isAdmin ? 'HR' : 'Admin';

                return redirect()->route('leave-requests.show', $leaveRequest)
                    ->with('success', "Your approval recorded. Waiting for {$otherRole} approval.");
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Leave request approval failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
                'user_id' => $user->id,
            ]);

            return back()->withErrors(['error' => 'Failed to approve leave request. Please try again.']);
        }
    }

    /**
     * Force approve a leave request (Super Admin only).
     * This bypasses the requirement for HR approval.
     */
    public function forceApprove(Request $request, LeaveRequest $leaveRequest)
    {
        $user = auth()->user();

        // Only Super Admin can force approve
        if ($user->role !== 'Super Admin') {
            return back()->withErrors(['error' => 'Only Super Admin can force approve leave requests.']);
        }

        if ($leaveRequest->status !== 'pending') {
            return back()->withErrors(['error' => 'Only pending requests can be approved.']);
        }

        // Validate request - denied_dates is optional for partial approval
        $validated = $request->validate([
            'review_notes' => 'required|string|min:10',
            'denied_dates' => 'nullable|array',
            'denied_dates.*' => 'date',
            'denial_reason' => 'nullable|required_with:denied_dates|string|min:10',
            'day_statuses' => 'nullable|array',
            'day_statuses.*.date' => 'required_with:day_statuses|date',
            'day_statuses.*.status' => 'required_with:day_statuses|string|in:pending,sl_credited,ncns,advised_absence,vl_credited,upto,spl_credited,absent',
            'day_statuses.*.is_half_day' => 'nullable|boolean',
            'day_statuses.*.notes' => 'nullable|string|max:500',
            'spl_half_day_overrides' => 'nullable|array',
            'spl_half_day_overrides.*' => 'boolean',
        ]);

        $leaveCreditService = $this->leaveCreditService;
        $hasDeniedDates = ! empty($validated['denied_dates']);

        // Backend credit guard: day_statuses REQUIRED for SL/VL — admin must explicitly assign per-day statuses
        if (in_array($leaveRequest->leave_type, ['SL', 'VL'])) {
            if (empty($validated['day_statuses'])) {
                return back()->withErrors(['error' => 'Per-day status assignment is required for '.($leaveRequest->leave_type === 'VL' ? 'Vacation' : 'Sick').' Leave requests. Each day must be assigned as credited or UPTO before force approving.']);
            }

            $dayStatusesInput = $validated['day_statuses'];
            $paidStatus = $leaveRequest->leave_type === 'VL' ? 'vl_credited' : 'sl_credited';
            $creditedDaysCount = collect($dayStatusesInput)->where('status', $paidStatus)->count();
            $pendingDaysCount = collect($dayStatusesInput)->where('status', 'pending')->count();

            if ($pendingDaysCount > 0) {
                return back()->withErrors(['error' => 'All days must have a status assigned. '.$pendingDaysCount.' day(s) are still pending.']);
            }

            if ($leaveRequest->leave_type === 'VL') {
                $creditCheck = $leaveCreditService->checkVlCreditDeduction($leaveRequest->user, $leaveRequest);
            } else {
                $creditCheck = $leaveCreditService->checkSlCreditDeduction($leaveRequest->user, $leaveRequest);
            }

            $availableCredits = (int) floor($creditCheck['credits_to_deduct'] ?? 0);
            if ($creditedDaysCount > $availableCredits) {
                return back()->withErrors([
                    'error' => "Cannot approve: {$creditedDaysCount} day(s) marked as {$paidStatus} but only {$availableCredits} credit(s) available. Reduce credited days or set excess days to ".($leaveRequest->leave_type === 'VL' ? 'UPTO' : 'Advised Absence').'.',
                ]);
            }
        }

        // SPL: no credit guard needed — auto-FIFO allocation with auto-denial handles credit assignment at approval time

        DB::beginTransaction();
        try {
            // Handle partial denial if denied_dates are provided
            if ($hasDeniedDates) {
                $startDate = Carbon::parse($leaveRequest->start_date);
                $endDate = Carbon::parse($leaveRequest->end_date);
                $deniedDates = collect($validated['denied_dates'])->map(fn ($d) => Carbon::parse($d));

                // Validate denied dates are within the leave period
                foreach ($deniedDates as $date) {
                    if ($date->lt($startDate) || $date->gt($endDate)) {
                        return back()->withErrors(['error' => "Date {$date->format('M d, Y')} is not within the leave period."]);
                    }
                }

                // Calculate which dates are approved
                $allWorkDays = [];
                $current = $startDate->copy();
                while ($current->lte($endDate)) {
                    if ($current->dayOfWeek >= Carbon::MONDAY && $current->dayOfWeek <= Carbon::FRIDAY) {
                        $allWorkDays[] = $current->format('Y-m-d');
                    }
                    $current->addDay();
                }

                $deniedDateStrings = $deniedDates->map(fn ($d) => $d->format('Y-m-d'))->toArray();
                $approvedDates = array_diff($allWorkDays, $deniedDateStrings);

                if (empty($approvedDates)) {
                    return back()->withErrors(['error' => 'Cannot deny all dates. Use full deny instead.']);
                }

                $approvedDaysCount = count($approvedDates);

                // Store denied dates
                foreach ($deniedDates as $date) {
                    LeaveRequestDeniedDate::create([
                        'leave_request_id' => $leaveRequest->id,
                        'denied_date' => $date,
                        'denial_reason' => $validated['denial_reason'],
                        'denied_by' => $user->id,
                    ]);
                }

                // Store original dates before updating
                $leaveRequest->original_start_date = $leaveRequest->start_date;
                $leaveRequest->original_end_date = $leaveRequest->end_date;

                // Update start and end dates to reflect only approved dates
                $leaveRequest->start_date = Carbon::parse(min($approvedDates));
                $leaveRequest->end_date = Carbon::parse(max($approvedDates));
                $leaveRequest->has_partial_denial = true;
                $leaveRequest->approved_days = $approvedDaysCount;
                $leaveRequest->save();
            }

            // Set both Admin and HR approval
            $leaveRequest->update([
                'admin_approved_by' => $user->id,
                'admin_approved_at' => now(),
                'admin_review_notes' => $validated['review_notes'],
                'hr_approved_by' => $user->id,
                'hr_approved_at' => now(),
                'hr_review_notes' => $validated['review_notes'],
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => $validated['review_notes'],
            ]);

            // If TL approval was required but not done, mark it as approved too
            if ($leaveRequest->requiresTlApproval() && ! $leaveRequest->isTlApproved()) {
                $leaveRequest->update([
                    'tl_approved_by' => $user->id,
                    'tl_approved_at' => now(),
                    'tl_review_notes' => $request->review_notes,
                ]);
            }

            // Handle leave credit deduction based on leave type
            if ($leaveRequest->leave_type === 'SL') {
                $dayStatuses = $request->input('day_statuses');
                $this->handleSlApproval($leaveRequest, $leaveCreditService, $dayStatuses);
            } elseif ($leaveRequest->leave_type === 'VL') {
                // Vacation Leave - per-day status assignment (VL Credited / UPTO)
                $dayStatuses = $request->input('day_statuses');
                $this->handleVlApproval($leaveRequest, $leaveCreditService, $dayStatuses);
            } elseif ($leaveRequest->leave_type === 'SPL') {
                // Solo Parent Leave - auto-FIFO credit allocation with optional half-day overrides
                $splHalfDayOverrides = $request->input('spl_half_day_overrides', []);
                $this->handleSplApproval($leaveRequest, $splHalfDayOverrides);
            } elseif ($leaveRequest->requiresCredits()) {
                // Other credited leave types - normal deduction
                $year = $request->input('credits_year', $leaveRequest->created_at->year);

                if ($leaveRequest->has_partial_denial && $leaveRequest->approved_days !== null) {
                    $originalDays = $leaveRequest->days_requested;
                    $leaveRequest->days_requested = $leaveRequest->approved_days;
                    $leaveCreditService->deductCredits($leaveRequest, $year);
                    $leaveRequest->days_requested = $originalDays;
                    $leaveRequest->update(['credits_deducted' => $leaveRequest->approved_days]);
                } else {
                    $leaveCreditService->deductCredits($leaveRequest, $year);
                }

                $this->updateAttendanceForApprovedLeave($leaveRequest);
            } else {
                // Non-credited leave types (UPTO, LOA, BL, LDV, ML) - no credit deduction, but still update attendance
                $this->updateAttendanceForApprovedLeave($leaveRequest);
            }

            // Notify the employee about approval
            $this->notificationService->notifyLeaveRequestFullyApproved(
                $leaveRequest->user_id,
                $leaveRequest->leave_type,
                $leaveRequest->id
            );

            // If VL was approved with UPTO conversion, send additional notification
            if ($leaveRequest->leave_type === 'VL') {
                $vlCreditedDays = $leaveRequest->days()->where('day_status', LeaveRequestDay::STATUS_VL_CREDITED)->count();
                $uptoDays = $leaveRequest->days()->where('day_status', LeaveRequestDay::STATUS_UPTO)->count();
                $totalDays = $vlCreditedDays + $uptoDays;

                if ($uptoDays > 0) {
                    $this->notificationService->notifyLeaveRequestApprovedWithUptoConversion(
                        $leaveRequest->user_id,
                        $leaveRequest->leave_type,
                        $vlCreditedDays,
                        $uptoDays,
                        $totalDays,
                        $leaveRequest->id
                    );
                }
            }

            DB::commit();

            // Send Email Notification to the employee
            try {
                $employee = $leaveRequest->user;
                if ($employee && $employee->email && filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($employee->email)->send(new LeaveRequestStatusUpdated($leaveRequest, $employee));
                }
            } catch (\Exception $mailException) {
                \Log::warning('Failed to send leave request force approval email', [
                    'error' => $mailException->getMessage(),
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }

            $successMsg = $hasDeniedDates
                ? "Leave request force approved with partial denial. {$leaveRequest->approved_days} day(s) approved."
                : 'Leave request force approved by Super Admin.';

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', $successMsg);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Leave request force approval failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
                'user_id' => $user->id,
            ]);

            return back()->withErrors(['error' => 'Failed to force approve leave request. Please try again.']);
        }
    }

    /**
     * Update per-day statuses for an approved Sick Leave request.
     * Allows admin to re-assign day statuses after initial approval.
     * Handles credit recalculation, attendance updates, and point adjustments.
     */
    public function updateDayStatuses(AssignDayStatusesRequest $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('approve', $leaveRequest);

        if (! in_array($leaveRequest->leave_type, ['SL', 'VL'])) {
            return back()->withErrors(['error' => 'Per-day status assignment is only available for Sick Leave and Vacation Leave requests.']);
        }

        if ($leaveRequest->status !== 'approved') {
            return back()->withErrors(['error' => 'Per-day statuses can only be updated for approved leave requests.']);
        }

        $user = auth()->user();
        $leaveCreditService = $this->leaveCreditService;
        $dayStatuses = $request->input('day_statuses');

        DB::beginTransaction();
        try {
            // Calculate previous credited days for credit adjustment
            $previousCreditedDays = $leaveRequest->getCreditedDaysCount();
            $previousCreditsDeducted = (float) ($leaveRequest->credits_deducted ?? 0);

            // Rollback existing attendance changes for this leave
            $this->rollbackAttendanceForCancelledLeave($leaveRequest);
            $this->rollbackExcusedAttendancePoints($leaveRequest);

            // If credits were previously deducted, restore them first
            if ($previousCreditsDeducted > 0) {
                $leaveCreditService->restoreCredits($leaveRequest);
            }

            // Re-apply with new day statuses
            $this->handleSlApproval($leaveRequest, $leaveCreditService, $dayStatuses);

            DB::commit();

            $leaveRequest->refresh();
            $newCreditedDays = $leaveRequest->getCreditedDaysCount();

            \Log::info("Day statuses updated for Leave Request #{$leaveRequest->id}", [
                'updated_by' => $user->id,
                'previous_credited' => $previousCreditedDays,
                'new_credited' => $newCreditedDays,
            ]);

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', 'Per-day statuses updated successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Day status update failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
                'user_id' => $user->id,
            ]);

            return back()->withErrors(['error' => 'Failed to update day statuses. Please try again.']);
        }
    }

    /**
     * Deny a leave request (Admin/HR only).
     */
    public function deny(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('deny', $leaveRequest);

        $user = auth()->user();

        if ($leaveRequest->status !== 'pending') {
            return back()->withErrors(['error' => 'Only pending requests can be denied.']);
        }

        $request->validate([
            'review_notes' => 'required|string|min:10',
        ]);

        DB::beginTransaction();
        try {
            $leaveRequest->update([
                'status' => 'denied',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => $request->review_notes,
            ]);

            // Notify the employee about denial
            $this->notificationService->notifyLeaveRequestStatusChange(
                $leaveRequest->user_id,
                'denied',
                $leaveRequest->leave_type,
                $leaveRequest->id
            );

            DB::commit();

            // Send Email Notification (outside transaction)
            try {
                $employee = $leaveRequest->user;
                if ($employee && $employee->email && filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($employee->email)->send(new LeaveRequestStatusUpdated($leaveRequest, $employee));
                }
            } catch (\Exception $mailException) {
                \Log::warning('Failed to send leave request denial email', [
                    'error' => $mailException->getMessage(),
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', 'Leave request denied.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withErrors(['error' => 'Failed to deny leave request. Please try again.']);
        }
    }

    /**
     * Partial deny - approve some dates while denying others.
     * This allows approvers to deny specific dates from a multi-day leave request.
     *
     * Example: Employee requests Mon-Fri (5 days) leave
     * - Approver denies Wed-Thu (conflict with important meeting)
     * - Mon-Tue and Fri are approved (3 days)
     * - Credits are only deducted for approved days
     */
    public function partialDeny(Request $request, LeaveRequest $leaveRequest)
    {
        $user = auth()->user();

        // Allow Team Lead, Admin, HR, Super Admin to partial deny
        $isTeamLead = $user->role === 'Team Lead';
        $isAdminOrHR = in_array($user->role, ['Admin', 'Super Admin', 'HR']);

        // Team Lead can only partial deny if TL approval is required and not yet done
        if ($isTeamLead) {
            if (! $leaveRequest->requiresTlApproval() || $leaveRequest->isTlApproved() || $leaveRequest->isTlRejected()) {
                return back()->withErrors(['error' => 'You cannot partially deny this request.']);
            }
        } elseif ($isAdminOrHR) {
            $this->authorize('approve', $leaveRequest);
        } else {
            return back()->withErrors(['error' => 'You do not have permission to partially deny this request.']);
        }

        if ($leaveRequest->status !== 'pending') {
            return back()->withErrors(['error' => 'Only pending requests can be partially denied.']);
        }

        $validated = $request->validate([
            'denied_dates' => 'required|array|min:1',
            'denied_dates.*' => 'required|date',
            'denial_reason' => 'required|string|min:10',
            'review_notes' => 'nullable|string|max:1000',
            'day_statuses' => 'nullable|array',
            'day_statuses.*.date' => 'required_with:day_statuses|date',
            'day_statuses.*.status' => 'required_with:day_statuses|string|in:pending,sl_credited,ncns,advised_absence,vl_credited,upto,spl_credited,absent',
            'day_statuses.*.is_half_day' => 'nullable|boolean',
            'day_statuses.*.notes' => 'nullable|string|max:500',
        ]);

        // Use the original (full) date range if a prior partial denial already narrowed start_date/end_date
        // This allows the next approver to see and re-select previously denied dates
        $startDate = $leaveRequest->has_partial_denial && $leaveRequest->original_start_date
            ? Carbon::parse($leaveRequest->original_start_date)
            : Carbon::parse($leaveRequest->start_date);
        $endDate = $leaveRequest->has_partial_denial && $leaveRequest->original_end_date
            ? Carbon::parse($leaveRequest->original_end_date)
            : Carbon::parse($leaveRequest->end_date);
        $deniedDates = collect($validated['denied_dates'])->map(fn ($d) => Carbon::parse($d));

        // Validate denied dates are within the leave period (using full original range)
        foreach ($deniedDates as $date) {
            if ($date->lt($startDate) || $date->gt($endDate)) {
                return back()->withErrors(['error' => "Date {$date->format('M d, Y')} is not within the leave period."]);
            }
        }

        // Calculate which dates are approved (total days minus denied dates)
        $allWorkDays = [];
        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            // Only count weekdays
            if ($current->dayOfWeek >= Carbon::MONDAY && $current->dayOfWeek <= Carbon::FRIDAY) {
                $allWorkDays[] = $current->format('Y-m-d');
            }
            $current->addDay();
        }

        $deniedDateStrings = $deniedDates->map(fn ($d) => $d->format('Y-m-d'))->toArray();
        $approvedDates = array_diff($allWorkDays, $deniedDateStrings);

        if (empty($approvedDates)) {
            // All dates denied - use full deny instead
            return $this->deny($request, $leaveRequest);
        }

        $approvedDaysCount = count($approvedDates);
        $deniedDaysCount = count($deniedDateStrings);

        DB::beginTransaction();
        try {
            // Replace existing denied dates if a prior partial denial exists
            // The current approver's selections supersede the previous approver's
            if ($leaveRequest->has_partial_denial) {
                LeaveRequestDeniedDate::where('leave_request_id', $leaveRequest->id)->delete();
            }

            // Store denied dates
            foreach ($deniedDates as $date) {
                LeaveRequestDeniedDate::create([
                    'leave_request_id' => $leaveRequest->id,
                    'denied_date' => $date,
                    'denial_reason' => $validated['denial_reason'],
                    'denied_by' => $user->id,
                ]);
            }

            // Note: Credits are NOT deducted during partial denial.
            // Credits will be deducted during final approval (Admin/HR approve or force approve)
            // to prevent double deduction when TL partially approves first.

            // Build review notes
            $roleLabel = $user->role === 'Team Lead' ? 'Team Lead' : (in_array($user->role, ['Admin', 'Super Admin']) ? 'Admin' : 'HR');
            $reviewNote = "{$roleLabel} partially approved: {$approvedDaysCount} day(s) approved, {$deniedDaysCount} day(s) denied. ";
            $reviewNote .= 'Denied dates: '.implode(', ', $deniedDates->map(fn ($d) => $d->format('M d, Y'))->toArray()).'. ';
            $reviewNote .= "Reason: {$validated['denial_reason']}";

            if ($validated['review_notes']) {
                $reviewNote .= ". Additional notes: {$validated['review_notes']}";
            }

            // Update leave request
            $updateData = [
                'has_partial_denial' => true,
                'approved_days' => $approvedDaysCount,
            ];

            // Store original dates before updating (only on first partial denial)
            if (! $leaveRequest->original_start_date) {
                $updateData['original_start_date'] = $leaveRequest->start_date;
            }
            if (! $leaveRequest->original_end_date) {
                $updateData['original_end_date'] = $leaveRequest->end_date;
            }

            // Update start and end dates to reflect only approved dates
            if (! empty($approvedDates)) {
                $updateData['start_date'] = min($approvedDates);
                $updateData['end_date'] = max($approvedDates);
            }

            // Determine which approval field to set based on user role
            if ($user->role === 'Team Lead') {
                $updateData['tl_approved_by'] = $user->id;
                $updateData['tl_approved_at'] = now();
                $updateData['tl_review_notes'] = $reviewNote;
            } elseif (in_array($user->role, ['Admin', 'Super Admin'])) {
                $updateData['admin_approved_by'] = $user->id;
                $updateData['admin_approved_at'] = now();
                $updateData['admin_review_notes'] = $reviewNote;
            } elseif ($user->role === 'HR') {
                $updateData['hr_approved_by'] = $user->id;
                $updateData['hr_approved_at'] = now();
                $updateData['hr_review_notes'] = $reviewNote;
            }

            // Check if this completes the dual approval
            $leaveRequest->fill($updateData);

            // Check if both Admin and HR have approved (and TL if required)
            $adminApproved = $leaveRequest->admin_approved_by !== null;
            $hrApproved = $leaveRequest->hr_approved_by !== null;
            $tlApproved = $leaveRequest->tl_approved_by !== null;
            $tlRequired = $leaveRequest->requires_tl_approval;

            // For TL partial approval, just save and notify - still needs Admin/HR
            if ($user->role === 'Team Lead') {
                $leaveRequest->save();

                // For SL/VL: pre-store day statuses from TL for later use during full approval
                if (in_array($leaveRequest->leave_type, ['SL', 'VL']) && $request->has('day_statuses') && $request->input('day_statuses')) {
                    LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                    foreach ($request->input('day_statuses') as $dayData) {
                        LeaveRequestDay::create([
                            'leave_request_id' => $leaveRequest->id,
                            'date' => $dayData['date'],
                            'day_status' => $dayData['status'],
                            'notes' => $dayData['notes'] ?? null,
                            'assigned_by' => $user->id,
                            'assigned_at' => now(),
                        ]);
                    }
                }

                // Notify Admin/HR that TL has partially approved
                $this->notificationService->create(
                    $leaveRequest->user_id,
                    'leave_request',
                    'Leave Request Partially Approved by Team Lead',
                    "Your {$leaveRequest->leave_type} request has been partially approved by your Team Lead. {$approvedDaysCount} day(s) approved, {$deniedDaysCount} day(s) denied. Now pending Admin/HR approval.",
                    ['leave_request_id' => $leaveRequest->id]
                );

                DB::commit();

                return redirect()->route('leave-requests.show', $leaveRequest)
                    ->with('success', "Partial approval recorded. {$approvedDaysCount} day(s) approved, {$deniedDaysCount} day(s) denied. Awaiting Admin/HR approval.");
            }

            // Check if fully approved (Admin + HR, and TL if required)
            $fullyApproved = $adminApproved && $hrApproved && (! $tlRequired || $tlApproved);

            // For non-TL partial approval where not yet fully approved, pre-store SL/VL day statuses
            if ($user->role !== 'Team Lead' && ! $fullyApproved) {
                if (in_array($leaveRequest->leave_type, ['SL', 'VL']) && $request->has('day_statuses') && $request->input('day_statuses')) {
                    LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                    foreach ($request->input('day_statuses') as $dayData) {
                        LeaveRequestDay::create([
                            'leave_request_id' => $leaveRequest->id,
                            'date' => $dayData['date'],
                            'day_status' => $dayData['status'],
                            'notes' => $dayData['notes'] ?? null,
                            'assigned_by' => $user->id,
                            'assigned_at' => now(),
                        ]);
                    }
                }
            }

            if ($fullyApproved) {
                // Both have approved - mark as approved
                $leaveRequest->status = 'approved';
                $leaveRequest->reviewed_by = $user->id;
                $leaveRequest->reviewed_at = now();
                $leaveRequest->review_notes = $reviewNote;

                // Handle leave credit deduction based on leave type
                $leaveCreditService = $this->leaveCreditService;

                if ($leaveRequest->leave_type === 'SL') {
                    // Sick Leave - per-day status assignment
                    $dayStatuses = $request->input('day_statuses');
                    if (! $dayStatuses) {
                        // Check for pre-stored day records from earlier partial approval step
                        $existingDays = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
                            ->orderBy('date')
                            ->get();
                        if ($existingDays->isNotEmpty()) {
                            $dayStatuses = $existingDays->map(fn ($d) => [
                                'date' => $d->date->format('Y-m-d'),
                                'status' => $d->day_status,
                                'notes' => $d->notes,
                            ])->toArray();
                            LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                        }
                    } else {
                        LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                    }
                    $this->handleSlApproval($leaveRequest, $leaveCreditService, $dayStatuses);
                } elseif ($leaveRequest->leave_type === 'VL') {
                    // Vacation Leave - per-day status assignment with UPTO conversion
                    $vlDayStatuses = $request->input('day_statuses');
                    if (! $vlDayStatuses) {
                        $existingVlDays = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
                            ->orderBy('date')
                            ->get();
                        if ($existingVlDays->isNotEmpty()) {
                            $vlDayStatuses = $existingVlDays->map(fn ($d) => [
                                'date' => $d->date->format('Y-m-d'),
                                'status' => $d->day_status,
                                'notes' => $d->notes,
                            ])->toArray();
                            LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                        }
                    } else {
                        LeaveRequestDay::where('leave_request_id', $leaveRequest->id)->delete();
                    }
                    $this->handleVlApproval($leaveRequest, $leaveCreditService, $vlDayStatuses);
                } elseif ($leaveRequest->requiresCredits()) {
                    // Other credited leave types - deduct for approved days only
                    $year = $leaveRequest->created_at->year;

                    $originalDays = $leaveRequest->days_requested;
                    $leaveRequest->days_requested = $approvedDaysCount;
                    $leaveCreditService->deductCredits($leaveRequest, $year);
                    $leaveRequest->days_requested = $originalDays;

                    $leaveRequest->credits_deducted = $approvedDaysCount;

                    $this->updateAttendanceForApprovedLeave($leaveRequest);
                } else {
                    // Non-credited leave types - no credit deduction, but still update attendance
                    $this->updateAttendanceForApprovedLeave($leaveRequest);
                }
            }

            $leaveRequest->save();

            // Notify the employee about partial approval
            $this->notificationService->create(
                $leaveRequest->user_id,
                'leave_request',
                'Leave Request Partially Approved',
                "Your {$leaveRequest->leave_type} request has been partially approved. {$approvedDaysCount} day(s) approved, {$deniedDaysCount} day(s) denied.",
                ['leave_request_id' => $leaveRequest->id]
            );

            DB::commit();

            // Send email notification
            try {
                $employee = $leaveRequest->user;
                if ($employee && $employee->email && filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($employee->email)->send(new LeaveRequestStatusUpdated($leaveRequest, $employee));
                }
            } catch (\Exception $mailException) {
                \Log::warning('Failed to send leave request partial approval email', [
                    'error' => $mailException->getMessage(),
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }

            $statusMsg = ($leaveRequest->status === 'approved')
                ? "Leave request partially approved. {$approvedDaysCount} day(s) will be used."
                : 'Partial approval recorded. Awaiting '.($adminApproved ? 'HR' : 'Admin').' approval.';

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', $statusMsg);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Partial deny failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
            ]);

            return back()->withErrors(['error' => 'Failed to process partial denial. '.$e->getMessage()]);
        }
    }

    /**
     * Adjust leave dates when employee reported to work on specific day(s).
     * This allows HR/Admin to reduce leave duration and restore partial credits.
     *
     * Example: 3-day leave (Mon-Wed), employee reported on Wed
     * - Original: Mon-Wed (3 days)
     * - Adjusted: Mon-Tue (2 days)
     * - Restores 1 day of credits
     */
    public function adjustForWorkDay(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('updateApproved', $leaveRequest);

        $validated = $request->validate([
            'work_date' => 'required|date',
            'adjustment_type' => 'required|in:end_early,start_late,remove_day',
            'notes' => 'nullable|string|max:500',
        ]);

        $workDate = Carbon::parse($validated['work_date']);
        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);
        $user = auth()->user();

        // Validate work date is within leave period
        if ($workDate->lt($startDate) || $workDate->gt($endDate)) {
            return back()->withErrors(['error' => 'Work date must be within the leave period.']);
        }

        // Cannot adjust past leaves
        if ($endDate->endOfDay()->isPast()) {
            return back()->withErrors(['error' => 'Cannot adjust leave that has already ended.']);
        }

        DB::beginTransaction();
        try {
            $originalStartDate = $leaveRequest->original_start_date ?? $leaveRequest->start_date;
            $originalEndDate = $leaveRequest->original_end_date ?? $leaveRequest->end_date;
            $originalDays = $leaveRequest->days_requested;

            $newStartDate = $startDate;
            $newEndDate = $endDate;

            switch ($validated['adjustment_type']) {
                case 'end_early':
                    // Employee reported on work_date, so leave ends the day before
                    $newEndDate = $workDate->copy()->subDay();
                    if ($newEndDate->lt($startDate)) {
                        // If work date is the first day, cancel the entire leave
                        return $this->cancelEntireLeaveForWork($leaveRequest, $workDate, $validated['notes'] ?? '');
                    }
                    break;

                case 'start_late':
                    // Employee reported on work_date but wants to continue leave after
                    $newStartDate = $workDate->copy()->addDay();
                    if ($newStartDate->gt($endDate)) {
                        // If work date is the last day, cancel the entire leave
                        return $this->cancelEntireLeaveForWork($leaveRequest, $workDate, $validated['notes'] ?? '');
                    }
                    break;

                case 'remove_day':
                    // This is more complex - for now redirect to manual date edit
                    return back()->withErrors(['error' => 'For removing a middle day, please use the Edit Dates feature to split the leave.']);
            }

            // Calculate new days
            $newDays = $this->leaveCreditService->calculateDays(
                $newStartDate,
                $newEndDate
            );

            $daysReduced = $originalDays - $newDays;

            if ($daysReduced <= 0) {
                return back()->withErrors(['error' => 'No days to reduce. Check the dates.']);
            }

            // Restore credits for reduced days
            if ($leaveRequest->credits_deducted) {
                $this->leaveCreditService->restorePartialCredits(
                    $leaveRequest,
                    $daysReduced,
                    "Adjusted for work on {$workDate->format('M d, Y')}"
                );
            }

            // Update leave request
            $adjustmentNote = "Adjusted: Employee reported to work on {$workDate->format('M d, Y')}. ".
                "Original period: {$startDate->format('M d')} - {$endDate->format('M d, Y')} ({$originalDays} days). ".
                "New period: {$newStartDate->format('M d')} - {$newEndDate->format('M d, Y')} ({$newDays} days). ".
                "{$daysReduced} day(s) credit restored.";

            if ($validated['notes']) {
                $adjustmentNote .= " Note: {$validated['notes']}";
            }

            $leaveRequest->update([
                'start_date' => $newStartDate,
                'end_date' => $newEndDate,
                'days_requested' => $newDays,
                'credits_deducted' => $leaveRequest->credits_deducted - $daysReduced,
                'original_start_date' => $originalStartDate,
                'original_end_date' => $originalEndDate,
                'date_modified_by' => $user->id,
                'date_modified_at' => now(),
                'date_modification_reason' => $adjustmentNote,
            ]);

            // Rollback attendance records for removed leave days (out of new range)
            $removedAttendances = Attendance::where('leave_request_id', $leaveRequest->id)
                ->where(function ($query) use ($newStartDate, $newEndDate) {
                    $query->where('shift_date', '<', $newStartDate)
                        ->orWhere('shift_date', '>', $newEndDate);
                })
                ->get();

            foreach ($removedAttendances as $attendance) {
                if ($attendance->pre_leave_status !== null) {
                    $attendance->update([
                        'status' => $attendance->pre_leave_status,
                        'pre_leave_status' => null,
                        'leave_request_id' => null,
                        'admin_verified' => false,
                        'notes' => null,
                    ]);
                } else {
                    $attendance->delete();
                }
            }

            // Approve the attendance record for the work day (if exists and pending)
            Attendance::where('user_id', $leaveRequest->user_id)
                ->where('shift_date', $workDate)
                ->where('admin_verified', false)
                ->update([
                    'admin_verified' => true,
                    'leave_request_id' => null, // No longer a leave conflict
                    'verification_notes' => "Leave adjusted - employee worked this day. Verified by {$user->name}",
                ]);

            // Notify employee
            $this->notificationService->notifyLeaveRequestDateChanged(
                $leaveRequest->user_id,
                str_replace('_', ' ', ucfirst($leaveRequest->leave_type)),
                $startDate->format('M d, Y'),
                $endDate->format('M d, Y'),
                $newStartDate->format('M d, Y'),
                $newEndDate->format('M d, Y'),
                $user->name,
                "Reported to work on {$workDate->format('M d, Y')}",
                $leaveRequest->id
            );

            DB::commit();

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', "Leave adjusted successfully. {$daysReduced} day(s) credit restored.");
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Leave adjustment failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
            ]);

            return back()->withErrors(['error' => 'Failed to adjust leave. '.$e->getMessage()]);
        }
    }

    /**
     * Cancel entire leave when employee reported on single-day leave or first/last day.
     */
    protected function cancelEntireLeaveForWork(LeaveRequest $leaveRequest, Carbon $workDate, string $notes = '')
    {
        $user = auth()->user();

        // Restore all credits
        if ($leaveRequest->credits_deducted) {
            $this->leaveCreditService->restoreCredits($leaveRequest);
        }

        $reason = "Employee reported to work on {$workDate->format('M d, Y')}. Leave cancelled and credits restored.";
        if ($notes) {
            $reason .= " Note: {$notes}";
        }

        $leaveRequest->update([
            'status' => 'cancelled',
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        // Rollback excused points first (before attendance deletion cascades)
        $this->rollbackExcusedAttendancePoints($leaveRequest);
        $this->rollbackAttendanceForCancelledLeave($leaveRequest);

        // Approve the work day attendance
        Attendance::where('user_id', $leaveRequest->user_id)
            ->where('shift_date', $workDate)
            ->where('admin_verified', false)
            ->update([
                'admin_verified' => true,
                'leave_request_id' => null,
                'verification_notes' => "Leave cancelled - employee worked. Verified by {$user->name}",
            ]);

        // Notify employee
        $this->notificationService->notifyLeaveRequestCancelledByAdmin(
            $leaveRequest->user_id,
            str_replace('_', ' ', ucfirst($leaveRequest->leave_type)),
            Carbon::parse($leaveRequest->start_date)->format('M d, Y'),
            Carbon::parse($leaveRequest->end_date)->format('M d, Y'),
            $user->name,
            $reason,
            $leaveRequest->id
        );

        DB::commit();

        return redirect()->route('leave-requests.show', $leaveRequest)
            ->with('success', 'Leave cancelled as employee reported to work. Credits restored.');
    }

    /**
     * Cancel a leave request.
     * - Employees can cancel their own pending requests
     * - Admin/Super Admin can cancel any pending or approved requests
     */
    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('cancel', $leaveRequest);

        $user = auth()->user();
        $isPrivileged = in_array($user->role, ['Super Admin', 'Admin', 'HR', 'Team Lead']);
        $isOwnRequest = $leaveRequest->user_id === $user->id;
        $isApprovedLeave = $leaveRequest->status === 'approved';
        $isPartiallyApproved = $leaveRequest->isPartiallyApproved();

        // Block cancellation of fully approved leaves with past end dates (prevents rolling back credits)
        // Partially approved leaves (has_partial_denial) are still cancellable
        if ($isApprovedLeave && ! $leaveRequest->has_partial_denial && $leaveRequest->end_date && $leaveRequest->end_date->endOfDay()->isPast()) {
            return back()->withErrors(['error' => 'Cannot cancel approved leave requests with past dates. Leave credits have already been deducted.']);
        }

        // For approved leaves, only privileged roles or the owner (if partially approved or future start) can cancel
        if ($isApprovedLeave && ! $isPrivileged && ! ($isOwnRequest && ($isPartiallyApproved || $leaveRequest->start_date > now()))) {
            return back()->withErrors(['error' => 'Only Admin, Super Admin, HR, or Team Lead can cancel approved leave requests.']);
        }

        // For non-privileged users, check canBeCancelled
        if (! $isPrivileged && ! $leaveRequest->canBeCancelled()) {
            return back()->withErrors(['error' => 'This leave request cannot be cancelled.']);
        }

        // Get cancellation reason from request (always required)
        $cancellationReason = $request->input('cancellation_reason', '');
        if (empty(trim($cancellationReason))) {
            return back()->withErrors(['cancellation_reason' => 'A reason is required when cancelling a leave request.']);
        }

        $leaveCreditService = $this->leaveCreditService;

        DB::beginTransaction();
        try {
            // Restore credits if it was approved and credits were deducted
            if ($leaveRequest->status === 'approved' && $leaveRequest->credits_deducted) {
                if ($leaveRequest->leave_type === 'SPL') {
                    $this->splCreditService->restoreCredits($leaveRequest);
                } else {
                    $leaveCreditService->restoreCredits($leaveRequest);
                }
            }

            // Rollback for approved leaves: un-excuse points first (before attendance cascade-delete)
            if ($leaveRequest->status === 'approved') {
                $this->rollbackExcusedAttendancePoints($leaveRequest);
                $this->rollbackAttendanceForCancelledLeave($leaveRequest);
            }

            // Clean up per-day status records (SL, VL, and SPL per-day tracking)
            if (in_array($leaveRequest->leave_type, ['SL', 'VL', 'SPL'])) {
                $leaveRequest->days()->delete();
            }

            // Update cancellation tracking
            $updateData = [
                'status' => 'cancelled',
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ];

            if ($cancellationReason) {
                $updateData['cancellation_reason'] = $cancellationReason;
            }

            $leaveRequest->update($updateData);

            // Notify the employee about cancellation (if cancelled by privileged role)
            if ($isPrivileged && ! $isOwnRequest) {
                $this->notificationService->notifyLeaveRequestCancelledByAdmin(
                    $leaveRequest->user_id,
                    str_replace('_', ' ', ucfirst($leaveRequest->leave_type)),
                    Carbon::parse($leaveRequest->start_date)->format('M d, Y'),
                    Carbon::parse($leaveRequest->end_date)->format('M d, Y'),
                    $user->name,
                    $cancellationReason ?? 'No reason provided',
                    $leaveRequest->id
                );
            }

            // Notify HR/Admin about cancellation (if cancelled by employee)
            if ($isOwnRequest) {
                $this->notificationService->notifyHrRolesAboutLeaveCancellation(
                    $user->name,
                    $leaveRequest->leave_type,
                    Carbon::parse($leaveRequest->start_date)->format('Y-m-d'),
                    Carbon::parse($leaveRequest->end_date)->format('Y-m-d')
                );
            }

            DB::commit();

            \Log::info('Leave request cancelled', [
                'leave_request_id' => $leaveRequest->id,
                'cancelled_by' => $user->id,
                'was_approved' => $isApprovedLeave,
                'credits_restored' => $leaveRequest->credits_deducted,
            ]);

            return redirect()->route('leave-requests.index')
                ->with('success', 'Leave request cancelled successfully.'.($leaveRequest->credits_deducted ? ' Credits have been restored.' : ''));
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Leave request cancellation failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
            ]);

            return back()->withErrors(['error' => 'Failed to cancel leave request. Please try again.']);
        }
    }

    /**
     * Delete a leave request (Admin/HR only).
     */
    public function destroy(LeaveRequest $leaveRequest)
    {
        $this->authorize('delete', $leaveRequest);

        $leaveCreditService = $this->leaveCreditService;

        DB::beginTransaction();
        try {
            // Delete medical certificate file if exists
            if ($leaveRequest->medical_cert_path && Storage::disk('local')->exists($leaveRequest->medical_cert_path)) {
                Storage::disk('local')->delete($leaveRequest->medical_cert_path);
            }

            // Restore credits if it was approved and credits were deducted
            if ($leaveRequest->status === 'approved' && $leaveRequest->credits_deducted) {
                $leaveCreditService->restoreCredits($leaveRequest);
            }

            // Rollback for approved leaves: un-excuse points first (before attendance cascade-delete)
            if ($leaveRequest->status === 'approved') {
                $this->rollbackExcusedAttendancePoints($leaveRequest);
                $this->rollbackAttendanceForCancelledLeave($leaveRequest);
            }

            $leaveRequest->delete();

            DB::commit();

            return redirect()->route('leave-requests.index')
                ->with('success', 'Leave request deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Leave request deletion failed', [
                'error' => $e->getMessage(),
                'leave_request_id' => $leaveRequest->id,
            ]);

            return back()->withErrors(['error' => 'Failed to delete leave request. Please try again.']);
        }
    }

    /**
     * Get leave credits balance (API endpoint for real-time display).
     */
    public function getCreditsBalance(Request $request)
    {
        $user = auth()->user();
        $year = $request->input('year', now()->year);

        $leaveCreditService = $this->leaveCreditService;
        $summary = $leaveCreditService->getSummary($user, $year);

        return response()->json($summary);
    }

    /**
     * Calculate days between dates (API endpoint for form).
     */
    public function calculateDays(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $leaveCreditService = $this->leaveCreditService;
        $days = $leaveCreditService->calculateDays($startDate, $endDate);

        return response()->json(['days' => $days]);
    }

    /**
     * Check for campaign conflicts (employees in the same campaign with overlapping leave dates).
     * Returns informational data only - users can still apply.
     *
     * @return JsonResponse
     */
    public function checkCampaignConflicts(Request $request)
    {
        $request->validate([
            'campaign_department' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'exclude_user_id' => 'nullable|integer',
            'exclude_leave_id' => 'nullable|integer',
        ]);

        $campaign = $request->input('campaign_department');
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $excludeUserId = $request->input('exclude_user_id');
        $excludeLeaveId = $request->input('exclude_leave_id');

        // Find all pending or approved leave requests in the same campaign that overlap
        $query = LeaveRequest::with('user:id,first_name,middle_name,last_name')
            ->where('campaign_department', $campaign)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($q) use ($startDate, $endDate) {
                $q->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate);
            });

        // Exclude current user if specified
        if ($excludeUserId) {
            $query->where('user_id', '!=', $excludeUserId);
        }

        // Exclude current leave request if editing
        if ($excludeLeaveId) {
            $query->where('id', '!=', $excludeLeaveId);
        }

        $conflicts = $query->get()->map(function ($leave) use ($startDate, $endDate) {
            // Calculate overlapping dates
            $overlapStart = max($startDate->timestamp, Carbon::parse($leave->start_date)->timestamp);
            $overlapEnd = min($endDate->timestamp, Carbon::parse($leave->end_date)->timestamp);
            $overlappingDates = [];

            $current = Carbon::createFromTimestamp($overlapStart);
            $end = Carbon::createFromTimestamp($overlapEnd);
            while ($current->lte($end)) {
                // Only include weekdays
                if ($current->isWeekday()) {
                    $overlappingDates[] = $current->format('Y-m-d');
                }
                $current->addDay();
            }

            return [
                'id' => $leave->id,
                'user_name' => $leave->user->name ?? 'Unknown',
                'leave_type' => $leave->leave_type,
                'start_date' => $leave->start_date->format('Y-m-d'),
                'end_date' => $leave->end_date->format('Y-m-d'),
                'status' => $leave->status,
                'created_at' => $leave->created_at->toISOString(),
                'overlapping_dates' => $overlappingDates,
            ];
        });

        return response()->json($conflicts);
    }

    /**
     * Get earlier conflicts for a leave request (first-come-first-serve check for VL/UPTO).
     * Returns leave requests from the same campaign that were submitted earlier and have overlapping dates.
     */
    private function getEarlierConflicts(LeaveRequest $leaveRequest): array
    {
        // Only check for VL and UPTO (first-come-first-serve policy)
        if (! in_array($leaveRequest->leave_type, ['VL', 'UPTO'])) {
            return [];
        }

        return LeaveRequest::with('user:id,first_name,middle_name,last_name')
            ->where('campaign_department', $leaveRequest->campaign_department)
            ->where('id', '!=', $leaveRequest->id)
            ->where('user_id', '!=', $leaveRequest->user_id)
            ->whereIn('leave_type', ['VL', 'UPTO'])
            ->whereIn('status', ['pending', 'approved'])
            ->where('created_at', '<', $leaveRequest->created_at) // Submitted BEFORE this request
            ->where(function ($q) use ($leaveRequest) {
                $q->where('start_date', '<=', $leaveRequest->end_date)
                    ->where('end_date', '>=', $leaveRequest->start_date);
            })
            ->get()
            ->map(function ($leave) use ($leaveRequest) {
                // Calculate overlapping dates
                $startDate = $leaveRequest->start_date;
                $endDate = $leaveRequest->end_date;
                $overlapStart = max($startDate->timestamp, $leave->start_date->timestamp);
                $overlapEnd = min($endDate->timestamp, $leave->end_date->timestamp);
                $overlappingDates = [];

                $current = Carbon::createFromTimestamp($overlapStart);
                $end = Carbon::createFromTimestamp($overlapEnd);
                while ($current->lte($end)) {
                    if ($current->isWeekday()) {
                        $overlappingDates[] = $current->format('Y-m-d');
                    }
                    $current->addDay();
                }

                return [
                    'id' => $leave->id,
                    'user_name' => $leave->user->name ?? 'Unknown',
                    'leave_type' => $leave->leave_type,
                    'start_date' => $leave->start_date->format('Y-m-d'),
                    'end_date' => $leave->end_date->format('Y-m-d'),
                    'status' => $leave->status,
                    'created_at' => $leave->created_at->toISOString(),
                    'overlapping_dates' => $overlappingDates,
                ];
            })
            ->toArray();
    }

    /**
     * Get the active employee schedule for a user on a specific date.
     */
    protected function getActiveScheduleForDate(int $userId, string $date): ?EmployeeSchedule
    {
        return EmployeeSchedule::where('user_id', $userId)
            ->where('is_active', true)
            ->where('effective_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $date);
            })
            ->first();
    }

    /**
     * Get a post-approval balance warning if other pending leave requests exist.
     *
     * Uses projected balance (accounting for future accruals) to determine
     * if remaining credits are insufficient for other pending requests.
     */
    protected function getPostApprovalBalanceWarning(LeaveRequest $leaveRequest): ?string
    {
        if (! in_array($leaveRequest->leave_type, LeaveRequest::CREDITED_LEAVE_TYPES)) {
            return null;
        }

        $year = Carbon::parse($leaveRequest->start_date)->year;
        $pendingInfo = $this->leaveCreditService->getPendingLeaveInfo($leaveRequest->user_id, $year);

        if ($pendingInfo['pending_count'] === 0) {
            return null;
        }

        $balance = $this->leaveCreditService->getBalance($leaveRequest->user, $year);
        $projectedBalance = $balance + $pendingInfo['future_accrual'];

        if ($projectedBalance < $pendingInfo['pending_credits']) {
            return "Note: {$leaveRequest->user->name} has {$pendingInfo['pending_count']} other pending leave request(s) totaling {$pendingInfo['pending_credits']} credit(s), but only {$balance} credit(s) remaining (projected: {$projectedBalance} with future accruals).";
        }

        return null;
    }

    /**
     * Handle Vacation Leave approval with per-day status assignment.
     *
     * Each day in the VL request can be assigned one of:
     * - vl_credited: Paid day (deducted from leave credits)
     * - upto: UPTO — Unpaid Time Off (no credits deducted, attendance on_leave)
     *
     * If day_statuses are provided by the admin, use them directly.
     * If not provided, auto-assign using credit logic (FIFO from earliest date).
     *
     * @param  array|null  $dayStatuses  Optional per-day statuses from admin
     */
    protected function handleVlApproval(LeaveRequest $leaveRequest, LeaveCreditService $leaveCreditService, ?array $dayStatuses = null): void
    {
        $user = $leaveRequest->user;
        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);
        $year = $startDate->year;

        // Get denied dates if this is a partial denial
        $deniedDates = [];
        if ($leaveRequest->has_partial_denial) {
            $deniedDates = $leaveRequest->deniedDates()
                ->pluck('denied_date')
                ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                ->toArray();
        }

        // Collect all valid dates in the leave period (excluding denied dates)
        $allDates = [];
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            if (! in_array($dateStr, $deniedDates)) {
                $allDates[] = $dateStr;
            }
            $currentDate->addDay();
        }

        // Build the per-day status map — day_statuses are now required for VL
        if ($dayStatuses !== null) {
            // Admin explicitly provided per-day statuses
            $dayStatusMap = collect($dayStatuses)->keyBy('date');
        } else {
            // Fallback to auto-assign only for legacy/edge cases (e.g., pre-stored records)
            \Log::warning("VL approval without explicit day_statuses for Leave Request #{$leaveRequest->id} — using auto-assign fallback");
            $dayStatusMap = $this->autoAssignVlDayStatuses($leaveRequest, $leaveCreditService, $allDates);
        }

        // Store per-day statuses in leave_request_days table
        $this->storeLeaveRequestDays($leaveRequest, $dayStatusMap);

        // Process each day based on its assigned status
        $creditedDays = 0;
        $uptoDays = 0;

        foreach ($allDates as $dateStr) {
            $dayInfo = $dayStatusMap[$dateStr] ?? null;
            $status = $dayInfo['status'] ?? LeaveRequestDay::STATUS_UPTO;
            $dayNotes = $dayInfo['notes'] ?? null;

            switch ($status) {
                case LeaveRequestDay::STATUS_VL_CREDITED:
                    // Paid day — set attendance to on_leave
                    $this->createOrUpdateAttendanceForDate(
                        $user,
                        $dateStr,
                        'on_leave',
                        "VL Credited (Paid) - Leave Request #{$leaveRequest->id}".($dayNotes ? " - {$dayNotes}" : ''),
                        $leaveRequest->id
                    );
                    $creditedDays++;
                    break;

                case LeaveRequestDay::STATUS_UPTO:
                    // UPTO — Unpaid Time Off (attendance marked as on_leave, no violation)
                    $this->createOrUpdateAttendanceForDate(
                        $user,
                        $dateStr,
                        'on_leave',
                        "UPTO (Unpaid) - VL Request #{$leaveRequest->id} - Insufficient VL credits".($dayNotes ? " - {$dayNotes}" : ''),
                        $leaveRequest->id
                    );
                    $uptoDays++;
                    break;

                default:
                    // Fallback — treat as UPTO (on_leave, no violation)
                    $this->createOrUpdateAttendanceForDate(
                        $user,
                        $dateStr,
                        'on_leave',
                        "UPTO (Unpaid) - VL Request #{$leaveRequest->id}".($dayNotes ? " - {$dayNotes}" : ''),
                        $leaveRequest->id
                    );
                    $uptoDays++;
                    break;
            }
        }

        // Deduct credits only for VL Credited days
        if ($creditedDays > 0) {
            // Only deduct from actually-accrued credit records.
            // Future credit records are NOT pre-created — approval is blocked
            // until the agent has accrued enough real credits.

            // Temporarily set days_requested to credited days for deduction
            $originalDays = $leaveRequest->days_requested;
            $leaveRequest->days_requested = $creditedDays;
            $leaveCreditService->deductCredits($leaveRequest, $year);
            $leaveRequest->days_requested = $originalDays;

            // deductCredits already sets credits_deducted to the actual amount deducted
        } else {
            // No credited days — no deduction needed
            $leaveRequest->update([
                'credits_deducted' => 0,
            ]);
        }
    }

    /**
     * Handle Solo Parent Leave approval with automatic FIFO credit allocation.
     *
     * Credits are assigned chronologically (earliest dates first) until exhausted:
     * - spl_credited: Paid from SPL credits. Attendance → 'on_leave', points auto-excused (no cert needed).
     * - auto-denied: Dates that cannot be covered by credits are automatically denied (partial denial).
     *
     * Half-day support: Pre-stored day records from submission may have is_half_day (0.5 credit instead of 1.0).
     */
    protected function handleSplApproval(LeaveRequest $leaveRequest, array $halfDayOverrides = []): void
    {
        $user = $leaveRequest->user;
        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);

        // Get denied dates if this is already a partial denial
        $existingDeniedDates = [];
        if ($leaveRequest->has_partial_denial) {
            $existingDeniedDates = $leaveRequest->deniedDates()
                ->pluck('denied_date')
                ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                ->toArray();
        }

        // Collect all valid weekday dates in the leave period (excluding weekends and already-denied dates)
        $allDates = [];
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            if ($currentDate->isWeekday() && ! in_array($dateStr, $existingDeniedDates)) {
                $allDates[] = $dateStr;
            }
            $currentDate->addDay();
        }

        // Get is_half_day settings from pre-stored day records (from submission), then apply admin overrides
        $preStoredDays = LeaveRequestDay::where('leave_request_id', $leaveRequest->id)
            ->pluck('is_half_day', 'date')
            ->mapWithKeys(fn ($isHalf, $date) => [Carbon::parse($date)->format('Y-m-d') => (bool) $isHalf]);

        // Merge admin half-day overrides on top of pre-stored settings
        $halfDayMap = $preStoredDays->toArray();
        foreach ($halfDayOverrides as $date => $isHalf) {
            $halfDayMap[$date] = (bool) $isHalf;
        }

        // Compute FIFO coverage: which dates are credited and which are uncovered
        $balance = $this->splCreditService->getBalance($user, $startDate->year);
        $creditsRemaining = $balance;
        $creditedDates = [];
        $uncoveredDates = [];

        foreach ($allDates as $dateStr) {
            $isHalfDay = $halfDayMap[$dateStr] ?? false;
            $creditNeeded = $isHalfDay ? 0.5 : 1.0;

            // Auto-downgrade to half-day if only 0.5 credits remain and day is full
            if (! $isHalfDay && $creditsRemaining >= 0.5 && $creditsRemaining < 1.0) {
                $isHalfDay = true;
                $creditNeeded = 0.5;
                $halfDayMap[$dateStr] = true;
            }

            if ($creditsRemaining >= $creditNeeded) {
                $creditedDates[] = $dateStr;
                $creditsRemaining -= $creditNeeded;
            } else {
                $uncoveredDates[] = $dateStr;
            }
        }

        // Auto-deny uncovered dates (insufficient credits)
        if (! empty($uncoveredDates)) {
            // Store original dates before modification (only on first partial denial)
            if (! $leaveRequest->original_start_date) {
                $leaveRequest->original_start_date = $leaveRequest->start_date;
            }
            if (! $leaveRequest->original_end_date) {
                $leaveRequest->original_end_date = $leaveRequest->end_date;
            }

            // Create denied date records for uncovered dates
            $approverId = auth()->id();
            foreach ($uncoveredDates as $dateStr) {
                LeaveRequestDeniedDate::create([
                    'leave_request_id' => $leaveRequest->id,
                    'denied_date' => $dateStr,
                    'denial_reason' => 'Auto-denied: Insufficient SPL credits',
                    'denied_by' => $approverId,
                ]);
            }

            // Update leave request with partial denial info
            if (! empty($creditedDates)) {
                $leaveRequest->start_date = Carbon::parse(min($creditedDates));
                $leaveRequest->end_date = Carbon::parse(max($creditedDates));
            }
            $leaveRequest->has_partial_denial = true;
            $leaveRequest->approved_days = count($creditedDates);
            $leaveRequest->save();
        }

        // Clear existing day records and rebuild (only for credited dates)
        $leaveRequest->days()->delete();
        $assignedBy = auth()->id();
        $now = now();
        $totalCreditsToDeduct = 0;

        foreach ($creditedDates as $dateStr) {
            $isHalfDay = $halfDayMap[$dateStr] ?? false;
            $creditValue = $isHalfDay ? 0.5 : 1.0;
            $halfDayLabel = $isHalfDay ? ' (Half-day)' : '';

            LeaveRequestDay::create([
                'leave_request_id' => $leaveRequest->id,
                'date' => $dateStr,
                'day_status' => LeaveRequestDay::STATUS_SPL_CREDITED,
                'is_half_day' => $isHalfDay,
                'notes' => 'Auto-assigned: SPL credit applied',
                'assigned_by' => $assignedBy,
                'assigned_at' => $now,
            ]);

            $this->createOrUpdateAttendanceForDate(
                $user,
                $dateStr,
                'on_leave',
                "SPL Credited{$halfDayLabel} - Leave Request #{$leaveRequest->id}",
                $leaveRequest->id
            );

            $totalCreditsToDeduct += $creditValue;
        }

        // Deduct SPL credits for credited days
        if ($totalCreditsToDeduct > 0) {
            $this->splCreditService->deductCredits($leaveRequest, $totalCreditsToDeduct, $startDate->year);
        } else {
            $leaveRequest->update(['credits_deducted' => 0]);
        }

        // Auto-excuse attendance points for spl_credited days (no certificate required for SPL)
        $this->autoExcuseAttendancePointsForSplDays($leaveRequest);

        \Log::info("SPL approval with auto-FIFO credit allocation for Leave Request #{$leaveRequest->id}", [
            'user_id' => $user->id,
            'credited_days' => count($creditedDates),
            'auto_denied_days' => count($uncoveredDates),
            'total_credits_deducted' => $totalCreditsToDeduct,
            'total_days' => count($allDates),
        ]);
    }

    /**
     * Auto-excuse attendance points for SPL Credited days.
     * SPL does NOT require a medical certificate — points are auto-excused on approval.
     * Only spl_credited days are excused; absent days are left untouched.
     */
    protected function autoExcuseAttendancePointsForSplDays(LeaveRequest $leaveRequest): int
    {
        $user = $leaveRequest->user;

        // Get spl_credited day dates
        $creditedDates = $leaveRequest->days()
            ->where('day_status', LeaveRequestDay::STATUS_SPL_CREDITED)
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->toArray();

        if (empty($creditedDates)) {
            return 0;
        }

        $reason = "Auto-excused: Approved SPL (Solo Parent Leave) - Leave Request #{$leaveRequest->id}";

        $pointsToExcuse = AttendancePoint::where('user_id', $user->id)
            ->where('is_excused', false)
            ->whereIn('shift_date', $creditedDates)
            ->get();

        $excusedCount = 0;
        foreach ($pointsToExcuse as $point) {
            $point->update([
                'is_excused' => true,
                'excused_by' => auth()->id(),
                'excused_at' => now(),
                'excuse_reason' => $reason,
            ]);
            $excusedCount++;
        }

        // Recalculate GBRO if any points were excused
        if ($excusedCount > 0) {
            try {
                $gbroService = app(GbroCalculationService::class);
                $gbroService->cascadeRecalculateGbro($user->id);
            } catch (\Exception $e) {
                \Log::warning("Failed to recalculate GBRO after SPL auto-excusing: {$e->getMessage()}");
            }
        }

        \Log::info("Auto-excused {$excusedCount} attendance points for SPL Leave Request #{$leaveRequest->id}", [
            'user_id' => $user->id,
            'credited_dates' => $creditedDates,
        ]);

        return $excusedCount;
    }

    /**
     * Create or update an attendance record for a specific date.
     */
    protected function createOrUpdateAttendanceForDate(
        User $user,
        string $dateStr,
        string $status,
        string $notes,
        int $leaveRequestId
    ): void {
        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $dateStr)
            ->first();

        if ($attendance) {
            // Skip NCNS days
            if ($attendance->status === 'ncns') {
                $existingNotes = $attendance->notes ? $attendance->notes."\n" : '';
                $attendance->update([
                    'notes' => $existingNotes."Leave applied but NCNS status preserved - Leave Request #{$leaveRequestId}",
                    'leave_request_id' => $leaveRequestId,
                    'pre_leave_status' => 'ncns',
                    'admin_verified' => true,
                ]);

                return;
            }

            $attendance->update([
                'pre_leave_status' => $attendance->status,
                'status' => $status,
                'notes' => $notes,
                'leave_request_id' => $leaveRequestId,
                'admin_verified' => true,
            ]);
        } else {
            $schedule = $this->getActiveScheduleForDate($user->id, $dateStr);
            Attendance::create([
                'user_id' => $user->id,
                'employee_schedule_id' => $schedule?->id,
                'shift_date' => $dateStr,
                'scheduled_time_in' => $schedule?->scheduled_time_in,
                'scheduled_time_out' => $schedule?->scheduled_time_out,
                'status' => $status,
                'notes' => $notes,
                'leave_request_id' => $leaveRequestId,
                'admin_verified' => true,
            ]);
        }
    }

    /**
     * Handle Sick Leave approval with per-day status assignment.
     *
     * Each day in the SL request can be assigned one of:
     * - sl_credited: Paid day (deducted from leave credits)
     * - ncns: No Call No Show (unpaid, gets attendance point)
     * - advised_absence: Agent informed but no credits (UPTO - Unpaid Time Off)
     *
     * If day_statuses are provided by the admin, use them directly.
     * If not provided, auto-assign using existing credit logic (backward compat).
     *
     * @param  array|null  $dayStatuses  Optional per-day statuses from admin
     */
    protected function handleSlApproval(LeaveRequest $leaveRequest, LeaveCreditService $leaveCreditService, ?array $dayStatuses = null): void
    {
        $user = $leaveRequest->user;
        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);

        // Get denied dates if this is a partial denial
        $deniedDates = [];
        if ($leaveRequest->has_partial_denial) {
            $deniedDates = $leaveRequest->deniedDates()
                ->pluck('denied_date')
                ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                ->toArray();
        }

        // Collect all valid workdays in the leave period (excluding denied dates)
        $allDates = [];
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            if (! in_array($dateStr, $deniedDates)) {
                $allDates[] = $dateStr;
            }
            $currentDate->addDay();
        }

        // Build the per-day status map — day_statuses are now required for SL
        if ($dayStatuses !== null) {
            // Admin explicitly provided per-day statuses
            $dayStatusMap = collect($dayStatuses)->keyBy('date');
        } else {
            // Fallback to auto-assign only for legacy/edge cases (e.g., pre-stored records)
            \Log::warning("SL approval without explicit day_statuses for Leave Request #{$leaveRequest->id} — using auto-assign fallback");
            $dayStatusMap = $this->autoAssignSlDayStatuses($leaveRequest, $leaveCreditService, $allDates);
        }

        // Store per-day statuses in leave_request_days table
        $this->storeLeaveRequestDays($leaveRequest, $dayStatusMap);

        // Process each day based on its assigned status
        $creditedDays = 0;
        $ncnsDays = 0;
        $advisedAbsenceDays = 0;

        foreach ($allDates as $dateStr) {
            $dayInfo = $dayStatusMap[$dateStr] ?? null;
            $status = $dayInfo['status'] ?? LeaveRequestDay::STATUS_ADVISED_ABSENCE;
            $dayNotes = $dayInfo['notes'] ?? null;

            switch ($status) {
                case LeaveRequestDay::STATUS_SL_CREDITED:
                    // Paid day — set attendance to on_leave
                    $this->createOrUpdateAttendanceForSlDay(
                        $user,
                        $dateStr,
                        'on_leave',
                        "SL Credited (Paid) - Leave Request #{$leaveRequest->id}".($dayNotes ? " - {$dayNotes}" : ''),
                        $leaveRequest->id
                    );
                    $creditedDays++;
                    break;

                case LeaveRequestDay::STATUS_NCNS:
                    // NCNS — preserve/set ncns status, create attendance point
                    $this->processNcnsDay($user, $dateStr, $leaveRequest->id, $dayNotes);
                    $ncnsDays++;
                    break;

                case LeaveRequestDay::STATUS_ADVISED_ABSENCE:
                    // Advised Absence (UPTO) — unpaid, set advised_absence
                    $this->createOrUpdateAttendanceForSlDay(
                        $user,
                        $dateStr,
                        'advised_absence',
                        "Advised Absence (UPTO - Unpaid) - Leave Request #{$leaveRequest->id}".($dayNotes ? " - {$dayNotes}" : ''),
                        $leaveRequest->id
                    );
                    $advisedAbsenceDays++;
                    break;
            }
        }

        // Deduct credits only for sl_credited days
        if ($creditedDays > 0) {
            $year = $startDate->year;
            $originalDays = $leaveRequest->days_requested;
            $leaveRequest->days_requested = $creditedDays;
            $leaveCreditService->deductCredits($leaveRequest, $year);
            $leaveRequest->days_requested = $originalDays;
        }

        // Update the leave request with summary info
        $leaveRequest->update([
            'credits_deducted' => $creditedDays,
            'sl_credits_applied' => $creditedDays > 0,
            'approved_days' => $creditedDays,
            'sl_no_credit_reason' => $this->buildSlSummaryReason($creditedDays, $ncnsDays, $advisedAbsenceDays),
        ]);

        // Auto-excuse attendance points for credited and advised absence days (with med cert)
        // NCNS days are NEVER auto-excused
        $this->autoExcuseAttendancePointsForSlDays($leaveRequest);

        \Log::info("SL approval with per-day statuses for Leave Request #{$leaveRequest->id}", [
            'user_id' => $user->id,
            'credited_days' => $creditedDays,
            'ncns_days' => $ncnsDays,
            'advised_absence_days' => $advisedAbsenceDays,
            'total_days' => count($allDates),
            'admin_assigned' => $dayStatuses !== null,
        ]);
    }

    /**
     * Auto-assign per-day statuses for SL based on credit availability and existing attendance.
     *
     * Logic:
     * 1. Check existing attendance — any existing NCNS days stay as NCNS
     * 2. Check credit availability for remaining non-NCNS days
     * 3. First N non-NCNS days get sl_credited (up to available credits)
     * 4. Remaining non-NCNS days get advised_absence
     *
     * @return Collection<string, array{date: string, status: string, notes: string|null}>
     */
    protected function autoAssignSlDayStatuses(LeaveRequest $leaveRequest, LeaveCreditService $leaveCreditService, array $allDates): Collection
    {
        $user = $leaveRequest->user;
        $creditCheck = $leaveCreditService->checkSlCreditDeduction($user, $leaveRequest);

        // Get existing attendance records to detect NCNS
        $existingAttendances = Attendance::where('user_id', $user->id)
            ->whereIn('shift_date', $allDates)
            ->pluck('status', 'shift_date')
            ->mapWithKeys(fn ($status, $date) => [Carbon::parse($date)->format('Y-m-d') => $status]);

        $result = collect();
        $creditsAvailable = $creditCheck['should_deduct'] ? (int) ($creditCheck['credits_to_deduct'] ?? 0) : 0;

        // If med cert not submitted or not eligible, no credits
        if (! $creditCheck['should_deduct'] && ! ($creditCheck['convert_to_upto'] ?? false) && ! ($creditCheck['partial_credit'] ?? false)) {
            $creditsAvailable = 0;
        }

        $creditsUsed = 0;

        foreach ($allDates as $dateStr) {
            $existingStatus = $existingAttendances[$dateStr] ?? null;

            if ($existingStatus === 'ncns') {
                // Existing NCNS — preserve
                $result[$dateStr] = [
                    'date' => $dateStr,
                    'status' => LeaveRequestDay::STATUS_NCNS,
                    'notes' => 'Auto-detected: Existing NCNS status preserved',
                ];
            } elseif ($creditsUsed < $creditsAvailable) {
                // Has credits remaining — mark as SL Credited
                $result[$dateStr] = [
                    'date' => $dateStr,
                    'status' => LeaveRequestDay::STATUS_SL_CREDITED,
                    'notes' => 'Auto-assigned: SL credit applied',
                ];
                $creditsUsed++;
            } else {
                // No credits remaining — Advised Absence (UPTO)
                $result[$dateStr] = [
                    'date' => $dateStr,
                    'status' => LeaveRequestDay::STATUS_ADVISED_ABSENCE,
                    'notes' => 'Auto-assigned: No SL credits remaining (UPTO - Unpaid)',
                ];
            }
        }

        return $result;
    }

    /**
     * Auto-assign per-day statuses for VL based on credit availability.
     *
     * Logic:
     * 1. Check VL credit availability
     * 2. First N days get vl_credited (up to available credits, FIFO from earliest date)
     * 3. Remaining days get upto (UPTO — Unpaid Time Off)
     *
     * @return Collection<string, array{date: string, status: string, notes: string|null}>
     */
    protected function autoAssignVlDayStatuses(LeaveRequest $leaveRequest, LeaveCreditService $leaveCreditService, array $allDates): Collection
    {
        $user = $leaveRequest->user;
        $creditCheck = $leaveCreditService->checkVlCreditDeduction($user, $leaveRequest);

        $result = collect();
        $creditsAvailable = 0;

        if ($creditCheck['should_deduct']) {
            $creditsAvailable = (int) ($creditCheck['credits_to_deduct'] ?? 0);
        } elseif ($creditCheck['convert_to_upto'] ?? false) {
            $creditsAvailable = 0;
        }

        $creditsUsed = 0;

        foreach ($allDates as $dateStr) {
            if ($creditsUsed < $creditsAvailable) {
                // Has credits remaining — mark as VL Credited
                $result[$dateStr] = [
                    'date' => $dateStr,
                    'status' => LeaveRequestDay::STATUS_VL_CREDITED,
                    'notes' => 'Auto-assigned: VL credit applied',
                ];
                $creditsUsed++;
            } else {
                // No credits remaining — UPTO (Unpaid Time Off)
                $result[$dateStr] = [
                    'date' => $dateStr,
                    'status' => LeaveRequestDay::STATUS_UPTO,
                    'notes' => 'Auto-assigned: No VL credits remaining (UPTO - Unpaid)',
                ];
            }
        }

        return $result;
    }

    /**
     * Store per-day statuses in the leave_request_days table.
     */
    protected function storeLeaveRequestDays(LeaveRequest $leaveRequest, $dayStatusMap): void
    {
        // Delete any existing day records for this request (for re-assignment)
        $leaveRequest->days()->delete();

        $assignedBy = auth()->id();
        $now = now();

        foreach ($dayStatusMap as $dateStr => $dayInfo) {
            LeaveRequestDay::create([
                'leave_request_id' => $leaveRequest->id,
                'date' => $dayInfo['date'] ?? $dateStr,
                'day_status' => $dayInfo['status'],
                'is_half_day' => ! empty($dayInfo['is_half_day']),
                'notes' => $dayInfo['notes'] ?? null,
                'assigned_by' => $assignedBy,
                'assigned_at' => $now,
            ]);
        }
    }

    /**
     * Create or update an attendance record for a specific SL day.
     * Unlike the generic createOrUpdateAttendanceForDate, this does NOT skip NCNS days —
     * the admin has explicitly assigned the status.
     */
    protected function createOrUpdateAttendanceForSlDay(
        User $user,
        string $dateStr,
        string $status,
        string $notes,
        int $leaveRequestId
    ): void {
        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $dateStr)
            ->first();

        if ($attendance) {
            $attendance->update([
                'pre_leave_status' => $attendance->status,
                'status' => $status,
                'notes' => $notes,
                'leave_request_id' => $leaveRequestId,
                'admin_verified' => true,
            ]);
        } else {
            $schedule = $this->getActiveScheduleForDate($user->id, $dateStr);
            Attendance::create([
                'user_id' => $user->id,
                'employee_schedule_id' => $schedule?->id,
                'shift_date' => $dateStr,
                'scheduled_time_in' => $schedule?->scheduled_time_in,
                'scheduled_time_out' => $schedule?->scheduled_time_out,
                'status' => $status,
                'notes' => $notes,
                'leave_request_id' => $leaveRequestId,
                'admin_verified' => true,
            ]);
        }
    }

    /**
     * Process an NCNS day for SL approval.
     * Sets/preserves ncns status on attendance and creates attendance point.
     */
    protected function processNcnsDay(User $user, string $dateStr, int $leaveRequestId, ?string $dayNotes = null): void
    {
        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $dateStr)
            ->first();

        $ncnsNote = "NCNS - Failed to notify team lead/manager - Leave Request #{$leaveRequestId}".($dayNotes ? " - {$dayNotes}" : '');

        if ($attendance) {
            if ($attendance->status === 'ncns') {
                // Already NCNS — just annotate
                $existingNotes = $attendance->notes ? $attendance->notes."\n" : '';
                $attendance->update([
                    'notes' => $existingNotes."SL applied - NCNS status preserved - Leave Request #{$leaveRequestId}",
                    'admin_verified' => true,
                ]);
            } else {
                // Force to NCNS per admin assignment
                $attendance->update([
                    'pre_leave_status' => $attendance->status,
                    'status' => 'ncns',
                    'notes' => $ncnsNote,
                    'leave_request_id' => $leaveRequestId,
                    'admin_verified' => true,
                ]);
            }
        } else {
            // Create new NCNS attendance record
            $schedule = $this->getActiveScheduleForDate($user->id, $dateStr);
            $attendance = Attendance::create([
                'user_id' => $user->id,
                'employee_schedule_id' => $schedule?->id,
                'shift_date' => $dateStr,
                'scheduled_time_in' => $schedule?->scheduled_time_in,
                'scheduled_time_out' => $schedule?->scheduled_time_out,
                'status' => 'ncns',
                'notes' => $ncnsNote,
                'leave_request_id' => $leaveRequestId,
                'admin_verified' => true,
            ]);
        }

        // Create attendance point for NCNS day (1.00 point, whole_day_absence)
        // Only create if one doesn't already exist for this date
        $existingPoint = AttendancePoint::where('user_id', $user->id)
            ->where('shift_date', $dateStr)
            ->where('point_type', 'whole_day_absence')
            ->first();

        if (! $existingPoint) {
            AttendancePoint::create([
                'user_id' => $user->id,
                'attendance_id' => $attendance->id,
                'shift_date' => $dateStr,
                'point_type' => 'whole_day_absence',
                'points' => 1.00,
                'status' => 'ncns',
                'is_advised' => false,
                'is_manual' => true,
                'created_by' => auth()->id(),
                'violation_details' => "NCNS during SL period - Leave Request #{$leaveRequestId}",
                'expires_at' => Carbon::parse($dateStr)->addYear(), // NCNS = 1 year expiration
                'expiration_type' => 'sro',
            ]);
        }
    }

    /**
     * Auto-excuse attendance points for SL days that are credited or advised absence (with med cert).
     * NCNS days' points are NEVER auto-excused.
     */
    protected function autoExcuseAttendancePointsForSlDays(LeaveRequest $leaveRequest): int
    {
        if (! $leaveRequest->medical_cert_submitted) {
            return 0;
        }

        $user = $leaveRequest->user;
        $excusedCount = 0;

        // Get the per-day statuses — only excuse points for sl_credited and advised_absence days
        $excusableDates = $leaveRequest->days()
            ->whereIn('day_status', [LeaveRequestDay::STATUS_SL_CREDITED, LeaveRequestDay::STATUS_ADVISED_ABSENCE])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->toArray();

        if (empty($excusableDates)) {
            return 0;
        }

        $certificateType = 'medical certificate';
        $reason = "Auto-excused: Approved SL with {$certificateType} - Leave Request #{$leaveRequest->id}";

        $pointsToExcuse = AttendancePoint::where('user_id', $user->id)
            ->where('is_excused', false)
            ->whereIn('shift_date', $excusableDates)
            ->get();

        foreach ($pointsToExcuse as $point) {
            $point->update([
                'is_excused' => true,
                'excused_by' => auth()->id(),
                'excused_at' => now(),
                'excuse_reason' => $reason,
            ]);
            $excusedCount++;
        }

        if ($excusedCount > 0) {
            try {
                $gbroService = app(GbroCalculationService::class);
                $gbroService->cascadeRecalculateGbro($user->id);
            } catch (\Exception $e) {
                \Log::warning("Failed to recalculate GBRO after auto-excusing SL points: {$e->getMessage()}");
            }
        }

        \Log::info("Auto-excused {$excusedCount} attendance points for SL Leave Request #{$leaveRequest->id} (excused dates: ".implode(', ', $excusableDates).')');

        return $excusedCount;
    }

    /**
     * Build a summary reason string for SL per-day status breakdown.
     */
    protected function buildSlSummaryReason(int $creditedDays, int $ncnsDays, int $advisedAbsenceDays): ?string
    {
        $parts = [];
        if ($creditedDays > 0) {
            $parts[] = "{$creditedDays} day(s) SL Credited (Paid)";
        }
        if ($ncnsDays > 0) {
            $parts[] = "{$ncnsDays} day(s) NCNS";
        }
        if ($advisedAbsenceDays > 0) {
            $parts[] = "{$advisedAbsenceDays} day(s) Advised Absence (UPTO - Unpaid)";
        }

        return ! empty($parts) ? implode(', ', $parts) : null;
    }

    /**
     * Rollback attendance records when a leave request is cancelled.
     *
     * Records with pre_leave_status (existing before approval) → revert to original status.
     * Records with null pre_leave_status (created by approval) → delete entirely.
     *
     * @return array{reverted: int, deleted: int} Count of affected records
     */
    protected function rollbackAttendanceForCancelledLeave(LeaveRequest $leaveRequest): array
    {
        $attendances = Attendance::where('leave_request_id', $leaveRequest->id)->get();

        $reverted = 0;
        $deleted = 0;

        foreach ($attendances as $attendance) {
            if ($attendance->pre_leave_status !== null) {
                // Record existed before approval — revert to original status
                $attendance->update([
                    'status' => $attendance->pre_leave_status,
                    'pre_leave_status' => null,
                    'leave_request_id' => null,
                    'admin_verified' => false,
                    'notes' => null,
                ]);
                $reverted++;
            } else {
                // Record was created by leave approval — delete it
                $attendance->delete();
                $deleted++;
            }
        }

        \Log::info("Attendance rollback for Leave Request #{$leaveRequest->id}", [
            'reverted' => $reverted,
            'deleted' => $deleted,
        ]);

        return ['reverted' => $reverted, 'deleted' => $deleted];
    }

    /**
     * Rollback auto-excused attendance points when a leave request is cancelled.
     *
     * Only un-excuses points that were auto-excused for this specific leave request
     * (identified by excuse_reason containing the leave request ID).
     * Recalculates GBRO after un-excusing.
     *
     * @return int Number of points un-excused
     */
    protected function rollbackExcusedAttendancePoints(LeaveRequest $leaveRequest): int
    {
        // Only SL, UPTO, and BL with medical/death cert had auto-excused points
        if (! in_array($leaveRequest->leave_type, ['SL', 'UPTO', 'BL'])) {
            return 0;
        }

        if (! $leaveRequest->medical_cert_submitted) {
            return 0;
        }

        $user = $leaveRequest->user;

        // Find points auto-excused for this specific leave request
        $pointsToRevert = AttendancePoint::where('user_id', $user->id)
            ->where('is_excused', true)
            ->where('excuse_reason', 'LIKE', "%Leave Request #{$leaveRequest->id}%")
            ->get();

        $unExcusedCount = 0;

        foreach ($pointsToRevert as $point) {
            $point->update([
                'is_excused' => false,
                'excused_by' => null,
                'excused_at' => null,
                'excuse_reason' => null,
            ]);
            $unExcusedCount++;
        }

        // Recalculate GBRO if any points were un-excused
        if ($unExcusedCount > 0) {
            try {
                $gbroService = app(GbroCalculationService::class);
                $gbroService->cascadeRecalculateGbro($user->id);
            } catch (\Exception $e) {
                \Log::warning("Failed to recalculate GBRO after un-excusing points: {$e->getMessage()}");
            }
        }

        \Log::info("Un-excused {$unExcusedCount} attendance points for cancelled Leave Request #{$leaveRequest->id}", [
            'user_id' => $user->id,
            'leave_type' => $leaveRequest->leave_type,
        ]);

        return $unExcusedCount;
    }

    /**
     * Automatically excuse attendance points for approved leave requests with medical certificate.
     * This applies to SL and UPTO with medical certificate submitted.
     *
     * @param  LeaveRequest  $leaveRequest  The approved leave request
     * @param  string|null  $excuseReason  Optional custom excuse reason
     * @return int Number of points excused
     */
    protected function autoExcuseAttendancePoints(LeaveRequest $leaveRequest, ?string $excuseReason = null): int
    {
        // Only auto-excuse if certificate is submitted
        // - For SL: requires medical certificate
        // - For UPTO: any supporting certificate/document is acceptable
        if (! $leaveRequest->medical_cert_submitted) {
            return 0;
        }

        // Only for SL, UPTO, and BL leave types
        if (! in_array($leaveRequest->leave_type, ['SL', 'UPTO', 'BL'])) {
            return 0;
        }

        $user = $leaveRequest->user;
        $startDate = Carbon::parse($leaveRequest->start_date)->format('Y-m-d');
        $endDate = Carbon::parse($leaveRequest->end_date)->format('Y-m-d');

        // Get denied dates if this is a partial denial
        $deniedDates = [];
        if ($leaveRequest->has_partial_denial) {
            $deniedDates = $leaveRequest->deniedDates()
                ->pluck('denied_date')
                ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                ->toArray();
        }

        // Build the default excuse reason
        $certificateType = match ($leaveRequest->leave_type) {
            'SL' => 'medical certificate',
            'BL' => 'death certificate',
            default => 'supporting certificate',
        };
        $defaultReason = "Auto-excused: Approved {$leaveRequest->leave_type} with {$certificateType} - Leave Request #{$leaveRequest->id}";
        $reason = $excuseReason ?? $defaultReason;

        // Find and excuse attendance points for the leave period
        $query = AttendancePoint::where('user_id', $user->id)
            ->where('is_excused', false)
            ->whereBetween('shift_date', [$startDate, $endDate]);

        if (! empty($deniedDates)) {
            $query->whereNotIn('shift_date', $deniedDates);
        }

        $pointsToExcuse = $query->get();
        $excusedCount = 0;

        foreach ($pointsToExcuse as $point) {
            $point->update([
                'is_excused' => true,
                'excused_by' => auth()->id(),
                'excused_at' => now(),
                'excuse_reason' => $reason,
            ]);
            $excusedCount++;
        }

        // If any points were excused, recalculate GBRO
        if ($excusedCount > 0) {
            try {
                $gbroService = app(GbroCalculationService::class);
                $gbroService->cascadeRecalculateGbro($user->id);
            } catch (\Exception $e) {
                \Log::warning("Failed to recalculate GBRO after auto-excusing points: {$e->getMessage()}");
            }
        }

        \Log::info("Auto-excused {$excusedCount} attendance points for Leave Request #{$leaveRequest->id}", [
            'user_id' => $user->id,
            'leave_type' => $leaveRequest->leave_type,
            'period' => "{$startDate} to {$endDate}",
        ]);

        return $excusedCount;
    }

    /**
     * Update attendance records for approved leave (VL, BL, etc.) to on_leave status.
     * For UPTO with medical certificate, also auto-excuse attendance points.
     */
    protected function updateAttendanceForApprovedLeave(LeaveRequest $leaveRequest): void
    {
        $user = $leaveRequest->user;
        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);
        $leaveNote = "On approved {$leaveRequest->leave_type}".($leaveRequest->reason ? " - {$leaveRequest->reason}" : '');

        // Get denied dates if this is a partial denial
        $deniedDates = [];
        if ($leaveRequest->has_partial_denial) {
            $deniedDates = $leaveRequest->deniedDates()
                ->pluck('denied_date')
                ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                ->toArray();
        }

        // Update existing attendance records (these already have schedule data)
        // Only update dates that are NOT denied
        // First, store pre_leave_status for each record individually
        $existingAttendances = Attendance::where('user_id', $user->id)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

        if (! empty($deniedDates)) {
            $existingAttendances->whereNotIn('shift_date', $deniedDates);
        }

        foreach ($existingAttendances->get() as $attendance) {
            $attendance->update([
                'pre_leave_status' => $attendance->status,
                'status' => 'on_leave',
                'notes' => $leaveNote,
                'leave_request_id' => $leaveRequest->id,
                'admin_verified' => true, // Auto-verify leave records
            ]);
        }

        // Create attendance records for days without existing records
        $existingDates = Attendance::where('user_id', $user->id)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->pluck('shift_date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->toArray();

        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            // Skip denied dates and existing dates
            if (! in_array($dateStr, $existingDates) && ! in_array($dateStr, $deniedDates)) {
                // Get the user's active schedule for this date
                $schedule = $this->getActiveScheduleForDate($user->id, $dateStr);

                // pre_leave_status null = created by leave approval
                Attendance::create([
                    'user_id' => $user->id,
                    'employee_schedule_id' => $schedule?->id,
                    'shift_date' => $dateStr,
                    'scheduled_time_in' => $schedule?->scheduled_time_in,
                    'scheduled_time_out' => $schedule?->scheduled_time_out,
                    'status' => 'on_leave',
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                    'admin_verified' => true, // Auto-verify leave records
                ]);
            }
            $currentDate->addDay();
        }

        // Auto-excuse attendance points for UPTO/BL with certificate
        // (SL is handled in handleSlApproval, but UPTO and BL submitted directly also need this)
        if (in_array($leaveRequest->leave_type, ['UPTO', 'BL']) && $leaveRequest->medical_cert_submitted) {
            $this->autoExcuseAttendancePoints($leaveRequest);
        }
    }
}
