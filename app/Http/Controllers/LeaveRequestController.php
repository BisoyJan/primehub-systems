<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveRequestRequest;
use App\Jobs\GenerateLeaveCreditsExportExcel;
use App\Mail\LeaveRequestStatusUpdated;
use App\Mail\LeaveRequestSubmitted;
use App\Mail\LeaveRequestTLStatusUpdated;
use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDeniedDate;
use App\Models\User;
use App\Services\AttendancePoint\GbroCalculationService;
use App\Services\LeaveCreditService;
use App\Services\NotificationService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class LeaveRequestController extends Controller
{
    protected $leaveCreditService;

    protected $notificationService;

    public function __construct(LeaveCreditService $leaveCreditService, NotificationService $notificationService)
    {
        $this->leaveCreditService = $leaveCreditService;
        $this->notificationService = $notificationService;
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

        // Determine Team Lead's campaign (if applicable)
        $teamLeadCampaignId = null;
        $teamLeadCampaignName = null;
        if ($isTeamLead) {
            $activeSchedule = $user->activeSchedule;
            if ($activeSchedule && $activeSchedule->campaign_id) {
                $teamLeadCampaignId = $activeSchedule->campaign_id;
                $teamLeadCampaignName = $activeSchedule->campaign?->name;
            }
        }

        $query = LeaveRequest::with(['user', 'reviewer', 'adminApprover', 'hrApprover', 'tlApprover']);

        // Admins see all requests
        if ($isAdmin) {
            // No filter - see all
        } elseif ($isTeamLead) {
            // Team Leads see their own requests + all agent requests that require TL approval
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id) // Own requests
                    ->orWhere('requires_tl_approval', true); // All agent requests needing TL approval
            });
        } else {
            // Regular employees see only their own
            $query->forUser($user->id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        // Filter by leave type
        if ($request->filled('type')) {
            $query->byType($request->type);
        }

        // Filter by date range
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
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
        if (! $campaignFilter && $isTeamLead && $teamLeadCampaignName) {
            $campaignFilter = $teamLeadCampaignName;
        }
        if ($campaignFilter) {
            $query->where('campaign_department', $campaignFilter);
        }

        $leaveRequests = $query->orderBy('created_at', 'desc')
            ->paginate(25)
            ->withQueryString();

        // Get list of campaigns/departments for filters (unique names from campaigns table)
        $campaigns = \App\Models\Campaign::orderBy('name')->pluck('name')->toArray();

        // Get all employees who have leave requests (for admin/TL employee search dropdown)
        $allEmployees = [];
        if ($isAdmin || $isTeamLead) {
            $employeeQuery = \App\Models\User::whereHas('leaveRequests');

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
            'filters' => $request->only(['status', 'type', 'start_date', 'end_date', 'user_id', 'employee_name', 'campaign_department']),
            'campaigns' => $campaigns,
            'allEmployees' => $allEmployees,
            'isAdmin' => $isAdmin,
            'isTeamLead' => $isTeamLead,
            'teamLeadCampaignName' => $teamLeadCampaignName,
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

        // Detect Team Lead's campaign for auto-filter
        $teamLeadCampaignId = null;
        if ($user->role === 'Team Lead') {
            $activeSchedule = $user->activeSchedule;
            if ($activeSchedule && $activeSchedule->campaign_id) {
                $teamLeadCampaignId = $activeSchedule->campaign_id;
            }
        }

        // Get filters
        $month = $request->input('month', now()->format('Y-m'));
        $campaignId = $request->input('campaign_id');
        $leaveType = $request->input('leave_type');
        $status = $request->input('status'); // 'approved', 'pending', or null for both
        $viewMode = $request->input('view_mode', 'single'); // 'single' or 'multi'

        // Auto-filter campaign for Team Leads when no campaign is specified
        if (! $campaignId && $teamLeadCampaignId) {
            $campaignId = $teamLeadCampaignId;
        }

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
            // Admin filter by campaign
            $query->whereHas('user.employeeSchedules', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId)
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
            'teamLeadCampaignId' => $teamLeadCampaignId,
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

        // For admins creating leave for employees, check if employee_id is provided
        $targetUser = $user;
        if ($isAdmin && $request->filled('employee_id')) {
            $targetUser = User::findOrFail($request->employee_id);
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

        // Get all employees for admin selection
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
            'isAdmin' => $isAdmin,
            'employees' => $employees,
            'selectedEmployeeId' => $targetUser->id,
            'canOverrideShortNotice' => $canOverrideShortNotice,
            'existingLeaveRequests' => $existingLeaveRequests,
        ]);
    }

    /**
     * Store a newly created leave request.
     */
    public function store(LeaveRequestRequest $request)
    {
        $user = auth()->user();
        $leaveCreditService = $this->leaveCreditService;

        // For admins, allow creating leave for other employees
        $targetUser = $user;
        if (in_array($user->role, ['Super Admin', 'Admin']) && $request->filled('employee_id')) {
            $targetUser = User::findOrFail($request->employee_id);
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
                // Short notice override tracking
                'short_notice_override' => $shortNoticeOverride,
                'short_notice_override_by' => $shortNoticeOverrideBy,
                'short_notice_override_at' => $shortNoticeOverride ? now() : null,
            ]);

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

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', 'Leave request submitted successfully.');
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

        $leaveRequest->load(['user', 'reviewer', 'adminApprover', 'hrApprover', 'tlApprover', 'deniedDates.denier']);

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

        // Get campaign_id from user's active employee schedule
        $activeSchedule = $leaveRequest->user->employeeSchedules()
            ->where('is_active', true)
            ->first();
        $leaveRequestData['campaign_id'] = $activeSchedule?->campaign_id;

        // Check if leave end date has passed (cannot modify past leaves)
        $leaveEndDatePassed = $leaveRequest->end_date && $leaveRequest->end_date->endOfDay()->isPast();

        // Check if Admin/Super Admin can cancel approved leave (not if dates passed)
        $canAdminCancel = in_array($user->role, ['Super Admin', 'Admin'])
            && $leaveRequest->status === 'approved'
            && ! $leaveEndDatePassed;

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

        return Inertia::render('FormRequest/Leave/Show', [
            'leaveRequest' => $leaveRequestData,
            'isAdmin' => $isAdmin,
            'isTeamLead' => $isTeamLead,
            'isSuperAdmin' => $user->role === 'Super Admin',
            'canCancel' => $leaveRequest->canBeCancelled() && $leaveRequest->user_id === $user->id,
            'canAdminCancel' => $canAdminCancel,
            'canEditApproved' => $canEditApproved,
            'hasUserApproved' => $hasUserApproved,
            'canTlApprove' => $canTlApprove,
            'userRole' => $user->role,
            'canViewMedicalCert' => $canViewMedicalCert,
            'earlierConflicts' => $earlierConflicts,
            'absenceWindowInfo' => $absenceWindowInfo,
            'activeAttendancePoints' => $activeAttendancePoints,
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

        // Get current attendance points
        $attendancePoints = $leaveCreditService->getAttendancePoints($targetUser);

        // Check if short notice override is requested (Admin/Super Admin only)
        $shortNoticeOverride = false;
        if ($request->boolean('short_notice_override') && $isAdmin) {
            $shortNoticeOverride = true;
        }

        // Prepare data for validation
        // Use the leave request's created_at as the filing date for 2-week notice calculation
        $validationData = array_merge($request->validated(), [
            'days_requested' => $daysRequested,
            'credits_year' => now()->year,
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
        ]);

        $leaveCreditService = $this->leaveCreditService;

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
                    // Sick Leave - special handling with attendance check
                    $this->handleSlApproval($leaveRequest, $leaveCreditService);
                } elseif ($leaveRequest->requiresCredits()) {
                    // Other credited leave types (VL, BL) - normal deduction
                    $year = $request->input('credits_year', now()->year);

                    // If partial denial, only deduct for approved days
                    if ($leaveRequest->has_partial_denial && $leaveRequest->approved_days !== null) {
                        // Temporarily adjust days_requested for credit deduction
                        $originalDays = $leaveRequest->days_requested;
                        $leaveRequest->days_requested = $leaveRequest->approved_days;
                        $leaveCreditService->deductCredits($leaveRequest, $year);
                        $leaveRequest->days_requested = $originalDays;

                        // Update credits_deducted to reflect actual deduction
                        $leaveRequest->update(['credits_deducted' => $leaveRequest->approved_days]);
                    } else {
                        $leaveCreditService->deductCredits($leaveRequest, $year);
                    }

                    // Update attendance records to on_leave status
                    $this->updateAttendanceForApprovedLeave($leaveRequest);
                } else {
                    // Non-credited leave types (UPTO, LOA, BL, SPL, LDV, ML) - no credit deduction, but still update attendance
                    $this->updateAttendanceForApprovedLeave($leaveRequest);
                }

                // Notify the employee about full approval
                $this->notificationService->notifyLeaveRequestFullyApproved(
                    $leaveRequest->user_id,
                    $leaveRequest->leave_type,
                    $leaveRequest->id
                );

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

                return redirect()->route('leave-requests.show', $leaveRequest)
                    ->with('success', 'Leave request fully approved by both Admin and HR.');
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
        ]);

        $leaveCreditService = $this->leaveCreditService;
        $hasDeniedDates = ! empty($validated['denied_dates']);

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
                $leaveRequest->start_date = min($approvedDates);
                $leaveRequest->end_date = max($approvedDates);
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
                $this->handleSlApproval($leaveRequest, $leaveCreditService);
            } elseif ($leaveRequest->requiresCredits()) {
                $year = $request->input('credits_year', now()->year);

                // If partial denial, only deduct for approved days
                if ($leaveRequest->has_partial_denial && $leaveRequest->approved_days !== null) {
                    // Temporarily adjust days_requested for credit deduction
                    $originalDays = $leaveRequest->days_requested;
                    $leaveRequest->days_requested = $leaveRequest->approved_days;
                    $leaveCreditService->deductCredits($leaveRequest, $year);
                    $leaveRequest->days_requested = $originalDays;

                    // Update credits_deducted to reflect actual deduction
                    $leaveRequest->update(['credits_deducted' => $leaveRequest->approved_days]);
                } else {
                    $leaveCreditService->deductCredits($leaveRequest, $year);
                }

                $this->updateAttendanceForApprovedLeave($leaveRequest);
            } else {
                // Non-credited leave types (UPTO, LOA, BL, SPL, LDV, ML) - no credit deduction, but still update attendance
                $this->updateAttendanceForApprovedLeave($leaveRequest);
            }

            // Notify the employee about approval
            $this->notificationService->notifyLeaveRequestFullyApproved(
                $leaveRequest->user_id,
                $leaveRequest->leave_type,
                $leaveRequest->id
            );

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
        ]);

        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);
        $deniedDates = collect($validated['denied_dates'])->map(fn ($d) => Carbon::parse($d));

        // Validate denied dates are within the leave period
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
            // Store denied dates
            foreach ($deniedDates as $date) {
                LeaveRequestDeniedDate::create([
                    'leave_request_id' => $leaveRequest->id,
                    'denied_date' => $date,
                    'denial_reason' => $validated['denial_reason'],
                    'denied_by' => $user->id,
                ]);
            }

            // Determine credits to deduct (only for approved days)
            $creditsToDeduct = 0;
            $creditsYear = Carbon::parse($leaveRequest->start_date)->year;

            if ($leaveRequest->requiresCredits()) {
                // For VL/SL, deduct credits only for approved days
                $employee = $leaveRequest->user;
                $balance = $this->leaveCreditService->getBalance($employee, $creditsYear);

                // Deduct up to available credits
                $creditsToDeduct = min($approvedDaysCount, $balance);

                if ($creditsToDeduct > 0) {
                    // Create a temporary copy with approved days for deduction
                    $tempLeaveRequest = $leaveRequest->replicate();
                    $tempLeaveRequest->days_requested = $creditsToDeduct;
                    $this->leaveCreditService->deductCredits($tempLeaveRequest, $creditsYear);

                    // Update the actual leave request with deduction info
                    $leaveRequest->credits_deducted = $creditsToDeduct;
                    $leaveRequest->credits_year = $creditsYear;
                }
            }

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

            if ($fullyApproved) {
                // Both have approved - mark as approved
                $leaveRequest->status = 'approved';
                $leaveRequest->reviewed_by = $user->id;
                $leaveRequest->reviewed_at = now();
                $leaveRequest->review_notes = $reviewNote;

                // Create attendance records only for approved dates
                foreach ($approvedDates as $dateStr) {
                    // Get the user's active schedule for this date
                    $schedule = $this->getActiveScheduleForDate($leaveRequest->user_id, $dateStr);

                    Attendance::updateOrCreate(
                        [
                            'user_id' => $leaveRequest->user_id,
                            'shift_date' => $dateStr,
                        ],
                        [
                            'employee_schedule_id' => $schedule?->id,
                            'scheduled_time_in' => $schedule?->scheduled_time_in,
                            'scheduled_time_out' => $schedule?->scheduled_time_out,
                            'status' => 'on_leave',
                            'leave_request_id' => $leaveRequest->id,
                            'admin_verified' => true,
                            'remarks' => "On approved leave ({$leaveRequest->leave_type}) - Partial approval",
                        ]
                    );
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

            // Delete attendance records for removed leave days
            Attendance::where('leave_request_id', $leaveRequest->id)
                ->where(function ($query) use ($newStartDate, $newEndDate) {
                    $query->where('shift_date', '<', $newStartDate)
                        ->orWhere('shift_date', '>', $newEndDate);
                })
                ->delete();

            // Approve the attendance record for the work day (if exists and pending)
            Attendance::where('user_id', $leaveRequest->user_id)
                ->where('shift_date', $workDate)
                ->where('admin_verified', false)
                ->update([
                    'admin_verified' => true,
                    'leave_request_id' => null, // No longer a leave conflict
                    'verification_notes' => "Leave adjusted - employee worked this day. Verified by {$user->name}",
                    'remarks' => null,
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

        // Delete attendance records for this leave
        Attendance::where('leave_request_id', $leaveRequest->id)->delete();

        // Approve the work day attendance
        Attendance::where('user_id', $leaveRequest->user_id)
            ->where('shift_date', $workDate)
            ->where('admin_verified', false)
            ->update([
                'admin_verified' => true,
                'leave_request_id' => null,
                'verification_notes' => "Leave cancelled - employee worked. Verified by {$user->name}",
                'remarks' => null,
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
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
        $isOwnRequest = $leaveRequest->user_id === $user->id;
        $isApprovedLeave = $leaveRequest->status === 'approved';

        // For approved leaves, only Admin/Super Admin can cancel
        if ($isApprovedLeave && ! $isAdmin) {
            return back()->withErrors(['error' => 'Only Admin or Super Admin can cancel approved leave requests.']);
        }

        // For regular employees, check canBeCancelled
        if (! $isAdmin && ! $leaveRequest->canBeCancelled()) {
            return back()->withErrors(['error' => 'This leave request cannot be cancelled.']);
        }

        // Get cancellation reason from request (required for admin cancellation of approved leaves)
        $cancellationReason = $request->input('cancellation_reason', '');
        if ($isApprovedLeave && $isAdmin && empty($cancellationReason)) {
            return back()->withErrors(['error' => 'A reason is required when cancelling an approved leave request.']);
        }

        $leaveCreditService = $this->leaveCreditService;

        DB::beginTransaction();
        try {
            // Restore credits if it was approved and credits were deducted
            if ($leaveRequest->status === 'approved' && $leaveRequest->credits_deducted) {
                $leaveCreditService->restoreCredits($leaveRequest);

                // Also delete attendance records for this leave
                Attendance::where('leave_request_id', $leaveRequest->id)->delete();
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

            // Notify the employee about cancellation (if cancelled by admin)
            if ($isAdmin && ! $isOwnRequest) {
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
     * @return \Illuminate\Http\JsonResponse
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
     * Start export job for leave credits.
     */
    public function exportCredits(Request $request)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $request->validate([
            'year' => 'required|integer|min:2020|max:'.(now()->year + 1),
        ]);

        $year = $request->input('year');
        $jobId = Str::uuid()->toString();

        // Initialize cache for progress tracking
        Cache::put("leave_credits_export_job:{$jobId}", [
            'percent' => 0,
            'status' => 'Starting export...',
            'finished' => false,
            'error' => false,
        ], 3600);

        // Dispatch job synchronously (runs immediately without queue worker)
        dispatch_sync(new GenerateLeaveCreditsExportExcel($jobId, $year));

        // Get the final progress to return download URL
        $progress = Cache::get("leave_credits_export_job:{$jobId}");

        return response()->json([
            'success' => true,
            'job_id' => $jobId,
            'finished' => $progress['finished'] ?? false,
            'downloadUrl' => $progress['downloadUrl'] ?? null,
        ]);
    }

    /**
     * Check export job progress.
     */
    public function exportCreditsProgress(Request $request)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $request->validate([
            'job_id' => 'required|string',
        ]);

        $jobId = $request->input('job_id');
        $cacheKey = "leave_credits_export_job:{$jobId}";

        $progress = Cache::get($cacheKey, [
            'percent' => 0,
            'status' => 'Unknown',
            'finished' => false,
            'error' => true,
        ]);

        return response()->json($progress);
    }

    /**
     * Download exported leave credits file.
     */
    public function exportCreditsDownload(Request $request, string $filename)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);
        $filePath = storage_path('app/temp/'.$filename);

        Log::info('Leave credits download attempt', [
            'filename' => $filename,
            'filePath' => $filePath,
            'exists' => file_exists($filePath),
        ]);

        if (! file_exists($filePath)) {
            Log::warning('Leave credits file not found', ['filePath' => $filePath]);

            return redirect()->route('leave-requests.credits.index')
                ->with('flash', [
                    'message' => 'File not found or has expired. Please try exporting again.',
                    'type' => 'error',
                ]);
        }

        // Don't delete immediately - let a cleanup job handle old files
        return response()->download($filePath, $filename);
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
     * Handle Sick Leave approval with special credit deduction logic.
     * Credits are only deducted if:
     * - Employee is eligible (6+ months)
     * - Has sufficient credits
     * - Medical certificate submitted
     * - Attendance status is NOT ncns (NCNS days keep their status)
     */
    protected function handleSlApproval(LeaveRequest $leaveRequest, LeaveCreditService $leaveCreditService): void
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

        // Check if we should deduct credits at all and get the reason if not
        $creditCheck = $leaveCreditService->checkSlCreditDeduction($user, $leaveRequest);

        if (! $creditCheck['should_deduct']) {
            // Check if this should be converted to UPTO (has med cert but no credits)
            if ($creditCheck['convert_to_upto'] ?? false) {
                // Convert SL to UPTO
                $leaveRequest->update([
                    'leave_type' => 'UPTO',
                    'sl_credits_applied' => false,
                    'sl_no_credit_reason' => $creditCheck['reason'],
                    'credits_deducted' => 0,
                ]);
                // Update attendance for UPTO (unpaid leave)
                $this->updateAttendanceForApprovedLeave($leaveRequest);

                // Auto-excuse attendance points if medical cert submitted
                $this->autoExcuseAttendancePoints($leaveRequest);

                return;
            }

            // No credits to deduct - just update attendance notes and track reason
            $this->updateSlAttendanceWithoutDeduction($leaveRequest, $creditCheck['reason']);

            // Auto-excuse attendance points if medical cert submitted
            $this->autoExcuseAttendancePoints($leaveRequest);

            return;
        }

        // Handle partial credit scenario - use available credits, rest as UPTO
        if ($creditCheck['partial_credit'] ?? false) {
            $creditsToDeduct = $creditCheck['credits_to_deduct'];
            $uptoDays = $creditCheck['upto_days'];

            // Update attendance records and deduct partial credits
            $this->handlePartialSlApproval($leaveRequest, $leaveCreditService, $creditsToDeduct, $uptoDays, $deniedDates);

            // Auto-excuse attendance points if medical cert submitted
            $this->autoExcuseAttendancePoints($leaveRequest);

            return;
        }

        // Full credits available - normal flow
        // Get attendance records for the leave period (excluding denied dates)
        $attendanceQuery = Attendance::where('user_id', $user->id)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

        if (! empty($deniedDates)) {
            $attendanceQuery->whereNotIn('shift_date', $deniedDates);
        }

        $attendances = $attendanceQuery->get();

        // Count days that are NOT ncns (these will have credits deducted)
        $daysToDeduct = 0;
        $leaveNote = "Covered by approved Sick Leave (SL) - Leave Request #{$leaveRequest->id}";

        foreach ($attendances as $attendance) {
            if ($attendance->status === 'ncns') {
                // NCNS stays unchanged - no credit deduction for this day
                // Just add a note that SL was applied but NCNS status preserved
                $existingNotes = $attendance->notes ? $attendance->notes."\n" : '';
                $attendance->update([
                    'notes' => $existingNotes."SL applied but NCNS status preserved - Leave Request #{$leaveRequest->id}",
                    'leave_request_id' => $leaveRequest->id,
                    'admin_verified' => true,
                ]);
            } else {
                // Non-NCNS: update status to advised_absence and deduct credit
                $daysToDeduct++;
                $attendance->update([
                    'status' => 'advised_absence',
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                    'admin_verified' => true,
                ]);
            }
        }

        // Also handle days with no attendance record yet (create advised_absence records)
        // Excluding denied dates
        $existingDates = $attendances->pluck('shift_date')->map(fn ($d) => $d->format('Y-m-d'))->toArray();
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            // Skip denied dates and existing dates
            if (! in_array($dateStr, $existingDates) && ! in_array($dateStr, $deniedDates)) {
                // Get the user's active schedule for this date
                $schedule = $this->getActiveScheduleForDate($user->id, $dateStr);

                // No attendance record exists - create one with advised_absence
                Attendance::create([
                    'user_id' => $user->id,
                    'employee_schedule_id' => $schedule?->id,
                    'shift_date' => $dateStr,
                    'scheduled_time_in' => $schedule?->scheduled_time_in,
                    'scheduled_time_out' => $schedule?->scheduled_time_out,
                    'status' => 'advised_absence',
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                    'admin_verified' => true,
                ]);
                $daysToDeduct++;
            }
            $currentDate->addDay();
        }

        // For partial denial, cap deduction at approved_days if set
        if ($leaveRequest->has_partial_denial && $leaveRequest->approved_days !== null) {
            $daysToDeduct = min($daysToDeduct, $leaveRequest->approved_days);
        }

        // Deduct only the non-NCNS days
        if ($daysToDeduct > 0) {
            $year = $startDate->year;

            // Temporarily adjust the leave request days for deduction
            $originalDays = $leaveRequest->days_requested;
            $leaveRequest->days_requested = $daysToDeduct;
            $leaveCreditService->deductCredits($leaveRequest, $year);
            $leaveRequest->days_requested = $originalDays;

            // Update credits_deducted and mark SL credits as applied
            $leaveRequest->update([
                'credits_deducted' => $daysToDeduct,
                'sl_credits_applied' => true,
                'sl_no_credit_reason' => null,
            ]);
        } else {
            // All days were NCNS - no credits deducted
            $leaveRequest->update([
                'credits_deducted' => 0,
                'sl_credits_applied' => false,
                'sl_no_credit_reason' => 'All days in the leave period had NCNS status - no credits deducted',
            ]);
        }

        // Auto-excuse attendance points if medical cert submitted
        $this->autoExcuseAttendancePoints($leaveRequest);
    }

    /**
     * Handle partial SL approval where user has some credits but not enough for all days.
     * Uses available credits for the first days, remaining days are marked as UPTO.
     *
     * @param  float  $creditsToDeduct  Number of days to use SL credits for
     * @param  float  $uptoDays  Number of days to mark as UPTO
     * @param  array  $deniedDates  Array of denied date strings
     */
    protected function handlePartialSlApproval(
        LeaveRequest $leaveRequest,
        LeaveCreditService $leaveCreditService,
        float $creditsToDeduct,
        float $uptoDays,
        array $deniedDates = []
    ): void {
        $user = $leaveRequest->user;
        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);
        $year = $startDate->year;

        // Get all non-NCNS attendance days (or create them) sorted by date
        $allDates = [];
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            if (! in_array($dateStr, $deniedDates)) {
                $allDates[] = $dateStr;
            }
            $currentDate->addDay();
        }

        // Get existing attendance records
        $existingAttendances = Attendance::where('user_id', $user->id)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->whereNotIn('shift_date', $deniedDates)
            ->get()
            ->keyBy(fn ($a) => $a->shift_date->format('Y-m-d'));

        $slDaysUsed = 0;
        $uptoDaysUsed = 0;

        foreach ($allDates as $index => $dateStr) {
            $attendance = $existingAttendances->get($dateStr);
            $isNcns = $attendance && $attendance->status === 'ncns';

            // NCNS days don't consume credits
            if ($isNcns) {
                $existingNotes = $attendance->notes ? $attendance->notes."\n" : '';
                $attendance->update([
                    'notes' => $existingNotes."SL applied but NCNS status preserved - Leave Request #{$leaveRequest->id}",
                    'leave_request_id' => $leaveRequest->id,
                    'admin_verified' => true,
                ]);

                continue;
            }

            // Determine if this day uses SL credit or UPTO
            if ($slDaysUsed < $creditsToDeduct) {
                // Use SL credit for this day
                $slDaysUsed++;
                $leaveNote = "Covered by approved Sick Leave (SL) - Leave Request #{$leaveRequest->id}";
                $status = 'advised_absence';
            } else {
                // Use UPTO for this day
                $uptoDaysUsed++;
                $leaveNote = "UPTO (Unpaid Time Off) - Insufficient SL credits - Leave Request #{$leaveRequest->id}";
                $status = 'on_leave';
            }

            if ($attendance) {
                $attendance->update([
                    'status' => $status,
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                    'admin_verified' => true,
                ]);
            } else {
                // Create attendance record
                $schedule = $this->getActiveScheduleForDate($user->id, $dateStr);
                Attendance::create([
                    'user_id' => $user->id,
                    'employee_schedule_id' => $schedule?->id,
                    'shift_date' => $dateStr,
                    'scheduled_time_in' => $schedule?->scheduled_time_in,
                    'scheduled_time_out' => $schedule?->scheduled_time_out,
                    'status' => $status,
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                    'admin_verified' => true,
                ]);
            }
        }

        // Deduct the SL credits used
        if ($slDaysUsed > 0) {
            $originalDays = $leaveRequest->days_requested;
            $leaveRequest->days_requested = $slDaysUsed;
            $leaveCreditService->deductCredits($leaveRequest, $year);
            $leaveRequest->days_requested = $originalDays;
        }

        // Update the leave request with partial credit info
        $leaveRequest->update([
            'credits_deducted' => $slDaysUsed,
            'sl_credits_applied' => $slDaysUsed > 0,
            'sl_no_credit_reason' => "Partial SL credits used: {$slDaysUsed} day(s) as SL, {$uptoDaysUsed} day(s) as UPTO",
        ]);

        \Log::info("Partial SL approval for Leave Request #{$leaveRequest->id}", [
            'user_id' => $user->id,
            'sl_days' => $slDaysUsed,
            'upto_days' => $uptoDaysUsed,
        ]);
    }

    /**
     * Update attendance for Sick Leave when no credits are being deducted.
     * Still updates status and notes for tracking purposes.
     *
     * @param  string|null  $reason  The reason why credits were not deducted
     */
    protected function updateSlAttendanceWithoutDeduction(LeaveRequest $leaveRequest, ?string $reason = null): void
    {
        $user = $leaveRequest->user;
        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);
        $leaveNote = "Covered by approved Sick Leave (SL) - No credits deducted - Leave Request #{$leaveRequest->id}";

        // Get denied dates if this is a partial denial
        $deniedDates = [];
        if ($leaveRequest->has_partial_denial) {
            $deniedDates = $leaveRequest->deniedDates()
                ->pluck('denied_date')
                ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                ->toArray();
        }

        // Update existing attendance records (excluding denied dates)
        $attendanceQuery = Attendance::where('user_id', $user->id)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

        if (! empty($deniedDates)) {
            $attendanceQuery->whereNotIn('shift_date', $deniedDates);
        }

        $attendances = $attendanceQuery->get();

        foreach ($attendances as $attendance) {
            if ($attendance->status === 'ncns') {
                // Keep NCNS status but add note
                $existingNotes = $attendance->notes ? $attendance->notes."\n" : '';
                $attendance->update([
                    'notes' => $existingNotes."SL applied (no credits) - NCNS status preserved - Leave Request #{$leaveRequest->id}",
                    'leave_request_id' => $leaveRequest->id,
                    'admin_verified' => true,
                ]);
            } else {
                // Update to advised_absence
                $attendance->update([
                    'status' => 'advised_absence',
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                    'admin_verified' => true,
                ]);
            }
        }

        // Create attendance records for days without existing records (excluding denied dates)
        $existingDates = $attendances->pluck('shift_date')->map(fn ($d) => $d->format('Y-m-d'))->toArray();
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            // Skip denied dates and existing dates
            if (! in_array($dateStr, $existingDates) && ! in_array($dateStr, $deniedDates)) {
                // Get the user's active schedule for this date
                $schedule = $this->getActiveScheduleForDate($user->id, $dateStr);

                Attendance::create([
                    'user_id' => $user->id,
                    'employee_schedule_id' => $schedule?->id,
                    'shift_date' => $dateStr,
                    'scheduled_time_in' => $schedule?->scheduled_time_in,
                    'scheduled_time_out' => $schedule?->scheduled_time_out,
                    'status' => 'advised_absence',
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                    'admin_verified' => true,
                ]);
            }
            $currentDate->addDay();
        }

        // Mark that no credits were deducted and track the reason
        $leaveRequest->update([
            'credits_deducted' => 0,
            'sl_credits_applied' => false,
            'sl_no_credit_reason' => $reason,
        ]);
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

        // Only for SL and UPTO leave types
        if (! in_array($leaveRequest->leave_type, ['SL', 'UPTO'])) {
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
        $certificateType = $leaveRequest->leave_type === 'SL' ? 'medical certificate' : 'supporting certificate';
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
        $updateQuery = Attendance::where('user_id', $user->id)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

        if (! empty($deniedDates)) {
            $updateQuery->whereNotIn('shift_date', $deniedDates);
        }

        $updateQuery->update([
            'status' => 'on_leave',
            'notes' => $leaveNote,
            'leave_request_id' => $leaveRequest->id,
            'admin_verified' => true, // Auto-verify leave records
        ]);

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

        // Auto-excuse attendance points for UPTO with medical certificate
        // (SL is handled in handleSlApproval, but UPTO submitted directly also needs this)
        if ($leaveRequest->leave_type === 'UPTO' && $leaveRequest->medical_cert_submitted) {
            $this->autoExcuseAttendancePoints($leaveRequest);
        }
    }

    /**
     * Display all employees' leave credits balances.
     * Only accessible by Super Admin, Admin, HR, and Team Lead.
     */
    public function creditsIndex(Request $request)
    {
        $user = auth()->user();

        // Check if user has permission to view all leave credits
        if (! app(PermissionService::class)->userHasPermission($user, 'leave_credits.view_all')) {
            // Redirect to their own credits page if they have view_own permission
            if (app(PermissionService::class)->userHasPermission($user, 'leave_credits.view_own')) {
                return redirect()->route('leave-requests.credits.show', $user->id);
            }
            abort(403, 'Unauthorized action.');
        }

        // Determine Team Lead's campaign (if applicable)
        $teamLeadCampaignId = null;
        if ($user->role === 'Team Lead') {
            $activeSchedule = $user->activeSchedule;
            if ($activeSchedule && $activeSchedule->campaign_id) {
                $teamLeadCampaignId = $activeSchedule->campaign_id;
            }
        }

        $year = (int) $request->input('year', now()->year);
        $search = $request->input('search', '');
        $roleFilter = $request->input('role', '');
        $eligibilityFilter = $request->input('eligibility', '');
        $campaignFilter = $request->input('campaign_id', '');

        // Calculate eligibility cutoff date (6 months ago from start of selected year)
        $eligibilityCutoffDate = Carbon::create($year, 1, 1)->subMonths(6);

        // Get all users with hire dates
        $query = User::whereNotNull('hired_date')
            ->where('is_approved', true);

        // Apply search filter - check if it's an ID (numeric) or name/email search
        if ($search) {
            if (is_numeric($search)) {
                // Search by user ID
                $query->where('id', $search);
            } else {
                // Search by name or email
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }
        }

        // Apply role filter
        if ($roleFilter) {
            $query->where('role', $roleFilter);
        }

        // Apply eligibility filter at query level
        // Eligible = hired_date is at least 6 months before start of selected year
        if ($eligibilityFilter === 'eligible') {
            $query->where('hired_date', '<=', $eligibilityCutoffDate);
        } elseif ($eligibilityFilter === 'not_eligible') {
            $query->where('hired_date', '>', $eligibilityCutoffDate);
        } elseif ($eligibilityFilter === 'pending_regularization') {
            // Filter for users with pending first regularization transfers
            // These are users hired in a PREVIOUS year who regularize in the SELECTED year
            // and haven't had their first regularization processed yet
            $previousYear = $year - 1;
            $query->whereRaw('YEAR(hired_date) = ?', [$previousYear])
                ->whereRaw('YEAR(DATE_ADD(hired_date, INTERVAL 6 MONTH)) = ?', [$year])
                ->whereNotIn('id', function ($subQuery) {
                    $subQuery->select('user_id')
                        ->from('leave_credit_carryovers')
                        ->where('is_first_regularization', true);
                });
        }

        // Apply campaign filter - auto-filter for Team Leads
        $campaignIdToFilter = $campaignFilter ?: null;
        if (! $campaignIdToFilter && $user->role === 'Team Lead' && $teamLeadCampaignId) {
            $campaignIdToFilter = $teamLeadCampaignId;
        }
        if ($campaignIdToFilter) {
            $query->whereHas('activeSchedule', function ($q) use ($campaignIdToFilter) {
                $q->where('campaign_id', $campaignIdToFilter);
            });
        }

        $users = $query->orderBy('first_name')->orderBy('last_name')->paginate(20)->withQueryString();

        // Get leave credits data for each user
        $creditsData = $users->through(function ($user) use ($year) {
            $summary = $this->leaveCreditService->getSummary($user, $year);
            // Get carryover FROM this year (what will be/was carried over to next year)
            $carryoverSummary = $this->leaveCreditService->getCarryoverFromYearSummary($user, $year);
            // Get carryover TO this year (what was received from previous year)
            $carryoverReceived = LeaveCreditCarryover::forUser($user->id)
                ->toYear($year)
                ->first();
            // Get regularization info for pending credit transfer display
            $regularizationInfo = $this->leaveCreditService->getRegularizationInfo($user, $year);
            $hireDate = Carbon::parse($user->hired_date);
            $eligibilityDate = $hireDate->copy()->addMonths(6);
            $hireYear = $hireDate->year;

            // Only show carryover forward if:
            // 1. It has been processed (year has ended)
            // 2. Has credits > 0
            // 3. User is regularized OR was already regularized before the carryover year
            // For users hired in the carryover year who are NOT yet regularized,
            // show as "Pending Transfer" instead of "Carryover Forward"
            $showCarryoverForward = false;
            if ($carryoverSummary['is_processed'] && $carryoverSummary['carryover_credits'] > 0) {
                if ($hireYear === $year) {
                    // User was hired in the carryover FROM year - only show if NOW regularized
                    $showCarryoverForward = $regularizationInfo['is_regularized'];
                } else {
                    // User was already regularized before this year - always show
                    $showCarryoverForward = true;
                }
            }

            // Check if there are pending credits from previous year awaiting regularization
            $pendingRegularizationCredits = $regularizationInfo['pending_credits'];
            $showPendingRegularization = $pendingRegularizationCredits['credits'] > 0
                && ! $regularizationInfo['has_first_regularization'];

            // For users hired in the displayed year who are NOT yet regularized,
            // show their credits as pending transfer (from carryover record)
            $showPendingTransferFromThisYear = false;
            if ($hireYear === $year && ! $regularizationInfo['is_regularized'] && $carryoverSummary['is_processed']) {
                $showPendingTransferFromThisYear = true;
            }

            // Only show carryover received if user is regularized for that carryover
            // For users hired in the carryover source year, only show after regularization
            $showCarryoverReceived = false;
            if ($carryoverReceived) {
                if ($carryoverReceived->from_year === $hireYear) {
                    // User was hired in the carryover source year - only show if NOW regularized
                    $showCarryoverReceived = $regularizationInfo['is_regularized'];
                } else {
                    // User was already regularized before the carryover year - always show
                    $showCarryoverReceived = true;
                }
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'hired_date' => $user->hired_date->format('Y-m-d'),
                'is_eligible' => $summary['is_eligible'],
                'eligibility_date' => $eligibilityDate->format('Y-m-d'),
                'monthly_rate' => $summary['monthly_rate'],
                'total_earned' => $summary['total_earned'],
                'total_used' => $summary['total_used'],
                'balance' => $summary['balance'],
                // Carryover received INTO this year (from previous year)
                // Only show if user is regularized (for first regularization carryovers)
                'carryover_received' => $showCarryoverReceived && $carryoverReceived ? [
                    'credits' => (float) $carryoverReceived->carryover_credits,
                    'from_year' => $carryoverReceived->from_year,
                    'is_first_regularization' => (bool) $carryoverReceived->is_first_regularization,
                    'cash_converted' => (bool) $carryoverReceived->cash_converted,
                ] : null,
                // Carryover forward FROM this year (to next year)
                'carryover_forward' => $showCarryoverForward ? [
                    'credits' => $carryoverSummary['carryover_credits'],
                    'to_year' => $carryoverSummary['to_year'],
                    'is_processed' => $carryoverSummary['is_processed'],
                    'is_expired' => $carryoverSummary['is_expired'] ?? false,
                    'cash_converted' => $carryoverSummary['cash_converted'],
                ] : null,
                // Keep old 'carryover' key for backwards compatibility
                'carryover' => $showCarryoverForward ? [
                    'credits' => $carryoverSummary['carryover_credits'],
                    'to_year' => $carryoverSummary['to_year'],
                    'is_processed' => $carryoverSummary['is_processed'],
                    'is_expired' => $carryoverSummary['is_expired'] ?? false,
                    'cash_converted' => $carryoverSummary['cash_converted'],
                ] : null,
                // Regularization info for pending credit transfer
                'regularization' => [
                    'is_regularized' => $regularizationInfo['is_regularized'],
                    'regularization_date' => $regularizationInfo['regularization_date'],
                    'hire_year' => $regularizationInfo['hire_year'],
                    'days_until_regularization' => $regularizationInfo['days_until_regularization'],
                    'has_first_regularization' => $regularizationInfo['has_first_regularization'],
                    // Show pending credits either from previous year OR from current year (if viewing hire year)
                    // For first regularization, show ALL credits (not capped)
                    'pending_credits' => $showPendingRegularization ? [
                        'from_year' => $pendingRegularizationCredits['year'],
                        'to_year' => $hireYear + 1, // Credits will transfer TO the year after hire (regularization year)
                        'credits' => $pendingRegularizationCredits['credits'],
                        'months_accrued' => $pendingRegularizationCredits['months_accrued'],
                    ] : ($showPendingTransferFromThisYear ? [
                        'from_year' => $year, // Credits are FROM the current view year
                        'to_year' => $year + 1, // Credits will transfer TO next year
                        // Show full earned credits (not capped) since first regularization transfers ALL
                        'credits' => LeaveCredit::forUser($user->id)->forYear($year)->sum('credits_balance'),
                        'months_accrued' => $summary['credits_by_month']->count(),
                    ] : null),
                ],
            ];
        });

        // Get all employees for search popover
        $allEmployees = User::whereNotNull('hired_date')
            ->where('is_approved', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);

        // Get campaigns for filter dropdown
        $campaigns = \App\Models\Campaign::orderBy('name')->get(['id', 'name']);

        return Inertia::render('FormRequest/Leave/Credits/Index', [
            'creditsData' => $creditsData,
            'allEmployees' => $allEmployees,
            'campaigns' => $campaigns,
            'teamLeadCampaignId' => $teamLeadCampaignId,
            'filters' => [
                'year' => (int) $year,
                'search' => $search,
                'role' => $roleFilter,
                'eligibility' => $eligibilityFilter,
                'campaign_id' => $campaignFilter,
            ],
            'availableYears' => range(now()->year, 2024, -1),
        ]);
    }

    /**
     * Show leave credits history page for a specific user.
     * All roles can view their own credits.
     * Super Admin, Admin, HR, Team Lead can view any user's credits.
     */
    public function creditsShow(Request $request, User $user)
    {
        $authUser = auth()->user();
        $permissionService = app(\App\Services\PermissionService::class);

        // Check permissions
        $canViewAll = $permissionService->userHasPermission($authUser, 'leave_credits.view_all');
        $canViewOwn = $permissionService->userHasPermission($authUser, 'leave_credits.view_own');
        $isViewingOwnCredits = $authUser->id === $user->id;

        // If viewing someone else's credits, must have view_all permission
        if (! $isViewingOwnCredits && ! $canViewAll) {
            abort(403, 'Unauthorized action.');
        }

        // If viewing own credits, must have at least view_own permission
        if ($isViewingOwnCredits && ! $canViewOwn && ! $canViewAll) {
            abort(403, 'Unauthorized action.');
        }

        $year = $request->input('year', now()->year);

        // Get monthly credits (excluding month 0 which is carryover - shown separately)
        $monthlyCredits = LeaveCredit::forUser($user->id)
            ->forYear($year)
            ->where('month', '>', 0) // Exclude carryover (month 0)
            ->orderBy('month')
            ->get()
            ->map(function ($credit) {
                return [
                    'id' => $credit->id,
                    'month' => $credit->month,
                    'month_name' => Carbon::create(null, $credit->month)->format('F'),
                    'credits_earned' => (float) $credit->credits_earned,
                    'credits_used' => (float) $credit->credits_used,
                    'credits_balance' => (float) $credit->credits_balance,
                    'accrued_at' => $credit->accrued_at->format('Y-m-d'),
                ];
            });

        // Get carryover credit record (month 0) to show deductions from carryover
        $carryoverCredit = LeaveCredit::forUser($user->id)
            ->forYear($year)
            ->where('month', 0)
            ->first();

        // Get leave requests that used credits this year
        $leaveRequests = LeaveRequest::where('user_id', $user->id)
            ->where('credits_year', $year)
            ->where('status', 'approved')
            ->whereNotNull('credits_deducted')
            ->where('credits_deducted', '>', 0)
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(function ($request) {
                return [
                    'id' => $request->id,
                    'leave_type' => $request->leave_type,
                    'start_date' => $request->start_date->format('Y-m-d'),
                    'end_date' => $request->end_date->format('Y-m-d'),
                    'days_requested' => (float) $request->days_requested,
                    'credits_deducted' => (float) $request->credits_deducted,
                    'approved_at' => $request->reviewed_at?->format('Y-m-d'),
                    'has_partial_denial' => (bool) $request->has_partial_denial,
                    'approved_days' => $request->approved_days !== null ? (float) $request->approved_days : null,
                ];
            });

        // Get summary
        $summary = $this->leaveCreditService->getSummary($user, $year);

        // Get carryover summary FROM this year (what will be/was carried over to next year)
        $carryoverSummary = $this->leaveCreditService->getCarryoverFromYearSummary($user, (int) $year);

        // Get carryover received INTO this year (from previous year)
        $carryoverReceived = LeaveCreditCarryover::forUser($user->id)
            ->toYear((int) $year)
            ->first();

        // Get regularization info
        $regularizationInfo = $this->leaveCreditService->getRegularizationInfo($user, (int) $year);
        $hireDate = Carbon::parse($user->hired_date);
        $hireYear = $hireDate->year;

        // Determine if carryover received should be shown (only if regularized or not first reg carryover)
        $showCarryoverReceived = false;
        if ($carryoverReceived) {
            if ($carryoverReceived->from_year === $hireYear) {
                // User was hired in the carryover source year - only show if NOW regularized
                $showCarryoverReceived = $regularizationInfo['is_regularized'];
            } else {
                // User was already regularized before the carryover year - always show
                $showCarryoverReceived = true;
            }
        }

        // Determine if carryover forward (for cash conversion) should be shown
        // - Don't show if user is viewing their hire year and not yet regularized (pending transfer)
        // - Don't show if the carryover is a first regularization transfer (not for cash)
        $showCarryoverForward = true;
        if ($hireYear === (int) $year && ! $regularizationInfo['is_regularized']) {
            // User is viewing their hire year and not yet regularized - no carryover forward
            $showCarryoverForward = false;
        }

        // Also don't show if the carryover is a first regularization (all credits transfer for leave, not cash)
        if ($carryoverSummary && ($carryoverSummary['is_first_regularization'] ?? false)) {
            $showCarryoverForward = false;
        }

        return Inertia::render('FormRequest/Leave/Credits/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'hired_date' => $user->hired_date->format('Y-m-d'),
            ],
            'year' => (int) $year,
            'summary' => [
                'is_eligible' => $summary['is_eligible'],
                'eligibility_date' => $summary['eligibility_date']?->format('Y-m-d'),
                'monthly_rate' => $summary['monthly_rate'],
                'total_earned' => $summary['total_earned'],
                'total_used' => $summary['total_used'],
                'balance' => $summary['balance'],
            ],
            'carryoverSummary' => $showCarryoverForward ? $carryoverSummary : null,
            'carryoverReceived' => $showCarryoverReceived && $carryoverReceived ? [
                'credits' => (float) $carryoverReceived->carryover_credits,
                'credits_used' => $carryoverCredit ? (float) $carryoverCredit->credits_used : 0,
                'credits_balance' => $carryoverCredit ? (float) $carryoverCredit->credits_balance : (float) $carryoverReceived->carryover_credits,
                'from_year' => $carryoverReceived->from_year,
                'is_first_regularization' => (bool) $carryoverReceived->is_first_regularization,
            ] : null,
            'regularization' => [
                'is_regularized' => $regularizationInfo['is_regularized'],
                'regularization_date' => $regularizationInfo['regularization_date'],
                'hire_year' => $hireYear,
            ],
            'monthlyCredits' => $monthlyCredits,
            'leaveRequests' => $leaveRequests,
            'availableYears' => range(now()->year, 2024, -1),
            'canViewAll' => $canViewAll,
        ]);
    }

    /**
     * Get regularization management statistics.
     */
    public function getRegularizationStats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $year = $request->input('year', now()->year);

        // Get users needing first regularization
        $usersNeedingRegularization = $this->leaveCreditService->getUsersNeedingFirstRegularization($year);

        // Count users with pending credits
        $usersWithPendingCredits = $usersNeedingRegularization->filter(function ($user) {
            $pendingCredits = $this->leaveCreditService->getPendingRegularizationCredits($user);

            return $pendingCredits && $pendingCredits['credits'] > 0;
        });

        // Get already processed count
        $alreadyProcessedCount = \App\Models\LeaveCreditCarryover::where('is_first_regularization', true)
            ->where('to_year', $year)
            ->count();

        return response()->json([
            'pending_count' => $usersWithPendingCredits->count(),
            'total_eligible' => $usersNeedingRegularization->count(),
            'already_processed' => $alreadyProcessedCount,
            'year' => $year,
        ]);
    }

    /**
     * Process regularization credit transfers.
     */
    public function processRegularization(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $validated = $request->validate([
            'year' => 'nullable|integer|min:2024',
            'user_id' => 'nullable|integer|exists:users,id',
            'dry_run' => 'nullable|boolean',
        ]);

        $year = $validated['year'] ?? now()->year;
        $userId = $validated['user_id'] ?? null;
        $dryRun = $validated['dry_run'] ?? false;

        $results = [
            'processed' => [],
            'skipped' => [],
            'errors' => [],
        ];

        try {
            if ($userId) {
                // Process single user
                $user = User::findOrFail($userId);
                $result = $this->processUserRegularization($user, $dryRun);

                if ($result['status'] === 'processed') {
                    $results['processed'][] = $result;
                } elseif ($result['status'] === 'skipped') {
                    $results['skipped'][] = $result;
                } else {
                    $results['errors'][] = $result;
                }
            } else {
                // Process all eligible users
                $usersNeedingRegularization = $this->leaveCreditService->getUsersNeedingFirstRegularization($year);

                foreach ($usersNeedingRegularization as $user) {
                    $result = $this->processUserRegularization($user, $dryRun);

                    if ($result['status'] === 'processed') {
                        $results['processed'][] = $result;
                    } elseif ($result['status'] === 'skipped') {
                        $results['skipped'][] = $result;
                    } else {
                        $results['errors'][] = $result;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'dry_run' => $dryRun,
                'year' => $year,
                'summary' => [
                    'processed' => count($results['processed']),
                    'skipped' => count($results['skipped']),
                    'errors' => count($results['errors']),
                ],
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            \Log::error('Regularization processing error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to process regularization: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process regularization for a single user.
     */
    private function processUserRegularization(User $user, bool $dryRun): array
    {
        $pendingCredits = $this->leaveCreditService->getPendingRegularizationCredits($user);

        if (! $pendingCredits || $pendingCredits['credits'] <= 0) {
            return [
                'status' => 'skipped',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'reason' => 'No pending credits or not eligible',
            ];
        }

        if ($dryRun) {
            return [
                'status' => 'processed',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'credits' => $pendingCredits['credits'],
                'months' => $pendingCredits['months_accrued'],
                'from_year' => $pendingCredits['year'],
                'dry_run' => true,
            ];
        }

        try {
            $carryover = $this->leaveCreditService->processFirstRegularizationTransfer(
                $user,
                auth()->id()
            );

            if ($carryover) {
                return [
                    'status' => 'processed',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'credits' => $carryover->carryover_credits,
                    'from_year' => $carryover->from_year,
                    'to_year' => $carryover->to_year,
                ];
            } else {
                return [
                    'status' => 'skipped',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'reason' => 'Transfer returned null',
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process monthly accruals for all eligible users.
     */
    public function processMonthlyAccruals(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        try {
            $result = $this->leaveCreditService->accrueCreditsForAllUsers();

            return response()->json([
                'success' => true,
                'message' => 'Monthly accruals processed successfully.',
                'summary' => [
                    'processed' => $result['processed'],
                    'skipped' => $result['skipped'],
                    'total_credits' => $result['total_credits'],
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Monthly accruals processing error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to process monthly accruals: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process year-end carryovers.
     */
    public function processYearEndCarryovers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $validated = $request->validate([
            'from_year' => 'required|integer|min:2024',
        ]);

        $fromYear = $validated['from_year'];

        try {
            $result = $this->leaveCreditService->processAllCarryovers($fromYear, auth()->id());

            return response()->json([
                'success' => true,
                'message' => "Year-end carryovers from {$fromYear} to ".($fromYear + 1).' processed successfully.',
                'summary' => [
                    'processed' => $result['processed'],
                    'skipped' => $result['skipped'],
                    'total_carryover' => $result['total_carryover'],
                    'total_forfeited' => $result['total_forfeited'],
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Year-end carryover processing error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to process year-end carryovers: '.$e->getMessage(),
            ], 500);
        }
    }
}
