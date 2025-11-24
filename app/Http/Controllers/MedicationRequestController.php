<?php

namespace App\Http\Controllers;

use App\Http\Requests\MedicationRequestRequest;
use App\Models\MedicationRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MedicationRequestController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the medication requests.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MedicationRequest::class);

        $user = auth()->user();
        $canViewAll = in_array($user->role, ['Super Admin', 'Admin', 'Team Lead']);

        $query = MedicationRequest::with(['user', 'approvedBy'])
            ->when(!$canViewAll, function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($request->medication_type, function ($query, $medicationType) {
                $query->where('medication_type', $medicationType);
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->latest();

        return Inertia::render('FormRequest/MedicationRequests/Index', [
            'medicationRequests' => $query->paginate(15)->withQueryString(),
            'filters' => $request->only(['search', 'status', 'medication_type']),
            'medicationTypes' => [
                'Declogen',
                'Biogesic',
                'Mefenamic Acid',
                'Kremil-S',
                'Cetirizine',
                'Saridon',
                'Diatabs',
            ],
            'users' => \App\Models\User::select('id', 'first_name', 'middle_name', 'last_name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(fn($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                ]),
        ]);
    }

    /**
     * Show the form for creating a new medication request.
     */
    public function create(): Response
    {
        $this->authorize('create', MedicationRequest::class);

        $canRequestForOthers = in_array(auth()->user()->role, ['Super Admin', 'Admin', 'Team Lead', 'HR']);

        return Inertia::render('FormRequest/MedicationRequests/Create', [
            'medicationTypes' => [
                'Declogen',
                'Biogesic',
                'Mefenamic Acid',
                'Kremil-S',
                'Cetirizine',
                'Saridon',
                'Diatabs',
            ],
            'onsetOptions' => [
                'Just today',
                'More than 1 day',
                'More than 1 week',
            ],
            'canRequestForOthers' => $canRequestForOthers,
            'users' => $canRequestForOthers
                ? \App\Models\User::select('id', 'first_name', 'middle_name', 'last_name')
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get()
                    ->map(fn($user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ])
                : null,
        ]);
    }

    /**
     * Check if a user has a pending medication request.
     */
    public function checkPendingRequest($userId)
    {
        $this->authorize('create', MedicationRequest::class);

        $hasPendingRequest = MedicationRequest::where('user_id', $userId)
            ->where('status', 'pending')
            ->exists();

        return response()->json([
            'hasPendingRequest' => $hasPendingRequest,
        ]);
    }

    /**
     * Store a newly created medication request in storage.
     */
    public function store(MedicationRequestRequest $request)
    {
        $this->authorize('create', MedicationRequest::class);

        $userId = $request->validated()['requested_for_user_id'] ?? auth()->id();
        $user = \App\Models\User::findOrFail($userId);

        // Check if user has a pending request
        $hasPendingRequest = MedicationRequest::where('user_id', $userId)
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingRequest) {
            $errorMessage = $userId === auth()->id()
                ? 'You already have a pending medication request. Please wait for it to be processed before submitting a new one.'
                : $user->name . ' already has a pending medication request. Please wait for it to be processed before submitting a new one.';

            return back()->withErrors([
                'message' => $errorMessage
            ]);
        }

        $medicationRequest = MedicationRequest::create([
            ...$request->validated(),
            'user_id' => $userId,
            'name' => $user->name,
        ]);

        return redirect()->route('medication-requests.index')
            ->with('success', 'Medication request submitted successfully.');
    }

    /**
     * Display the specified medication request.
     */
    public function show(MedicationRequest $medicationRequest): Response
    {
        $this->authorize('view', $medicationRequest);

        $medicationRequest->load(['user', 'approvedBy']);

        return Inertia::render('FormRequest/MedicationRequests/Show', [
            'medicationRequest' => $medicationRequest,
        ]);
    }

    /**
     * Update the status of the medication request.
     */
    public function updateStatus(Request $request, MedicationRequest $medicationRequest)
    {
        $this->authorize('update', $medicationRequest);

        $validated = $request->validate([
            'status' => ['required', 'in:approved,dispensed,rejected'],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $medicationRequest->update([
            ...$validated,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Medication request status updated successfully.');
    }

    /**
     * Cancel a pending medication request (user can cancel their own).
     */
    public function cancel(MedicationRequest $medicationRequest)
    {
        // User can only cancel their own pending requests
        if ($medicationRequest->user_id !== auth()->id()) {
            abort(403, 'You can only cancel your own requests.');
        }

        if ($medicationRequest->status !== 'pending') {
            return back()->withErrors([
                'message' => 'Only pending requests can be cancelled.'
            ]);
        }

        $medicationRequest->delete();

        return redirect()->route('medication-requests.index')
            ->with('success', 'Medication request cancelled successfully.');
    }

    /**
     * Remove the specified medication request from storage.
     */
    public function destroy(MedicationRequest $medicationRequest)
    {
        $this->authorize('delete', $medicationRequest);

        $medicationRequest->delete();

        return redirect()->route('medication-requests.index')
            ->with('success', 'Medication request deleted successfully.');
    }
}
