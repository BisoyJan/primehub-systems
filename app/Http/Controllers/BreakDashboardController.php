<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateBreakTimerExportExcel;
use App\Models\BreakSession;
use App\Models\Campaign;
use App\Models\User;
use App\Services\BreakTimerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class BreakDashboardController extends Controller
{
    public function __construct(protected BreakTimerService $breakTimerService) {}

    /**
     * Get the campaign IDs for a Team Lead, or empty array for other roles.
     */
    private function getTeamLeadCampaignIds(): array
    {
        $user = auth()->user();

        if ($user->role !== 'Team Lead') {
            return [];
        }

        return $user->getCampaignIds();
    }

    /**
     * Scope a BreakSession query to only show sessions for users in the given campaigns.
     */
    private function scopeByCampaigns($query, array $campaignIds): void
    {
        if (! empty($campaignIds)) {
            $query->whereHas('user.activeSchedule', function ($q) use ($campaignIds) {
                $q->whereIn('campaign_id', $campaignIds);
            });
        }
    }

    /**
     * Get the users list, scoped to the Team Lead's campaigns if applicable.
     */
    private function getScopedUsers(array $campaignIds)
    {
        $query = User::query()
            ->where('is_approved', true)
            ->orderBy('first_name');

        if (! empty($campaignIds)) {
            $query->whereHas('activeSchedule', function ($q) use ($campaignIds) {
                $q->whereIn('campaign_id', $campaignIds);
            });
        }

        return $query->get(['id', 'first_name', 'last_name']);
    }

    /**
     * Get the campaigns list, scoped to the Team Lead's campaigns if applicable.
     */
    private function getScopedCampaigns(array $campaignIds): Collection
    {
        $query = Campaign::query()->orderBy('name');

        if (! empty($campaignIds)) {
            $query->whereIn('id', $campaignIds);
        }

        return $query->get(['id', 'name']);
    }

    /**
     * Filter a query by campaign_id via user's active schedule.
     */
    private function filterByCampaign($query, ?string $campaignId): void
    {
        if ($campaignId) {
            $query->whereHas('user.activeSchedule', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId);
            });
        }
    }

    public function index(Request $request)
    {
        $policy = $this->breakTimerService->getActivePolicy();
        $today = $this->breakTimerService->getShiftDate($policy);
        $date = $request->query('date', $today);
        $teamLeadCampaignIds = $this->getTeamLeadCampaignIds();

        $query = BreakSession::query()
            ->with(['user.activeSchedule.campaign', 'breakEvents' => fn ($q) => $q->whereIn('action', ['pause', 'resume'])->orderBy('occurred_at')])
            ->where('shift_date', $date)
            ->search($request->query('search'))
            ->orderByRaw("CASE status WHEN 'overage' THEN 1 WHEN 'active' THEN 2 WHEN 'paused' THEN 3 WHEN 'completed' THEN 4 ELSE 5 END")
            ->orderBy('overage_seconds', 'desc')
            ->orderBy('started_at', 'desc');

        $this->scopeByCampaigns($query, $teamLeadCampaignIds);
        $this->filterByCampaign($query, $request->query('campaign_id'));

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->query('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->query('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        $paginated = $query->paginate(20)->withQueryString();

        $items = $paginated->getCollection()->map(function (BreakSession $session) {
            $timing = $this->breakTimerService->getSessionTimingSnapshot($session);

            return [
                'id' => $session->id,
                'session_id' => $session->session_id,
                'user' => $session->user ? [
                    'id' => $session->user->id,
                    'first_name' => $session->user->first_name,
                    'last_name' => $session->user->last_name,
                ] : null,
                'campaign' => $session->user?->activeSchedule?->campaign?->name,
                'station' => $session->station,
                'type' => $session->type,
                'status' => $session->status,
                'duration_seconds' => $session->duration_seconds,
                'started_at' => $session->started_at?->toDateTimeString(),
                'ended_at' => $session->ended_at?->toDateTimeString(),
                'expected_end_at' => $timing['expected_end_at']?->toDateTimeString(),
                'remaining_seconds' => $session->remaining_seconds,
                'overage_seconds' => $session->overage_seconds,
                'live_overage_seconds' => $timing['overage_seconds'],
                'is_overbreak_now' => $timing['is_overbreak_now'],
                'total_paused_seconds' => $session->total_paused_seconds,
                'last_pause_reason' => $session->last_pause_reason,
                'pause_resume_events' => $session->breakEvents->map(fn ($event) => [
                    'action' => $event->action,
                    'occurred_at' => $event->occurred_at?->toDateTimeString(),
                    'reason' => $event->reason,
                ])->values()->toArray(),
            ];
        })->toArray();

        // Live stats for the date
        $sessionsForDate = BreakSession::query()->where('shift_date', $date);
        $this->scopeByCampaigns($sessionsForDate, $teamLeadCampaignIds);
        $this->filterByCampaign($sessionsForDate, $request->query('campaign_id'));

        $activeSessionsForDate = BreakSession::query()
            ->where('shift_date', $date)
            ->where('status', 'active')
            ->with('breakEvents');
        $this->scopeByCampaigns($activeSessionsForDate, $teamLeadCampaignIds);
        $this->filterByCampaign($activeSessionsForDate, $request->query('campaign_id'));

        $currentlyOverbreak = $activeSessionsForDate
            ->get()
            ->filter(fn (BreakSession $session) => $this->breakTimerService->getSessionTimingSnapshot($session)['is_overbreak_now'])
            ->count();

        $stats = [
            'total_sessions' => (clone $sessionsForDate)->count(),
            'active_now' => (clone $sessionsForDate)->whereIn('status', ['active', 'paused'])->count(),
            'currently_overbreak' => $currentlyOverbreak,
            'completed' => (clone $sessionsForDate)->where('status', 'completed')->count(),
            'overage' => (clone $sessionsForDate)->where('status', 'overage')->count(),
            'avg_overage_seconds' => (int) (clone $sessionsForDate)->where('overage_seconds', '>', 0)->avg('overage_seconds'),
        ];

        return Inertia::render('BreakTimer/Dashboard', [
            'sessions' => [
                'data' => $items,
                'links' => $paginated->toArray()['links'] ?? [],
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'stats' => $stats,
            'filters' => [
                'date' => $date,
                'search' => $request->query('search', ''),
                'status' => $request->query('status', ''),
                'type' => $request->query('type', ''),
                'user_id' => $request->query('user_id', ''),
                'campaign_id' => $request->query('campaign_id', ''),
            ],
            'users' => $this->getScopedUsers($teamLeadCampaignIds),
            'campaigns' => $this->getScopedCampaigns($teamLeadCampaignIds),
        ]);
    }

    public function reports(Request $request)
    {
        $startDate = $request->query('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', now()->toDateString());
        $teamLeadCampaignIds = $this->getTeamLeadCampaignIds();

        $query = BreakSession::query()
            ->with(['user.activeSchedule.campaign'])
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->search($request->query('search'))
            ->orderBy('shift_date', 'desc')
            ->orderBy('started_at', 'desc');

        $this->scopeByCampaigns($query, $teamLeadCampaignIds);
        $this->filterByCampaign($query, $request->query('campaign_id'));

        if ($request->query('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->query('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($action = $request->query('admin_action')) {
            if (in_array($action, ['force_end', 'restore', 'reset', 'auto_end'], true)) {
                $query->whereIn('id', function ($q) use ($action) {
                    $q->select('break_session_id')->from('break_events')->where('action', $action);
                });
            }
        }

        $paginated = $query->paginate(25)->withQueryString();

        $items = $paginated->getCollection()->map(function (BreakSession $session) {
            return [
                'id' => $session->id,
                'session_id' => $session->session_id,
                'user' => $session->user ? [
                    'id' => $session->user->id,
                    'first_name' => $session->user->first_name,
                    'last_name' => $session->user->last_name,
                ] : null,
                'campaign' => $session->user?->activeSchedule?->campaign?->name,
                'station' => $session->station,
                'type' => $session->type,
                'status' => $session->status,
                'duration_seconds' => $session->duration_seconds,
                'started_at' => $session->started_at?->toDateTimeString(),
                'ended_at' => $session->ended_at?->toDateTimeString(),
                'remaining_seconds' => $session->remaining_seconds,
                'overage_seconds' => $session->overage_seconds,
                'total_paused_seconds' => $session->total_paused_seconds,
                'shift_date' => $session->shift_date?->toDateString(),
                'last_pause_reason' => $session->last_pause_reason,
                'ended_by' => $session->ended_by,
            ];
        })->toArray();

        // Summary stats for the period
        $periodQuery = BreakSession::query()->whereBetween('shift_date', [$startDate, $endDate]);
        $this->scopeByCampaigns($periodQuery, $teamLeadCampaignIds);
        $this->filterByCampaign($periodQuery, $request->query('campaign_id'));
        $summary = [
            'total_sessions' => (clone $periodQuery)->count(),
            'total_overage' => (clone $periodQuery)->where('status', 'overage')->count(),
            'avg_overage_seconds' => (int) (clone $periodQuery)->where('overage_seconds', '>', 0)->avg('overage_seconds'),
            'total_resets' => (clone $periodQuery)->where('status', 'reset')->count(),
            'total_force_ended' => (clone $periodQuery)->whereIn('id', function ($q) {
                $q->select('break_session_id')->from('break_events')->where('action', 'force_end');
            })->count(),
            'total_restored' => (clone $periodQuery)->whereIn('id', function ($q) {
                $q->select('break_session_id')->from('break_events')->where('action', 'restore');
            })->count(),
        ];

        return Inertia::render('BreakTimer/Reports', [
            'sessions' => [
                'data' => $items,
                'links' => $paginated->toArray()['links'] ?? [],
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'summary' => $summary,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'search' => $request->query('search', ''),
                'user_id' => $request->query('user_id', ''),
                'type' => $request->query('type', ''),
                'status' => $request->query('status', ''),
                'campaign_id' => $request->query('campaign_id', ''),
                'admin_action' => $request->query('admin_action', ''),
            ],
            'users' => $this->getScopedUsers($teamLeadCampaignIds),
            'campaigns' => $this->getScopedCampaigns($teamLeadCampaignIds),
        ]);
    }

    public function startExport(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_id' => 'nullable|integer|exists:users,id',
            'type' => 'nullable|string|in:1st_break,2nd_break,lunch,combined',
            'status' => 'nullable|string|in:active,paused,completed,overage',
            'search' => 'nullable|string|max:255',
        ]);

        $teamLeadCampaignIds = $this->getTeamLeadCampaignIds();

        $countQuery = BreakSession::query()
            ->whereBetween('shift_date', [$request->input('start_date'), $request->input('end_date')])
            ->when($request->input('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->search($v));

        $this->scopeByCampaigns($countQuery, $teamLeadCampaignIds);
        $count = $countQuery->count();

        if ($count === 0) {
            return response()->json([
                'error' => true,
                'message' => 'No break session records found matching your selected filters.',
            ], 422);
        }

        $jobId = (string) Str::uuid();

        dispatch_sync(new GenerateBreakTimerExportExcel(
            $jobId,
            $request->input('start_date'),
            $request->input('end_date'),
            $request->input('user_id') ? (int) $request->input('user_id') : null,
            $request->input('type'),
            $request->input('status'),
            $request->input('search'),
            ! empty($teamLeadCampaignIds) ? $teamLeadCampaignIds : null,
        ));

        $tempDir = storage_path('app/temp');
        $pattern = $tempDir."/break_timer_export_*_{$jobId}.xlsx";
        $files = glob($pattern);

        if (empty($files)) {
            return response()->json([
                'error' => true,
                'message' => 'Export generation failed. Please try again.',
            ], 500);
        }

        return response()->download($files[0], basename($files[0]))->deleteFileAfterSend(true);
    }

    public function exportProgress(string $jobId)
    {
        $cacheKey = "break_timer_export_job:{$jobId}";

        return response()->json(Cache::get($cacheKey, [
            'percent' => 0,
            'status' => 'Not started',
            'finished' => false,
            'downloadUrl' => null,
        ]));
    }

    public function downloadExport(string $jobId)
    {
        $cacheKey = "break_timer_export_job:{$jobId}";
        $tempDir = storage_path('app/temp');
        $pattern = $tempDir."/break_timer_export_*_{$jobId}.xlsx";
        $files = glob($pattern);

        if (empty($files)) {
            Cache::forget($cacheKey);
            abort(404, 'Export file not found. Please generate a new export.');
        }

        $filePath = $files[0];
        $filename = basename($filePath);

        Cache::forget($cacheKey);

        return response()->download($filePath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Force-end an active or paused break session on behalf of an agent.
     * For shifts where the agent forgot to end (logged off, left office, etc.).
     */
    public function forceEnd(Request $request, BreakSession $breakSession)
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->ensureSessionInScope($breakSession);

        if (! in_array($breakSession->status, ['active', 'paused'], true)) {
            return redirect()->back()->with('flash', [
                'message' => 'Only active or paused sessions can be force-ended.',
                'type' => 'error',
            ]);
        }

        try {
            $admin = auth()->user();
            $adminName = trim("{$admin->first_name} {$admin->last_name}") ?: $admin->email;

            $this->breakTimerService->forceEndSession(
                $breakSession,
                (int) $admin->id,
                $adminName,
                $request->input('reason'),
            );

            return redirect()->back()->with('flash', [
                'message' => 'Session force-ended successfully.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakTimer ForceEnd Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to force-end session.',
                'type' => 'error',
            ]);
        }
    }

    /**
     * Restore a previously ended session that was unintentionally ended/reset
     * with significant remaining time.
     */
    public function restore(Request $request, BreakSession $breakSession)
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->ensureSessionInScope($breakSession);

        if (! in_array($breakSession->status, ['completed', 'overage', 'reset', 'auto_ended'], true)) {
            return redirect()->back()->with('flash', [
                'message' => 'Only ended, reset, or auto-ended sessions can be restored.',
                'type' => 'error',
            ]);
        }

        if ((int) $breakSession->remaining_seconds < 30) {
            return redirect()->back()->with('flash', [
                'message' => 'Session has less than 30 seconds remaining and cannot be restored.',
                'type' => 'error',
            ]);
        }

        $hasActive = BreakSession::query()
            ->forUser($breakSession->user_id)
            ->forDate($breakSession->shift_date->toDateString())
            ->active()
            ->where('id', '!=', $breakSession->id)
            ->exists();

        if ($hasActive) {
            return redirect()->back()->with('flash', [
                'message' => 'Agent already has another active session. End it first before restoring.',
                'type' => 'error',
            ]);
        }

        try {
            $admin = auth()->user();
            $adminName = trim("{$admin->first_name} {$admin->last_name}") ?: $admin->email;

            $this->breakTimerService->restoreSession(
                $breakSession,
                (int) $admin->id,
                $adminName,
                $request->input('reason'),
            );

            return redirect()->back()->with('flash', [
                'message' => 'Session restored successfully.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakTimer Restore Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to restore session.',
                'type' => 'error',
            ]);
        }
    }

    /**
     * Return the full event timeline for a session (audit trail).
     */
    public function timeline(BreakSession $breakSession)
    {
        $this->ensureSessionInScope($breakSession);

        $events = $breakSession->breakEvents()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'action', 'remaining_seconds', 'overage_seconds', 'reason', 'occurred_at'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'action' => $e->action,
                'remaining_seconds' => $e->remaining_seconds,
                'overage_seconds' => $e->overage_seconds,
                'reason' => $e->reason,
                'occurred_at' => $e->occurred_at?->toDateTimeString(),
            ])
            ->all();

        return response()->json([
            'session' => [
                'id' => $breakSession->id,
                'session_id' => $breakSession->session_id,
                'status' => $breakSession->status,
                'ended_by' => $breakSession->ended_by,
                'type' => $breakSession->type,
            ],
            'events' => $events,
        ]);
    }

    /**
     * Ensure a Team Lead can only act on sessions belonging to users in their campaigns.
     */
    private function ensureSessionInScope(BreakSession $session): void
    {
        $campaignIds = $this->getTeamLeadCampaignIds();

        if (empty($campaignIds)) {
            return;
        }

        $inScope = BreakSession::query()
            ->where('id', $session->id)
            ->whereHas('user.activeSchedule', fn ($q) => $q->whereIn('campaign_id', $campaignIds))
            ->exists();

        if (! $inScope) {
            abort(403, 'This session is outside your assigned campaigns.');
        }
    }
}
