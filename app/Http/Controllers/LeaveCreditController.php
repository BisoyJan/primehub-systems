<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLeaveCarryoverRequest;
use App\Http\Requests\UpdateLeaveCreditRequest;
use App\Jobs\GenerateLeaveCreditsExportExcel;
use App\Models\Campaign;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveCreditService;
use App\Services\PermissionService;
use App\Services\SplCreditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class LeaveCreditController extends Controller
{
    public function __construct(
        protected LeaveCreditService $leaveCreditService,
        protected SplCreditService $splCreditService
    ) {}

    /**
     * Display all employees' leave credits balances.
     * Only accessible by Super Admin, Admin, HR, and Team Lead.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Check if user has permission to view all leave credits
        if (! app(PermissionService::class)->userHasPermission($user, 'leave_credits.view_all')) {
            // Redirect to their own credits page if they have view_own permission
            if (app(PermissionService::class)->userHasPermission($user, 'leave_credits.view_own')) {
                return redirect()->route('leave-requests.credits.show', $user->id);
            }
            abort(403, 'Unauthorized action.');
        }

        // Determine Team Lead's campaign (if applicable)
        $teamLeadCampaignId = null;
        if ($user->role === 'Team Lead') {
            $activeSchedule = $user->activeSchedule;
            if ($activeSchedule && $activeSchedule->campaign_id) {
                $teamLeadCampaignId = $activeSchedule->campaign_id;
            }
        }

        $year = (int) $request->input('year', now()->year);
        $search = $request->input('search', '');
        $roleFilter = $request->input('role', '');
        $eligibilityFilter = $request->input('eligibility', '');
        $campaignFilter = $request->input('campaign_id', '');

        // Calculate eligibility cutoff date (6 months ago from start of selected year)
        $eligibilityCutoffDate = Carbon::create($year, 1, 1)->subMonths(6);

        // Get all users with hire dates
        $query = User::whereNotNull('hired_date')
            ->where('is_approved', true);

        // Apply search filter - check if it's an ID (numeric) or name/email search
        if ($search) {
            if (is_numeric($search)) {
                // Search by user ID
                $query->where('id', $search);
            } else {
                // Search by name or email
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }
        }

        // Apply role filter
        if ($roleFilter) {
            $query->where('role', $roleFilter);
        }

        // Apply eligibility filter at query level
        // Eligible = hired_date is at least 6 months before start of selected year
        if ($eligibilityFilter === 'eligible') {
            $query->where('hired_date', '<=', $eligibilityCutoffDate);
        } elseif ($eligibilityFilter === 'not_eligible') {
            $query->where('hired_date', '>', $eligibilityCutoffDate);
        } elseif ($eligibilityFilter === 'pending_regularization') {
            // Filter for users with pending first regularization transfers
            // These are users hired in a PREVIOUS year who regularize in the SELECTED year
            // and haven't had their first regularization processed yet
            $previousYear = $year - 1;
            $query->whereRaw('YEAR(hired_date) = ?', [$previousYear])
                ->whereRaw('YEAR(DATE_ADD(hired_date, INTERVAL 6 MONTH)) = ?', [$year])
                ->whereNotIn('id', function ($subQuery) {
                    $subQuery->select('user_id')
                        ->from('leave_credit_carryovers')
                        ->where('is_first_regularization', true);
                });
        }

        // Apply campaign filter - auto-filter for Team Leads
        $campaignIdToFilter = $campaignFilter ?: null;
        if (! $campaignIdToFilter && $user->role === 'Team Lead' && $teamLeadCampaignId) {
            $campaignIdToFilter = $teamLeadCampaignId;
        }
        if ($campaignIdToFilter) {
            $query->whereHas('activeSchedule', function ($q) use ($campaignIdToFilter) {
                $q->where('campaign_id', $campaignIdToFilter);
            });
        }

        $users = $query->orderBy('first_name')->orderBy('last_name')->paginate(20)->withQueryString();

        // Batch query: pending leave requests (VL/SL) grouped by user for this year
        $pendingByUser = LeaveRequest::whereIn('user_id', $users->pluck('id'))
            ->where('status', 'pending')
            ->whereIn('leave_type', LeaveRequest::CREDITED_LEAVE_TYPES)
            ->whereYear('start_date', $year)
            ->selectRaw('user_id, COUNT(*) as pending_count, COALESCE(SUM(days_requested), 0) as pending_credits')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id')
            ->map(fn ($row) => [
                'pending_count' => (int) $row->pending_count,
                'pending_credits' => (float) $row->pending_credits,
            ]);

        // Get leave credits data for each user
        $creditsData = $users->through(function ($user) use ($year, $pendingByUser) {
            $summary = $this->leaveCreditService->getSummary($user, $year);
            // Get carryover FROM this year (what will be/was carried over to next year)
            $carryoverSummary = $this->leaveCreditService->getCarryoverFromYearSummary($user, $year);
            // Get carryover TO this year (what was received from previous year)
            $carryoverReceived = LeaveCreditCarryover::forUser($user->id)
                ->toYear($year)
                ->first();
            // Get regularization info for pending credit transfer display
            $regularizationInfo = $this->leaveCreditService->getRegularizationInfo($user, $year);
            $hireDate = Carbon::parse($user->hired_date);
            $eligibilityDate = $hireDate->copy()->addMonths(6);
            $hireYear = $hireDate->year;

            // Only show carryover forward if:
            // 1. It has been processed (year has ended)
            // 2. Has credits > 0
            // 3. User is regularized OR was already regularized before the carryover year
            // For users hired in the carryover year who are NOT yet regularized,
            // show as "Pending Transfer" instead of "Carryover Forward"
            $showCarryoverForward = false;
            if ($carryoverSummary['is_processed'] && $carryoverSummary['carryover_credits'] > 0) {
                if ($hireYear === $year) {
                    // User was hired in the carryover FROM year - only show if NOW regularized
                    $showCarryoverForward = $regularizationInfo['is_regularized'];
                } else {
                    // User was already regularized before this year - always show
                    $showCarryoverForward = true;
                }
            }

            // Check if there are pending credits from previous year awaiting regularization
            $pendingRegularizationCredits = $regularizationInfo['pending_credits'];
            $showPendingRegularization = $pendingRegularizationCredits['credits'] > 0
                && ! $regularizationInfo['has_first_regularization'];

            // For users hired in the displayed year who are NOT yet regularized,
            // show their credits as pending transfer (from carryover record)
            $showPendingTransferFromThisYear = false;
            if ($hireYear === $year && ! $regularizationInfo['is_regularized'] && $carryoverSummary['is_processed']) {
                $showPendingTransferFromThisYear = true;
            }

            // Only show carryover received if user is regularized for that carryover
            // For users hired in the carryover source year, only show after regularization
            $showCarryoverReceived = false;
            if ($carryoverReceived) {
                if ($carryoverReceived->from_year === $hireYear) {
                    // User was hired in the carryover source year - only show if NOW regularized
                    $showCarryoverReceived = $regularizationInfo['is_regularized'];
                } else {
                    // User was already regularized before the carryover year - always show
                    $showCarryoverReceived = true;
                }
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'hired_date' => $user->hired_date->format('Y-m-d'),
                'is_eligible' => $summary['is_eligible'],
                'eligibility_date' => $eligibilityDate->format('Y-m-d'),
                'monthly_rate' => $summary['monthly_rate'],
                'total_earned' => $summary['total_earned'],
                'total_used' => $summary['total_used'],
                'balance' => $summary['balance'],
                // Carryover received INTO this year (from previous year)
                // Only show if user is regularized (for first regularization carryovers)
                'carryover_received' => $showCarryoverReceived && $carryoverReceived ? [
                    'id' => $carryoverReceived->id,
                    'credits' => (float) $carryoverReceived->carryover_credits,
                    'from_year' => $carryoverReceived->from_year,
                    'is_first_regularization' => (bool) $carryoverReceived->is_first_regularization,
                    'cash_converted' => (bool) $carryoverReceived->cash_converted,
                ] : null,
                // Carryover forward FROM this year (to next year)
                'carryover_forward' => $showCarryoverForward ? [
                    'credits' => $carryoverSummary['carryover_credits'],
                    'to_year' => $carryoverSummary['to_year'],
                    'is_processed' => $carryoverSummary['is_processed'],
                    'is_expired' => $carryoverSummary['is_expired'] ?? false,
                    'cash_converted' => $carryoverSummary['cash_converted'],
                ] : null,
                // Keep old 'carryover' key for backwards compatibility
                'carryover' => $showCarryoverForward ? [
                    'credits' => $carryoverSummary['carryover_credits'],
                    'to_year' => $carryoverSummary['to_year'],
                    'is_processed' => $carryoverSummary['is_processed'],
                    'is_expired' => $carryoverSummary['is_expired'] ?? false,
                    'cash_converted' => $carryoverSummary['cash_converted'],
                ] : null,
                // Regularization info for pending credit transfer
                'regularization' => [
                    'is_regularized' => $regularizationInfo['is_regularized'],
                    'regularization_date' => $regularizationInfo['regularization_date'],
                    'hire_year' => $regularizationInfo['hire_year'],
                    'days_until_regularization' => $regularizationInfo['days_until_regularization'],
                    'has_first_regularization' => $regularizationInfo['has_first_regularization'],
                    // Show pending credits either from previous year OR from current year (if viewing hire year)
                    // For first regularization, show ALL credits (not capped)
                    'pending_credits' => $showPendingRegularization ? [
                        'from_year' => $pendingRegularizationCredits['year'],
                        'to_year' => $hireYear + 1, // Credits will transfer TO the year after hire (regularization year)
                        'credits' => $pendingRegularizationCredits['credits'],
                        'months_accrued' => $pendingRegularizationCredits['months_accrued'],
                    ] : ($showPendingTransferFromThisYear ? [
                        'from_year' => $year, // Credits are FROM the current view year
                        'to_year' => $year + 1, // Credits will transfer TO next year
                        // Show full earned credits (not capped) since first regularization transfers ALL
                        'credits' => LeaveCredit::forUser($user->id)->forYear($year)->sum('credits_balance'),
                        'months_accrued' => $summary['credits_by_month']->count(),
                    ] : null),
                ],
                // Pending VL/SL leave requests not yet deducted
                'pending_count' => $pendingByUser[$user->id]['pending_count'] ?? 0,
                'pending_credits' => (float) ($pendingByUser[$user->id]['pending_credits'] ?? 0),
            ];
        });

        // Get all employees for search popover
        $allEmployees = User::whereNotNull('hired_date')
            ->where('is_approved', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);

        // Get campaigns for filter dropdown
        $campaigns = Campaign::orderBy('name')->get(['id', 'name']);

        return Inertia::render('FormRequest/Leave/Credits/Index', [
            'creditsData' => $creditsData,
            'allEmployees' => $allEmployees,
            'campaigns' => $campaigns,
            'teamLeadCampaignId' => $teamLeadCampaignId,
            'filters' => [
                'year' => (int) $year,
                'search' => $search,
                'role' => $roleFilter,
                'eligibility' => $eligibilityFilter,
                'campaign_id' => $campaignFilter,
            ],
            'availableYears' => range(now()->year, 2024, -1),
            'canEdit' => app(PermissionService::class)->userHasPermission(auth()->user(), 'leave_credits.edit'),
        ]);
    }

    /**
     * Show leave credits history page for a specific user.
     * All roles can view their own credits.
     * Super Admin, Admin, HR, Team Lead can view any user's credits.
     */
    public function show(Request $request, User $user)
    {
        $authUser = auth()->user();
        $permissionService = app(PermissionService::class);

        // Check permissions
        $canViewAll = $permissionService->userHasPermission($authUser, 'leave_credits.view_all');
        $canViewOwn = $permissionService->userHasPermission($authUser, 'leave_credits.view_own');
        $isViewingOwnCredits = $authUser->id === $user->id;

        // If viewing someone else's credits, must have view_all permission
        if (! $isViewingOwnCredits && ! $canViewAll) {
            abort(403, 'Unauthorized action.');
        }

        // If viewing own credits, must have at least view_own permission
        if ($isViewingOwnCredits && ! $canViewOwn && ! $canViewAll) {
            abort(403, 'Unauthorized action.');
        }

        $year = $request->input('year', now()->year);

        // Get monthly credits (excluding month 0 which is carryover - shown separately)
        $monthlyCredits = LeaveCredit::forUser($user->id)
            ->forYear($year)
            ->where('month', '>', 0) // Exclude carryover (month 0)
            ->orderBy('month')
            ->get()
            ->map(function ($credit) {
                return [
                    'id' => $credit->id,
                    'month' => $credit->month,
                    'month_name' => Carbon::create(null, $credit->month)->format('F'),
                    'credits_earned' => (float) $credit->credits_earned,
                    'credits_used' => (float) $credit->credits_used,
                    'credits_balance' => (float) $credit->credits_balance,
                    'accrued_at' => $credit->accrued_at->format('Y-m-d'),
                ];
            });

        // Get carryover credit record (month 0) to show deductions from carryover
        $carryoverCredit = LeaveCredit::forUser($user->id)
            ->forYear($year)
            ->where('month', 0)
            ->first();

        // Get leave requests that used credits this year
        $leaveRequests = LeaveRequest::where('user_id', $user->id)
            ->where('credits_year', $year)
            ->where('status', 'approved')
            ->whereNotNull('credits_deducted')
            ->where('credits_deducted', '>', 0)
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(function ($request) {
                return [
                    'id' => $request->id,
                    'leave_type' => $request->leave_type,
                    'start_date' => $request->start_date->format('Y-m-d'),
                    'end_date' => $request->end_date->format('Y-m-d'),
                    'days_requested' => (float) $request->days_requested,
                    'credits_deducted' => (float) $request->credits_deducted,
                    'approved_at' => $request->reviewed_at?->format('Y-m-d'),
                    'has_partial_denial' => (bool) $request->has_partial_denial,
                    'approved_days' => $request->approved_days !== null ? (float) $request->approved_days : null,
                ];
            });

        // Get summary
        $summary = $this->leaveCreditService->getSummary($user, $year);

        // Get carryover summary FROM this year (what will be/was carried over to next year)
        $carryoverSummary = $this->leaveCreditService->getCarryoverFromYearSummary($user, (int) $year);

        // Get carryover received INTO this year (from previous year)
        $carryoverReceived = LeaveCreditCarryover::forUser($user->id)
            ->toYear((int) $year)
            ->first();

        // Get regularization info
        $regularizationInfo = $this->leaveCreditService->getRegularizationInfo($user, (int) $year);
        $hireDate = Carbon::parse($user->hired_date);
        $hireYear = $hireDate->year;

        // Determine if carryover received should be shown (only if regularized or not first reg carryover)
        $showCarryoverReceived = false;
        if ($carryoverReceived) {
            if ($carryoverReceived->from_year === $hireYear) {
                // User was hired in the carryover source year - only show if NOW regularized
                $showCarryoverReceived = $regularizationInfo['is_regularized'];
            } else {
                // User was already regularized before the carryover year - always show
                $showCarryoverReceived = true;
            }
        }

        // Determine if carryover forward (for cash conversion) should be shown
        // - Don't show if user is viewing their hire year and not yet regularized (pending transfer)
        // - Don't show if the carryover is a first regularization transfer (not for cash)
        $showCarryoverForward = true;
        if ($hireYear === (int) $year && ! $regularizationInfo['is_regularized']) {
            // User is viewing their hire year and not yet regularized - no carryover forward
            $showCarryoverForward = false;
        }

        // Also don't show if the carryover is a first regularization (all credits transfer for leave, not cash)
        if ($carryoverSummary && ($carryoverSummary['is_first_regularization'] ?? false)) {
            $showCarryoverForward = false;
        }

        return Inertia::render('FormRequest/Leave/Credits/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'hired_date' => $user->hired_date->format('Y-m-d'),
                'avatar' => $user->avatar,
                'avatar_url' => $user->avatar_url,
            ],
            'year' => (int) $year,
            'summary' => [
                'is_eligible' => $summary['is_eligible'],
                'eligibility_date' => $summary['eligibility_date']?->format('Y-m-d'),
                'monthly_rate' => $summary['monthly_rate'],
                'total_earned' => $summary['total_earned'],
                'total_used' => $summary['total_used'],
                'balance' => $summary['balance'],
            ],
            'carryoverSummary' => $showCarryoverForward ? $carryoverSummary : null,
            'carryoverReceived' => $showCarryoverReceived && $carryoverReceived ? [
                'id' => $carryoverReceived->id,
                'credits' => (float) $carryoverReceived->carryover_credits,
                'credits_used' => $carryoverCredit ? (float) $carryoverCredit->credits_used : 0,
                'credits_balance' => $carryoverCredit ? (float) $carryoverCredit->credits_balance : (float) $carryoverReceived->carryover_credits,
                'from_year' => $carryoverReceived->from_year,
                'is_first_regularization' => (bool) $carryoverReceived->is_first_regularization,
                'cash_converted' => (bool) $carryoverReceived->cash_converted,
                'cash_converted_at' => $carryoverReceived->cash_converted_at?->format('M d, Y'),
            ] : null,
            'regularization' => [
                'is_regularized' => $regularizationInfo['is_regularized'],
                'regularization_date' => $regularizationInfo['regularization_date'],
                'hire_year' => $hireYear,
            ],
            'monthlyCredits' => $monthlyCredits,
            'leaveRequests' => $leaveRequests,
            'availableYears' => range(now()->year, 2024, -1),
            'canViewAll' => $canViewAll,
            'canEdit' => $canViewAll && app(PermissionService::class)->userHasPermission(auth()->user(), 'leave_credits.edit'),
            'pendingLeaveInfo' => $this->leaveCreditService->getPendingLeaveInfo($user->id, (int) $year),
            'creditEditHistory' => $canViewAll ? $this->getCreditEditHistory($user->id, (int) $year) : [],
            'splCreditsSummary' => $user->is_solo_parent ? $this->splCreditService->getSummary($user, (int) $year) : null,
        ]);
    }

    /**
     * Get credit edit history from activity logs for a specific user and year.
     */
    private function getCreditEditHistory(int $userId, int $year): array
    {
        $activities = Activity::where('log_name', 'leave-credits')
            ->whereIn('event', ['carryover_manually_adjusted', 'credit_manually_adjusted'])
            ->where('properties->user_id', $userId)
            ->where('properties->year', $year)
            ->with('causer:id,first_name,last_name')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // Collect IDs of activities that have been reverted
        $revertedIds = $activities
            ->filter(fn (Activity $a) => ! empty($a->properties['reverted_activity_id']))
            ->pluck('properties.reverted_activity_id')
            ->toArray();

        return $activities
            ->map(function (Activity $activity) use ($revertedIds) {
                $props = $activity->properties;

                return [
                    'id' => $activity->id,
                    'event' => $activity->event,
                    'description' => $activity->description,
                    'reason' => $props['reason'] ?? null,
                    'editor_name' => $activity->causer
                        ? trim($activity->causer->first_name.' '.$activity->causer->last_name)
                        : (isset($props['editor_id']) ? 'User #'.$props['editor_id'] : 'System'),
                    'old_value' => $activity->event === 'carryover_manually_adjusted'
                        ? ($props['old_carryover'] ?? null)
                        : ($props['old_earned'] ?? null),
                    'new_value' => $activity->event === 'carryover_manually_adjusted'
                        ? ($props['new_carryover'] ?? null)
                        : ($props['new_earned'] ?? null),
                    'month' => $props['month'] ?? null,
                    'unabsorbed' => (float) ($props['unabsorbed'] ?? 0),
                    'is_revert' => ! empty($props['is_revert']),
                    'is_reverted' => in_array($activity->id, $revertedIds),
                    'created_at' => $activity->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();
    }

    /**
     * Update carryover credits for a user.
     */
    public function updateCarryover(UpdateLeaveCarryoverRequest $request, User $user)
    {
        $year = $request->input('year', now()->year);

        $carryover = LeaveCreditCarryover::forUser($user->id)
            ->toYear($year)
            ->first();

        if (! $carryover) {
            return redirect()->back()->with('message', 'No carryover record found for this year.')->with('type', 'error');
        }

        try {
            $result = DB::transaction(function () use ($carryover, $request) {
                return $this->leaveCreditService->updateCarryoverCredits(
                    $carryover,
                    $request->input('carryover_credits'),
                    $request->input('reason'),
                    auth()->id()
                );
            });

            $message = "Carryover credits updated from {$result['old_value']} to {$result['new_value']}.";
            if ($result['unabsorbed'] > 0) {
                $message .= " Warning: {$result['unabsorbed']} excess credits could not be redistributed.";
            }

            // Append pending request warning
            $pendingInfo = $this->leaveCreditService->getPendingLeaveInfo($user->id, (int) $year);
            if ($pendingInfo['pending_count'] > 0) {
                $message .= " Note: {$user->name} has {$pendingInfo['pending_count']} pending leave request(s) totaling {$pendingInfo['pending_credits']} credit(s) not yet deducted.";
            }

            return redirect()->back()->with('message', $message)->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('Credits Update Carryover Error: '.$e->getMessage());

            return redirect()->back()->with('message', 'Failed to update carryover credits.')->with('type', 'error');
        }
    }

    /**
     * Update monthly credit earned amount for a user.
     */
    public function updateMonthly(UpdateLeaveCreditRequest $request, User $user, LeaveCredit $leaveCredit)
    {
        if ($leaveCredit->user_id !== $user->id) {
            abort(403, 'Credit record does not belong to this user.');
        }

        try {
            $result = DB::transaction(function () use ($leaveCredit, $request) {
                return $this->leaveCreditService->updateMonthlyCredit(
                    $leaveCredit,
                    $request->input('credits_earned'),
                    $request->input('reason'),
                    auth()->id()
                );
            });

            $message = "Monthly credit updated from {$result['old_earned']} to {$result['new_earned']}.";
            if ($result['unabsorbed'] > 0) {
                $message .= " Warning: {$result['unabsorbed']} excess credits could not be redistributed.";
            }

            // Append pending request warning
            $year = $leaveCredit->year;
            $pendingInfo = $this->leaveCreditService->getPendingLeaveInfo($user->id, $year);
            if ($pendingInfo['pending_count'] > 0) {
                $message .= " Note: {$user->name} has {$pendingInfo['pending_count']} pending leave request(s) totaling {$pendingInfo['pending_credits']} credit(s) not yet deducted.";
            }

            return redirect()->back()->with('message', $message)->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('Credits Update Monthly Error: '.$e->getMessage());

            return redirect()->back()->with('message', 'Failed to update monthly credits.')->with('type', 'error');
        }
    }

    /**
     * Revert the most recent credit edit for a user.
     *
     * Supports cascade reverts: if the latest entry is itself a revert,
     * undoing it will delete the revert entry and restore the previous state,
     * revealing the original edit as the new latest entry.
     */
    public function revertEdit(Request $request, User $user, Activity $activity): RedirectResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $props = $activity->properties;

        // Validate the activity belongs to this user
        if ((int) ($props['user_id'] ?? 0) !== $user->id) {
            return redirect()->back()->with('message', 'This edit does not belong to the specified user.')->with('type', 'error');
        }

        // Validate event type
        if (! in_array($activity->event, ['carryover_manually_adjusted', 'credit_manually_adjusted'])) {
            return redirect()->back()->with('message', 'This activity log entry is not a credit edit.')->with('type', 'error');
        }

        $year = (int) ($props['year'] ?? now()->year);

        // Ensure this is the most recent edit for this user+year
        $latestActivity = Activity::where('log_name', 'leave-credits')
            ->whereIn('event', ['carryover_manually_adjusted', 'credit_manually_adjusted'])
            ->where('properties->user_id', $user->id)
            ->where('properties->year', $year)
            ->orderByDesc('id')
            ->first();

        if (! $latestActivity || $latestActivity->id !== $activity->id) {
            return redirect()->back()->with('message', 'Only the most recent credit edit can be reverted.')->with('type', 'error');
        }

        $isUndoingRevert = ! empty($props['is_revert']);

        $revertedAt = $activity->created_at->format('M d, Y h:i A');
        $userReason = $request->input('reason');
        $reason = $isUndoingRevert
            ? "Undid revert from {$revertedAt}"
            : "Reverted edit from {$revertedAt}";
        if ($userReason) {
            $reason .= " — {$userReason}";
        }

        try {
            if ($activity->event === 'carryover_manually_adjusted') {
                $oldValue = (float) ($props['old_carryover'] ?? 0);

                $carryover = LeaveCreditCarryover::forUser($user->id)
                    ->toYear($year)
                    ->first();

                if (! $carryover) {
                    return redirect()->back()->with('message', 'No carryover record found for this year.')->with('type', 'error');
                }

                $result = DB::transaction(function () use ($carryover, $oldValue, $reason, $activity, $isUndoingRevert) {
                    $result = $this->leaveCreditService->updateCarryoverCredits(
                        $carryover,
                        $oldValue,
                        $reason,
                        auth()->id()
                    );

                    if ($isUndoingRevert) {
                        // Cascade: delete the revert entry and the new activity entry
                        /** @var Activity|null $newActivity */
                        $newActivity = Activity::where('log_name', 'leave-credits')
                            ->where('event', 'carryover_manually_adjusted')
                            ->where('properties->user_id', $carryover->user_id)
                            ->orderByDesc('id')
                            ->first();

                        if ($newActivity) {
                            $newActivity->delete();
                        }

                        // Clear is_reverted on the original entry
                        $originalId = $activity->properties['reverted_activity_id'] ?? null;
                        if ($originalId) {
                            $original = Activity::find($originalId);
                            if ($original) {
                                // No changes needed — is_reverted is computed dynamically in getCreditEditHistory
                            }
                        }

                        $activity->delete();
                    } else {
                        // Normal revert: mark the new activity as a revert
                        /** @var Activity|null $revertActivity */
                        $revertActivity = Activity::where('log_name', 'leave-credits')
                            ->where('event', 'carryover_manually_adjusted')
                            ->where('properties->user_id', $carryover->user_id)
                            ->orderByDesc('id')
                            ->first();

                        if ($revertActivity) {
                            $revertActivity->properties = $revertActivity->properties->merge([
                                'is_revert' => true,
                                'reverted_activity_id' => $activity->id,
                            ]);
                            $revertActivity->save();
                        }
                    }

                    return $result;
                });

                $message = $isUndoingRevert
                    ? "Revert undone. Carryover credits restored from {$result['old_value']} to {$result['new_value']}."
                    : "Carryover credits reverted from {$result['old_value']} to {$result['new_value']}.";
                if ($result['unabsorbed'] > 0) {
                    $message .= " Warning: {$result['unabsorbed']} excess credits could not be redistributed.";
                }
            } else {
                $oldValue = (float) ($props['old_earned'] ?? 0);
                $month = (int) ($props['month'] ?? 0);

                $leaveCredit = LeaveCredit::where('user_id', $user->id)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->first();

                if (! $leaveCredit) {
                    return redirect()->back()->with('message', 'No monthly credit record found for this period.')->with('type', 'error');
                }

                $result = DB::transaction(function () use ($leaveCredit, $oldValue, $reason, $activity, $isUndoingRevert) {
                    $result = $this->leaveCreditService->updateMonthlyCredit(
                        $leaveCredit,
                        $oldValue,
                        $reason,
                        auth()->id()
                    );

                    if ($isUndoingRevert) {
                        // Cascade: delete the revert entry and the new activity entry
                        /** @var Activity|null $newActivity */
                        $newActivity = Activity::where('log_name', 'leave-credits')
                            ->where('event', 'credit_manually_adjusted')
                            ->where('properties->user_id', $leaveCredit->user_id)
                            ->orderByDesc('id')
                            ->first();

                        if ($newActivity) {
                            $newActivity->delete();
                        }

                        $activity->delete();
                    } else {
                        // Normal revert: mark the new activity as a revert
                        /** @var Activity|null $revertActivity */
                        $revertActivity = Activity::where('log_name', 'leave-credits')
                            ->where('event', 'credit_manually_adjusted')
                            ->where('properties->user_id', $leaveCredit->user_id)
                            ->orderByDesc('id')
                            ->first();

                        if ($revertActivity) {
                            $revertActivity->properties = $revertActivity->properties->merge([
                                'is_revert' => true,
                                'reverted_activity_id' => $activity->id,
                            ]);
                            $revertActivity->save();
                        }
                    }

                    return $result;
                });

                $message = $isUndoingRevert
                    ? "Revert undone. Monthly credit (Month {$month}) restored from {$result['old_earned']} to {$result['new_earned']}."
                    : "Monthly credit (Month {$month}) reverted from {$result['old_earned']} to {$result['new_earned']}.";
                if ($result['unabsorbed'] > 0) {
                    $message .= " Warning: {$result['unabsorbed']} excess credits could not be redistributed.";
                }
            }

            return redirect()->back()->with('message', $message)->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('Credits Revert Edit Error: '.$e->getMessage());

            return redirect()->back()->with('message', 'Failed to revert credit edit.')->with('type', 'error');
        }
    }

    /**
     * Start export job for leave credits.
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $request->validate([
            'year' => 'required|integer|min:2020|max:'.(now()->year + 1),
        ]);

        $year = $request->input('year');
        $jobId = Str::uuid()->toString();

        // Initialize cache for progress tracking
        Cache::put("leave_credits_export_job:{$jobId}", [
            'percent' => 0,
            'status' => 'Starting export...',
            'finished' => false,
            'error' => false,
        ], 3600);

        // Dispatch job synchronously (runs immediately without queue worker)
        dispatch_sync(new GenerateLeaveCreditsExportExcel($jobId, $year));

        // Get the final progress to return download URL
        $progress = Cache::get("leave_credits_export_job:{$jobId}");

        return response()->json([
            'success' => true,
            'job_id' => $jobId,
            'finished' => $progress['finished'] ?? false,
            'downloadUrl' => $progress['downloadUrl'] ?? null,
        ]);
    }

    /**
     * Check export job progress.
     */
    public function exportProgress(Request $request)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $request->validate([
            'job_id' => 'required|string',
        ]);

        $jobId = $request->input('job_id');
        $cacheKey = "leave_credits_export_job:{$jobId}";

        $progress = Cache::get($cacheKey, [
            'percent' => 0,
            'status' => 'Unknown',
            'finished' => false,
            'error' => true,
        ]);

        return response()->json($progress);
    }

    /**
     * Download exported leave credits file.
     */
    public function exportDownload(Request $request, string $filename)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);
        $filePath = storage_path('app/temp/'.$filename);

        Log::info('Leave credits download attempt', [
            'filename' => $filename,
            'filePath' => $filePath,
            'exists' => file_exists($filePath),
        ]);

        if (! file_exists($filePath)) {
            Log::warning('Leave credits file not found', ['filePath' => $filePath]);

            return redirect()->route('leave-requests.credits.index')
                ->with('flash', [
                    'message' => 'File not found or has expired. Please try exporting again.',
                    'type' => 'error',
                ]);
        }

        // Don't delete immediately - let a cleanup job handle old files
        return response()->download($filePath, $filename);
    }

    /**
     * Get regularization management statistics.
     */
    public function getRegularizationStats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $year = $request->input('year', now()->year);

        // Get users needing first regularization
        $usersNeedingRegularization = $this->leaveCreditService->getUsersNeedingFirstRegularization($year);

        // Count users with pending credits
        $usersWithPendingCredits = $usersNeedingRegularization->filter(function ($user) {
            $pendingCredits = $this->leaveCreditService->getPendingRegularizationCredits($user);

            return $pendingCredits && $pendingCredits['credits'] > 0;
        });

        // Get already processed count
        $alreadyProcessedCount = LeaveCreditCarryover::where('is_first_regularization', true)
            ->where('to_year', $year)
            ->count();

        return response()->json([
            'pending_count' => $usersWithPendingCredits->count(),
            'total_eligible' => $usersNeedingRegularization->count(),
            'already_processed' => $alreadyProcessedCount,
            'year' => $year,
        ]);
    }

    /**
     * Process regularization credit transfers.
     */
    public function processRegularization(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $validated = $request->validate([
            'year' => 'nullable|integer|min:2024',
            'user_id' => 'nullable|integer|exists:users,id',
            'dry_run' => 'nullable|boolean',
        ]);

        $year = $validated['year'] ?? now()->year;
        $userId = $validated['user_id'] ?? null;
        $dryRun = $validated['dry_run'] ?? false;

        $results = [
            'processed' => [],
            'skipped' => [],
            'errors' => [],
        ];

        try {
            if ($userId) {
                // Process single user
                $user = User::findOrFail($userId);
                $result = $this->processUserRegularization($user, $dryRun);

                if ($result['status'] === 'processed') {
                    $results['processed'][] = $result;
                } elseif ($result['status'] === 'skipped') {
                    $results['skipped'][] = $result;
                } else {
                    $results['errors'][] = $result;
                }
            } else {
                // Process all eligible users
                $usersNeedingRegularization = $this->leaveCreditService->getUsersNeedingFirstRegularization($year);

                foreach ($usersNeedingRegularization as $user) {
                    $result = $this->processUserRegularization($user, $dryRun);

                    if ($result['status'] === 'processed') {
                        $results['processed'][] = $result;
                    } elseif ($result['status'] === 'skipped') {
                        $results['skipped'][] = $result;
                    } else {
                        $results['errors'][] = $result;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'dry_run' => $dryRun,
                'year' => $year,
                'summary' => [
                    'processed' => count($results['processed']),
                    'skipped' => count($results['skipped']),
                    'errors' => count($results['errors']),
                ],
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            Log::error('Regularization processing error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to process regularization: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process regularization for a single user.
     */
    private function processUserRegularization(User $user, bool $dryRun): array
    {
        $pendingCredits = $this->leaveCreditService->getPendingRegularizationCredits($user);

        if (! $pendingCredits || $pendingCredits['credits'] <= 0) {
            return [
                'status' => 'skipped',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'reason' => 'No pending credits or not eligible',
            ];
        }

        if ($dryRun) {
            return [
                'status' => 'processed',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'credits' => $pendingCredits['credits'],
                'months' => $pendingCredits['months_accrued'],
                'from_year' => $pendingCredits['year'],
                'dry_run' => true,
            ];
        }

        try {
            $carryover = $this->leaveCreditService->processFirstRegularizationTransfer(
                $user,
                auth()->id()
            );

            if ($carryover) {
                return [
                    'status' => 'processed',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'credits' => $carryover->carryover_credits,
                    'from_year' => $carryover->from_year,
                    'to_year' => $carryover->to_year,
                ];
            } else {
                return [
                    'status' => 'skipped',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'reason' => 'Transfer returned null',
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process monthly accruals for all eligible users.
     */
    public function processMonthlyAccruals(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        try {
            $result = $this->leaveCreditService->accrueCreditsForAllUsers();

            return response()->json([
                'success' => true,
                'message' => 'Monthly accruals processed successfully.',
                'summary' => [
                    'processed' => $result['processed'],
                    'skipped' => $result['skipped'],
                    'total_credits' => $result['total_credits'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Monthly accruals processing error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to process monthly accruals: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process year-end carryovers.
     */
    public function processYearEndCarryovers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $validated = $request->validate([
            'from_year' => 'required|integer|min:2024',
        ]);

        $fromYear = $validated['from_year'];

        try {
            $result = $this->leaveCreditService->processAllCarryovers($fromYear, auth()->id());

            return response()->json([
                'success' => true,
                'message' => "Year-end carryovers from {$fromYear} to ".($fromYear + 1).' processed successfully.',
                'summary' => [
                    'processed' => $result['processed'],
                    'skipped' => $result['skipped'],
                    'total_carryover' => $result['total_carryover'],
                    'total_forfeited' => $result['total_forfeited'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Year-end carryover processing error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to process year-end carryovers: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Scan for leave requests with mismatched credits_year (filed in one year, deducted from another).
     * Returns a preview of affected requests and users.
     */
    public function yearMismatchScan(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! in_array($user->role, ['Super Admin', 'Admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $affected = LeaveRequest::whereColumn(DB::raw('YEAR(created_at)'), '!=', 'credits_year')
            ->where('status', 'approved')
            ->whereNotNull('credits_deducted')
            ->where('credits_deducted', '>', 0)
            ->with('user:id,first_name,last_name,email')
            ->orderBy('user_id')
            ->orderBy('start_date')
            ->get();

        $grouped = $affected->groupBy('user_id')->map(function ($requests) {
            $user = $requests->first()->user;

            return [
                'user_id' => $user->id,
                'name' => $user->first_name.' '.$user->last_name,
                'email' => $user->email,
                'requests' => $requests->map(fn ($lr) => [
                    'id' => $lr->id,
                    'leave_type' => $lr->leave_type,
                    'start_date' => $lr->start_date->format('M d, Y'),
                    'end_date' => $lr->end_date->format('M d, Y'),
                    'days_requested' => $lr->days_requested,
                    'credits_deducted' => $lr->credits_deducted,
                    'current_credits_year' => $lr->credits_year,
                    'correct_credits_year' => $lr->created_at->year,
                    'filed_date' => $lr->created_at->format('M d, Y'),
                ])->values()->toArray(),
                'total_to_restore' => $requests->sum('credits_deducted'),
            ];
        })->values()->toArray();

        return response()->json([
            'total_requests' => $affected->count(),
            'total_users' => count($grouped),
            'total_credits' => $affected->sum('credits_deducted'),
            'users' => $grouped,
        ]);
    }

    /**
     * Fix leave requests with mismatched credits_year by restoring credits
     * from the wrong year and re-deducting from the correct year.
     */
    public function yearMismatchFix(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! in_array($user->role, ['Super Admin', 'Admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $affected = LeaveRequest::whereColumn(DB::raw('YEAR(created_at)'), '!=', 'credits_year')
            ->where('status', 'approved')
            ->whereNotNull('credits_deducted')
            ->where('credits_deducted', '>', 0)
            ->get();

        if ($affected->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No mismatched leave requests found.',
                'summary' => ['fixed' => 0, 'total_credits_moved' => 0],
            ]);
        }

        DB::beginTransaction();
        try {
            $fixed = 0;
            $totalCreditsMoved = 0;
            $details = [];

            foreach ($affected as $lr) {
                $correctYear = $lr->created_at->year;
                $wrongYear = $lr->credits_year;
                $creditsMoved = (float) $lr->credits_deducted;

                // 1. Restore credits from the wrong year
                $this->leaveCreditService->restoreCredits($lr);

                // 2. Update credits_year to the correct year
                $lr->update(['credits_year' => $correctYear]);

                // 3. Re-deduct from the correct year
                $lr->refresh();
                $this->leaveCreditService->deductCredits($lr, $correctYear);

                $lr->refresh();
                $fixed++;
                $totalCreditsMoved += $creditsMoved;

                $details[] = [
                    'leave_request_id' => $lr->id,
                    'user_name' => $lr->user->first_name.' '.$lr->user->last_name,
                    'credits_moved' => $creditsMoved,
                    'from_year' => $wrongYear,
                    'to_year' => $correctYear,
                ];
            }

            DB::commit();

            Log::info('Credits year mismatch fix completed', [
                'fixed_by' => $user->id,
                'fixed_count' => $fixed,
                'total_credits_moved' => $totalCreditsMoved,
                'details' => $details,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Fixed {$fixed} leave request(s). {$totalCreditsMoved} credits moved to correct year.",
                'summary' => [
                    'fixed' => $fixed,
                    'total_credits_moved' => $totalCreditsMoved,
                    'details' => $details,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Credits year mismatch fix failed', [
                'error' => $e->getMessage(),
                'attempted_by' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Fix failed: '.$e->getMessage().'. All changes have been rolled back.',
            ], 500);
        }
    }

    /**
     * Process bulk cash conversion for all eligible carryovers.
     * Converts regular carryover credits to cash, making them unusable for VL/SL.
     */
    public function processCashConversions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $validated = $request->validate([
            'year' => 'required|integer|min:2024',
        ]);

        $year = $validated['year'];

        try {
            $result = $this->leaveCreditService->processBulkCashConversion($year, auth()->id());

            return response()->json([
                'success' => true,
                'message' => "Cash conversion processed: {$result['processed']} carryovers converted, {$result['total_converted']} total credits.",
                'summary' => [
                    'processed' => $result['processed'],
                    'skipped' => $result['skipped'],
                    'total_converted' => $result['total_converted'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Cash conversion processing error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to process cash conversions: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Convert a single user's carryover credits to cash.
     */
    public function convertUserCarryover(Request $request, User $user): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $validated = $request->validate([
            'year' => 'required|integer|min:2024',
        ]);

        $year = $validated['year'];

        $carryover = LeaveCreditCarryover::forUser($user->id)
            ->toYear($year)
            ->first();

        if (! $carryover) {
            return response()->json([
                'success' => false,
                'error' => 'No carryover record found for this user and year.',
            ], 404);
        }

        try {
            $result = $this->leaveCreditService->convertCarryoverToCash($carryover, auth()->id());

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['message'],
                ], 422);
            }

            // Check for pending leave requests that may be affected
            $pendingInfo = $this->leaveCreditService->getPendingLeaveInfo($user->id, $year);
            $pendingWarning = null;
            if ($pendingInfo['pending_count'] > 0) {
                $pendingWarning = "{$user->name} has {$pendingInfo['pending_count']} pending leave request(s) totaling {$pendingInfo['pending_credits']} credit(s). Those requests may have insufficient credits upon approval.";
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'].($pendingWarning ? " Warning: {$pendingWarning}" : ''),
                'credits_converted' => $result['credits_converted'],
                'pending_warning' => $pendingWarning,
            ]);
        } catch (\Exception $e) {
            Log::error('User cash conversion error: '.$e->getMessage(), [
                'user_id' => $user->id,
                'year' => $year,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to convert carryover: '.$e->getMessage(),
            ], 500);
        }
    }
}
