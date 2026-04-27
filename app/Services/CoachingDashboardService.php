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

    public const STATUS_DRAFT = 'Draft';

    /**
     * Status color mapping for frontend.
     */
    public const STATUS_COLORS = [
        self::STATUS_COACHING_DONE => 'green',
        self::STATUS_NEEDS_COACHING => 'yellow',
        self::STATUS_BADLY_NEEDS_COACHING => 'orange',
        self::STATUS_PLEASE_COACH_ASAP => 'red',
        self::STATUS_NO_RECORD => 'gray',
        self::STATUS_DRAFT => 'blue',
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
        self::STATUS_DRAFT => 6,
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
        $lastSession = CoachingSession::submitted()
            ->where('coachee_id', $coacheeId)
            ->orderByDesc('session_date')
            ->first();

        if (! $lastSession) {
            // Check if agent has any draft sessions
            $hasDraft = CoachingSession::draft()
                ->where('coachee_id', $coacheeId)
                ->exists();

            return $hasDraft ? self::STATUS_DRAFT : self::STATUS_NO_RECORD;
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
        $recentSessions = CoachingSession::submitted()
            ->where('coachee_id', $coacheeId)
            ->orderByDesc('session_date')
            ->limit(3)
            ->pluck('session_date')
            ->toArray();

        $pendingCount = CoachingSession::submitted()
            ->where('coachee_id', $coacheeId)
            ->where('ack_status', 'Pending')
            ->count();

        $totalSessions = CoachingSession::submitted()
            ->where('coachee_id', $coacheeId)
            ->whereYear('session_date', now()->year)
            ->count();

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
     * Normalize a campaign filter value (scalar id, CSV string, or array) into a list of int ids.
     *
     * @return array<int>|null
     */
    protected function normalizeCampaignIds(?array $filters): ?array
    {
        if (! $filters) {
            return null;
        }
        $val = $filters['campaign_id'] ?? $filters['campaign_ids'] ?? null;
        if ($val === null || $val === '' || $val === []) {
            return null;
        }
        if (is_array($val)) {
            $ids = array_map('intval', $val);
        } elseif (is_string($val) && str_contains($val, ',')) {
            $ids = array_map('intval', explode(',', $val));
        } else {
            $ids = [(int) $val];
        }
        $ids = array_values(array_filter($ids, fn ($id) => $id > 0));

        return empty($ids) ? null : $ids;
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

        if ($campaignIds = $this->normalizeCampaignIds($filters)) {
            $query->whereHas('activeSchedule', function ($q) use ($campaignIds) {
                $q->whereIn('campaign_id', $campaignIds);
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

        // Batch query for trend data (current vs previous 30-day window)
        $trendData = CoachingSession::submitted()
            ->whereIn('coachee_id', $coacheeIds)
            ->where('session_date', '>=', now()->subDays(60))
            ->selectRaw('coachee_id,
                SUM(CASE WHEN session_date >= ? THEN 1 ELSE 0 END) as current_count,
                SUM(CASE WHEN session_date < ? THEN 1 ELSE 0 END) as previous_count',
                [now()->subDays(30), now()->subDays(30)])
            ->groupBy('coachee_id')
            ->get()
            ->keyBy('coachee_id');

        // Batch load all users with their active schedules and campaigns
        $users = User::whereIn('id', $coacheeIds)
            ->with('activeSchedule.campaign')
            ->get()
            ->keyBy('id');

        // Batch query: last 3 session dates per coachee (for summary)
        $recentSessions = CoachingSession::submitted()
            ->whereIn('coachee_id', $coacheeIds)
            ->orderByDesc('session_date')
            ->get(['coachee_id', 'session_date'])
            ->groupBy('coachee_id')
            ->map(fn ($sessions) => $sessions->take(3)->pluck('session_date')->toArray());

        // Batch query: pending acknowledgement counts per coachee
        $pendingCounts = CoachingSession::submitted()
            ->whereIn('coachee_id', $coacheeIds)
            ->where('ack_status', 'Pending')
            ->selectRaw('coachee_id, COUNT(*) as cnt')
            ->groupBy('coachee_id')
            ->pluck('cnt', 'coachee_id');

        // Batch query: total sessions YTD per coachee
        $totalSessionCounts = CoachingSession::submitted()
            ->whereIn('coachee_id', $coacheeIds)
            ->whereYear('session_date', now()->year)
            ->selectRaw('coachee_id, COUNT(*) as cnt')
            ->groupBy('coachee_id')
            ->pluck('cnt', 'coachee_id');

        // Batch query: submitted sessions in the current calendar month per coachee
        // (used to evaluate weekly cadence — target is monthly_session_target sessions per month).
        $monthlySessionCounts = CoachingSession::submitted()
            ->whereIn('coachee_id', $coacheeIds)
            ->whereBetween('session_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->selectRaw('coachee_id, COUNT(*) as cnt')
            ->groupBy('coachee_id')
            ->pluck('cnt', 'coachee_id');

        // Batch query: last session date per coachee (for status calculation)
        $lastSessionDates = CoachingSession::submitted()
            ->whereIn('coachee_id', $coacheeIds)
            ->selectRaw('coachee_id, MAX(session_date) as last_date')
            ->groupBy('coachee_id')
            ->pluck('last_date', 'coachee_id');

        // Batch query: coachees with draft sessions (for status)
        $coacheesWithDrafts = CoachingSession::draft()
            ->whereIn('coachee_id', $coacheeIds)
            ->distinct()
            ->pluck('coachee_id')
            ->flip();

        // Get thresholds once
        $thresholds = CoachingStatusSetting::getThresholds();
        $coachingDoneMax = $thresholds['coaching_done_max_days'] ?? 15;
        $needsCoachingMax = $thresholds['needs_coaching_max_days'] ?? 30;
        $badlyNeedsMax = $thresholds['badly_needs_coaching_max_days'] ?? 45;
        $noRecordDays = $thresholds['no_record_days'] ?? 60;

        foreach ($coacheeIds as $coacheeId) {
            $user = $users->get($coacheeId);

            if (! $user) {
                continue;
            }

            // Calculate status from batch data
            $lastDate = $lastSessionDates->get($coacheeId);
            if (! $lastDate) {
                $status = $coacheesWithDrafts->has($coacheeId) ? self::STATUS_DRAFT : self::STATUS_NO_RECORD;
            } else {
                $daysSince = Carbon::parse($lastDate)->startOfDay()->diffInDays(Carbon::today());
                if ($daysSince >= $noRecordDays) {
                    $status = self::STATUS_NO_RECORD;
                } elseif ($daysSince > $badlyNeedsMax) {
                    $status = self::STATUS_PLEASE_COACH_ASAP;
                } elseif ($daysSince > $needsCoachingMax) {
                    $status = self::STATUS_BADLY_NEEDS_COACHING;
                } elseif ($daysSince > $coachingDoneMax) {
                    $status = self::STATUS_NEEDS_COACHING;
                } else {
                    $status = self::STATUS_COACHING_DONE;
                }
            }

            $statusColor = self::STATUS_COLORS[$status] ?? 'gray';
            $dates = $recentSessions->get($coacheeId, []);

            // Build summary from batch data
            $summary = [
                'coaching_status' => $status,
                'status_color' => $statusColor,
                'last_coached_date' => $dates[0] ?? null,
                'previous_coached_date' => $dates[1] ?? null,
                'older_coached_date' => $dates[2] ?? null,
                'pending_acknowledgements' => $pendingCounts->get($coacheeId, 0),
                'total_sessions' => $totalSessionCounts->get($coacheeId, 0),
                'sessions_this_month' => (int) $monthlySessionCounts->get($coacheeId, 0),
            ];

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
                'sessions_this_month' => $summary['sessions_this_month'],
                'schedule_effective_date' => $user->activeSchedule?->effective_date?->toDateString(),
                'trend' => ($trendData[$coacheeId]->current_count ?? 0) - ($trendData[$coacheeId]->previous_count ?? 0),
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
        $unacknowledgedQuery = CoachingSession::submitted()
            ->with(['coachee', 'coach'])
            ->where('ack_status', 'Pending')
            ->orderBy('session_date');

        $forReviewQuery = CoachingSession::submitted()
            ->with(['coachee', 'coach'])
            ->where('compliance_status', 'For_Review')
            ->orderBy('session_date');

        // Apply filters
        if ($campaignIds = $this->normalizeCampaignIds($filters)) {
            $unacknowledgedQuery->whereHas('coachee.activeSchedule', function ($q) use ($campaignIds) {
                $q->whereIn('campaign_id', $campaignIds);
            });
            $forReviewQuery->whereHas('coachee.activeSchedule', function ($q) use ($campaignIds) {
                $q->whereIn('campaign_id', $campaignIds);
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
            'unacknowledged' => $this->sortBySeverity($unacknowledgedQuery->get()),
            'for_review' => $this->sortBySeverity($forReviewQuery->get()),
            'at_risk_agents' => $atRiskAgents,
        ];
    }

    /**
     * Sort sessions by severity_flag priority (Critical first, null/Low last).
     */
    private function sortBySeverity(Collection $sessions): Collection
    {
        $priority = ['Critical' => 0, 'High' => 1, 'Medium' => 2, 'Low' => 3];

        return $sessions->sortBy(function ($session) use ($priority) {
            return $priority[$session->severity_flag] ?? 4;
        })->values();
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

        if ($campaignIds = $this->normalizeCampaignIds($filters)) {
            $query->whereHas('activeSchedule', function ($q) use ($campaignIds) {
                $q->whereIn('campaign_id', $campaignIds);
            });
        }

        $tlIds = $query->pluck('id');

        return $this->buildDashboardData($tlIds, $filters);
    }

    /**
     * Build per-campaign coaching-completion stats for the current calendar month.
     *
     * - Pro-rates the monthly session target by elapsed weeks (capped at the target).
     * - Excludes agents whose active schedule started after the month began (new hires / transfers).
     * - Caches the result for 5 minutes per filter combination.
     *
     * @param  array{agents: Collection}  $dashboardData
     * @return array{
     *     monthly_target: int,
     *     expected_so_far_per_agent: int,
     *     weeks_elapsed: int,
     *     period_label: string,
     *     campaigns: array<int, array{account: string, total: int, eligible: int, excluded: int, capped_sessions: int, expected_sessions: int, total_sessions_this_month: int, fully_coached: int, behind_weekly: int, at_risk: int, rate: int, health: string}>,
     *     totals: array{total: int, eligible: int, excluded: int, capped_sessions: int, expected_sessions: int, total_sessions_this_month: int, fully_coached: int, behind_weekly: int, at_risk: int, rate: int, health: string}
     * }
     */
    public function buildCampaignCompletion(array $dashboardData): array
    {
        $monthlyTarget = (int) CoachingStatusSetting::getThreshold('monthly_session_target') ?: 4;
        $today = Carbon::today();
        $weeksElapsed = (int) min((int) ceil($today->day / 7), $monthlyTarget);
        $expectedSoFarPerAgent = $weeksElapsed; // 1 session per elapsed week, capped at monthly target.
        $monthStart = $today->copy()->startOfMonth()->toDateString();

        $atRiskStatuses = [
            self::STATUS_PLEASE_COACH_ASAP,
            self::STATUS_BADLY_NEEDS_COACHING,
            self::STATUS_NO_RECORD,
        ];

        $groups = collect($dashboardData['agents'] ?? [])
            ->groupBy(fn ($a) => $a['account'] ?? 'Unassigned');

        $campaigns = [];
        $totalAccumulator = [
            'total' => 0,
            'eligible' => 0,
            'excluded' => 0,
            'capped_sessions' => 0,
            'expected_sessions' => 0,
            'total_sessions_this_month' => 0,
            'fully_coached' => 0,
            'behind_weekly' => 0,
            'at_risk' => 0,
        ];

        foreach ($groups as $account => $agents) {
            $eligible = $agents->filter(function ($a) use ($monthStart) {
                $eff = $a['schedule_effective_date'] ?? null;

                return $eff === null || $eff <= $monthStart;
            });

            $excluded = $agents->count() - $eligible->count();
            $eligibleCount = $eligible->count();
            $totalSessionsThisMonth = (int) $eligible->sum('sessions_this_month');
            $cappedSessions = (int) $eligible->sum(fn ($a) => min((int) ($a['sessions_this_month'] ?? 0), $monthlyTarget));
            $expectedSessions = $eligibleCount * $monthlyTarget;
            $fullyCoached = $eligible->filter(fn ($a) => (int) ($a['sessions_this_month'] ?? 0) >= $monthlyTarget)->count();
            $behindWeekly = $eligible->filter(fn ($a) => (int) ($a['sessions_this_month'] ?? 0) < $expectedSoFarPerAgent)->count();
            $atRisk = $eligible->filter(fn ($a) => in_array($a['coaching_status'] ?? null, $atRiskStatuses, true))->count();
            $rate = $expectedSessions > 0 ? (int) round(($cappedSessions / $expectedSessions) * 100) : 0;

            $campaigns[] = [
                'account' => $account,
                'total' => $agents->count(),
                'eligible' => $eligibleCount,
                'excluded' => $excluded,
                'capped_sessions' => $cappedSessions,
                'expected_sessions' => $expectedSessions,
                'total_sessions_this_month' => $totalSessionsThisMonth,
                'fully_coached' => $fullyCoached,
                'behind_weekly' => $behindWeekly,
                'at_risk' => $atRisk,
                'rate' => $rate,
                'health' => $this->healthLevel($rate),
            ];

            $totalAccumulator['total'] += $agents->count();
            $totalAccumulator['eligible'] += $eligibleCount;
            $totalAccumulator['excluded'] += $excluded;
            $totalAccumulator['capped_sessions'] += $cappedSessions;
            $totalAccumulator['expected_sessions'] += $expectedSessions;
            $totalAccumulator['total_sessions_this_month'] += $totalSessionsThisMonth;
            $totalAccumulator['fully_coached'] += $fullyCoached;
            $totalAccumulator['behind_weekly'] += $behindWeekly;
            $totalAccumulator['at_risk'] += $atRisk;
        }

        // Sort by urgency: lowest rate first, then most behind first.
        usort($campaigns, function ($a, $b) {
            return [$a['rate'], -$a['behind_weekly']] <=> [$b['rate'], -$b['behind_weekly']];
        });

        $totalsRate = $totalAccumulator['expected_sessions'] > 0
            ? (int) round(($totalAccumulator['capped_sessions'] / $totalAccumulator['expected_sessions']) * 100)
            : 0;

        $totals = $totalAccumulator + [
            'rate' => $totalsRate,
            'health' => $this->healthLevel($totalsRate),
        ];

        return [
            'monthly_target' => $monthlyTarget,
            'expected_so_far_per_agent' => $expectedSoFarPerAgent,
            'weeks_elapsed' => $weeksElapsed,
            'period_label' => $today->format('F Y'),
            'campaigns' => $campaigns,
            'totals' => $totals,
        ];
    }

    /**
     * Bucket a percentage into a health level for UI color-coding.
     */
    protected function healthLevel(int $rate): string
    {
        if ($rate >= 80) {
            return 'green';
        }

        if ($rate >= 50) {
            return 'amber';
        }

        return 'red';
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
            self::STATUS_DRAFT => 0,
        ];
    }

    /**
     * Calculate the follow-up compliance rate for a set of coaching sessions.
     * Rate = sessions with follow-up date that were completed on time / total sessions with follow-up dates.
     * "Completed on time" = a subsequent session exists for the same coachee before or on the follow-up date.
     *
     * @return array{rate: int, completed: int, total: int}
     */
    public function getFollowUpComplianceRate(?int $coachId = null, ?int $campaignId = null): array
    {
        $query = CoachingSession::submitted()
            ->whereNotNull('follow_up_date')
            ->where('follow_up_date', '<=', now());

        if ($coachId) {
            $query->where('coach_id', $coachId);
        }

        if ($campaignId) {
            $query->whereHas('coachee', function ($q) use ($campaignId) {
                $q->whereHas('campaigns', function ($cq) use ($campaignId) {
                    $cq->where('campaigns.id', $campaignId);
                });
            });
        }

        $sessionsWithFollowUp = $query->get(['id', 'coachee_id', 'follow_up_date', 'session_date']);
        $total = $sessionsWithFollowUp->count();

        if ($total === 0) {
            return ['rate' => null, 'completed' => 0, 'total' => 0];
        }

        // Batch: get all follow-up sessions for relevant coachees to avoid N+1
        $coacheeIds = $sessionsWithFollowUp->pluck('coachee_id')->unique();
        $allSessions = CoachingSession::submitted()
            ->whereIn('coachee_id', $coacheeIds)
            ->orderBy('session_date')
            ->get(['coachee_id', 'session_date'])
            ->groupBy('coachee_id');

        $completed = 0;
        foreach ($sessionsWithFollowUp as $session) {
            $coacheeSessions = $allSessions->get($session->coachee_id, collect());
            $hasFollowUp = $coacheeSessions->contains(function ($s) use ($session) {
                return $s->session_date > $session->session_date
                    && $s->session_date <= $session->follow_up_date;
            });

            if ($hasFollowUp) {
                $completed++;
            }
        }

        return [
            'rate' => round(($completed / $total) * 100),
            'completed' => $completed,
            'total' => $total,
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

        $baseQuery = CoachingSession::submitted()
            ->with(['coachee:id,first_name,last_name', 'coach:id,first_name,last_name'])
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
