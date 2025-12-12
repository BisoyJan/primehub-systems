<?php

namespace App\Http\Controllers;

use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Models\Campaign;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmployeeScheduleController extends Controller
{
    /**
     * Display a listing of employee schedules.
     */
    public function index(Request $request)
    {
        $query = EmployeeSchedule::with(['user', 'campaign', 'site']);

        // Search by employee name
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        // Filters
        if ($request->has('user_id') && $request->user_id !== 'all') {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('campaign_id') && $request->campaign_id !== 'all') {
            $query->where('campaign_id', $request->campaign_id);
        }

        if ($request->has('is_active') && $request->is_active !== 'all') {
            $query->where('is_active', $request->is_active);
        }

        if ($request->has('active_only') && $request->active_only) {
            $query->active();
        }

        // Order by user name first for grouping, then by effective_date
        $schedules = $query->join('users', 'employee_schedules.user_id', '=', 'users.id')
            ->select('employee_schedules.*')
            ->orderBy('users.first_name')
            ->orderBy('users.last_name')
            ->orderBy('employee_schedules.effective_date', 'desc')
            ->paginate(25)
            ->withQueryString();

        $users = User::orderBy('first_name')->get();
        $campaigns = Campaign::orderBy('name')->get();
        $sites = Site::orderBy('name')->get();

        // Get users without any schedules
        $usersWithoutSchedules = User::doesntHave('employeeSchedules')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);

        // Get users with schedules but no active schedule
        $usersWithInactiveSchedules = User::whereHas('employeeSchedules', function ($query) {
                // Has at least one schedule
            })
            ->whereDoesntHave('employeeSchedules', function ($query) {
                // But no active schedule
                $query->where('is_active', true);
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);

        return Inertia::render('Attendance/EmployeeSchedules/Index', [
            'schedules' => $schedules,
            'users' => $users,
            'campaigns' => $campaigns,
            'sites' => $sites,
            'usersWithoutSchedules' => $usersWithoutSchedules,
            'usersWithInactiveSchedules' => $usersWithInactiveSchedules,
            'filters' => $request->only(['search', 'user_id', 'campaign_id', 'is_active', 'active_only']),
        ]);
    }

    /**
     * Show the form for creating a new schedule.
     */
    public function create(Request $request)
    {
        $currentUser = $request->user();
        $isRestrictedRole = in_array($currentUser->role, ['Agent', 'Team Lead']);
        $isFirstTimeSetup = $isRestrictedRole && !$currentUser->employeeSchedules()->exists();

        // Get all users with their hired_date and schedule count
        $users = User::withCount('employeeSchedules')
            ->orderBy('first_name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'hired_date' => $user->hired_date?->format('Y-m-d'),
                    'has_schedule' => $user->employee_schedules_count > 0,
                ];
            });

        $campaigns = Campaign::orderBy('name')->get();
        $sites = Site::orderBy('name')->get();

        return Inertia::render('Attendance/EmployeeSchedules/Create', [
            'users' => $users,
            'campaigns' => $campaigns,
            'sites' => $sites,
            'currentUser' => [
                'id' => $currentUser->id,
                'name' => $currentUser->name,
                'email' => $currentUser->email,
                'role' => $currentUser->role,
            ],
            'isRestrictedRole' => $isRestrictedRole,
            'isFirstTimeSetup' => $isFirstTimeSetup,
        ]);
    }

    /**
     * Store a newly created schedule.
     */
    public function store(Request $request)
    {
        $currentUser = $request->user();
        $isRestrictedRole = in_array($currentUser->role, ['Agent', 'Team Lead']);
        $isFirstTimeSetup = $isRestrictedRole && !$currentUser->employeeSchedules()->exists();

        // Build validation rules based on user role
        $rules = [
            'user_id' => 'required|exists:users,id',
            'shift_type' => 'required|in:graveyard_shift,night_shift,morning_shift,afternoon_shift,utility_24h',
            'scheduled_time_in' => 'required|date_format:H:i',
            'scheduled_time_out' => 'required|date_format:H:i',
            'work_days' => 'required|array',
            'work_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'grace_period_minutes' => 'required|integer|min:0|max:60',
            'effective_date' => 'required|date',
            'end_date' => 'nullable|date|after:effective_date',
        ];

        // Make campaign_id and site_id required for Agent and Team Lead roles
        if ($isRestrictedRole) {
            $rules['campaign_id'] = 'required|exists:campaigns,id';
            $rules['site_id'] = 'required|exists:sites,id';
        } else {
            $rules['campaign_id'] = 'nullable|exists:campaigns,id';
            $rules['site_id'] = 'nullable|exists:sites,id';
        }

        $validated = $request->validate($rules);

        // Check for duplicate schedule (same site, shift type, time in, time out for this user)
        $duplicateQuery = EmployeeSchedule::where('user_id', $validated['user_id'])
            ->where('shift_type', $validated['shift_type'])
            ->where('scheduled_time_in', $validated['scheduled_time_in'])
            ->where('scheduled_time_out', $validated['scheduled_time_out']);

        // Check site_id (handle null values properly)
        if (isset($validated['site_id']) && $validated['site_id']) {
            $duplicateQuery->where('site_id', $validated['site_id']);
        } else {
            $duplicateQuery->whereNull('site_id');
        }

        $existingSchedule = $duplicateQuery->first();

        if ($existingSchedule) {
            throw ValidationException::withMessages([
                'shift_type' => 'A schedule with the same site, shift type, and times already exists for this employee.',
            ]);
        }

        // Deactivate previous active schedules if this is active
        if (!isset($validated['end_date'])) {
            EmployeeSchedule::where('user_id', $validated['user_id'])
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $schedule = EmployeeSchedule::create($validated);

        // If first-time setup for Agent/Team Lead, redirect to dashboard
        if ($isFirstTimeSetup) {
            return redirect()->route('dashboard')
                ->with('flash', ['message' => 'Your schedule has been set up successfully. Welcome!', 'type' => 'success']);
        }

        return redirect()->route('employee-schedules.index')
            ->with('flash', ['message' => 'Employee schedule created successfully', 'type' => 'success']);
    }

    /**
     * Show the form for editing the specified schedule.
     */
    public function edit(Request $request, EmployeeSchedule $employeeSchedule)
    {
        $currentUser = $request->user();
        $canEditEffectiveDate = in_array($currentUser->role, ['Super Admin', 'Admin', 'HR']);

        $users = User::orderBy('first_name')->get();
        $campaigns = Campaign::orderBy('name')->get();
        $sites = Site::orderBy('name')->get();

        // Format time fields to H:i (remove seconds) for frontend compatibility
        $scheduleData = $employeeSchedule->toArray();
        $scheduleData['scheduled_time_in'] = substr($employeeSchedule->scheduled_time_in, 0, 5);
        $scheduleData['scheduled_time_out'] = substr($employeeSchedule->scheduled_time_out, 0, 5);
        // Format date fields to Y-m-d for frontend compatibility
        $scheduleData['effective_date'] = $employeeSchedule->effective_date?->format('Y-m-d');
        $scheduleData['end_date'] = $employeeSchedule->end_date?->format('Y-m-d');

        return Inertia::render('Attendance/EmployeeSchedules/Edit', [
            'schedule' => $scheduleData,
            'users' => $users,
            'campaigns' => $campaigns,
            'sites' => $sites,
            'canEditEffectiveDate' => $canEditEffectiveDate,
        ]);
    }

    /**
     * Update the specified schedule.
     */
    public function update(Request $request, EmployeeSchedule $employeeSchedule)
    {
        $currentUser = $request->user();
        $canEditEffectiveDate = in_array($currentUser->role, ['Super Admin', 'Admin', 'HR']);

        $rules = [
            'campaign_id' => 'nullable|exists:campaigns,id',
            'site_id' => 'nullable|exists:sites,id',
            'shift_type' => 'required|in:graveyard_shift,night_shift,morning_shift,afternoon_shift,utility_24h',
            'scheduled_time_in' => 'required|date_format:H:i',
            'scheduled_time_out' => 'required|date_format:H:i',
            'work_days' => 'required|array',
            'work_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'grace_period_minutes' => 'required|integer|min:0|max:60',
            'is_active' => 'boolean',
            'end_date' => 'nullable|date|after:effective_date',
        ];

        // Allow effective_date to be updated by admin roles
        if ($canEditEffectiveDate) {
            $rules['effective_date'] = 'nullable|date';
        }

        $validated = $request->validate($rules);

        // Check for duplicate schedule (same site, shift type, time in, time out for this user, excluding current schedule)
        $duplicateQuery = EmployeeSchedule::where('user_id', $employeeSchedule->user_id)
            ->where('id', '!=', $employeeSchedule->id)
            ->where('shift_type', $validated['shift_type'])
            ->where('scheduled_time_in', $validated['scheduled_time_in'])
            ->where('scheduled_time_out', $validated['scheduled_time_out']);

        // Check site_id (handle null values properly)
        if (isset($validated['site_id']) && $validated['site_id']) {
            $duplicateQuery->where('site_id', $validated['site_id']);
        } else {
            $duplicateQuery->whereNull('site_id');
        }

        $existingSchedule = $duplicateQuery->first();

        if ($existingSchedule) {
            throw ValidationException::withMessages([
                'shift_type' => 'A schedule with the same site, shift type, and times already exists for this employee.',
            ]);
        }

        // If activating this schedule, deactivate other schedules for this user
        if (isset($validated['is_active']) && $validated['is_active'] && !$employeeSchedule->is_active) {
            EmployeeSchedule::where('user_id', $employeeSchedule->user_id)
                ->where('id', '!=', $employeeSchedule->id)
                ->update(['is_active' => false]);
        }

        $employeeSchedule->update($validated);

        return redirect()->route('employee-schedules.index')
            ->with('flash', ['message' => 'Employee schedule updated successfully', 'type' => 'success']);
    }

    /**
     * Remove the specified schedule.
     */
    public function destroy(EmployeeSchedule $employeeSchedule)
    {
        $employeeSchedule->delete();

        return redirect()->route('employee-schedules.index')
            ->with('flash', ['message' => 'Employee schedule deleted successfully', 'type' => 'success']);
    }

    /**
     * Toggle the active status of a schedule.
     */
    public function toggleActive(EmployeeSchedule $employeeSchedule)
    {
        $newStatus = !$employeeSchedule->is_active;

        // If activating, deactivate other schedules for this user
        if ($newStatus) {
            EmployeeSchedule::where('user_id', $employeeSchedule->user_id)
                ->where('id', '!=', $employeeSchedule->id)
                ->update(['is_active' => false]);
        }

        $employeeSchedule->update(['is_active' => $newStatus]);

        return redirect()->back()
            ->with('flash', ['message' => 'Schedule status updated successfully', 'type' => 'success']);
    }

    /**
     * Get schedule for a specific user and date.
     */
    public function getSchedule(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'date' => 'required|date',
        ]);

        $schedule = EmployeeSchedule::where('user_id', $request->user_id)
            ->forDate($request->date)
            ->where('is_active', true)
            ->with(['campaign', 'site'])
            ->first();

        return response()->json($schedule);
    }

    /**
     * Get all schedules for a specific user.
     */
    public function getUserSchedules(Request $request, $userId)
    {
        $request->validate([
            'user_id' => 'nullable|exists:users,id',
        ]);

        $schedules = EmployeeSchedule::where('user_id', $userId)
            ->with(['user', 'campaign', 'site'])
            ->orderBy('effective_date', 'desc')
            ->get();

        return response()->json($schedules);
    }

    /**
     * Show the first-time schedule setup form for Agent/Team Lead.
     * This is a separate route without permission middleware.
     */
    public function firstTimeSetup(Request $request)
    {
        $currentUser = $request->user();

        // Only allow Agent and Team Lead roles
        if (!in_array($currentUser->role, ['Agent', 'Team Lead'])) {
            return redirect()->route('dashboard')
                ->with('flash', ['message' => 'You do not need to set up a schedule.', 'type' => 'info']);
        }

        // Check if user already has a schedule
        if ($currentUser->employeeSchedules()->exists()) {
            return redirect()->route('dashboard')
                ->with('flash', ['message' => 'You already have a schedule set up.', 'type' => 'info']);
        }

        $campaigns = Campaign::orderBy('name')->get();
        $sites = Site::orderBy('name')->get();

        return Inertia::render('Attendance/EmployeeSchedules/Create', [
            'users' => [], // Not needed for first-time setup
            'campaigns' => $campaigns,
            'sites' => $sites,
            'currentUser' => [
                'id' => $currentUser->id,
                'name' => $currentUser->name,
                'email' => $currentUser->email,
                'role' => $currentUser->role,
            ],
            'isRestrictedRole' => true,
            'isFirstTimeSetup' => true,
        ]);
    }

    /**
     * Store the first-time schedule setup for Agent/Team Lead.
     * This is a separate route without permission middleware.
     */
    public function storeFirstTimeSetup(Request $request)
    {
        $currentUser = $request->user();

        // Only allow Agent and Team Lead roles
        if (!in_array($currentUser->role, ['Agent', 'Team Lead'])) {
            return redirect()->route('dashboard')
                ->with('flash', ['message' => 'You do not need to set up a schedule.', 'type' => 'info']);
        }

        // Check if user already has a schedule
        if ($currentUser->employeeSchedules()->exists()) {
            return redirect()->route('dashboard')
                ->with('flash', ['message' => 'You already have a schedule set up.', 'type' => 'info']);
        }

        $validated = $request->validate([
            'campaign_id' => 'required|exists:campaigns,id',
            'site_id' => 'required|exists:sites,id',
            'shift_type' => 'required|in:graveyard_shift,night_shift,morning_shift,afternoon_shift,utility_24h',
            'scheduled_time_in' => 'required|date_format:H:i',
            'scheduled_time_out' => 'required|date_format:H:i',
            'work_days' => 'required|array',
            'work_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'effective_date' => 'required|date',
        ]);

        // Force the user_id to be the current user
        $validated['user_id'] = $currentUser->id;
        $validated['grace_period_minutes'] = 15; // Default grace period
        $validated['is_active'] = true;

        // Check for duplicate schedule (same site, shift type, time in, time out) - safeguard
        $duplicateQuery = EmployeeSchedule::where('user_id', $validated['user_id'])
            ->where('shift_type', $validated['shift_type'])
            ->where('scheduled_time_in', $validated['scheduled_time_in'])
            ->where('scheduled_time_out', $validated['scheduled_time_out']);

        // Check site_id (handle null values properly)
        if (isset($validated['site_id']) && $validated['site_id']) {
            $duplicateQuery->where('site_id', $validated['site_id']);
        } else {
            $duplicateQuery->whereNull('site_id');
        }

        $existingSchedule = $duplicateQuery->first();

        if ($existingSchedule) {
            throw ValidationException::withMessages([
                'shift_type' => 'A schedule with the same site, shift type, and times already exists.',
            ]);
        }

        // Update user's hired_date with the effective_date
        $currentUser->update(['hired_date' => $validated['effective_date']]);

        EmployeeSchedule::create($validated);

        return redirect()->route('dashboard')
            ->with('flash', ['message' => 'Your schedule has been set up successfully. Welcome!', 'type' => 'success']);
    }
}
