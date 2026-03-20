<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcknowledgeCoachingSessionRequest;
use App\Http\Requests\ReviewCoachingSessionRequest;
use App\Http\Requests\StoreCoachingSessionRequest;
use App\Http\Requests\UpdateCoachingSessionRequest;
use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\CoachingSessionAttachment;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\CoachingDashboardService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CoachingSessionController extends Controller
{
    use RedirectsWithFlashMessages;

    public function __construct(
        protected NotificationService $notificationService,
        protected CoachingDashboardService $dashboardService,
    ) {}

    /**
     * Display a listing of coaching sessions.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', CoachingSession::class);

        $user = auth()->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin', 'HR']);
        $isTeamLead = $user->role === 'Team Lead';
        $isAgent = $user->role === 'Agent';

        // Detect Team Lead's campaign for auto-filter
        $teamLeadCampaignId = null;
        if ($isTeamLead) {
            $teamLeadCampaignId = $user->activeSchedule?->campaign_id;
        }

        $query = CoachingSession::with(['coachee', 'coach', 'complianceReviewer']);

        // Role-based filtering
        if ($isAgent) {
            $query->forCoachee($user->id);
        } elseif ($isTeamLead) {
            // TL sees sessions they coached + sessions for agents in their campaign + sessions where TL is coachee
            if ($teamLeadCampaignId) {
                $query->where(function ($q) use ($user, $teamLeadCampaignId) {
                    $q->where('coach_id', $user->id)
                        ->orWhere('coachee_id', $user->id)
                        ->orWhereHas('coachee.activeSchedule', function ($sq) use ($teamLeadCampaignId) {
                            $sq->where('campaign_id', $teamLeadCampaignId);
                        });
                });
            } else {
                $query->where(function ($q) use ($user) {
                    $q->where('coach_id', $user->id)
                        ->orWhere('coachee_id', $user->id);
                });
            }
        }
        // Admin/HR see all — no filter

        // Search filter
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Acknowledgement status filter
        if ($request->filled('ack_status')) {
            $query->where('ack_status', $request->ack_status);
        }

        // Compliance status filter
        if ($request->filled('compliance_status')) {
            $query->where('compliance_status', $request->compliance_status);
        }

        // Purpose filter
        if ($request->filled('purpose')) {
            $query->where('purpose', $request->purpose);
        }

        // Campaign filter (admin/HR)
        if ($request->filled('campaign_id')) {
            $query->forCampaign($request->campaign_id);
        }

        // Coachee role filter (admin only)
        if ($request->filled('coachee_role') && $isAdmin) {
            $query->whereHas('coachee', function ($q) use ($request) {
                $q->where('role', $request->coachee_role);
            });
        }

        // Date range filter
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->dateRange($request->date_from, $request->date_to);
        }

        $sessions = $query->orderByDesc('session_date')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        // Get campaigns for filter dropdown
        $campaigns = Campaign::orderBy('name')->get(['id', 'name']);

        // Get all agents for the search combobox (for admin/TL)
        $allAgents = collect();
        if ($isAdmin) {
            $allAgents = User::whereIn('role', ['Agent', 'Team Lead'])
                ->where('is_approved', true)
                ->where('is_active', true)
                ->with('activeSchedule.campaign:id,name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'role']);
        } elseif ($isTeamLead && $teamLeadCampaignId) {
            $agentIds = EmployeeSchedule::where('campaign_id', $teamLeadCampaignId)
                ->where('is_active', true)
                ->whereHas('user', function ($q) {
                    $q->where('role', 'Agent')
                        ->where('is_approved', true)
                        ->where('is_active', true);
                })
                ->pluck('user_id')
                ->unique();

            $allAgents = User::whereIn('id', $agentIds)
                ->with('activeSchedule.campaign:id,name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name']);
        }

        // Coachee summary (for agent or TL-as-coachee view)
        $agentSummary = null;
        if ($isAgent || $isTeamLead) {
            $agentSummary = $this->dashboardService->getCoacheeSummary($user->id);
        }

        return Inertia::render('Coaching/Sessions/Index', [
            'sessions' => $sessions,
            'agentSummary' => $agentSummary,
            'campaigns' => $campaigns,
            'allAgents' => $allAgents,
            'filters' => $request->only([
                'search', 'ack_status', 'compliance_status', 'purpose',
                'campaign_id', 'date_from', 'date_to', 'coachee_role',
            ]),
            'isAdmin' => $isAdmin,
            'isTeamLead' => $isTeamLead,
            'isAgent' => $isAgent,
            'teamLeadCampaignId' => $teamLeadCampaignId,
        ]);
    }

    /**
     * Show the form for creating a new coaching session.
     */
    public function create(Request $request)
    {
        $this->authorize('create', CoachingSession::class);

        $user = auth()->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);

        $agents = collect();
        $teamLeads = collect();

        // Coaching mode from query param (for admin)
        $coachingMode = $request->input('coaching_mode', 'assign');

        if ($isAdmin) {
            // Admins: fetch all agents (frontend filters by selected TL's campaign)
            $agents = User::where('role', 'Agent')
                ->where('is_approved', true)
                ->where('is_active', true)
                ->with('activeSchedule.campaign:id,name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name']);

            // Fetch team leads who have an active campaign schedule
            $teamLeads = User::where('role', 'Team Lead')
                ->where('is_approved', true)
                ->where('is_active', true)
                ->whereHas('activeSchedule', fn ($q) => $q->whereNotNull('campaign_id'))
                ->with('activeSchedule.campaign:id,name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name']);

            // Coachable team leads (for direct coaching mode)
            $coachableTeamLeads = $teamLeads;
        } else {
            // Team Lead: agents in their campaign only
            $campaignId = $user->activeSchedule?->campaign_id;
            if ($campaignId) {
                $agentIds = EmployeeSchedule::where('campaign_id', $campaignId)
                    ->where('is_active', true)
                    ->whereHas('user', function ($q) {
                        $q->where('role', 'Agent')
                            ->where('is_approved', true)
                            ->where('is_active', true);
                    })
                    ->pluck('user_id')
                    ->unique();

                $agents = User::whereIn('id', $agentIds)
                    ->with('activeSchedule.campaign:id,name')
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get(['id', 'first_name', 'middle_name', 'last_name']);
            }
        }

        // Get campaigns for reference
        $campaigns = Campaign::orderBy('name')->get(['id', 'name']);

        // Pre-select agent if provided via query param
        $selectedAgentId = $request->input('agent_id');

        return Inertia::render('Coaching/Sessions/Create', [
            'agents' => $agents,
            'teamLeads' => $teamLeads,
            'coachableTeamLeads' => $isAdmin ? ($coachableTeamLeads ?? collect()) : collect(),
            'campaigns' => $campaigns,
            'isAdmin' => $isAdmin,
            'coachingMode' => $coachingMode,
            'selectedAgentId' => $selectedAgentId ? (int) $selectedAgentId : null,
            'purposes' => CoachingSession::PURPOSE_LABELS,
            'severityFlags' => CoachingSession::SEVERITY_FLAGS,
        ]);
    }

    /**
     * Store a newly created coaching session.
     */
    public function store(StoreCoachingSessionRequest $request)
    {
        $this->authorize('create', CoachingSession::class);

        try {
            $session = DB::transaction(function () use ($request) {
                $validated = $request->validated();

                $user = auth()->user();
                $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
                $coachingMode = $validated['coaching_mode'] ?? 'assign';

                if ($coachingMode === 'direct' && $isAdmin) {
                    // Admin directly coaches a TL
                    $validated['coach_id'] = $user->id;
                } else {
                    // Admin assigns TL→Agent, or TL creates their own
                    $validated['coach_id'] = $isAdmin
                        ? $validated['coach_id']
                        : $user->id;
                }

                // Remove non-model fields from validated data before creating
                unset($validated['coaching_mode'], $validated['attachments']);

                $validated['ack_status'] = 'Pending';
                $validated['compliance_status'] = 'Awaiting_Agent_Ack';

                return CoachingSession::create($validated);
            });

            // Handle file uploads outside transaction
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $filename = 'coaching_'.$session->id.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                    $path = $file->storeAs('coaching_attachments', $filename, 'local');

                    $session->attachments()->create([
                        'file_path' => $path,
                        'original_filename' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            // Notify the coachee
            $session->load('coachee', 'coach');
            $this->notificationService->notifyCoachingSessionCreated(
                $session->coachee_id,
                $session->coach->name,
                $session->session_date->format('Y-m-d'),
                $session->id,
            );

            return $this->redirectWithFlash(
                'coaching.sessions.index',
                'Coaching session created successfully.',
            );
        } catch (\Exception $e) {
            Log::error('CoachingSessionController@store Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to create coaching session.', 'error');
        }
    }

    /**
     * Display the specified coaching session.
     */
    public function show(CoachingSession $session)
    {
        $this->authorize('view', $session);

        $session->load(['coachee', 'coach', 'complianceReviewer', 'attachments']);

        $user = auth()->user();
        $canAcknowledge = $user->can('acknowledge', $session);
        $canReview = $user->can('review', $session);
        $canEdit = $user->can('update', $session);

        return Inertia::render('Coaching/Sessions/Show', [
            'session' => $session,
            'canAcknowledge' => $canAcknowledge,
            'canReview' => $canReview,
            'canEdit' => $canEdit,
            'purposes' => CoachingSession::PURPOSE_LABELS,
        ]);
    }

    /**
     * Show the form for editing the specified coaching session.
     */
    public function edit(CoachingSession $session)
    {
        $this->authorize('update', $session);

        $session->load(['coachee', 'coach', 'attachments']);

        return Inertia::render('Coaching/Sessions/Edit', [
            'session' => $session,
            'purposes' => CoachingSession::PURPOSE_LABELS,
            'severityFlags' => CoachingSession::SEVERITY_FLAGS,
        ]);
    }

    /**
     * Update the specified coaching session.
     */
    public function update(UpdateCoachingSessionRequest $request, CoachingSession $session)
    {
        $this->authorize('update', $session);

        try {
            DB::transaction(function () use ($request, $session) {
                $validated = $request->validated();
                unset($validated['attachments'], $validated['removed_attachments']);
                $session->update($validated);
            });

            // Handle removed attachments
            if ($request->filled('removed_attachments')) {
                $attachmentsToRemove = $session->attachments()
                    ->whereIn('id', $request->input('removed_attachments'))
                    ->get();

                foreach ($attachmentsToRemove as $attachment) {
                    Storage::disk('local')->delete($attachment->file_path);
                    $attachment->delete();
                }
            }

            // Handle new file uploads
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $filename = 'coaching_'.$session->id.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                    $path = $file->storeAs('coaching_attachments', $filename, 'local');

                    $session->attachments()->create([
                        'file_path' => $path,
                        'original_filename' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            return redirect()->route('coaching.sessions.show', $session)
                ->with('message', 'Coaching session updated successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('CoachingSessionController@update Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to update coaching session.', 'error');
        }
    }

    /**
     * Remove the specified coaching session.
     */
    public function destroy(CoachingSession $session)
    {
        $this->authorize('delete', $session);

        try {
            // Delete attachment files from storage
            foreach ($session->attachments as $attachment) {
                Storage::disk('local')->delete($attachment->file_path);
            }

            DB::transaction(function () use ($session) {
                $session->delete();
            });

            return $this->redirectWithFlash(
                'coaching.sessions.index',
                'Coaching session deleted successfully.',
            );
        } catch (\Exception $e) {
            Log::error('CoachingSessionController@destroy Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to delete coaching session.', 'error');
        }
    }

    /**
     * View a coaching session attachment image.
     */
    public function viewAttachment(CoachingSession $session, CoachingSessionAttachment $attachment)
    {
        $this->authorize('view', $session);

        if ($attachment->coaching_session_id !== $session->id) {
            abort(404);
        }

        $path = Storage::disk('local')->path($attachment->file_path);

        if (! file_exists($path)) {
            abort(404, 'Attachment not found.');
        }

        return response()->file($path);
    }

    /**
     * Agent acknowledges a coaching session.
     */
    public function acknowledge(AcknowledgeCoachingSessionRequest $request, CoachingSession $session)
    {
        $this->authorize('acknowledge', $session);

        try {
            DB::transaction(function () use ($request, $session) {
                $session->update([
                    'ack_status' => 'Acknowledged',
                    'ack_timestamp' => Carbon::now(),
                    'ack_comment' => $request->validated('ack_comment'),
                    'compliance_status' => 'For_Review',
                ]);
            });

            // Notify the coach
            $session->load('coachee', 'coach');
            $sessionDate = $session->session_date->format('Y-m-d');

            $this->notificationService->notifyCoachingAcknowledged(
                $session->coach_id,
                $session->coachee->name,
                $sessionDate,
                $session->id,
            );

            // Notify admins that this session is ready for review
            $this->notificationService->notifyAdminsCoachingReadyForReview(
                $session->coachee->name,
                $session->coach->name,
                $sessionDate,
                $session->id,
            );

            return redirect()->route('coaching.sessions.show', $session)
                ->with('message', 'Coaching session acknowledged successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('CoachingSessionController@acknowledge Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to acknowledge coaching session.', 'error');
        }
    }

    /**
     * Compliance reviews (verifies/rejects) a coaching session.
     */
    public function review(ReviewCoachingSessionRequest $request, CoachingSession $session)
    {
        $this->authorize('review', $session);

        try {
            DB::transaction(function () use ($request, $session) {
                $validated = $request->validated();

                $session->update([
                    'compliance_status' => $validated['compliance_status'],
                    'compliance_reviewer_id' => auth()->id(),
                    'compliance_review_timestamp' => Carbon::now(),
                    'compliance_notes' => $validated['compliance_notes'] ?? null,
                ]);
            });

            // Notify the coach (if not an admin) and coachee
            $session->load('coachee', 'coach');
            $sessionDate = $session->session_date->format('Y-m-d');

            // Only notify the coach if they are not an admin role (admins can see review status directly)
            if (! in_array($session->coach->role, ['Super Admin', 'Admin', 'HR'])) {
                $this->notificationService->notifyCoachingReviewed(
                    $session->coach_id,
                    $session->coachee->name,
                    $sessionDate,
                    $session->compliance_status,
                    $session->id,
                );
            }

            $this->notificationService->notifyCoacheeCoachingReviewed(
                $session->coachee_id,
                $session->coach->name,
                $sessionDate,
                $session->compliance_status,
                $session->id,
            );

            return $this->redirectWithFlash(
                'coaching.sessions.index',
                "Coaching session marked as {$session->compliance_status}.",
            );
        } catch (\Exception $e) {
            Log::error('CoachingSessionController@review Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to review coaching session.', 'error');
        }
    }
}
