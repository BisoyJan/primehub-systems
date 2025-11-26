<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveCreditService;
use App\Services\NotificationService;
use App\Mail\LeaveRequestStatusUpdated;
use App\Mail\LeaveRequestSubmitted;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $query = LeaveRequest::with(['user', 'reviewer']);

        // Admins see all requests, employees see only their own
        if (!$isAdmin) {
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
            $targetUser = \App\Models\User::findOrFail($request->employee_id);
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
        $attendanceViolations = \App\Models\AttendancePoint::where('user_id', $targetUser->id)
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
        $campaignsFromDb = \App\Models\Campaign::orderBy('name')->pluck('name')->toArray();
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
            ]);

            // Notify HR/Admin users about new leave request
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

            // Send confirmation email to the employee
            if ($targetUser->email && filter_var($targetUser->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($targetUser->email)->send(new LeaveRequestSubmitted($leaveRequest, $targetUser));
            }

            DB::commit();

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

        $leaveRequest->load(['user', 'reviewer']);

        return Inertia::render('FormRequest/Leave/Show', [
            'leaveRequest' => $leaveRequest,
            'isAdmin' => $isAdmin,
            'canCancel' => $leaveRequest->canBeCancelled() && $leaveRequest->user_id === $user->id,
        ]);
    }

    /**
     * Approve a leave request (Admin/HR only).
     */
    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('approve', $leaveRequest);

        $user = auth()->user();

        if ($leaveRequest->status !== 'pending') {
            return back()->withErrors(['error' => 'Only pending requests can be approved.']);
        }

        $leaveCreditService = $this->leaveCreditService;

        DB::beginTransaction();
        try {
            // Update request status
            $leaveRequest->update([
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => $request->review_notes,
            ]);

            // Deduct leave credits if applicable
            if ($leaveRequest->requiresCredits()) {
                $year = $request->input('credits_year', now()->year);
                $leaveCreditService->deductCredits($leaveRequest, $year);
            }

            // TODO: Create attendance records for the leave period
            // This will mark the days as on leave in the attendance table

            // Notify the employee about approval
            $this->notificationService->notifyLeaveRequestStatusChange(
                $leaveRequest->user_id,
                'approved',
                $leaveRequest->leave_type,
                $leaveRequest->id
            );

            // Send Email Notification
            $employee = $leaveRequest->user;
            if ($employee && $employee->email && filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($employee->email)->send(new LeaveRequestStatusUpdated($leaveRequest, $employee));
            }

            DB::commit();

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', 'Leave request approved successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Failed to approve leave request. Please try again.']);
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

            // Send Email Notification
            $employee = $leaveRequest->user;
            if ($employee && $employee->email && filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($employee->email)->send(new LeaveRequestStatusUpdated($leaveRequest, $employee));
            }

            DB::commit();

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
                \Carbon\Carbon::parse($leaveRequest->start_date)->format('Y-m-d'),
                \Carbon\Carbon::parse($leaveRequest->end_date)->format('Y-m-d')
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
}
