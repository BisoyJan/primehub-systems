<?php

namespace App\Services;

use App\Models\CoachingSession;
use App\Models\CoachingStatusSetting;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CoachingDashboardService
{
    /**
     * Coaching status constants.
     */
    public const STATUS_COACHING_DONE = 'Coaching Done';

    public const STATUS_NEEDS_COACHING = 'Needs Coaching';

    public const STATUS_BADLY_NEEDS_COACHING = 'Badly Needs Coaching';

    public const STATUS_PLEASE_COACH_ASAP = 'Please Coach ASAP';

    public const STATUS_NO_RECORD = 'No Record';

    /**
     * Status color mapping for frontend.
     */
    public const STATUS_COLORS = [
        self::STATUS_COACHING_DONE => 'green',
        self::STATUS_NEEDS_COACHING => 'yellow',
        self::STATUS_BADLY_NEEDS_COACHING => 'orange',
        self::STATUS_PLEASE_COACH_ASAP => 'red',
        self::STATUS_NO_RECORD => 'gray',
    ];

    /**
     * Status priority for sorting (lower = more urgent).
     */
    public const STATUS_PRIORITY = [
        self::STATUS_PLEASE_COACH_ASAP => 1,
        self::STATUS_BADLY_NEEDS_COACHING => 2,
        self::STATUS_NEEDS_COACHING => 3,
        self::STATUS_NO_RECORD => 4,
        self::STATUS_COACHING_DONE => 5,
    ];

    /**
     * Get the coaching status for a specific agent.
     *
     * Threshold-based logic using configurable day ranges.
     * No Critical severity override — severity is tracked separately on sessions.
     */
    public function getCoachingStatus(int $coacheeId): string
    {
        $thresholds = CoachingStatusSetting::getThresholds();
        $lastSession = CoachingSession::where('coachee_id', $coacheeId)
            ->orderByDesc('session_date')
            ->first();

        if (! $lastSession) {
            return self::STATUS_NO_RECORD;
        }

        $daysSinceLastCoaching = Carbon::parse($lastSession->session_date)
            ->startOfDay()
            ->diffInDays(Carbon::today());

        $coachingDoneMax = $thresholds['coaching_done_max_days'] ?? 15;
        $needsCoachingMax = $thresholds['needs_coaching_max_days'] ?? 30;
        $badlyNeedsMax = $thresholds['badly_needs_coaching_max_days'] ?? 45;
        $noRecordDays = $thresholds['no_record_days'] ?? 60;

        if ($daysSinceLastCoaching >= $noRecordDays) {
            return self::STATUS_NO_RECORD;
        }

        if ($daysSinceLastCoaching > $badlyNeedsMax) {
            return self::STATUS_PLEASE_COACH_ASAP;
        }

        if ($daysSinceLastCoaching > $needsCoachingMax) {
            return self::STATUS_BADLY_NEEDS_COACHING;
        }

        if ($daysSinceLastCoaching > $coachingDoneMax) {
            return self::STATUS_NEEDS_COACHING;
        }

        return self::STATUS_COACHING_DONE;
    }

    /**
     * Get coaching summary for a specific agent.
     *
     * @return array{coaching_status: string, status_color: string, last_coached_date: ?string, previous_coached_date: ?string, older_coached_date: ?string, pending_acknowledgements: int, total_sessions: int}
     */
    public function getCoacheeSummary(int $coacheeId): array
    {
        $recentSessions = CoachingSession::where('coachee_id', $coacheeId)
            ->orderByDesc('session_date')
            ->limit(3)
            ->pluck('session_date')
            ->toArray();

        $pendingCount = CoachingSession::where('coachee_id', $coacheeId)
            ->where('ack_status', 'Pending')
            ->count();

        $totalSessions = CoachingSession::where('coachee_id', $coacheeId)->count();

        $status = $this->getCoachingStatus($coacheeId);

        return [
            'coaching_status' => $status,
            'status_color' => self::STATUS_COLORS[$status] ?? 'gray',
            'last_coached_date' => $recentSessions[0] ?? null,
            'previous_coached_date' => $recentSessions[1] ?? null,
            'older_coached_date' => $recentSessions[2] ?? null,
            'pending_acknowledgements' => $pendingCount,
            'total_sessions' => $totalSessions,
        ];
    }

    /**
     * Get dashboard data for a team lead.
     *
     * @return array{total_agents: int, status_counts: array<string, int>, agents: Collection}
     */
    public function getTeamLeadDashboardData(User $teamLead, ?array $filters = null): array
    {
        // Get agents in the team lead's campaigns
        $campaignIds = $teamLead->getCampaignIds();

        if (empty($campaignIds)) {
            return [
                'total_agents' => 0,
                'status_counts' => $this->emptyStatusCounts(),
                'agents' => collect(),
            ];
        }

        $agentIds = EmployeeSchedule::whereIn('campaign_id', $campaignIds)
            ->where('is_active', true)
            ->whereHas('user', function ($q) {
                $q->where('role', 'Agent')
                    ->where('is_active', true)
                    ->whereNull('deleted_at');
            })
            ->pluck('user_id')
            ->unique()
            ->values();

        return $this->buildDashboardData($agentIds, $filters);
    }

    /**
     * Get dashboard data for compliance/admin (all agents).
     *
     * @return array{total_agents: int, status_counts: array<string, int>, agents: Collection}
     */
    public function getComplianceDashboardData(?array $filters = null): array
    {
        $query = User::where('role', 'Agent')
            ->where('is_active', true)
            ->whereNull('deleted_at');

        if (isset($filters['campaign_id'])) {
            $campaignId = $filters['campaign_id'];
            $query->whereHas('activeSchedule', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId);
            });
        }

        if (isset($filters['coach_id'])) {
            $coachId = $filters['coach_id'];
            $query->whereHas('coachingSessionsAsCoachee', function ($q) use ($coachId) {
                $q->where('coach_id', $coachId);
            });
        }

        $agentIds = $query->pluck('id');

        return $this->buildDashboardData($agentIds, $filters);
    }

    /**
     * Build dashboard data for a set of agent IDs.
     */
    protected function buildDashboardData(Collection $coacheeIds, ?array $filters = null): array
    {
        $statusCounts = $this->emptyStatusCounts();
        $agents = collect();

        foreach ($coacheeIds as $coacheeId) {
            $summary = $this->getCoacheeSummary($coacheeId);
            $user = User::find($coacheeId);

            if (! $user) {
                continue;
            }

            // Apply status filter if set
            if (isset($filters['coaching_status']) && $summary['coaching_status'] !== $filters['coaching_status']) {
                continue;
            }

            // Apply date range filter on last coached date
            if (isset($filters['date_from']) && $summary['last_coached_date']) {
                if (Carbon::parse($summary['last_coached_date'])->lt(Carbon::parse($filters['date_from']))) {
                    continue;
                }
            }
            if (isset($filters['date_to']) && $summary['last_coached_date']) {
                if (Carbon::parse($summary['last_coached_date'])->gt(Carbon::parse($filters['date_to']))) {
                    continue;
                }
            }

            $statusCounts[$summary['coaching_status']] = ($statusCounts[$summary['coaching_status']] ?? 0) + 1;

            $campaign = $user->activeSchedule?->campaign;

            $agents->push([
                'id' => $user->id,
                'name' => $user->name,
                'account' => $campaign?->name ?? 'N/A',
                'campaign_id' => $campaign?->id,
                'coaching_status' => $summary['coaching_status'],
                'status_color' => $summary['status_color'],
                'last_coached_date' => $summary['last_coached_date'],
                'previous_coached_date' => $summary['previous_coached_date'],
                'older_coached_date' => $summary['older_coached_date'],
                'pending_acknowledgements' => $summary['pending_acknowledgements'],
                'total_sessions' => $summary['total_sessions'],
            ]);
        }

        // Sort by priority (most urgent first), then by last coached date (oldest first)
        $agents = $agents->sortBy([
            fn ($a, $b) => (self::STATUS_PRIORITY[$a['coaching_status']] ?? 99) <=> (self::STATUS_PRIORITY[$b['coaching_status']] ?? 99),
            fn ($a, $b) => ($a['last_coached_date'] ?? '1900-01-01') <=> ($b['last_coached_date'] ?? '1900-01-01'),
        ])->values();

        return [
            'total_agents' => $coacheeIds->count(),
            'status_counts' => $statusCounts,
            'agents' => $agents,
        ];
    }

    /**
     * Get compliance queue data (sessions pending review).
     *
     * @return array{unacknowledged: Collection, for_review: Collection, at_risk_agents: Collection}
     */
    public function getComplianceQueueData(?array $filters = null): array
    {
        $unacknowledgedQuery = CoachingSession::with(['coachee', 'coach'])
            ->where('ack_status', 'Pending')
            ->orderBy('session_date');

        $forReviewQuery = CoachingSession::with(['coachee', 'coach'])
            ->where('compliance_status', 'For_Review')
            ->orderBy('session_date');

        // Apply filters
        if (isset($filters['campaign_id'])) {
            $campaignId = $filters['campaign_id'];
            $unacknowledgedQuery->whereHas('coachee.activeSchedule', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId);
            });
            $forReviewQuery->whereHas('coachee.activeSchedule', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId);
            });
        }

        if (isset($filters['coach_id'])) {
            $unacknowledgedQuery->where('coach_id', $filters['coach_id']);
            $forReviewQuery->where('coach_id', $filters['coach_id']);
        }

        if (isset($filters['coachee_role'])) {
            $coacheeRole = $filters['coachee_role'];
            $unacknowledgedQuery->whereHas('coachee', fn ($q) => $q->where('role', $coacheeRole));
            $forReviewQuery->whereHas('coachee', fn ($q) => $q->where('role', $coacheeRole));
        }

        // Get at-risk agents (No Record / Please Coach ASAP)
        $atRiskAgents = collect();
        $atRiskQuery = User::where('is_active', true)->whereNull('deleted_at');

        if (isset($filters['coachee_role'])) {
            $atRiskQuery->where('role', $filters['coachee_role']);
        } else {
            $atRiskQuery->where('role', 'Agent');
        }

        $allAgents = $atRiskQuery->pluck('id');
        foreach ($allAgents as $agentId) {
            $status = $this->getCoachingStatus($agentId);
            if (in_array($status, [self::STATUS_NO_RECORD, self::STATUS_PLEASE_COACH_ASAP])) {
                $user = User::find($agentId);
                if ($user) {
                    $atRiskAgents->push([
                        'id' => $user->id,
                        'name' => $user->name,
                        'account' => $user->activeSchedule?->campaign?->name ?? 'N/A',
                        'coaching_status' => $status,
                        'status_color' => self::STATUS_COLORS[$status],
                    ]);
                }
            }
        }

        return [
            'unacknowledged' => $unacknowledgedQuery->get(),
            'for_review' => $forReviewQuery->get(),
            'at_risk_agents' => $atRiskAgents,
        ];
    }

    /**
     * Get coaching data for Team Leads (as coachees), used by admin dashboard.
     *
     * @return array{total_agents: int, status_counts: array<string, int>, agents: Collection}
     */
    public function getTeamLeadCoachingData(?array $filters = null): array
    {
        $query = User::where('role', 'Team Lead')
            ->where('is_active', true)
            ->whereNull('deleted_at');

        if (isset($filters['campaign_id'])) {
            $campaignId = $filters['campaign_id'];
            $query->whereHas('activeSchedule', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId);
            });
        }

        $tlIds = $query->pluck('id');

        return $this->buildDashboardData($tlIds, $filters);
    }

    /**
     * Get empty status counts array.
     *
     * @return array<string, int>
     */
    protected function emptyStatusCounts(): array
    {
        return [
            self::STATUS_COACHING_DONE => 0,
            self::STATUS_NEEDS_COACHING => 0,
            self::STATUS_BADLY_NEEDS_COACHING => 0,
            self::STATUS_PLEASE_COACH_ASAP => 0,
            self::STATUS_NO_RECORD => 0,
        ];
    }

    /**
     * Get expanded follow-ups for coaching dashboards (30-day window + overdue).
     *
     * @return array{upcoming: array, overdue: array}
     */
    public function getExpandedFollowUps(User $user): array
    {
        $today = Carbon::today();
        $thirtyDaysFromNow = Carbon::today()->addDays(30);

        $baseQuery = CoachingSession::with(['coachee:id,first_name,last_name', 'coach:id,first_name,last_name'])
            ->whereNotNull('follow_up_date');

        if ($user->role === 'Team Lead') {
            $baseQuery->where(function ($q) use ($user) {
                $q->where('coach_id', $user->id)
                    ->orWhere('coachee_id', $user->id);
            });
        }

        $mapSession = function (CoachingSession $session) {
            return [
                'id' => $session->id,
                'agent_name' => ($session->coachee?->last_name ?? '').', '.($session->coachee?->first_name ?? ''),
                'team_lead_name' => ($session->coach?->last_name ?? '').', '.($session->coach?->first_name ?? ''),
                'follow_up_date' => $session->follow_up_date->format('Y-m-d'),
                'purpose_label' => CoachingSession::PURPOSE_LABELS[$session->purpose] ?? $session->purpose,
                'session_date' => $session->session_date->format('Y-m-d'),
            ];
        };

        // Upcoming: today → 30 days out
        $upcoming = (clone $baseQuery)
            ->whereBetween('follow_up_date', [$today, $thirtyDaysFromNow])
            ->orderBy('follow_up_date')
            ->get()
            ->map($mapSession)
            ->values()
            ->toArray();

        // Overdue: past follow-up dates (up to 30 days back)
        $thirtyDaysAgo = Carbon::today()->subDays(30);
        $overdue = (clone $baseQuery)
            ->whereBetween('follow_up_date', [$thirtyDaysAgo, $today->copy()->subDay()])
            ->orderByDesc('follow_up_date')
            ->get()
            ->map($mapSession)
            ->values()
            ->toArray();

        return [
            'upcoming' => $upcoming,
            'overdue' => $overdue,
        ];
    }
}
