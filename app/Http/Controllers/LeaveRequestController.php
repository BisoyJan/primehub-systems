<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveRequestRequest;
use App\Jobs\GenerateLeaveCreditsExportExcel;
use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveCreditService;
use App\Services\NotificationService;
use App\Mail\LeaveRequestStatusUpdated;
use App\Mail\LeaveRequestSubmitted;
use App\Mail\LeaveRequestTLStatusUpdated;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        $leaveRequests = $query->orderBy('created_at', 'desc')
            ->paginate(25)
            ->withQueryString();

        // Check if current user has pending leave requests
        $hasPendingRequests = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        return Inertia::render('FormRequest/Leave/Index', [
            'leaveRequests' => $leaveRequests,
            'filters' => $request->only(['status', 'type', 'start_date', 'end_date', 'user_id']),
            'isAdmin' => $isAdmin,
            'isTeamLead' => $isTeamLead,
            'hasPendingRequests' => $hasPendingRequests,
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

        return Inertia::render('FormRequest/Leave/Create', [
            'creditsSummary' => $creditsSummary,
            'attendancePoints' => $attendancePoints,
            'attendanceViolations' => $attendanceViolations,
            'hasRecentAbsence' => $hasRecentAbsence,
            'hasPendingRequests' => $hasPendingRequests,
            'nextEligibleLeaveDate' => $nextEligibleLeaveDate,
            'campaigns' => $campaigns,
            'selectedCampaign' => $selectedCampaign,
            'twoWeeksFromNow' => $twoWeeksFromNow,
            'isAdmin' => $isAdmin,
            'employees' => $employees,
            'selectedEmployeeId' => $targetUser->id,
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

        // Check if user has pending leave requests
        $hasPendingRequests = LeaveRequest::where('user_id', $targetUser->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingRequests) {
            return back()->withErrors(['error' => 'You cannot create a new leave request while you have pending requests. Please wait for approval or cancel your existing pending request.'])->withInput();
        }

        // Calculate days
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $daysRequested = $leaveCreditService->calculateDays($startDate, $endDate);

        // Get current attendance points
        $attendancePoints = $leaveCreditService->getAttendancePoints($targetUser);

        // Prepare data for validation
        $validationData = array_merge($request->validated(), [
            'days_requested' => $daysRequested,
            'credits_year' => now()->year,
        ]);

        // Validate business rules
        $validation = $leaveCreditService->validateLeaveRequest($targetUser, $validationData);

        if (!$validation['valid']) {
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
                'status' => 'pending',
                'attendance_points_at_request' => $attendancePoints,
                'requires_tl_approval' => $requiresTlApproval,
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

        $leaveRequest->load(['user', 'reviewer', 'adminApprover', 'hrApprover', 'tlApprover']);

        // Check if current user has already approved
        $hasUserApproved = false;
        if (in_array($user->role, ['Super Admin', 'Admin']) && $leaveRequest->isAdminApproved()) {
            $hasUserApproved = true;
        } elseif ($user->role === 'HR' && $leaveRequest->isHrApproved()) {
            $hasUserApproved = true;
        }

        // Check if Team Lead can approve this request
        $canTlApprove = false;
        if ($isTeamLead && $leaveRequest->requiresTlApproval() && !$leaveRequest->isTlApproved() && !$leaveRequest->isTlRejected()) {
            // Any Team Lead can approve agent leave requests
            $canTlApprove = true;
        }

        // Format dates to prevent timezone issues
        $leaveRequestData = $leaveRequest->toArray();
        $leaveRequestData['start_date'] = $leaveRequest->start_date->format('Y-m-d');
        $leaveRequestData['end_date'] = $leaveRequest->end_date->format('Y-m-d');

        return Inertia::render('FormRequest/Leave/Show', [
            'leaveRequest' => $leaveRequestData,
            'isAdmin' => $isAdmin,
            'isTeamLead' => $isTeamLead,
            'isSuperAdmin' => $user->role === 'Super Admin',
            'canCancel' => $leaveRequest->canBeCancelled() && $leaveRequest->user_id === $user->id,
            'hasUserApproved' => $hasUserApproved,
            'canTlApprove' => $canTlApprove,
            'userRole' => $user->role,
        ]);
    }

    /**
     * Show the form for editing the specified leave request.
     */
    public function edit(LeaveRequest $leaveRequest)
    {
        $this->authorize('update', $leaveRequest);

        $user = auth()->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin', 'HR']);

        // Only pending requests can be edited
        if ($leaveRequest->status !== 'pending') {
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

        // Campaign/Department options
        $campaignsFromDb = Campaign::orderBy('name')->pluck('name')->toArray();
        $campaigns = array_merge(['Management (For TL/Admin)'], $campaignsFromDb);

        // Calculate two weeks from now for 2-week notice validation
        $twoWeeksFromNow = now()->addWeeks(2)->format('Y-m-d');

        $leaveRequest->load('user');

        // Format dates to prevent timezone issues
        $leaveRequestData = $leaveRequest->toArray();
        $leaveRequestData['start_date'] = $leaveRequest->start_date->format('Y-m-d');
        $leaveRequestData['end_date'] = $leaveRequest->end_date->format('Y-m-d');

        return Inertia::render('FormRequest/Leave/Edit', [
            'leaveRequest' => $leaveRequestData,
            'creditsSummary' => $creditsSummary,
            'attendancePoints' => $attendancePoints,
            'attendanceViolations' => $attendanceViolations,
            'hasRecentAbsence' => $hasRecentAbsence,
            'nextEligibleLeaveDate' => $nextEligibleLeaveDate,
            'campaigns' => $campaigns,
            'twoWeeksFromNow' => $twoWeeksFromNow,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Update the specified leave request.
     */
    public function update(LeaveRequestRequest $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('update', $leaveRequest);

        // Only pending requests can be edited
        if ($leaveRequest->status !== 'pending') {
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

        // Prepare data for validation
        $validationData = array_merge($request->validated(), [
            'days_requested' => $daysRequested,
            'credits_year' => now()->year,
        ]);

        // Validate business rules
        $validation = $leaveCreditService->validateLeaveRequest($targetUser, $validationData);

        if (!$validation['valid']) {
            return back()->withErrors(['validation' => $validation['errors']])->withInput();
        }

        DB::beginTransaction();
        try {
            $leaveRequest->update([
                'leave_type' => $request->leave_type,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days_requested' => $daysRequested,
                'reason' => $request->reason,
                'campaign_department' => $request->campaign_department,
                'medical_cert_submitted' => $request->boolean('medical_cert_submitted', false),
                'attendance_points_at_request' => $attendancePoints,
            ]);

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
     * Team Lead approves a leave request from their campaign agent.
     */
    public function approveTL(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('tlApprove', $leaveRequest);

        $user = auth()->user();

        if ($leaveRequest->status !== 'pending') {
            return back()->withErrors(['error' => 'Only pending requests can be approved.']);
        }

        if (!$leaveRequest->requiresTlApproval()) {
            return back()->withErrors(['error' => 'This request does not require Team Lead approval.']);
        }

        if ($leaveRequest->isTlApproved() || $leaveRequest->isTlRejected()) {
            return back()->withErrors(['error' => 'This request has already been reviewed by Team Lead.']);
        }

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

        if (!$leaveRequest->requiresTlApproval()) {
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
                'review_notes' => 'Rejected by Team Lead: ' . $request->review_notes,
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
        if ($leaveRequest->requiresTlApproval() && !$leaveRequest->isTlApproved()) {
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
                    $leaveCreditService->deductCredits($leaveRequest, $year);

                    // Update attendance records to on_leave status
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

        $leaveCreditService = $this->leaveCreditService;

        DB::beginTransaction();
        try {
            // Set both Admin and HR approval
            $reviewNotes = $request->review_notes
                ? trim($request->review_notes)
                : 'Approved';

            $leaveRequest->update([
                'admin_approved_by' => $user->id,
                'admin_approved_at' => now(),
                'admin_review_notes' => $reviewNotes,
                'hr_approved_by' => $user->id,
                'hr_approved_at' => now(),
                'hr_review_notes' => $reviewNotes,
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
            ]);

            // If TL approval was required but not done, mark it as approved too
            if ($leaveRequest->requiresTlApproval() && !$leaveRequest->isTlApproved()) {
                $leaveRequest->update([
                    'tl_approved_by' => $user->id,
                    'tl_approved_at' => now(),
                    'tl_review_notes' => $reviewNotes,
                ]);
            }

            // Handle leave credit deduction based on leave type
            if ($leaveRequest->leave_type === 'SL') {
                $this->handleSlApproval($leaveRequest, $leaveCreditService);
            } elseif ($leaveRequest->requiresCredits()) {
                $year = $request->input('credits_year', now()->year);
                $leaveCreditService->deductCredits($leaveRequest, $year);
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

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', 'Leave request force approved by Super Admin.');
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
     * Cancel a leave request (Employee only, for their own requests).
     */
    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('cancel', $leaveRequest);

        $user = auth()->user();

        if (!$leaveRequest->canBeCancelled()) {
            return back()->withErrors(['error' => 'This leave request cannot be cancelled.']);
        }

        $leaveCreditService = $this->leaveCreditService;

        DB::beginTransaction();
        try {
            // Restore credits if it was approved and credits were deducted
            if ($leaveRequest->status === 'approved' && $leaveRequest->credits_deducted) {
                $leaveCreditService->restoreCredits($leaveRequest);
            }

            $leaveRequest->update([
                'status' => 'cancelled',
            ]);

            // Notify HR/Admin about cancellation
            $this->notificationService->notifyHrRolesAboutLeaveCancellation(
                $user->name,
                $leaveRequest->leave_type,
                Carbon::parse($leaveRequest->start_date)->format('Y-m-d'),
                Carbon::parse($leaveRequest->end_date)->format('Y-m-d')
            );

            DB::commit();

            return redirect()->route('leave-requests.index')
                ->with('success', 'Leave request cancelled successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
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
     * Start export job for leave credits.
     */
    public function exportCredits(Request $request)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $request->validate([
            'year' => 'required|integer|min:2020|max:' . (now()->year + 1),
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

        // Dispatch job
        dispatch(new GenerateLeaveCreditsExportExcel($jobId, $year));

        return response()->json([
            'success' => true,
            'job_id' => $jobId,
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
    public function exportCreditsDownload(Request $request)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $filename = $request->route('filename');
        $filePath = storage_path('app/temp/' . $filename);

        if (!file_exists($filePath)) {
            abort(404, 'File not found or has expired.');
        }

        return response()->download($filePath, $filename)->deleteFileAfterSend(true);
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

        // Check if we should deduct credits at all
        $shouldDeduct = $leaveCreditService->shouldDeductSlCredits($user, $leaveRequest);

        if (!$shouldDeduct) {
            // No credits to deduct - just update attendance notes
            $this->updateSlAttendanceWithoutDeduction($leaveRequest);
            return;
        }

        // Get attendance records for the leave period
        $attendances = Attendance::where('user_id', $user->id)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get();

        // Count days that are NOT ncns (these will have credits deducted)
        $daysToDeduct = 0;
        $leaveNote = "Covered by approved Sick Leave (SL) - Leave Request #{$leaveRequest->id}";

        foreach ($attendances as $attendance) {
            if ($attendance->status === 'ncns') {
                // NCNS stays unchanged - no credit deduction for this day
                // Just add a note that SL was applied but NCNS status preserved
                $existingNotes = $attendance->notes ? $attendance->notes . "\n" : '';
                $attendance->update([
                    'notes' => $existingNotes . "SL applied but NCNS status preserved - Leave Request #{$leaveRequest->id}",
                    'leave_request_id' => $leaveRequest->id,
                ]);
            } else {
                // Non-NCNS: update status to advised_absence and deduct credit
                $daysToDeduct++;
                $attendance->update([
                    'status' => 'advised_absence',
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }
        }

        // Also handle days with no attendance record yet (create advised_absence records)
        $existingDates = $attendances->pluck('shift_date')->map(fn($d) => $d->format('Y-m-d'))->toArray();
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            if (!in_array($dateStr, $existingDates)) {
                // No attendance record exists - create one with advised_absence
                Attendance::create([
                    'user_id' => $user->id,
                    'shift_date' => $dateStr,
                    'status' => 'advised_absence',
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                ]);
                $daysToDeduct++;
            }
            $currentDate->addDay();
        }

        // Deduct only the non-NCNS days
        if ($daysToDeduct > 0) {
            $year = $startDate->year;

            // Temporarily adjust the leave request days for deduction
            $originalDays = $leaveRequest->days_requested;
            $leaveRequest->days_requested = $daysToDeduct;
            $leaveCreditService->deductCredits($leaveRequest, $year);
            $leaveRequest->days_requested = $originalDays;

            // Update credits_deducted to reflect actual deduction
            $leaveRequest->update(['credits_deducted' => $daysToDeduct]);
        }
    }

    /**
     * Update attendance for Sick Leave when no credits are being deducted.
     * Still updates status and notes for tracking purposes.
     */
    protected function updateSlAttendanceWithoutDeduction(LeaveRequest $leaveRequest): void
    {
        $user = $leaveRequest->user;
        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);
        $leaveNote = "Covered by approved Sick Leave (SL) - No credits deducted - Leave Request #{$leaveRequest->id}";

        // Update existing attendance records
        $attendances = Attendance::where('user_id', $user->id)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get();

        foreach ($attendances as $attendance) {
            if ($attendance->status === 'ncns') {
                // Keep NCNS status but add note
                $existingNotes = $attendance->notes ? $attendance->notes . "\n" : '';
                $attendance->update([
                    'notes' => $existingNotes . "SL applied (no credits) - NCNS status preserved - Leave Request #{$leaveRequest->id}",
                    'leave_request_id' => $leaveRequest->id,
                ]);
            } else {
                // Update to advised_absence
                $attendance->update([
                    'status' => 'advised_absence',
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }
        }

        // Create attendance records for days without existing records
        $existingDates = $attendances->pluck('shift_date')->map(fn($d) => $d->format('Y-m-d'))->toArray();
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            if (!in_array($dateStr, $existingDates)) {
                Attendance::create([
                    'user_id' => $user->id,
                    'shift_date' => $dateStr,
                    'status' => 'advised_absence',
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }
            $currentDate->addDay();
        }

        // Mark that no credits were deducted
        $leaveRequest->update(['credits_deducted' => 0]);
    }

    /**
     * Update attendance records for approved leave (VL, BL, etc.) to on_leave status.
     */
    protected function updateAttendanceForApprovedLeave(LeaveRequest $leaveRequest): void
    {
        $user = $leaveRequest->user;
        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);
        $leaveNote = "On approved {$leaveRequest->leave_type} - Leave Request #{$leaveRequest->id}";

        // Update existing attendance records
        Attendance::where('user_id', $user->id)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->update([
                'status' => 'on_leave',
                'notes' => $leaveNote,
                'leave_request_id' => $leaveRequest->id,
            ]);

        // Create attendance records for days without existing records
        $existingDates = Attendance::where('user_id', $user->id)
            ->whereBetween('shift_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->pluck('shift_date')
            ->map(fn($d) => $d->format('Y-m-d'))
            ->toArray();

        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            if (!in_array($dateStr, $existingDates)) {
                Attendance::create([
                    'user_id' => $user->id,
                    'shift_date' => $dateStr,
                    'status' => 'on_leave',
                    'notes' => $leaveNote,
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }
            $currentDate->addDay();
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
        if (!app(\App\Services\PermissionService::class)->userHasPermission($user, 'leave_credits.view_all')) {
            // Redirect to their own credits page if they have view_own permission
            if (app(\App\Services\PermissionService::class)->userHasPermission($user, 'leave_credits.view_own')) {
                return redirect()->route('leave-requests.credits.show', $user->id);
            }
            abort(403, 'Unauthorized action.');
        }

        $year = $request->input('year', now()->year);
        $search = $request->input('search', '');
        $roleFilter = $request->input('role', '');
        $eligibilityFilter = $request->input('eligibility', '');

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

        $users = $query->orderBy('first_name')->orderBy('last_name')->paginate(20);

        // Get leave credits data for each user
        $creditsData = $users->through(function ($user) use ($year) {
            $summary = $this->leaveCreditService->getSummary($user, $year);
            $hireDate = Carbon::parse($user->hired_date);
            $eligibilityDate = $hireDate->copy()->addMonths(6);

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
            ];
        });

        // Apply eligibility filter after transformation
        if ($eligibilityFilter === 'eligible') {
            $creditsData = $creditsData->filter(fn($item) => $item['is_eligible']);
        } elseif ($eligibilityFilter === 'not_eligible') {
            $creditsData = $creditsData->filter(fn($item) => !$item['is_eligible']);
        }

        // Get all employees for search popover
        $allEmployees = User::whereNotNull('hired_date')
            ->where('is_approved', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);

        return Inertia::render('FormRequest/Leave/Credits/Index', [
            'creditsData' => $creditsData,
            'allEmployees' => $allEmployees,
            'filters' => [
                'year' => (int) $year,
                'search' => $search,
                'role' => $roleFilter,
                'eligibility' => $eligibilityFilter,
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
        if (!$isViewingOwnCredits && !$canViewAll) {
            abort(403, 'Unauthorized action.');
        }

        // If viewing own credits, must have at least view_own permission
        if ($isViewingOwnCredits && !$canViewOwn && !$canViewAll) {
            abort(403, 'Unauthorized action.');
        }

        $year = $request->input('year', now()->year);

        // Get monthly credits
        $monthlyCredits = LeaveCredit::forUser($user->id)
            ->forYear($year)
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
                ];
            });

        // Get summary
        $summary = $this->leaveCreditService->getSummary($user, $year);

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
            'monthlyCredits' => $monthlyCredits,
            'leaveRequests' => $leaveRequests,
            'availableYears' => range(now()->year, 2024, -1),
            'canViewAll' => $canViewAll,
        ]);
    }
}
