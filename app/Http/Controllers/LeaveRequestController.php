<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LeaveRequestController extends Controller
{
    protected $leaveCreditService;

    public function __construct(LeaveCreditService $leaveCreditService)
    {
        $this->leaveCreditService = $leaveCreditService;
    }

    /**
     * Display a listing of leave requests.
     */
    public function index(Request $request)
    {
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
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Leave/Index', [
            'leaveRequests' => $leaveRequests,
            'filters' => $request->only(['status', 'type', 'start_date', 'end_date', 'user_id']),
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Show the form for creating a new leave request.
     */
    public function create()
    {
        $user = auth()->user();
        $leaveCreditService = $this->leaveCreditService;

        // Auto-backfill missing credits if any
        $leaveCreditService->backfillCredits($user);

        // Get leave credits summary
        $creditsSummary = $leaveCreditService->getSummary($user);

        // Get attendance points
        $attendancePoints = $leaveCreditService->getAttendancePoints($user);

        // Check if user has recent absence
        $hasRecentAbsence = $leaveCreditService->hasRecentAbsence($user);
        $nextEligibleLeaveDate = $hasRecentAbsence
            ? $leaveCreditService->getNextEligibleLeaveDate($user)
            : null;

        // Team Lead email options
        $teamLeadEmails = [
            'TeamLead1@primedigital.ph',
            'TeamLead2@primedigital.ph',
            'TeamLead3@primedigital.ph',
            'TeamLead4@primedigital.ph',
            'TeamLead5@primedigital.ph',
            'TeamLead6@primedigital.ph',
            'TeamLead7@primedigital.ph',
            'TeamLead8@primedigital.ph',
        ];

        // Campaign/Department options - fetch from database + add Management
        $campaignsFromDb = \App\Models\Campaign::orderBy('name')->pluck('name')->toArray();
        $campaigns = array_merge(['Management (For TL/Admin)'], $campaignsFromDb);

        return Inertia::render('Leave/Create', [
            'creditsSummary' => $creditsSummary,
            'attendancePoints' => $attendancePoints,
            'hasRecentAbsence' => $hasRecentAbsence,
            'nextEligibleLeaveDate' => $nextEligibleLeaveDate?->format('Y-m-d'),
            'teamLeadEmails' => $teamLeadEmails,
            'campaigns' => $campaigns,
            'twoWeeksFromNow' => now()->addWeeks(2)->format('Y-m-d'),
        ]);
    }

    /**
     * Store a newly created leave request.
     */
    public function store(LeaveRequestRequest $request)
    {
        $user = auth()->user();
        $leaveCreditService = $this->leaveCreditService;

        // Calculate days
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $daysRequested = $leaveCreditService->calculateDays($startDate, $endDate);

        // Get current attendance points
        $attendancePoints = $leaveCreditService->getAttendancePoints($user);

        // Prepare data for validation
        $validationData = array_merge($request->validated(), [
            'days_requested' => $daysRequested,
            'credits_year' => now()->year,
        ]);

        // Validate business rules
        $validation = $leaveCreditService->validateLeaveRequest($user, $validationData);

        if (!$validation['valid']) {
            return back()->withErrors(['validation' => $validation['errors']])->withInput();
        }

        DB::beginTransaction();
        try {
            // Create leave request
            $leaveRequest = LeaveRequest::create([
                'user_id' => $user->id,
                'leave_type' => $request->leave_type,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days_requested' => $daysRequested,
                'reason' => $request->reason,
                'team_lead_email' => $request->team_lead_email,
                'campaign_department' => $request->campaign_department,
                'medical_cert_submitted' => $request->boolean('medical_cert_submitted', false),
                'status' => 'pending',
                'attendance_points_at_request' => $attendancePoints,
            ]);

            DB::commit();

            return redirect()->route('leave-requests.show', $leaveRequest)
                ->with('success', 'Leave request submitted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Failed to submit leave request. Please try again.'])->withInput();
        }
    }

    /**
     * Display the specified leave request.
     */
    public function show(LeaveRequest $leaveRequest)
    {
        $user = auth()->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin', 'HR']);

        // Check authorization
        if (!$isAdmin && $leaveRequest->user_id !== $user->id) {
            abort(403, 'Unauthorized access to leave request.');
        }

        $leaveRequest->load(['user', 'reviewer']);

        return Inertia::render('Leave/Show', [
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
        $user = auth()->user();

        // Check authorization
        if (!in_array($user->role, ['Super Admin', 'Admin', 'HR'])) {
            abort(403, 'Unauthorized to approve leave requests.');
        }

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
        $user = auth()->user();

        // Check authorization
        if (!in_array($user->role, ['Super Admin', 'Admin', 'HR'])) {
            abort(403, 'Unauthorized to deny leave requests.');
        }

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
        $user = auth()->user();

        // Check authorization
        if ($leaveRequest->user_id !== $user->id) {
            abort(403, 'Unauthorized to cancel this leave request.');
        }

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
