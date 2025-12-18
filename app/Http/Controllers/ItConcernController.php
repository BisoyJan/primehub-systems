<?php

namespace App\Http\Controllers;

use App\Models\ItConcern;
use App\Models\Site;
use App\Models\User;
use App\Http\Requests\ItConcernRequest;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ItConcernController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    /**
     * Display a listing of IT concerns.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', ItConcern::class);

        $query = ItConcern::with(['user', 'site', 'resolvedBy']);

        // If user is an Agent, only show their own concerns
        if (auth()->user()->role === 'Agent' || auth()->user()->role === 'Admin') {
            $query->where('user_id', auth()->id());
        }

        // Search by station number or description
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('station_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filters
        if ($request->has('site_id') && $request->site_id !== 'all') {
            $query->where('site_id', $request->site_id);
        }

        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }

        // Sort by priority (urgent first) only for pending/in_progress, resolved/cancelled items are sorted normally by date
        $concerns = $query->orderByRaw("CASE
            WHEN status IN ('pending', 'in_progress') AND priority = 'urgent' THEN 1
            WHEN status IN ('pending', 'in_progress') AND priority = 'high' THEN 2
            WHEN status IN ('pending', 'in_progress') AND priority = 'medium' THEN 3
            WHEN status IN ('pending', 'in_progress') AND priority = 'low' THEN 4
            ELSE 5 END")
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->withQueryString();

        $sites = Site::orderBy('name')->get();

        return Inertia::render('FormRequest/ItConcerns/Index', [
            'concerns' => $concerns,
            'sites' => $sites,
            'filters' => $request->only(['search', 'site_id', 'category', 'status', 'priority']),
        ]);
    }

    /**
     * Show the form for creating a new IT concern.
     */
    public function create()
    {
        $this->authorize('create', ItConcern::class);

        $sites = Site::orderBy('name')->get();
        
        // Pass users list for all roles to file concerns on behalf of others (e.g., if their PC is not working)
        $users = User::where('is_approved', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name']);

        return Inertia::render('FormRequest/ItConcerns/Create', [
            'sites' => $sites,
            'users' => $users,
        ]);
    }

    /**
     * Store a newly created IT concern.
     */
    public function store(ItConcernRequest $request)
    {
        $this->authorize('create', ItConcern::class);

        $validated = $request->validated();
        
        // If user_id is provided (filing for someone else), use it; otherwise default to authenticated user
        if (!isset($validated['user_id']) || empty($validated['user_id'])) {
            $validated['user_id'] = auth()->id();
        }

        $itConcern = ItConcern::create($validated);

        // Load relationships for notification details
        $itConcern->load(['site', 'user']);

        // Notify IT roles
        $this->notificationService->notifyItRolesAboutNewConcern(
            $itConcern->station_number,
            $itConcern->site->name,
            $itConcern->user->name,
            $itConcern->category,
            $itConcern->priority,
            $itConcern->description,
            $itConcern->id
        );

        return redirect()->route('it-concerns.index')
            ->with('flash', ['message' => 'IT concern submitted successfully', 'type' => 'success']);
    }

    /**
     * Display the specified IT concern.
     */
    public function show(ItConcern $itConcern)
    {
        $this->authorize('view', $itConcern);

        $itConcern->load(['user', 'site', 'resolvedBy']);

        return Inertia::render('FormRequest/ItConcerns/Show', [
            'concern' => $itConcern,
        ]);
    }

    /**
     * Show the form for editing the specified IT concern.
     */
    public function edit(ItConcern $itConcern)
    {
        $itConcern->load(['user', 'resolvedBy']);
        $sites = Site::orderBy('name')->get();

        return Inertia::render('FormRequest/ItConcerns/Edit', [
            'concern' => $itConcern,
            'sites' => $sites,
        ]);
    }

    /**
     * Update the specified IT concern.
     */
    public function update(ItConcernRequest $request, ItConcern $itConcern)
    {
        $validated = $request->validated();
        $oldStatus = $itConcern->status;

        // If status is being changed to resolved, set resolved_at timestamp and resolved_by
        if (isset($validated['status']) && $validated['status'] === 'resolved') {
            // Only set resolved_at if not already resolved
            if ($itConcern->status !== 'resolved') {
                $validated['resolved_at'] = now();
            }
            // Auto-fill resolved_by if user is IT role and not already set
            if (auth()->user()->role === 'IT' || auth()->user()->role === 'Super Admin' && !$itConcern->resolved_by) {
                $validated['resolved_by'] = auth()->id();
            }
        }

        $itConcern->update($validated);

        $user = auth()->user();

        // 1. If Agent updates, notify IT roles
        if ($user->role === 'Agent' || $user->role === 'Admin') {
            $itConcern->load(['site']);
            $this->notificationService->notifyItRolesAboutConcernUpdate(
                $itConcern->station_number,
                $itConcern->site->name,
                $user->name,
                $itConcern->id
            );
        }

        // 2. If IT updates status, notify Agent
        if (($user->role === 'IT' || $user->role === 'Super Admin') && isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $this->notificationService->notifyItConcernStatusChange(
                $itConcern->user_id,
                $validated['status'],
                $itConcern->station_number,
                $itConcern->id
            );
        }

        return redirect()->route('it-concerns.index')
            ->with('flash', ['message' => 'IT concern updated successfully', 'type' => 'success']);
    }

    /**
     * Remove the specified IT concern.
     */
    public function destroy(ItConcern $itConcern)
    {
        $user = auth()->user();

        // Capture details before deletion for notification
        $itConcern->load(['site']);
        $stationNumber = $itConcern->station_number;
        $siteName = $itConcern->site->name;
        $agentName = $user->name;

        $itConcern->delete();

        return redirect()->route('it-concerns.index')
            ->with('flash', ['message' => 'IT concern deleted successfully', 'type' => 'success']);
    }

    /**
     * Update the status of an IT concern.
     */
    public function updateStatus(Request $request, ItConcern $itConcern)
    {
        $request->validate([
            'status' => 'required|in:pending,in_progress,resolved,cancelled',
        ]);

        $oldStatus = $itConcern->status;
        $data = ['status' => $request->status];

        if ($request->status === 'resolved') {
            $data['resolved_at'] = now();
        }

        $itConcern->update($data);

        // Notify Agent if status changed
        if ($request->status !== $oldStatus) {
            $this->notificationService->notifyItConcernStatusChange(
                $itConcern->user_id,
                $request->status,
                $itConcern->station_number,
                $itConcern->id
            );
        }

        return redirect()->back()
            ->with('flash', ['message' => 'Status updated successfully', 'type' => 'success']);
    }

    /**
     * Add resolution notes to an IT concern.
     */
    public function resolve(Request $request, ItConcern $itConcern)
    {
        $request->validate([
            'resolution_notes' => 'required|string|max:1000',
            'status' => 'nullable|in:pending,in_progress,resolved,cancelled',
            'priority' => 'nullable|in:low,medium,high,urgent',
        ]);

        $oldStatus = $itConcern->status;

        $data = [
            'resolution_notes' => $request->resolution_notes,
        ];

        // Update status if provided, default to 'resolved'
        $data['status'] = $request->input('status', 'resolved');

        // Update priority if provided
        if ($request->has('priority')) {
            $data['priority'] = $request->priority;
        }

        // Set resolved_at timestamp if status is resolved
        if ($data['status'] === 'resolved' && $itConcern->status !== 'resolved') {
            $data['resolved_at'] = now();
            // Auto-fill resolved_by if user is IT role and not already set
            if ((auth()->user()->role === 'IT' || auth()->user()->role === 'Super Admin') && !$itConcern->resolved_by) {
                $data['resolved_by'] = auth()->id();
            }
        }

        $itConcern->update($data);

        // Notify Agent if status changed
        if ($itConcern->status !== $oldStatus) {
            $this->notificationService->notifyItConcernStatusChange(
                $itConcern->user_id,
                $itConcern->status,
                $itConcern->station_number,
                $itConcern->id
            );
        }

        return redirect()->back()
            ->with('flash', ['message' => 'IT concern updated successfully', 'type' => 'success']);
    }

    /**
     * Cancel an IT concern (for agents to cancel their own requests).
     */
    public function cancel(ItConcern $itConcern)
    {
        $user = auth()->user();

        // Only allow owner to cancel their own pending/in_progress concerns
        if ($itConcern->user_id !== $user->id) {
            abort(403, 'You can only cancel your own IT concerns.');
        }

        if (!in_array($itConcern->status, ['pending', 'in_progress'])) {
            return redirect()->back()
                ->with('flash', ['message' => 'Only pending or in-progress concerns can be cancelled.', 'type' => 'error']);
        }

        $itConcern->update(['status' => 'cancelled']);

        // Notify IT roles about the cancellation
        $itConcern->load(['site']);
        $this->notificationService->notifyItRolesAboutConcernCancellation(
            $itConcern->station_number,
            $itConcern->site->name,
            $user->name,
            $itConcern->id
        );

        return redirect()->back()
            ->with('flash', ['message' => 'IT concern cancelled successfully', 'type' => 'success']);
    }
}
