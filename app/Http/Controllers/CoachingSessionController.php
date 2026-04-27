<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcknowledgeCoachingSessionRequest;
use App\Http\Requests\ReviewCoachingSessionRequest;
use App\Http\Requests\StoreCoachingSessionRequest;
use App\Http\Requests\StoreDraftCoachingSessionRequest;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        // Detect Team Lead's campaigns for auto-filter
        $teamLeadCampaignIds = [];
        if ($isTeamLead) {
            $teamLeadCampaignIds = $user->getCampaignIds();
        }

        $query = CoachingSession::with(['coachee', 'coach', 'complianceReviewer']);

        // Handle drafts tab — coaches see their own drafts
        $showDrafts = $request->input('tab') === 'drafts';
        if ($showDrafts) {
            $query->draft()->where('coach_id', $user->id);
        } else {
            // Exclude drafts from all non-draft views
            $query->submitted();
        }

        // Role-based filtering (only when not in drafts tab)
        if ($isAgent && ! $showDrafts) {
            $query->forCoachee($user->id);
        } elseif ($isTeamLead && ! $showDrafts) {
            $activeTab = $request->input('tab', 'team');

            if ($activeTab === 'my') {
                // "My Sessions" tab — only sessions where TL is the coachee
                $query->forCoachee($user->id);
            } else {
                // "Team Sessions" tab — sessions TL coached or for agents in their campaigns (excluding TL's own)
                if (! empty($teamLeadCampaignIds)) {
                    $query->where('coachee_id', '!=', $user->id)
                        ->where(function ($q) use ($user, $teamLeadCampaignIds) {
                            $q->where('coach_id', $user->id)
                                ->orWhereHas('coachee.activeSchedule', function ($sq) use ($teamLeadCampaignIds) {
                                    $sq->whereIn('campaign_id', $teamLeadCampaignIds);
                                });
                        });
                } else {
                    $query->where('coach_id', $user->id);
                }
            }
        } elseif ($isAdmin && ! $showDrafts) {
            $activeTab = $request->input('tab', 'all');

            if ($activeTab === 'needs_review') {
                $query->where('compliance_status', 'For_Review');
            }
        }

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

        // Campaign filter (admin/HR) — accepts CSV string, scalar, or array.
        if ($request->filled('campaign_id')) {
            $raw = $request->input('campaign_id');
            if (is_array($raw)) {
                $ids = array_map('intval', $raw);
            } elseif (is_string($raw) && str_contains($raw, ',')) {
                $ids = array_map('intval', explode(',', $raw));
            } else {
                $ids = [(int) $raw];
            }
            $ids = array_values(array_filter($ids, fn ($id) => $id > 0));
            if (! empty($ids)) {
                $query->forCampaign($ids);
            }
        }

        // Coachee role filter (admin only)
        if ($request->filled('coachee_role') && $isAdmin) {
            $query->whereHas('coachee', function ($q) use ($request) {
                $q->where('role', $request->coachee_role);
            });
        }

        // Team lead (coach) filter (admin only)
        if ($request->filled('team_lead_id') && $isAdmin) {
            $query->where('coach_id', $request->team_lead_id);
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
        $campaigns = ! empty($teamLeadCampaignIds)
            ? Campaign::whereIn('id', $teamLeadCampaignIds)->orderBy('name')->get(['id', 'name'])
            : Campaign::orderBy('name')->get(['id', 'name']);

        // Get team leads for filter dropdown (admin only)
        $teamLeads = collect();
        if ($isAdmin) {
            $teamLeads = User::where('role', 'Team Lead')
                ->where('is_approved', true)
                ->where('is_active', true)
                ->with('campaigns:id')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name'])
                ->map(function ($tl) {
                    return [
                        'id' => $tl->id,
                        'first_name' => $tl->first_name,
                        'last_name' => $tl->last_name,
                        'campaign_ids' => $tl->campaigns->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    ];
                });
        }

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
        } elseif ($isTeamLead && ! empty($teamLeadCampaignIds)) {
            $agentIds = EmployeeSchedule::whereIn('campaign_id', $teamLeadCampaignIds)
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

        // Tab badge counts
        $pendingAckCount = null;
        $pendingReviewCount = null;
        $draftCount = null;
        $activeTabValue = null;

        if ($isTeamLead) {
            $pendingAckCount = CoachingSession::submitted()->forCoachee($user->id)->where('ack_status', 'Pending')->count();
            $draftCount = CoachingSession::draft()->where('coach_id', $user->id)->count();
            $activeTabValue = $request->input('tab', 'team');
        } elseif ($isAdmin) {
            $pendingReviewCount = CoachingSession::submitted()->where('compliance_status', 'For_Review')->count();
            $draftCount = CoachingSession::draft()->where('coach_id', $user->id)->count();
            $activeTabValue = $request->input('tab', 'all');
        }

        return Inertia::render('Coaching/Sessions/Index', [
            'sessions' => $sessions,
            'agentSummary' => $agentSummary,
            'campaigns' => $campaigns,
            'allAgents' => $allAgents,
            'teamLeads' => $teamLeads,
            'filters' => $request->only([
                'search', 'ack_status', 'compliance_status', 'purpose',
                'campaign_id', 'date_from', 'date_to', 'coachee_role', 'team_lead_id',
            ]),
            'isAdmin' => $isAdmin,
            'isTeamLead' => $isTeamLead,
            'isAgent' => $isAgent,
            'teamLeadCampaignIds' => $teamLeadCampaignIds,
            'activeTab' => $activeTabValue,
            'pendingAckCount' => $pendingAckCount,
            'pendingReviewCount' => $pendingReviewCount,
            'draftCount' => $draftCount,
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
            // Team Lead: agents in their campaigns only
            $campaignIds = $user->getCampaignIds();
            if (! empty($campaignIds)) {
                $agentIds = EmployeeSchedule::whereIn('campaign_id', $campaignIds)
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
        $selectedAgentId = $request->input('agent_id') ?? $request->input('coachee_id');

        // Week boundaries used for draft lookups and coached-this-week checks
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        // Load existing draft for the selected agent this week
        $existingDraft = null;
        if ($selectedAgentId) {
            $draft = CoachingSession::where('coachee_id', $selectedAgentId)
                ->where('coach_id', $user->id)
                ->where('is_draft', true)
                ->whereBetween('session_date', [$startOfWeek, $endOfWeek])
                ->first();

            if ($draft) {
                $existingDraft = $draft->only([
                    'id', 'coachee_id', 'session_date', 'purpose', 'severity_flag',
                    'profile_new_hire', 'profile_tenured', 'profile_returning', 'profile_previously_coached_same_issue',
                    'focus_attendance_tardiness', 'focus_productivity', 'focus_compliance', 'focus_callouts',
                    'focus_recognition_milestones', 'focus_growth_development', 'focus_other', 'focus_other_notes',
                    'root_cause_lack_of_skills', 'root_cause_lack_of_clarity', 'root_cause_personal_issues',
                    'root_cause_motivation_engagement', 'root_cause_health_fatigue', 'root_cause_workload_process',
                    'root_cause_peer_conflict', 'root_cause_others', 'root_cause_others_notes',
                    'performance_description', 'agent_strengths_wins', 'smart_action_plan', 'follow_up_date',
                ]);
            }
        }

        // Handle clone_from
        $cloneData = null;
        if ($request->has('clone_from')) {
            $sourceSession = CoachingSession::find($request->clone_from);
            if ($sourceSession && $request->user()->can('view', $sourceSession)) {
                $cloneData = $sourceSession->only([
                    'coachee_id', 'purpose', 'severity_flag',
                    'profile_new_hire', 'profile_tenured', 'profile_returning', 'profile_previously_coached_same_issue',
                    'focus_attendance_tardiness', 'focus_productivity', 'focus_compliance', 'focus_callouts',
                    'focus_recognition_milestones', 'focus_growth_development', 'focus_other', 'focus_other_notes',
                    'root_cause_lack_of_skills', 'root_cause_lack_of_clarity', 'root_cause_personal_issues',
                    'root_cause_motivation_engagement', 'root_cause_health_fatigue', 'root_cause_workload_process',
                    'root_cause_peer_conflict', 'root_cause_others', 'root_cause_others_notes',
                    'performance_description', 'agent_strengths_wins', 'smart_action_plan',
                ]);
            }
        }

        // Get IDs of agents who have already been coached this week
        $coachedThisWeekIds = CoachingSession::whereBetween('session_date', [$startOfWeek, $endOfWeek])
            ->where('is_draft', false)
            ->pluck('coachee_id')
            ->unique()
            ->values()
            ->all();

        $draftedThisWeekIds = CoachingSession::whereBetween('session_date', [$startOfWeek, $endOfWeek])
            ->where('is_draft', true)
            ->pluck('coachee_id')
            ->unique()
            ->values()
            ->all();

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
            'clone_data' => $cloneData,
            'existingDraft' => $existingDraft,
            'coachedThisWeekIds' => $coachedThisWeekIds,
            'draftedThisWeekIds' => $draftedThisWeekIds,
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
                $validated['is_draft'] = false;
                $validated['submitted_at'] = now();

                // Clean up any existing auto-saved draft for the same coachee/coach/session_date.
                // Matching by session_date (instead of current week) ensures drafts created for
                // past weeks are still cleaned up when the TL finally submits.
                $existingDraft = CoachingSession::where('coachee_id', $validated['coachee_id'])
                    ->where('coach_id', $validated['coach_id'])
                    ->where('is_draft', true)
                    ->whereDate('session_date', $validated['session_date'])
                    ->first();

                if ($existingDraft) {
                    // Delete attachments from the draft
                    foreach ($existingDraft->attachments as $attachment) {
                        Storage::disk('local')->delete($attachment->file_path);
                        $attachment->delete();
                    }
                    $existingDraft->delete();
                }

                return CoachingSession::create($validated);
            });

            // Handle file uploads outside transaction
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $filename = 'coaching_'.$session->id.'_'.Str::uuid().'.'.$file->getClientOriginalExtension();
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
     * Save a coaching session as a draft (relaxed validation).
     */
    public function storeDraft(StoreDraftCoachingSessionRequest $request)
    {
        $this->authorize('create', CoachingSession::class);

        try {
            $session = DB::transaction(function () use ($request) {
                $validated = $request->validated();

                $user = auth()->user();
                $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
                $coachingMode = $validated['coaching_mode'] ?? 'assign';

                if ($coachingMode === 'direct' && $isAdmin) {
                    $validated['coach_id'] = $user->id;
                } else {
                    $validated['coach_id'] = $isAdmin
                        ? ($validated['coach_id'] ?? $user->id)
                        : $user->id;
                }

                unset($validated['coaching_mode'], $validated['attachments']);

                // Check if this coachee already has a session this week
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();

                $existingSession = CoachingSession::where('coachee_id', $validated['coachee_id'])
                    ->where('coach_id', $validated['coach_id'])
                    ->whereBetween('session_date', [$startOfWeek, $endOfWeek])
                    ->first();

                if ($existingSession && ! $existingSession->is_draft) {
                    throw new \RuntimeException('ALREADY_SUBMITTED');
                }

                if ($existingSession && $existingSession->is_draft) {
                    // Update the existing draft instead of creating a duplicate
                    unset($validated['coach_id'], $validated['coachee_id']);
                    $existingSession->update($validated);

                    return $existingSession;
                }

                $validated['is_draft'] = true;
                $validated['ack_status'] = 'Pending';
                $validated['compliance_status'] = 'Awaiting_Agent_Ack';

                return CoachingSession::create($validated);
            });

            // Handle file uploads outside transaction
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $filename = 'coaching_'.$session->id.'_'.Str::uuid().'.'.$file->getClientOriginalExtension();
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
                ->with('message', 'Coaching session saved as draft.')
                ->with('type', 'success');
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'ALREADY_SUBMITTED') {
                return $this->backWithFlash('This agent already has a submitted coaching session this week.', 'error');
            }
            throw $e;
        } catch (\Exception $e) {
            Log::error('CoachingSessionController@storeDraft Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to save coaching session draft.', 'error');
        }
    }

    /**
     * Auto-save a coaching session draft (silent JSON response for background saves).
     */
    public function autoSaveDraft(StoreDraftCoachingSessionRequest $request): JsonResponse
    {
        $this->authorize('create', CoachingSession::class);

        try {
            $validated = $request->validated();

            $user = auth()->user();
            $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
            $coachingMode = $validated['coaching_mode'] ?? 'assign';

            if ($coachingMode === 'direct' && $isAdmin) {
                $validated['coach_id'] = $user->id;
            } else {
                $validated['coach_id'] = $isAdmin
                    ? ($validated['coach_id'] ?? $user->id)
                    : $user->id;
            }

            unset($validated['coaching_mode'], $validated['attachments']);

            $draftId = $request->input('draft_id');

            if ($draftId) {
                $session = CoachingSession::findOrFail($draftId);

                if (! $session->is_draft) {
                    return response()->json(['error' => 'Cannot update a submitted session.'], 403);
                }

                if ($session->coach_id !== $user->id && ! $isAdmin) {
                    return response()->json(['error' => 'Unauthorized.'], 403);
                }

                unset($validated['coach_id'], $validated['coachee_id']);

                // Atomic update: only update if still a draft (prevents race with submitDraft)
                $affected = CoachingSession::where('id', $session->id)
                    ->where('is_draft', true)
                    ->update($validated);

                if ($affected === 0) {
                    return response()->json(['error' => 'Session was already submitted.'], 409);
                }

                $session->refresh();
            } else {
                // Check if this coachee already has a session this week
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();

                $existingSession = CoachingSession::where('coachee_id', $validated['coachee_id'])
                    ->where('coach_id', $validated['coach_id'])
                    ->whereBetween('session_date', [$startOfWeek, $endOfWeek])
                    ->first();

                if ($existingSession && ! $existingSession->is_draft) {
                    return response()->json(['error' => 'This agent already has a submitted coaching session this week.'], 422);
                }

                if ($existingSession && $existingSession->is_draft) {
                    // Update the existing draft instead of creating a duplicate
                    $coacheeId = $validated['coachee_id'];
                    $coachId = $validated['coach_id'];
                    unset($validated['coach_id'], $validated['coachee_id']);
                    $existingSession->update($validated);
                    $session = $existingSession;
                } else {
                    $validated['is_draft'] = true;
                    $validated['ack_status'] = 'Pending';
                    $validated['compliance_status'] = 'Awaiting_Agent_Ack';

                    $session = CoachingSession::create($validated);
                }
            }

            return response()->json([
                'draft_id' => $session->id,
                'saved_at' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('CoachingSessionController@autoSaveDraft Error: '.$e->getMessage());

            return response()->json(['error' => 'Failed to auto-save draft.'], 500);
        }
    }

    /**
     * Submit a draft coaching session (applies full validation).
     */
    public function submitDraft(StoreCoachingSessionRequest $request, CoachingSession $session)
    {
        $this->authorize('update', $session);

        if (! $session->is_draft) {
            return $this->backWithFlash('This session has already been submitted.', 'error');
        }

        try {
            DB::transaction(function () use ($request, $session) {
                $validated = $request->validated();

                unset($validated['coaching_mode'], $validated['attachments'], $validated['coach_id'], $validated['coachee_id']);

                $validated['is_draft'] = false;
                $validated['submitted_at'] = now();

                // Atomic update: only submit if still a draft (prevents double-submit race)
                $affected = CoachingSession::where('id', $session->id)
                    ->where('is_draft', true)
                    ->update($validated);

                if ($affected === 0) {
                    throw new \RuntimeException('ALREADY_SUBMITTED');
                }

                $session->refresh();
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
                    $filename = 'coaching_'.$session->id.'_'.Str::uuid().'.'.$file->getClientOriginalExtension();
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

            return redirect()->route('coaching.sessions.show', $session)
                ->with('message', 'Draft submitted successfully.')
                ->with('type', 'success');
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'ALREADY_SUBMITTED') {
                return $this->backWithFlash('This session has already been submitted.', 'error');
            }
            throw $e;
        } catch (\Exception $e) {
            Log::error('CoachingSessionController@submitDraft Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to submit draft.', 'error');
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

        // Load last 5 coaching sessions for the same coachee (excluding current, submitted only)
        $coachingHistory = CoachingSession::where('coachee_id', $session->coachee_id)
            ->where('id', '!=', $session->id)
            ->submitted()
            ->orderByDesc('session_date')
            ->limit(5)
            ->select(['id', 'session_date', 'purpose', 'severity_flag', 'compliance_status', 'ack_status'])
            ->with(['coach:id,first_name,last_name'])
            ->get();

        $canSubmitDraft = $session->is_draft && $user->can('update', $session);

        return Inertia::render('Coaching/Sessions/Show', [
            'session' => $session,
            'coaching_history' => $coachingHistory,
            'canAcknowledge' => $canAcknowledge,
            'canReview' => $canReview,
            'canEdit' => $canEdit,
            'canSubmitDraft' => $canSubmitDraft,
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
                    $filename = 'coaching_'.$session->id.'_'.Str::uuid().'.'.$file->getClientOriginalExtension();
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
            // Collect file paths before deleting DB records
            $filePaths = $session->attachments->pluck('file_path')->toArray();

            DB::transaction(function () use ($session) {
                $session->delete();
            });

            // Clean up files after successful DB deletion
            foreach ($filePaths as $filePath) {
                Storage::disk('local')->delete($filePath);
            }

            return $this->backWithFlash('Coaching session deleted successfully.');
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

        // Prevent concurrent acknowledgement race condition
        if ($session->ack_status !== 'Pending') {
            return $this->backWithFlash('This session has already been acknowledged.', 'error');
        }

        try {
            DB::transaction(function () use ($request, $session) {
                // Atomic update: only acknowledge if still Pending
                $affected = CoachingSession::where('id', $session->id)
                    ->where('ack_status', 'Pending')
                    ->update([
                        'ack_status' => 'Acknowledged',
                        'ack_timestamp' => Carbon::now(),
                        'ack_comment' => $request->validated('ack_comment'),
                        'agent_response' => $request->input('agent_response'),
                        'agent_response_at' => $request->input('agent_response') ? now() : null,
                        'compliance_status' => 'For_Review',
                    ]);

                if ($affected === 0) {
                    throw new \RuntimeException('ALREADY_ACKNOWLEDGED');
                }

                $session->refresh();
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
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'ALREADY_ACKNOWLEDGED') {
                return $this->backWithFlash('This session has already been acknowledged.', 'error');
            }
            throw $e;
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

        // Prevent concurrent review race condition
        if ($session->compliance_status !== 'For_Review') {
            return $this->backWithFlash('This session has already been reviewed.', 'error');
        }

        try {
            DB::transaction(function () use ($request, $session) {
                $validated = $request->validated();

                // Atomic update: only review if still For_Review
                $affected = CoachingSession::where('id', $session->id)
                    ->where('compliance_status', 'For_Review')
                    ->update([
                        'compliance_status' => $validated['compliance_status'],
                        'compliance_reviewer_id' => auth()->id(),
                        'compliance_review_timestamp' => Carbon::now(),
                        'compliance_notes' => $validated['compliance_notes'] ?? null,
                    ]);

                if ($affected === 0) {
                    throw new \RuntimeException('ALREADY_REVIEWED');
                }

                $session->refresh();
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
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'ALREADY_REVIEWED') {
                return $this->backWithFlash('This session has already been reviewed.', 'error');
            }
            throw $e;
        } catch (\Exception $e) {
            Log::error('CoachingSessionController@review Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to review coaching session.', 'error');
        }
    }
}
