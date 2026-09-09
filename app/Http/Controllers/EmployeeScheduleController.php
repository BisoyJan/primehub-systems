<?php

namespace App\Http\Controllers;

use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmployeeScheduleController extends Controller
{
    use RedirectsWithFlashMessages;

    /**
     * Display a listing of employee schedules.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Determine Team Lead's campaigns (if applicable)
        $teamLeadCampaignIds = [];
        if ($user->role === 'Team Lead') {
            $teamLeadCampaignIds = $user->getCampaignIds();
        }

        $scheduleFilters = $this->scheduleFilters($request, $user, $teamLeadCampaignIds);

        $employeesQuery = User::query()
            ->whereHas('employeeSchedules', $scheduleFilters)
            ->with(['employeeSchedules' => function ($q) use ($scheduleFilters) {
                $scheduleFilters($q);
                $q->with(['campaign', 'site'])
                    ->orderByDesc('employee_schedules.is_active')
                    ->orderByDesc('employee_schedules.effective_date');
            }]);

        // Search by employee name
        if ($request->has('search') && $request->search) {
            $employeesQuery->searchName($request->search);
        }

        if ($request->has('role') && $request->role !== 'all' && $request->role) {
            $roles = is_array($request->role)
                ? $request->role
                : array_filter(explode(',', $request->role));
            if (count($roles) > 0) {
                $employeesQuery->whereIn('role', $roles);
            }
        }

        // Filter out resigned employees by default
        // Resigned = has hired_date AND is_active = false
        $showResigned = $request->has('show_resigned') && $request->show_resigned;
        if (! $showResigned) {
            $employeesQuery->where(function ($q) {
                $q->whereNull('hired_date')->orWhere('is_active', true);
            });
        }

        $employees = $employeesQuery
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $employee) => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'name' => $employee->name,
                'role' => $employee->role,
                'schedules' => $employee->employeeSchedules,
            ]);

        $users = User::orderBy('first_name')->get();
        $campaigns = Campaign::orderBy('name')->get();
        $sites = Site::orderBy('name')->get();

        // Available roles for filtering
        $roles = [
            'Agent',
            'Team Lead',
            'IT',
            'HR',
            'Admin',
            'Utility',
        ];

        // Scope: exclude resigned employees (hired_date set AND is_active = false)
        $activeEmployeeScope = function ($q) {
            $q->where(function ($sub) {
                $sub->whereNull('hired_date')
                    ->orWhere('is_active', true);
            });
        };

        // Get users without any schedules (active employees only)
        $usersWithoutSchedules = User::doesntHave('employeeSchedules')
            ->where($activeEmployeeScope)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);

        // Get users with schedules but no active schedule (active employees only)
        $usersWithInactiveSchedules = User::whereHas('employeeSchedules', function ($query) {
            // Has at least one schedule
        })
            ->whereDoesntHave('employeeSchedules', function ($query) {
                // But no active schedule
                $query->where('is_active', true);
            })
            ->where($activeEmployeeScope)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);

        // Get users with multiple schedules (active employees only)
        $usersWithMultipleSchedules = User::withCount('employeeSchedules')
            ->where($activeEmployeeScope)
            ->having('employee_schedules_count', '>', 1)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'schedule_count' => $user->employee_schedules_count,
                ];
            });

        return Inertia::render('Attendance/EmployeeSchedules/Index', [
            'employees' => $employees,
            'users' => $users,
            'campaigns' => $campaigns,
            'sites' => $sites,
            'roles' => $roles,
            'teamLeadCampaignIds' => $teamLeadCampaignIds,
            'usersWithoutSchedules' => $usersWithoutSchedules,
            'usersWithInactiveSchedules' => $usersWithInactiveSchedules,
            'usersWithMultipleSchedules' => $usersWithMultipleSchedules,
            'filters' => $request->only(['search', 'user_id', 'role', 'campaign_id', 'is_active', 'active_only', 'show_resigned']),
        ]);
    }

    /**
     * Build the schedule-level filter closure, reused for both the `whereHas`
     * existence check and the eager-loaded schedule list so nested schedules
     * match the applied filters.
     */
    private function scheduleFilters(Request $request, User $user, array $teamLeadCampaignIds): \Closure
    {
        $userIds = [];
        if ($request->has('user_id') && $request->user_id !== 'all' && $request->user_id) {
            $userIds = is_array($request->user_id)
                ? $request->user_id
                : array_filter(explode(',', $request->user_id));
        }

        $campaignIds = [];
        if ($request->has('campaign_id') && $request->campaign_id !== 'all' && $request->campaign_id) {
            $campaignIds = is_array($request->campaign_id)
                ? $request->campaign_id
                : array_filter(explode(',', $request->campaign_id));
        }
        if (empty($campaignIds) && $user->role === 'Team Lead' && ! empty($teamLeadCampaignIds)) {
            $campaignIds = $teamLeadCampaignIds;
        }

        $statuses = [];
        if ($request->has('is_active') && $request->is_active !== 'all' && $request->is_active !== null && $request->is_active !== '') {
            $statuses = is_array($request->is_active)
                ? $request->is_active
                : array_filter(explode(',', (string) $request->is_active), fn ($v) => $v !== '');
        }

        $activeOnly = $request->has('active_only') && $request->active_only;

        return function ($query) use ($userIds, $campaignIds, $statuses, $activeOnly) {
            if (count($userIds) > 0) {
                $query->whereIn('employee_schedules.user_id', $userIds);
            }

            if (count($campaignIds) > 0) {
                $query->whereIn('employee_schedules.campaign_id', $campaignIds);
            }

            if (count($statuses) > 0) {
                $query->whereIn('employee_schedules.is_active', $statuses);
            }

            if ($activeOnly) {
                $query->active();
            }
        };
    }

    /**
     * Show the form for creating a new schedule.
     */
    public function create(Request $request)
    {
        $currentUser = $request->user();
        $isRestrictedRole = in_array($currentUser->role, ['Agent', 'Team Lead']);
        $isFirstTimeSetup = $isRestrictedRole && ! $currentUser->employeeSchedules()->exists();

        // Get all users with their hired_date and schedule count
        $users = User::withCount('employeeSchedules')
            ->orderBy('first_name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
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
        $isFirstTimeSetup = $isRestrictedRole && ! $currentUser->employeeSchedules()->exists();

        // Build validation rules based on user role
        // Resolve the TARGET user's role (admins can create schedules for others).
        $targetUserId = $request->input('user_id');
        $targetUserRole = $targetUserId
            ? optional(User::find($targetUserId))->role
            : $currentUser->role;
        $isTeamLeadTarget = $targetUserRole === 'Team Lead';

        $rules = [
            'user_id' => 'required|exists:users,id',
            'is_utility' => 'sometimes|boolean',
            'is_flexible' => 'sometimes|boolean',
            'scheduled_time_in' => ['nullable', 'date_format:H:i'],
            'scheduled_time_out' => ['nullable', 'date_format:H:i'],
            'work_days' => ['nullable', 'array'],
            'work_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'grace_period_minutes' => 'required|integer|min:0|max:60',
            'effective_date' => 'required|date',
            'end_date' => 'nullable|date|after:effective_date',
        ];

        if (! ($request->boolean('is_flexible') ?? false)) {
            $rules['scheduled_time_in'] = ['required', 'date_format:H:i'];
            $rules['scheduled_time_out'] = ['required', 'date_format:H:i'];
            $rules['work_days'] = ['required', 'array'];
        }

        // For Team Leads: campaign_id is derived from the first managed campaign.
        // The single Campaign dropdown is hidden in the UI; campaign_ids is the source of truth.
        if ($isTeamLeadTarget) {
            $rules['campaign_id'] = 'nullable|exists:campaigns,id';
            $rules['site_id'] = 'required|exists:sites,id';
            $rules['campaign_ids'] = 'required|array|min:1';
            $rules['campaign_ids.*'] = 'exists:campaigns,id';
        } elseif ($isRestrictedRole) {
            // Agent self/admin-created schedule
            $rules['campaign_id'] = 'required|exists:campaigns,id';
            $rules['site_id'] = 'required|exists:sites,id';
            $rules['campaign_ids'] = 'nullable|array';
            $rules['campaign_ids.*'] = 'exists:campaigns,id';
        } else {
            $rules['campaign_id'] = 'nullable|exists:campaigns,id';
            $rules['site_id'] = 'nullable|exists:sites,id';
            $rules['campaign_ids'] = 'nullable|array';
            $rules['campaign_ids.*'] = 'exists:campaigns,id';
        }

        $validated = $request->validate($rules);
        $validated['is_flexible'] = (bool) ($validated['is_flexible'] ?? false);

        // effective_date is the employee's hired date, so it must stay identical
        // across every schedule they own.
        $targetUser = User::find($validated['user_id']);
        if ($targetUser?->hired_date) {
            $validated['effective_date'] = $targetUser->hired_date->format('Y-m-d');
        }

        if ($validated['is_flexible']) {
            $validated['scheduled_time_in'] = null;
            $validated['scheduled_time_out'] = null;
            $validated['work_days'] = [];
            $validated['shift_type'] = 'night_shift';
        } else {
            $validated['shift_type'] = $this->deriveShiftType(
                $validated['scheduled_time_in'],
                (bool) ($validated['is_utility'] ?? false)
            );
        }

        unset($validated['is_utility']);

        // For Team Leads, derive the schedule's primary campaign_id from the
        // first item in campaign_ids so the legacy single-FK column stays
        // consistent with the campaign_user pivot.
        if ($isTeamLeadTarget && ! empty($validated['campaign_ids'])) {
            $validated['campaign_id'] = (int) $validated['campaign_ids'][0];
        }

        // Check for duplicate schedule (same site, shift type, time in, time out for this user)
        $duplicateQuery = EmployeeSchedule::where('user_id', $validated['user_id'])
            ->where('is_flexible', $validated['is_flexible'])
            ->where('shift_type', $validated['shift_type']);

        if (! $validated['is_flexible']) {
            $duplicateQuery->where('scheduled_time_in', $validated['scheduled_time_in'])
                ->where('scheduled_time_out', $validated['scheduled_time_out']);
        }

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
        if (! isset($validated['end_date'])) {
            EmployeeSchedule::where('user_id', $validated['user_id'])
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $schedule = EmployeeSchedule::create($validated);

        if ($targetUser && ! $targetUser->hired_date) {
            $targetUser->update(['hired_date' => $validated['effective_date']]);
        }

        // Sync campaign_user pivot for Team Leads
        if ($targetUser && $targetUser->role === 'Team Lead' && ! empty($validated['campaign_ids'])) {
            $targetUser->campaigns()->syncWithoutDetaching($validated['campaign_ids']);
        }

        // If first-time setup for Agent/Team Lead, redirect to dashboard
        if ($isFirstTimeSetup) {
            return $this->redirectWithFlash('dashboard', 'Your schedule has been set up successfully. Welcome!');
        }

        return $this->redirectWithFlash('employee-schedules.index', 'Employee schedule created successfully');
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
        $scheduleData['scheduled_time_in'] = $employeeSchedule->scheduled_time_in ? substr($employeeSchedule->scheduled_time_in, 0, 5) : null;
        $scheduleData['scheduled_time_out'] = $employeeSchedule->scheduled_time_out ? substr($employeeSchedule->scheduled_time_out, 0, 5) : null;
        $scheduleData['work_days'] = $employeeSchedule->work_days ?? [];
        // Format date fields to Y-m-d for frontend compatibility
        $scheduleData['effective_date'] = $employeeSchedule->effective_date?->format('Y-m-d');
        $scheduleData['end_date'] = $employeeSchedule->end_date?->format('Y-m-d');

        // Get the schedule owner's currently assigned campaign IDs (for TL multi-campaign)
        $scheduleOwner = $employeeSchedule->user;
        $userCampaignIds = $scheduleOwner?->role === 'Team Lead'
            ? $scheduleOwner->getCampaignIds()
            : [];

        return Inertia::render('Attendance/EmployeeSchedules/Edit', [
            'schedule' => $scheduleData,
            'users' => $users,
            'campaigns' => $campaigns,
            'sites' => $sites,
            'canEditEffectiveDate' => $canEditEffectiveDate,
            'userCampaignIds' => $userCampaignIds,
        ]);
    }

    /**
     * Update the specified schedule.
     */
    public function update(Request $request, EmployeeSchedule $employeeSchedule)
    {
        $currentUser = $request->user();
        $canEditEffectiveDate = in_array($currentUser->role, ['Super Admin', 'Admin', 'HR']);

        $isTeamLeadTarget = $employeeSchedule->user?->role === 'Team Lead';

        $rules = [
            'campaign_id' => 'nullable|exists:campaigns,id',
            'campaign_ids' => $isTeamLeadTarget ? 'required|array|min:1' : 'nullable|array',
            'campaign_ids.*' => 'exists:campaigns,id',
            'site_id' => 'nullable|exists:sites,id',
            'is_utility' => 'sometimes|boolean',
            'is_flexible' => 'sometimes|boolean',
            'scheduled_time_in' => ['nullable', 'date_format:H:i'],
            'scheduled_time_out' => ['nullable', 'date_format:H:i'],
            'work_days' => ['nullable', 'array'],
            'work_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'grace_period_minutes' => 'required|integer|min:0|max:60',
            'is_active' => 'boolean',
            'end_date' => 'nullable|date|after:effective_date',
        ];

        if (! ($request->boolean('is_flexible') ?? false)) {
            $rules['scheduled_time_in'] = ['required', 'date_format:H:i'];
            $rules['scheduled_time_out'] = ['required', 'date_format:H:i'];
            $rules['work_days'] = ['required', 'array'];
        }

        // Allow effective_date to be updated by admin roles
        if ($canEditEffectiveDate) {
            $rules['effective_date'] = 'nullable|date';
        }

        $validated = $request->validate($rules);
        $validated['is_flexible'] = (bool) ($validated['is_flexible'] ?? false);

        if ($validated['is_flexible']) {
            $validated['scheduled_time_in'] = null;
            $validated['scheduled_time_out'] = null;
            $validated['work_days'] = [];
            $validated['shift_type'] = 'night_shift';
        } else {
            $validated['shift_type'] = $this->deriveShiftType(
                $validated['scheduled_time_in'],
                (bool) ($validated['is_utility'] ?? false)
            );
        }

        unset($validated['is_utility']);

        // For Team Leads, derive the schedule's primary campaign_id from the
        // first item in campaign_ids so the legacy single-FK column stays
        // consistent with the campaign_user pivot.
        if ($isTeamLeadTarget && ! empty($validated['campaign_ids'])) {
            $validated['campaign_id'] = (int) $validated['campaign_ids'][0];
        }

        // Check for duplicate schedule (same site, shift type, time in, time out for this user, excluding current schedule)
        $duplicateQuery = EmployeeSchedule::where('user_id', $employeeSchedule->user_id)
            ->where('id', '!=', $employeeSchedule->id)
            ->where('shift_type', $validated['shift_type'])
            ->where('is_flexible', $validated['is_flexible']);

        if (! $validated['is_flexible']) {
            $duplicateQuery->where('scheduled_time_in', $validated['scheduled_time_in'])
                ->where('scheduled_time_out', $validated['scheduled_time_out']);
        }

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
        if (isset($validated['is_active']) && $validated['is_active'] && ! $employeeSchedule->is_active) {
            EmployeeSchedule::where('user_id', $employeeSchedule->user_id)
                ->where('id', '!=', $employeeSchedule->id)
                ->update(['is_active' => false]);
        }

        $employeeSchedule->update($validated);

        // The hired date is owned by the employee; UserObserver cascades the new
        // value to their other schedules.
        $scheduleOwner = $employeeSchedule->user;
        if ($canEditEffectiveDate && ! empty($validated['effective_date']) && $scheduleOwner) {
            $scheduleOwner->update(['hired_date' => $employeeSchedule->effective_date->format('Y-m-d')]);
        }

        // Sync campaign_user pivot for Team Leads
        if ($scheduleOwner && $scheduleOwner->role === 'Team Lead' && isset($validated['campaign_ids'])) {
            $scheduleOwner->campaigns()->sync($validated['campaign_ids']);
        }

        return $this->redirectWithFlash('employee-schedules.index', 'Employee schedule updated successfully');
    }

    /**
     * Remove the specified schedule.
     */
    public function destroy(EmployeeSchedule $employeeSchedule)
    {
        $employeeSchedule->delete();

        return $this->redirectWithFlash('employee-schedules.index', 'Employee schedule deleted successfully');
    }

    /**
     * Toggle the active status of a schedule.
     */
    public function toggleActive(EmployeeSchedule $employeeSchedule)
    {
        $newStatus = ! $employeeSchedule->is_active;

        // If activating, deactivate other schedules for this user
        if ($newStatus) {
            EmployeeSchedule::where('user_id', $employeeSchedule->user_id)
                ->where('id', '!=', $employeeSchedule->id)
                ->update(['is_active' => false]);
        }

        $employeeSchedule->update(['is_active' => $newStatus]);

        return $this->backWithFlash('Schedule status updated successfully');
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
        if (! in_array($currentUser->role, ['Agent', 'Team Lead'])) {
            return $this->redirectWithFlash('dashboard', 'You do not need to set up a schedule.', 'info');
        }

        // Check if user already has a schedule
        if ($currentUser->employeeSchedules()->exists()) {
            return $this->redirectWithFlash('dashboard', 'You already have a schedule set up.', 'info');
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
        if (! in_array($currentUser->role, ['Agent', 'Team Lead'])) {
            return $this->redirectWithFlash('dashboard', 'You do not need to set up a schedule.', 'info');
        }

        // Check if user already has a schedule
        if ($currentUser->employeeSchedules()->exists()) {
            return $this->redirectWithFlash('dashboard', 'You already have a schedule set up.', 'info');
        }

        $validated = $request->validate([
            'campaign_id' => 'required|exists:campaigns,id',
            'site_id' => 'required|exists:sites,id',
            'is_utility' => 'sometimes|boolean',
            'is_flexible' => 'sometimes|boolean',
            'scheduled_time_in' => ['nullable', 'date_format:H:i'],
            'scheduled_time_out' => ['nullable', 'date_format:H:i'],
            'work_days' => ['nullable', 'array'],
            'work_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'effective_date' => 'required|date',
        ]);

        // Force the user_id to be the current user
        $validated['user_id'] = $currentUser->id;
        $validated['grace_period_minutes'] = 0; // Default grace period for first-time setup
        $validated['is_active'] = true;
        $validated['is_flexible'] = (bool) ($validated['is_flexible'] ?? false);

        if ($validated['is_flexible']) {
            $validated['scheduled_time_in'] = null;
            $validated['scheduled_time_out'] = null;
            $validated['work_days'] = [];
            $validated['shift_type'] = 'night_shift';
        } else {
            $validated['shift_type'] = $this->deriveShiftType(
                $validated['scheduled_time_in'],
                (bool) ($validated['is_utility'] ?? false)
            );
        }

        unset($validated['is_utility']);

        // Check for duplicate schedule (same site, shift type, time in, time out) - safeguard
        $duplicateQuery = EmployeeSchedule::where('user_id', $validated['user_id'])
            ->where('shift_type', $validated['shift_type'])
            ->where('is_flexible', $validated['is_flexible']);

        if (! $validated['is_flexible']) {
            $duplicateQuery->where('scheduled_time_in', $validated['scheduled_time_in'])
                ->where('scheduled_time_out', $validated['scheduled_time_out']);
        }

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

        return $this->redirectWithFlash('dashboard', 'Your schedule has been set up successfully. Welcome!');
    }

    /**
     * Derive the canonical shift_type from a scheduled time-in.
     *
     * 24-hour utility shifts are flagged explicitly because they cannot be
     * inferred from a clock. Everything else maps from the time-in hour:
     *   05:00–11:59 → morning_shift
     *   12:00–17:59 → afternoon_shift
     *   otherwise   → night_shift  (absorbs the former graveyard 00:00–04:59)
     */
    private function deriveShiftType(string $timeIn, bool $isUtility): string
    {
        if ($isUtility) {
            return 'utility_24h';
        }

        $hour = (int) substr($timeIn, 0, 2);

        return match (true) {
            $hour >= 5 && $hour < 12 => 'morning_shift',
            $hour >= 12 && $hour < 18 => 'afternoon_shift',
            default => 'night_shift',
        };
    }
}
