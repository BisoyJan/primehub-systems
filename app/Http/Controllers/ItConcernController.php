<?php

namespace App\Http\Controllers;

use App\Models\ItConcern;
use App\Models\Site;
use App\Models\User;
use App\Http\Requests\ItConcernRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ItConcernController extends Controller
{
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

        // Sort by priority (urgent first) and creation date
        $concerns = $query->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
            ->orderBy('created_at', 'desc')
            ->paginate(25)
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

        return Inertia::render('FormRequest/ItConcerns/Create', [
            'sites' => $sites,
        ]);
    }

    /**
     * Store a newly created IT concern.
     */
    public function store(ItConcernRequest $request)
    {
        $this->authorize('create', ItConcern::class);

        $validated = $request->validated();
        $validated['user_id'] = auth()->id();

        ItConcern::create($validated);

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

        return redirect()->route('it-concerns.index')
            ->with('flash', ['message' => 'IT concern updated successfully', 'type' => 'success']);
    }

    /**
     * Remove the specified IT concern.
     */
    public function destroy(ItConcern $itConcern)
    {
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

        $data = ['status' => $request->status];

        if ($request->status === 'resolved') {
            $data['resolved_at'] = now();
        }

        $itConcern->update($data);

        return redirect()->back()
            ->with('flash', ['message' => 'Status updated successfully', 'type' => 'success']);
    }

    /**
     * Assign an IT concern to a user.
     */
    public function assign(Request $request, ItConcern $itConcern)
    {
        $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        $itConcern->update([
            'assigned_to' => $request->assigned_to,
            'status' => 'in_progress',
        ]);

        return redirect()->back()
            ->with('flash', ['message' => 'IT concern assigned successfully', 'type' => 'success']);
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

        return redirect()->back()
            ->with('flash', ['message' => 'IT concern updated successfully', 'type' => 'success']);
    }
}
