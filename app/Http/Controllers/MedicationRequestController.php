<?php

namespace App\Http\Controllers;

use App\Http\Requests\MedicationRequestRequest;
use App\Models\MedicationRequest;
use App\Models\User;
use App\Services\NotificationService;
use App\Mail\MedicationRequestSubmitted;
use App\Mail\MedicationRequestStatusUpdated;
use Illuminate\Support\Facades\Mail;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MedicationRequestController extends Controller
{
    use AuthorizesRequests;

    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Display a listing of the medication requests.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MedicationRequest::class);

        $query = MedicationRequest::with(['user.activeSchedule.campaign', 'user.activeSchedule.site', 'approvedBy'])
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('medication_type', 'like', "%{$search}%");
                });
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->latest();

        return Inertia::render('FormRequest/MedicationRequests/Index', [
            'medicationRequests' => $query->paginate(15)->withQueryString(),
            'filters' => $request->only(['search', 'status']),
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
                ? User::select('id', 'first_name', 'middle_name', 'last_name', 'email')
                    ->where('is_active', true)
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get()
                : null,
        ]);
    }

    /**
     * Store a newly created medication request in storage.
     */
    public function store(MedicationRequestRequest $request)
    {
        $this->authorize('create', MedicationRequest::class);

        $userId = $request->validated()['requested_for_user_id'] ?? auth()->id();
        $user = User::findOrFail($userId);

        $medicationRequest = MedicationRequest::create([
            ...$request->validated(),
            'user_id' => $userId,
            'name' => $user->name,
        ]);

        // Notify HR/Admin
        $this->notificationService->notifyHrRolesAboutNewMedicationRequest(
            $user->name,
            $medicationRequest->medication_type,
            $medicationRequest->id
        );

        // Send confirmation email to the employee
        if ($user->email && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            Mail::to($user->email)->send(new MedicationRequestSubmitted($medicationRequest, $user));
        }

        return redirect()->route('medication-requests.index')
            ->with('success', 'Medication request submitted successfully.');
    }

    /**
     * Display the specified medication request.
     */
    public function show(MedicationRequest $medicationRequest): Response
    {
        $this->authorize('view', $medicationRequest);

        $medicationRequest->load(['user.activeSchedule.campaign', 'user.activeSchedule.site', 'approvedBy']);

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

        // Notify the employee about status change
        $this->notificationService->notifyMedicationRequestStatusChange(
            $medicationRequest->user_id,
            $medicationRequest->status,
            $medicationRequest->medication_type,
            $medicationRequest->id
        );

        // Send Email Notification
        $employee = $medicationRequest->user;
        if ($employee && $employee->email && filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
            Mail::to($employee->email)->send(new MedicationRequestStatusUpdated($medicationRequest, $employee));
        }

        return back()->with('success', 'Medication request status updated successfully.');
    }

    /**
     * Remove the specified medication request from storage.
     */
    public function destroy(MedicationRequest $medicationRequest)
    {
        $this->authorize('delete', $medicationRequest);

        // Capture details before deletion
        $requesterName = $medicationRequest->name;
        $medicationType = $medicationRequest->medication_type;

        $medicationRequest->delete();

        // Notify HR/Admin about cancellation
        $this->notificationService->notifyHrRolesAboutMedicationRequestCancellation(
            $requesterName,
            $medicationType
        );

        return redirect()->route('medication-requests.index')
            ->with('success', 'Medication request deleted successfully.');
    }
}
