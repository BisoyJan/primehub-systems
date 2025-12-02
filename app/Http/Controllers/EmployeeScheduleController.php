<?php

namespace App\Http\Controllers;

use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Models\Campaign;
use App\Models\Site;
use Illuminate\Http\Request;
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

        $schedules = $query->orderBy('effective_date', 'desc')
            ->paginate(25)
            ->withQueryString();

        $users = User::orderBy('first_name')->get();
        $campaigns = Campaign::orderBy('name')->get();
        $sites = Site::orderBy('name')->get();

        return Inertia::render('Attendance/EmployeeSchedules/Index', [
            'schedules' => $schedules,
            'users' => $users,
            'campaigns' => $campaigns,
            'sites' => $sites,
            'filters' => $request->only(['search', 'user_id', 'campaign_id', 'is_active', 'active_only']),
        ]);
    }

    /**
     * Show the form for creating a new schedule.
     */
    public function create()
    {
        // Only get users who don't have an active schedule
        $usersWithActiveSchedule = EmployeeSchedule::where('is_active', true)
            ->pluck('user_id')
            ->toArray();

        $users = User::whereNotIn('id', $usersWithActiveSchedule)
            ->orderBy('first_name')
            ->get();

        $campaigns = Campaign::orderBy('name')->get();
        $sites = Site::orderBy('name')->get();

        return Inertia::render('Attendance/EmployeeSchedules/Create', [
            'users' => $users,
            'campaigns' => $campaigns,
            'sites' => $sites,
        ]);
    }

    /**
     * Store a newly created schedule.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'campaign_id' => 'nullable|exists:campaigns,id',
            'site_id' => 'nullable|exists:sites,id',
            'shift_type' => 'required|in:graveyard_shift,night_shift,morning_shift,afternoon_shift,utility_24h',
            'scheduled_time_in' => 'required|date_format:H:i',
            'scheduled_time_out' => 'required|date_format:H:i',
            'work_days' => 'required|array',
            'work_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'grace_period_minutes' => 'required|integer|min:0|max:60',
            'effective_date' => 'required|date',
            'end_date' => 'nullable|date|after:effective_date',
        ]);

        // Deactivate previous active schedules if this is active
        if (!isset($validated['end_date'])) {
            EmployeeSchedule::where('user_id', $validated['user_id'])
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $schedule = EmployeeSchedule::create($validated);

        return redirect()->route('employee-schedules.index')
            ->with('flash', ['message' => 'Employee schedule created successfully', 'type' => 'success']);
    }

    /**
     * Show the form for editing the specified schedule.
     */
    public function edit(EmployeeSchedule $employeeSchedule)
    {
        $users = User::orderBy('first_name')->get();
        $campaigns = Campaign::orderBy('name')->get();
        $sites = Site::orderBy('name')->get();

        return Inertia::render('Attendance/EmployeeSchedules/Edit', [
            'schedule' => $employeeSchedule,
            'users' => $users,
            'campaigns' => $campaigns,
            'sites' => $sites,
        ]);
    }

    /**
     * Update the specified schedule.
     */
    public function update(Request $request, EmployeeSchedule $employeeSchedule)
    {
        $validated = $request->validate([
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
        ]);

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
}
