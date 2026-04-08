<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CoachingSession;
use App\Models\EmployeeSchedule;
use App\Models\ItConcern;
use App\Models\LeaveRequest;
use App\Models\MedicationRequest;
use App\Models\PcMaintenance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class LoginDigestService
{
    /**
     * Get a role-specific login digest with actionable item counts.
     *
     * Returns a lightweight summary (COUNT queries only) for the login dialog.
     * Cached per-user for 60 seconds to avoid repeated queries on refresh.
     *
     * @return array{greeting: string, items: array<int, array{key: string, label: string, count: int, route: string, icon: string, priority: string}>, total_actionable: int}
     */
    public function getDigest(User $user): array
    {
        return Cache::remember(
            "login_digest_{$user->id}",
            60,
            fn () => $this->buildDigest($user)
        );
    }

    protected function buildDigest(User $user): array
    {
        $role = $user->role;
        $items = [];

        match ($role) {
            'Super Admin', 'Admin' => $items = $this->getAdminDigest($user),
            'HR' => $items = $this->getHrDigest(),
            'IT' => $items = $this->getItDigest(),
            'Team Lead' => $items = $this->getTeamLeadDigest($user),
            'Agent' => $items = $this->getAgentDigest($user),
            'Utility' => $items = $this->getUtilityDigest($user),
            default => $items = [],
        };

        // Filter out items with zero counts
        $items = array_values(array_filter($items, fn (array $item) => $item['count'] > 0));

        // Sort by priority: critical → high → medium → low
        $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($items, fn (array $a, array $b) => ($priorityOrder[$a['priority']] ?? 4) <=> ($priorityOrder[$b['priority']] ?? 4));

        return [
            'greeting' => $this->buildGreeting($user),
            'items' => $items,
            'total_actionable' => array_sum(array_column($items, 'count')),
        ];
    }

    protected function buildGreeting(User $user): string
    {
        $hour = (int) now()->format('H');
        $timeOfDay = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        return "{$timeOfDay}, {$user->first_name}!";
    }

    /**
     * @return array<int, array{key: string, label: string, count: int, route: string, icon: string, priority: string}>
     */
    protected function getAdminDigest(User $user): array
    {
        $pendingLeaves = LeaveRequest::where('status', 'pending')
            ->whereNull('admin_approved_by')
            ->count();

        $pendingCoachingReviews = CoachingSession::where('compliance_status', 'For_Review')
            ->count();

        $overdueMaintenance = PcMaintenance::where('status', 'overdue')
            ->count();

        $pendingItConcerns = ItConcern::whereIn('status', ['pending', 'in_progress'])
            ->count();

        $pendingUndertimeApprovals = Attendance::pendingUndertimeApproval()->count();

        $pendingMedication = MedicationRequest::where('status', 'pending')->count();

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);
        $totalActiveAgents = User::where('role', 'Agent')
            ->where('is_active', true)
            ->where('is_approved', true)
            ->count();
        $coachedThisWeek = CoachingSession::whereBetween('session_date', [$weekStart, $weekEnd])
            ->distinct('coachee_id')
            ->count('coachee_id');
        $notCoachedCount = max(0, $totalActiveAgents - $coachedThisWeek);

        return [
            [
                'key' => 'pending_leaves',
                'label' => 'Pending Leave Approvals',
                'count' => $pendingLeaves,
                'route' => 'leave-requests.index',
                'icon' => 'calendar',
                'priority' => $pendingLeaves > 5 ? 'critical' : 'high',
            ],
            [
                'key' => 'coaching_reviews',
                'label' => 'Coaching Compliance Reviews',
                'count' => $pendingCoachingReviews,
                'route' => 'coaching.dashboard',
                'icon' => 'clipboard-check',
                'priority' => 'high',
            ],
            [
                'key' => 'overdue_maintenance',
                'label' => 'Overdue PC Maintenance',
                'count' => $overdueMaintenance,
                'route' => 'pc-maintenance.index',
                'icon' => 'wrench',
                'priority' => $overdueMaintenance > 0 ? 'high' : 'low',
            ],
            [
                'key' => 'pending_it_concerns',
                'label' => 'Unresolved IT Concerns',
                'count' => $pendingItConcerns,
                'route' => 'it-concerns.index',
                'icon' => 'alert-triangle',
                'priority' => 'medium',
            ],
            [
                'key' => 'pending_undertime',
                'label' => 'Pending Undertime Approvals',
                'count' => $pendingUndertimeApprovals,
                'route' => 'attendance.index',
                'icon' => 'clock',
                'priority' => 'medium',
            ],
            [
                'key' => 'pending_medication',
                'label' => 'Pending Medication Requests',
                'count' => $pendingMedication,
                'route' => 'medication-requests.index',
                'icon' => 'pill',
                'priority' => 'medium',
            ],
            [
                'key' => 'not_coached_this_week',
                'label' => 'Agents Not Coached This Week',
                'count' => $notCoachedCount,
                'route' => 'coaching.dashboard',
                'icon' => 'users',
                'priority' => 'low',
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, count: int, route: string, icon: string, priority: string}>
     */
    protected function getHrDigest(): array
    {
        $pendingLeaves = LeaveRequest::where('status', 'pending')
            ->whereNull('hr_approved_by')
            ->count();

        $pendingCoachingReviews = CoachingSession::where('compliance_status', 'For_Review')
            ->count();

        $pendingMedication = MedicationRequest::where('status', 'pending')->count();

        return [
            [
                'key' => 'pending_leaves',
                'label' => 'Pending Leave Approvals',
                'count' => $pendingLeaves,
                'route' => 'leave-requests.index',
                'icon' => 'calendar',
                'priority' => $pendingLeaves > 5 ? 'critical' : 'high',
            ],
            [
                'key' => 'coaching_reviews',
                'label' => 'Coaching Compliance Reviews',
                'count' => $pendingCoachingReviews,
                'route' => 'coaching.dashboard',
                'icon' => 'clipboard-check',
                'priority' => 'high',
            ],
            [
                'key' => 'pending_medication',
                'label' => 'Pending Medication Requests',
                'count' => $pendingMedication,
                'route' => 'medication-requests.index',
                'icon' => 'pill',
                'priority' => 'medium',
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, count: int, route: string, icon: string, priority: string}>
     */
    protected function getItDigest(): array
    {
        $pendingConcerns = ItConcern::where('status', 'pending')->count();
        $inProgressConcerns = ItConcern::where('status', 'in_progress')->count();

        $overdueMaintenance = PcMaintenance::where('status', 'overdue')->count();

        return [
            [
                'key' => 'pending_it_concerns',
                'label' => 'Pending IT Concerns',
                'count' => $pendingConcerns,
                'route' => 'it-concerns.index',
                'icon' => 'alert-triangle',
                'priority' => $pendingConcerns > 3 ? 'critical' : 'high',
            ],
            [
                'key' => 'in_progress_concerns',
                'label' => 'In-Progress IT Concerns',
                'count' => $inProgressConcerns,
                'route' => 'it-concerns.index',
                'icon' => 'loader',
                'priority' => 'medium',
            ],
            [
                'key' => 'overdue_maintenance',
                'label' => 'Overdue PC Maintenance',
                'count' => $overdueMaintenance,
                'route' => 'pc-maintenance.index',
                'icon' => 'wrench',
                'priority' => $overdueMaintenance > 0 ? 'high' : 'low',
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, count: int, route: string, icon: string, priority: string}>
     */
    protected function getTeamLeadDigest(User $user): array
    {
        $tlCampaignIds = EmployeeSchedule::where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('campaign_id')
            ->filter()
            ->toArray();

        // Pending TL leave approvals (agents in their campaigns)
        $pendingTlLeaves = 0;
        if (! empty($tlCampaignIds)) {
            $pendingTlLeaves = LeaveRequest::where('status', 'pending')
                ->where('requires_tl_approval', true)
                ->whereNull('tl_approved_by')
                ->where(function ($q) {
                    $q->whereNull('tl_rejected')
                        ->orWhere('tl_rejected', false);
                })
                ->whereHas('user.employeeSchedules', function ($q) use ($tlCampaignIds) {
                    $q->where('is_active', true)
                        ->whereIn('campaign_id', $tlCampaignIds);
                })
                ->count();
        }

        // Coaching follow-ups due within 7 days
        $followUpsDue = CoachingSession::where('coach_id', $user->id)
            ->whereNotNull('follow_up_date')
            ->whereBetween('follow_up_date', [Carbon::today(), Carbon::today()->addDays(7)])
            ->count();

        // Agents not coached this week in their campaigns
        $notCoachedCount = 0;
        if (! empty($tlCampaignIds)) {
            $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
            $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);

            $agentIds = EmployeeSchedule::whereIn('campaign_id', $tlCampaignIds)
                ->where('is_active', true)
                ->whereHas('user', function ($q) {
                    $q->where('role', 'Agent')
                        ->where('is_active', true)
                        ->where('is_approved', true);
                })
                ->pluck('user_id')
                ->unique();

            $coachedIds = CoachingSession::whereBetween('session_date', [$weekStart, $weekEnd])
                ->whereIn('coachee_id', $agentIds)
                ->pluck('coachee_id')
                ->unique();

            $notCoachedCount = $agentIds->diff($coachedIds)->count();
        }

        // Pending coaching acknowledgements for sessions they coached
        $pendingAcks = CoachingSession::where('coach_id', $user->id)
            ->where('ack_status', 'Pending')
            ->count();

        // Own pending coaching acknowledgements (as coachee)
        $ownPendingAcks = CoachingSession::where('coachee_id', $user->id)
            ->where('ack_status', 'Pending')
            ->count();

        return [
            [
                'key' => 'pending_tl_leaves',
                'label' => 'Leave Requests Awaiting Your Approval',
                'count' => $pendingTlLeaves,
                'route' => 'leave-requests.index',
                'icon' => 'calendar',
                'priority' => $pendingTlLeaves > 3 ? 'critical' : 'high',
            ],
            [
                'key' => 'coaching_follow_ups',
                'label' => 'Coaching Follow-Ups Due (7 Days)',
                'count' => $followUpsDue,
                'route' => 'coaching.dashboard',
                'icon' => 'clipboard-check',
                'priority' => 'high',
            ],
            [
                'key' => 'not_coached_this_week',
                'label' => 'Agents Not Coached This Week',
                'count' => $notCoachedCount,
                'route' => 'coaching.dashboard',
                'icon' => 'users',
                'priority' => $notCoachedCount > 5 ? 'high' : 'medium',
            ],
            [
                'key' => 'pending_coaching_acks',
                'label' => 'Unacknowledged Coaching Sessions',
                'count' => $pendingAcks,
                'route' => 'coaching.dashboard',
                'icon' => 'message-square',
                'priority' => 'medium',
            ],
            [
                'key' => 'own_pending_acks',
                'label' => 'Your Pending Coaching Acknowledgements',
                'count' => $ownPendingAcks,
                'route' => 'coaching.dashboard',
                'icon' => 'bell',
                'priority' => $ownPendingAcks > 0 ? 'high' : 'low',
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, count: int, route: string, icon: string, priority: string}>
     */
    protected function getAgentDigest(User $user): array
    {
        $pendingAcks = CoachingSession::where('coachee_id', $user->id)
            ->where('ack_status', 'Pending')
            ->count();

        $pendingLeaves = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $openConcerns = ItConcern::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        $followUps = CoachingSession::where('coachee_id', $user->id)
            ->whereNotNull('follow_up_date')
            ->whereBetween('follow_up_date', [Carbon::today(), Carbon::today()->addDays(7)])
            ->count();

        return [
            [
                'key' => 'pending_coaching_acks',
                'label' => 'Pending Coaching Acknowledgements',
                'count' => $pendingAcks,
                'route' => 'coaching.dashboard',
                'icon' => 'clipboard-check',
                'priority' => $pendingAcks > 0 ? 'high' : 'low',
            ],
            [
                'key' => 'pending_leaves',
                'label' => 'Your Pending Leave Requests',
                'count' => $pendingLeaves,
                'route' => 'leave-requests.index',
                'icon' => 'calendar',
                'priority' => 'medium',
            ],
            [
                'key' => 'open_it_concerns',
                'label' => 'Your Open IT Concerns',
                'count' => $openConcerns,
                'route' => 'it-concerns.index',
                'icon' => 'alert-triangle',
                'priority' => 'medium',
            ],
            [
                'key' => 'coaching_follow_ups',
                'label' => 'Upcoming Coaching Follow-Ups',
                'count' => $followUps,
                'route' => 'coaching.dashboard',
                'icon' => 'message-square',
                'priority' => 'low',
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, count: int, route: string, icon: string, priority: string}>
     */
    protected function getUtilityDigest(User $user): array
    {
        $pendingLeaves = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $openConcerns = ItConcern::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        return [
            [
                'key' => 'pending_leaves',
                'label' => 'Your Pending Leave Requests',
                'count' => $pendingLeaves,
                'route' => 'leave-requests.index',
                'icon' => 'calendar',
                'priority' => 'medium',
            ],
            [
                'key' => 'open_it_concerns',
                'label' => 'Your Open IT Concerns',
                'count' => $openConcerns,
                'route' => 'it-concerns.index',
                'icon' => 'alert-triangle',
                'priority' => 'medium',
            ],
        ];
    }
}
