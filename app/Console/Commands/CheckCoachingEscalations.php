<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\User;
use App\Services\CoachingDashboardService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class CheckCoachingEscalations extends Command
{
    protected $signature = 'coaching:check-escalations';

    protected $description = 'Check for overdue coaching follow-ups and at-risk agents, send notifications';

    public function __construct(
        private CoachingDashboardService $dashboardService,
        private NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->checkOverdueFollowUps();
        $this->checkAtRiskAgents();

        return self::SUCCESS;
    }

    private function checkOverdueFollowUps(): void
    {
        $overdueSessions = CoachingSession::whereNotNull('follow_up_date')
            ->where('follow_up_date', '<', now()->startOfDay())
            ->where('follow_up_date', '>=', now()->subDays(7)->startOfDay())
            ->with(['coach:id,first_name,last_name', 'coachee:id,first_name,last_name'])
            ->get();

        foreach ($overdueSessions as $session) {
            $hasSubsequentSession = CoachingSession::where('coachee_id', $session->coachee_id)
                ->where('session_date', '>', $session->session_date)
                ->exists();

            if (! $hasSubsequentSession && $session->coach) {
                $coacheeName = $session->coachee
                    ? "{$session->coachee->first_name} {$session->coachee->last_name}"
                    : 'Agent';
                $daysOverdue = now()->startOfDay()->diffInDays($session->follow_up_date);

                $this->notificationService->create(
                    $session->coach_id,
                    'coaching_escalation',
                    'Overdue Coaching Follow-up',
                    "Follow-up for {$coacheeName} is {$daysOverdue} day(s) overdue (due {$session->follow_up_date->format('M d, Y')}).",
                    [
                        'session_id' => $session->id,
                        'coachee_name' => $coacheeName,
                        'follow_up_date' => $session->follow_up_date->toDateString(),
                        'link' => route('coaching.sessions.show', $session->id),
                    ]
                );

                $this->info("Notified coach for overdue follow-up: {$coacheeName}");
            }
        }
    }

    private function checkAtRiskAgents(): void
    {
        $agents = User::where('role', 'Agent')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->with('activeSchedule')
            ->get();

        foreach ($agents as $agent) {
            $status = $this->dashboardService->getCoachingStatus($agent->id);

            if ($status === CoachingDashboardService::STATUS_PLEASE_COACH_ASAP) {
                $campaignId = $agent->activeSchedule?->campaign_id;

                if (! $campaignId) {
                    continue;
                }

                $teamLeadIds = Campaign::find($campaignId)
                    ?->teamLeads()
                    ->pluck('users.id')
                    ->toArray() ?? [];

                foreach ($teamLeadIds as $tlId) {
                    $this->notificationService->create(
                        $tlId,
                        'coaching_escalation',
                        'Agent Needs Immediate Coaching',
                        "{$agent->first_name} {$agent->last_name} has not been coached and requires immediate attention.",
                        [
                            'agent_id' => $agent->id,
                            'agent_name' => "{$agent->first_name} {$agent->last_name}",
                            'coaching_status' => $status,
                            'link' => route('coaching.dashboard'),
                        ]
                    );
                }

                $this->info("Escalated: {$agent->first_name} {$agent->last_name} - {$status}");
            }
        }
    }
}
