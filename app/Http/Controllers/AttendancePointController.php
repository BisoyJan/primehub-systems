<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendancePointRequest;
use App\Http\Requests\UpdateAttendancePointRequest;
use App\Http\Traits\RedirectsWithFlashMessages;
use App\Jobs\GenerateAttendancePointsExportExcel;
use App\Jobs\GenerateAllAttendancePointsExportExcel;
use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\Campaign;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Carbon\Carbon;

class AttendancePointController extends Controller
{
    use RedirectsWithFlashMessages;

    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', AttendancePoint::class);

        $user = auth()->user();

        // Determine Team Lead's campaign (if applicable)
        $teamLeadCampaignId = null;
        if ($user->role === 'Team Lead') {
            $activeSchedule = $user->activeSchedule;
            if ($activeSchedule && $activeSchedule->campaign_id) {
                $teamLeadCampaignId = $activeSchedule->campaign_id;
            }
        }

        // Redirect restricted roles to their own show page
        $restrictedRoles = ['Agent', 'IT', 'Utility'];
        if (in_array($user->role, $restrictedRoles)) {
            return redirect()->route('attendance-points.show', ['user' => auth()->id()]);
        }

        $query = AttendancePoint::with(['user', 'attendance', 'excusedBy', 'createdBy'])
            ->orderBy('shift_date', 'desc');

        if (true) {
            // Only allow user_id filter for non-restricted roles
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
        }

        if ($request->filled('point_type')) {
            $query->where('point_type', $request->point_type);
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->active()->nonExpired();
            } elseif ($request->status === 'excused') {
                $query->where('is_excused', true);
            } elseif ($request->status === 'expired') {
                $query->expired();
            }
        }

        // Campaign filter - auto-filter for Team Leads
        $campaignIdToFilter = ($request->filled('campaign_id') && $request->campaign_id !== 'all') ? $request->campaign_id : null;
        if (!$campaignIdToFilter && $user->role === 'Team Lead' && $teamLeadCampaignId) {
            $campaignIdToFilter = $teamLeadCampaignId;
        }
        if ($campaignIdToFilter) {
            $query->whereHas('user.activeSchedule', function ($q) use ($campaignIdToFilter) {
                $q->where('campaign_id', $campaignIdToFilter);
            });
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->dateRange($request->date_from, $request->date_to);
        }

        // Add expiring_soon filter - points expiring within 30 days
        if ($request->boolean('expiring_soon')) {
            $query->where('is_expired', false)
                  ->where('expires_at', '<=', Carbon::now()->addDays(30))
                  ->where('expires_at', '>=', Carbon::now());
        }

        // Add gbro_eligible filter
        if ($request->boolean('gbro_eligible')) {
            $query->where('eligible_for_gbro', true)
                  ->where('is_excused', false)
                  ->where('is_expired', false);
        }

        $points = $query->paginate(25)->appends($request->except('page'));

        $users = User::orderBy('first_name')->get();
        $campaigns = Campaign::orderBy('name')->get();

        // Pass user_id for stats calculation when restricted
        $statsUserId = in_array(auth()->user()->role, $restrictedRoles) ? auth()->id() : null;
        $stats = $this->calculateStats($request, $statsUserId);

        return Inertia::render('Attendance/Points/Index', [
            'points' => $points,
            'users' => $users,
            'campaigns' => $campaigns,
            'stats' => $stats,
            'teamLeadCampaignId' => $teamLeadCampaignId,
            'filters' => [
                'user_id' => $request->user_id,
                'campaign_id' => $request->campaign_id,
                'point_type' => $request->point_type,
                'status' => $request->status,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'expiring_soon' => $request->boolean('expiring_soon') ? 'true' : null,
                'gbro_eligible' => $request->boolean('gbro_eligible') ? 'true' : null,
            ],
        ]);
    }

    public function show(User $user, Request $request)
    {
        // Authorization: Users can only view their own points unless they're admin/HR/Team Lead
        $currentUser = $request->user();
        if ($currentUser->id !== $user->id && !in_array($currentUser->role, ['Admin', 'Super Admin', 'HR', 'Team Lead'])) {
            abort(403, 'Unauthorized to view other user points');
        }

        $showAll = $request->boolean('show_all', false);

        $startDate = $request->filled('date_from')
            ? Carbon::parse($request->date_from)
            : Carbon::now()->startOfMonth();

        $endDate = $request->filled('date_to')
            ? Carbon::parse($request->date_to)
            : Carbon::now()->endOfMonth();

        $pointsQuery = AttendancePoint::with(['attendance', 'excusedBy'])
            ->where('user_id', $user->id);

        // Only apply date range if not showing all
        if (!$showAll) {
            $pointsQuery->dateRange($startDate, $endDate);
        }

        $points = $pointsQuery->orderBy('shift_date', 'desc')->get();

        $totals = [
            'total_points' => $points->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'excused_points' => $points->where('is_excused', true)->sum('points'),
            'expired_points' => $points->where('is_expired', true)->sum('points'),
            'by_type' => [
                'whole_day_absence' => $points->where('point_type', 'whole_day_absence')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'half_day_absence' => $points->where('point_type', 'half_day_absence')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'undertime' => $points->where('point_type', 'undertime')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'undertime_more_than_hour' => $points->where('point_type', 'undertime_more_than_hour')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'tardy' => $points->where('point_type', 'tardy')->where('is_excused', false)->where('is_expired', false)->sum('points'),
            ],
            'count_by_type' => [
                'whole_day_absence' => $points->where('point_type', 'whole_day_absence')->where('is_excused', false)->where('is_expired', false)->count(),
                'half_day_absence' => $points->where('point_type', 'half_day_absence')->where('is_excused', false)->where('is_expired', false)->count(),
                'undertime' => $points->where('point_type', 'undertime')->where('is_excused', false)->where('is_expired', false)->count(),
                'undertime_more_than_hour' => $points->where('point_type', 'undertime_more_than_hour')->where('is_excused', false)->where('is_expired', false)->count(),
                'tardy' => $points->where('point_type', 'tardy')->where('is_excused', false)->where('is_expired', false)->count(),
            ],
        ];

        // Calculate GBRO statistics
        // Note: Last violation date includes excused points (GBRO clock doesn't reset just because a point was excused)
        $lastViolationDate = AttendancePoint::where('user_id', $user->id)
            ->where('is_expired', false)
            ->max('shift_date');

        // Get the most recent GBRO application date for this user
        $lastGbroDate = AttendancePoint::where('user_id', $user->id)
            ->whereNotNull('gbro_applied_at')
            ->max('gbro_applied_at');

        $daysClean = 0;
        $daysUntilGbro = 60;
        $eligiblePointsCount = 0;
        $eligiblePointsSum = 0;
        $gbroReferenceDate = null;
        $gbroReferenceType = null;

        if ($lastViolationDate) {
            // The GBRO clock starts from the MORE RECENT of:
            // 1. The last GBRO application date, OR
            // 2. The last violation date
            $lastViolationCarbon = Carbon::parse($lastViolationDate);
            $gbroReferenceDate = $lastViolationDate;
            $gbroReferenceType = 'violation';

            if ($lastGbroDate) {
                $lastGbroCarbon = Carbon::parse($lastGbroDate);
                if ($lastGbroCarbon->greaterThan($lastViolationCarbon)) {
                    $gbroReferenceDate = $lastGbroDate;
                    $gbroReferenceType = 'gbro';
                }
            }

            $daysClean = Carbon::parse($gbroReferenceDate)->diffInDays(Carbon::now());
            $daysUntilGbro = max(0, 60 - $daysClean);

            // Get the last 2 active (non-excused) points eligible for GBRO deduction
            $eligiblePoints = AttendancePoint::where('user_id', $user->id)
                ->where('is_excused', false)
                ->where('is_expired', false)
                ->where('eligible_for_gbro', true)
                ->orderBy('shift_date', 'desc')
                ->limit(2)
                ->get();

            $eligiblePointsCount = $eligiblePoints->count();
            $eligiblePointsSum = $eligiblePoints->sum('points');
        }

        return Inertia::render('Attendance/Points/Show', [
            'user' => $user,
            'points' => $points,
            'totals' => $totals,
            'dateRange' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'gbroStats' => [
                'days_clean' => $daysClean,
                'days_until_gbro' => $daysUntilGbro,
                'eligible_points_count' => $eligiblePointsCount,
                'eligible_points_sum' => (float) $eligiblePointsSum,
                'last_violation_date' => $lastViolationDate,
                'last_gbro_date' => $lastGbroDate,
                'gbro_reference_date' => $gbroReferenceDate,
                'gbro_reference_type' => $gbroReferenceType,
                'is_gbro_ready' => $daysClean >= 60 && $eligiblePointsCount > 0,
            ],
            'filters' => [
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'show_all' => $showAll,
            ],
        ]);
    }

    public function excuse(Request $request, AttendancePoint $point)
    {
        // Authorization: Only Admin, Super Admin, or HR can excuse points
        if (!in_array($request->user()->role, ['Admin', 'Super Admin', 'HR'])) {
            abort(403, 'Unauthorized to excuse points');
        }

        $request->validate([
            'excuse_reason' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        $userId = $point->user_id;

        $point->update([
            'is_excused' => true,
            'excused_by' => $request->user()->id,
            'excused_at' => now(),
            'excuse_reason' => $request->excuse_reason,
            'notes' => $request->notes,
        ]);

        // Cascade recalculate GBRO dates after excusing (excused points are excluded from GBRO)
        $this->cascadeRecalculateGbro($userId);

        return redirect()->back()->with('success', 'Attendance point excused successfully.');
    }

    public function unexcuse(Request $request, AttendancePoint $point)
    {
        // Authorization: Only Admin, Super Admin, or HR can unexcuse points
        if (!in_array($request->user()->role, ['Admin', 'Super Admin', 'HR'])) {
            abort(403, 'Unauthorized to unexcuse points');
        }

        $userId = $point->user_id;

        $point->update([
            'is_excused' => false,
            'excused_by' => null,
            'excused_at' => null,
            'excuse_reason' => null,
        ]);

        // Cascade recalculate GBRO dates after unexcusing
        $this->cascadeRecalculateGbro($userId);

        return redirect()->back()->with('success', 'Excuse removed successfully.');
    }

    /**
     * Recalculate GBRO dates for a specific user.
     * Performs a full cascade recalculation of all GBRO expirations.
     */
    public function recalculateGbro(Request $request, User $user)
    {
        // Authorization: Only Admin, Super Admin, or HR can recalculate GBRO
        if (!in_array($request->user()->role, ['Admin', 'Super Admin', 'HR'])) {
            abort(403, 'Unauthorized to recalculate GBRO');
        }

        try {
            $this->cascadeRecalculateGbro($user->id);

            // Get summary of results
            $activeCount = AttendancePoint::where('user_id', $user->id)
                ->where('is_expired', false)
                ->where('eligible_for_gbro', true)
                ->count();

            $expiredCount = AttendancePoint::where('user_id', $user->id)
                ->where('is_expired', true)
                ->where('expiration_type', 'gbro')
                ->count();

            return redirect()->back()->with('success',
                "GBRO recalculated for {$user->full_name}. Active GBRO points: {$activeCount}, Expired via GBRO: {$expiredCount}");
        } catch (\Exception $e) {
            Log::error('AttendancePointController recalculateGbro Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to recalculate GBRO dates.');
        }
    }

    /**
     * Store a new manual attendance point
     */
    public function store(StoreAttendancePointRequest $request)
    {
        try {
            $userId = $request->user_id;

            DB::transaction(function () use ($request) {
                $pointType = $request->point_type;
                $isAdvised = $request->boolean('is_advised', false);
                $shiftDate = Carbon::parse($request->shift_date);

                // Delete any existing points for the same user and date to prevent duplicates
                // This handles cases where:
                // 1. A manual point already exists for this date
                // 2. An auto-generated point exists from attendance verification
                AttendancePoint::where('user_id', $request->user_id)
                    ->where('shift_date', $request->shift_date)
                    ->delete();

                // Determine if this is a NCNS/FTN type (1-year expiration, not GBRO eligible)
                $isNcnsOrFtn = $pointType === 'whole_day_absence' && !$isAdvised;
                $expiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);
                $isGbroEligible = !$isNcnsOrFtn;

                // Generate violation details if not provided
                $violationDetails = $request->violation_details;
                if (empty($violationDetails)) {
                    $violationDetails = $this->generateManualViolationDetails(
                        $pointType,
                        $isAdvised,
                        $request->tardy_minutes,
                        $request->undertime_minutes
                    );
                }

                // Build the point data (no GBRO expiration check here - cascade will handle it)
                $pointData = [
                    'user_id' => $request->user_id,
                    'attendance_id' => null, // Manual entries don't have attendance records
                    'shift_date' => $request->shift_date,
                    'point_type' => $pointType,
                    'points' => AttendancePoint::POINT_VALUES[$pointType] ?? 0,
                    'status' => null,
                    'is_advised' => $isAdvised,
                    'is_manual' => true,
                    'created_by' => $request->user()->id,
                    'notes' => $request->notes,
                    'violation_details' => $violationDetails,
                    'tardy_minutes' => $request->tardy_minutes,
                    'undertime_minutes' => $request->undertime_minutes,
                    'expires_at' => $expiresAt,
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                    'eligible_for_gbro' => $isGbroEligible,
                    'gbro_expires_at' => null, // Will be set by cascade recalculation
                ];

                $point = AttendancePoint::create($pointData);

                // Send notification to the employee about the manually created attendance point
                $this->notificationService->notifyManualAttendancePoint(
                    $request->user_id,
                    $pointType,
                    Carbon::parse($request->shift_date)->format('M d, Y'),
                    $point->points ?? 0
                );
            });

            // Cascade recalculate ALL GBRO expirations for this user
            // This handles backdated points and ensures correct GBRO state
            $this->cascadeRecalculateGbro($userId);

            return $this->redirectWithFlash('attendance-points.index', 'Manual attendance point created successfully. Employee notified.');
        } catch (\Exception $e) {
            Log::error('AttendancePointController Store Error: ' . $e->getMessage());
            return $this->redirectWithFlash('attendance-points.index', 'Failed to create manual attendance point.', 'error');
        }
    }

    /**
     * Update a manual attendance point
     */
    public function update(UpdateAttendancePointRequest $request, AttendancePoint $point)
    {
        // Ensure it's a manual entry
        if (!$point->is_manual) {
            return $this->backWithFlash('Cannot edit auto-generated attendance points.', 'error');
        }

        try {
            $userId = $point->user_id;

            DB::transaction(function () use ($request, $point) {
                $pointType = $request->point_type;
                $isAdvised = $request->boolean('is_advised', false);
                $shiftDate = Carbon::parse($request->shift_date);

                // Recalculate expiration
                $isNcnsOrFtn = $pointType === 'whole_day_absence' && !$isAdvised;
                $expiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);

                // Generate violation details if not provided
                $violationDetails = $request->violation_details;
                if (empty($violationDetails)) {
                    $violationDetails = $this->generateManualViolationDetails(
                        $pointType,
                        $isAdvised,
                        $request->tardy_minutes,
                        $request->undertime_minutes
                    );
                }

                $point->update([
                    'user_id' => $request->user_id,
                    'shift_date' => $request->shift_date,
                    'point_type' => $pointType,
                    'points' => AttendancePoint::POINT_VALUES[$pointType] ?? 0,
                    'is_advised' => $isAdvised,
                    'notes' => $request->notes,
                    'violation_details' => $violationDetails,
                    'tardy_minutes' => $request->tardy_minutes,
                    'undertime_minutes' => $request->undertime_minutes,
                    'expires_at' => $expiresAt,
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                    'eligible_for_gbro' => !$isNcnsOrFtn,
                ]);

                // Send notification to the employee about the updated attendance point
                $this->notificationService->notifyManualAttendancePoint(
                    $request->user_id,
                    $pointType,
                    Carbon::parse($request->shift_date)->format('M d, Y'),
                    $point->fresh()->points ?? 0
                );
            });

            // Recalculate GBRO dates for the user after edit (cascade handles backdated edits)
            $this->cascadeRecalculateGbro($userId);

            return $this->backWithFlash('Manual attendance point updated successfully. Employee notified.');
        } catch (\Exception $e) {
            Log::error('AttendancePointController Update Error: ' . $e->getMessage());
            return $this->backWithFlash('Failed to update manual attendance point.', 'error');
        }
    }

    /**
     * Delete a manual attendance point
     */
    public function destroy(AttendancePoint $point)
    {
        $this->authorize('delete', $point);

        // Ensure it's a manual entry
        if (!$point->is_manual) {
            return $this->backWithFlash('Cannot delete auto-generated attendance points.', 'error');
        }

        try {
            $userId = $point->user_id;
            $point->delete();

            // Cascade recalculate GBRO dates for the user after deletion
            $this->cascadeRecalculateGbro($userId);

            return $this->redirectWithFlash('attendance-points.index', 'Manual attendance point deleted successfully.');
        } catch (\Exception $e) {
            Log::error('AttendancePointController Destroy Error: ' . $e->getMessage());
            return $this->backWithFlash('Failed to delete manual attendance point.', 'error');
        }
    }

    /**
     * Generate violation details for manual entries
     */
    private function generateManualViolationDetails(
        string $pointType,
        bool $isAdvised,
        ?int $tardyMinutes,
        ?int $undertimeMinutes
    ): string {
        return match ($pointType) {
            'whole_day_absence' => $isAdvised
                ? 'Manual Entry: Advised absence (Failed to Notify - FTN)'
                : 'Manual Entry: No Call, No Show (NCNS) - Did not report for work without prior notice',
            'half_day_absence' => 'Manual Entry: Half-day absence recorded',
            'tardy' => sprintf('Manual Entry: Late arrival by %d minutes', $tardyMinutes ?? 0),
            'undertime' => sprintf('Manual Entry: Early departure by %d minutes (up to 1 hour)', $undertimeMinutes ?? 0),
            'undertime_more_than_hour' => sprintf('Manual Entry: Early departure by %d minutes (more than 1 hour)', $undertimeMinutes ?? 0),
            default => 'Manual Entry: Attendance violation',
        };
    }

    /**
     * Cascade recalculate all GBRO expirations for a user.
     * This method performs a full GBRO simulation from scratch:
     * 1. Resets all GBRO-expired points to active
     * 2. Simulates GBRO expirations chronologically
     * 3. After each GBRO, next trigger = previous GBRO date + 60 (unless new violation resets it)
     * 4. Updates all states accordingly
     *
     * Called when inserting/editing/deleting a backdated point to ensure correct GBRO state.
     *
     * @param int $userId The user ID
     * @return void
     */
    private function cascadeRecalculateGbro(int $userId): void
    {
        $batchId = 'cascade_' . now()->format('YmdHis');

        // Step 1: Get ALL GBRO-eligible points (including those expired via GBRO)
        // Include excused points for DATE calculations, but they won't be expired
        $allPoints = AttendancePoint::where('user_id', $userId)
            ->where('eligible_for_gbro', true)
            ->orderBy('shift_date', 'asc')
            ->get();

        if ($allPoints->isEmpty()) {
            return;
        }

        // Step 2: Reset all GBRO-expired points to active (we'll recalculate which should be expired)
        // Only reset non-excused points (excused points don't get expired via GBRO)
        foreach ($allPoints->where('is_excused', false) as $point) {
            if ($point->is_expired && $point->expiration_type === 'gbro') {
                $point->update([
                    'is_expired' => false,
                    'expiration_type' => 'sro', // Reset to SRO pending
                    'expired_at' => null,
                    'gbro_applied_at' => null,
                    'gbro_batch_id' => null,
                ]);
            }
            // Clear GBRO dates - will recalculate
            $point->update(['gbro_expires_at' => null]);
        }

        // Step 3: Simulate GBRO expirations chronologically
        // ALL points (including excused) for date calculations
        // But only non-excused points can be expired
        $allActivePoints = AttendancePoint::where('user_id', $userId)
            ->where('eligible_for_gbro', true)
            ->where('is_expired', false)
            ->orderBy('shift_date', 'asc')
            ->get();

        // Points eligible to be expired via GBRO (non-excused only)
        $activePoints = $allActivePoints->where('is_excused', false)->values();

        $lastGbroDate = null; // Track the last GBRO date for calculating next trigger

        // Continue expiring until no more GBRO triggers
        while ($activePoints->count() >= 1) {
            // After a GBRO, the next scheduled GBRO is lastGbroDate + 60
            // But if there's a NEW violation between lastGbroDate and (lastGbroDate+60),
            // the clock RESETS to that new violation's date.
            // NOTE: Use ALL points (including excused) for date calculations

            $gbroDate = $this->calculateNextGbroDate($allActivePoints, $lastGbroDate);

            if (!$gbroDate) {
                break; // No more GBRO to apply
            }

            // Get the newest 2 NON-EXCUSED active points BEFORE the GBRO trigger date
            // Excused points are protected from GBRO expiration
            $pointsBeforeGbro = $activePoints->filter(function ($p) use ($gbroDate) {
                return Carbon::parse($p->shift_date)->lessThan($gbroDate);
            })->sortByDesc('shift_date')->values();

            // Expire the last 2 (newest) non-excused points
            $toExpire = $pointsBeforeGbro->take(2);

            if ($toExpire->isEmpty()) {
                break;
            }

            foreach ($toExpire as $point) {
                $point->update([
                    'is_expired' => true,
                    'expiration_type' => 'gbro',
                    'expired_at' => $gbroDate->format('Y-m-d'),
                    'gbro_expires_at' => $gbroDate->format('Y-m-d'),
                    'gbro_applied_at' => $gbroDate->format('Y-m-d'),
                    'gbro_batch_id' => $batchId,
                ]);
            }

            // Remember this GBRO date for next iteration
            $lastGbroDate = $gbroDate;

            // Refresh active points list (exclude newly expired)
            $expiredIds = $toExpire->pluck('id')->toArray();
            $allActivePoints = $allActivePoints->reject(fn($p) => in_array($p->id, $expiredIds))->values();
            $activePoints = $activePoints->reject(fn($p) => in_array($p->id, $expiredIds))->values();
        }

        // Step 4: Set GBRO dates for remaining active points (only first 2 newest)
        // If we had a GBRO, use lastGbroDate + 60 as the next GBRO date
        // Otherwise, use the newest point's date + 60
        $this->updateUserGbroExpirationDates($userId, $lastGbroDate);
    }

    /**
     * Calculate the next GBRO date based on current active points and last GBRO.
     *
     * Rules:
     * 1. If no previous GBRO: Find gap > 60 days, GBRO = (newest point before gap) + 60
     * 2. If previous GBRO exists: Next GBRO = lastGbroDate + 60
     *    - BUT if a violation occurred between lastGbroDate and (lastGbroDate+60), RESET
     *    - After reset: GBRO = (newest remaining point) + 60
     *
     * @param \Illuminate\Support\Collection $activePoints
     * @param Carbon|null $lastGbroDate
     * @return Carbon|null The next GBRO date, or null if none
     */
    private function calculateNextGbroDate($activePoints, ?Carbon $lastGbroDate): ?Carbon
    {
        if ($activePoints->isEmpty()) {
            return null;
        }

        $sortedPoints = $activePoints->sortBy('shift_date')->values();
        $newestPoint = $sortedPoints->last();
        $newestDate = Carbon::parse($newestPoint->shift_date)->startOfDay();
        $today = now()->startOfDay();

        if ($lastGbroDate) {
            // We have a previous GBRO - check if there's a violation that resets the clock
            $nextScheduledGbro = $lastGbroDate->copy()->startOfDay()->addDays(60);

            // Find any violation AFTER the last GBRO date
            $violationAfterGbro = $sortedPoints->first(function ($p) use ($lastGbroDate) {
                return Carbon::parse($p->shift_date)->startOfDay()->greaterThan($lastGbroDate->startOfDay());
            });

            if ($violationAfterGbro) {
                $violationDate = Carbon::parse($violationAfterGbro->shift_date)->startOfDay();

                // If violation occurred BEFORE the next scheduled GBRO, clock RESETS
                if ($violationDate->lessThan($nextScheduledGbro)) {
                    // Clock resets to the newest remaining point
                    // New GBRO = (newest point) + 60, if > 60 days have passed
                    $daysFromNewest = $newestDate->diffInDays($today);

                    if ($daysFromNewest > 60) {
                        $newGbro = $newestDate->copy()->addDays(60);
                        if ($newGbro->lessThanOrEqualTo($today)) {
                            return $newGbro;
                        }
                    }
                    return null; // Clock reset but not enough time passed
                }
            }

            // No violation reset - use scheduled GBRO if it has passed
            if ($nextScheduledGbro->lessThanOrEqualTo($today)) {
                return $nextScheduledGbro;
            }
            return null;
        }

        // No previous GBRO - find first trigger based on gap
        // Look for a gap > 60 days between consecutive points
        for ($i = 0; $i < $sortedPoints->count() - 1; $i++) {
            $current = $sortedPoints[$i];
            $next = $sortedPoints[$i + 1];

            $currentDate = Carbon::parse($current->shift_date)->startOfDay();
            $nextDate = Carbon::parse($next->shift_date)->startOfDay();
            $gap = $currentDate->diffInDays($nextDate);

            if ($gap > 60) {
                // Find the NEWEST point before this gap
                $pointsBeforeGap = $sortedPoints->filter(function ($p) use ($nextDate) {
                    return Carbon::parse($p->shift_date)->startOfDay()->lessThan($nextDate);
                })->sortByDesc('shift_date')->first();

                if ($pointsBeforeGap) {
                    $newestBeforeGap = Carbon::parse($pointsBeforeGap->shift_date)->startOfDay();
                    $gbroDate = $newestBeforeGap->copy()->addDays(60);

                    if ($gbroDate->lessThanOrEqualTo($today)) {
                        return $gbroDate;
                    }
                }
            }
        }

        // No gap found between points - check from newest to today
        $daysFromNewest = $newestDate->diffInDays($today);
        if ($daysFromNewest > 60) {
            $gbroDate = $newestDate->copy()->addDays(60);
            if ($gbroDate->lessThanOrEqualTo($today)) {
                return $gbroDate;
            }
        }

        return null;
    }

    /**
     * Update GBRO expiration dates for all active GBRO-eligible points of a user.
     * Called when a new violation occurs, resetting the GBRO clock for all points.
     *
     * @param int $userId The user ID
     * @param Carbon $newViolationDate The date of the new violation (becomes the new reference)
     */
    private function updateUserGbroExpirationDates(int $userId, ?Carbon $referenceDate = null): void
    {
        // Get ALL active GBRO-eligible points (including excused) for date reference
        $allActivePoints = AttendancePoint::where('user_id', $userId)
            ->where('is_expired', false)
            ->where('eligible_for_gbro', true)
            ->whereNull('gbro_applied_at')
            ->orderBy('shift_date', 'desc')
            ->get();

        // Get only non-excused points that can receive GBRO dates
        $activePoints = $allActivePoints->where('is_excused', false)->values();

        if ($activePoints->isEmpty()) {
            return;
        }

        // GBRO Rules:
        // 1. Only the first 2 active NON-EXCUSED points (newest) get a GBRO date
        // 2. GBRO date = reference_date + 60 days
        // 3. Reference date is the newest violation date (INCLUDING excused) or provided date
        // 4. Points beyond the first 2 have NULL gbro_expires_at (not calculated)
        // 5. When first 2 expire, remaining points recalculate based on scheduled GBRO date
        // 6. Excused points: their DATE counts for reference, but they don't get GBRO dates

        // Use provided reference date or the newest violation date (INCLUDING excused)
        $baseDate = $referenceDate ?? Carbon::parse($allActivePoints->first()->shift_date);
        $gbroExpiresAt = $baseDate->copy()->addDays(60)->format('Y-m-d');

        foreach ($activePoints as $index => $point) {
            if ($index < 2) {
                // First 2 points get the GBRO date
                $point->update(['gbro_expires_at' => $gbroExpiresAt]);
            } else {
                // Points beyond first 2 get NULL (not calculated)
                $point->update(['gbro_expires_at' => null]);
            }
        }
    }

    /**
     * Rescan attendance records and regenerate points
     */
    public function rescan(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $startDate = Carbon::parse($request->date_from);
        $endDate = Carbon::parse($request->date_to);

        // Get all attendance records in the date range with issues
        // ONLY process verified attendance records
        $attendances = Attendance::with('user')
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->whereIn('status', ['ncns', 'advised_absence', 'half_day_absence', 'tardy', 'undertime', 'undertime_more_than_hour'])
            ->where('admin_verified', true)
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($attendances as $attendance) {
            // Check if point already exists for this attendance
            $existingPoint = AttendancePoint::where('attendance_id', $attendance->id)->first();

            if ($existingPoint) {
                $skipped++;
                continue;
            }

            // Determine point type and value based on status
            $pointData = $this->determinePointType($attendance);

            if ($pointData) {
                $isNcnsOrFtn = $pointData['type'] === 'whole_day_absence' && !$attendance->is_advised;
                $shiftDate = Carbon::parse($attendance->shift_date);
                $expiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);
                $gbroExpiresAt = $isNcnsOrFtn ? null : $shiftDate->copy()->addDays(60)->format('Y-m-d');

                $violationDetails = $this->generateViolationDetails($attendance);

                AttendancePoint::create([
                    'user_id' => $attendance->user_id,
                    'attendance_id' => $attendance->id,
                    'shift_date' => $attendance->shift_date,
                    'point_type' => $pointData['type'],
                    'points' => $pointData['points'],
                    'status' => $attendance->status,
                    'is_advised' => $attendance->is_advised,
                    'expires_at' => $expiresAt,
                    'gbro_expires_at' => $gbroExpiresAt,
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                    'violation_details' => $violationDetails,
                    'tardy_minutes' => $attendance->tardy_minutes,
                    'undertime_minutes' => $attendance->undertime_minutes,
                    'eligible_for_gbro' => !$isNcnsOrFtn,
                ]);

                // Update all existing active GBRO-eligible points for this user
                if (!$isNcnsOrFtn) {
                    $this->updateUserGbroExpirationDates($attendance->user_id, $shiftDate);
                }

                $created++;
            }
        }

        return redirect()->back()->with('success', "Rescan completed. Created: {$created} points, Skipped: {$skipped} existing points.");
    }

    /**
     * Determine point type and value based on attendance status
     */
    private function determinePointType(Attendance $attendance): ?array
    {
        $type = match ($attendance->status) {
            'ncns', 'advised_absence' => 'whole_day_absence',
            'half_day_absence' => 'half_day_absence',
            'undertime' => 'undertime',
            'undertime_more_than_hour' => 'undertime_more_than_hour',
            'tardy' => 'tardy',
            default => null,
        };

        if (!$type) {
            return null;
        }

        return [
            'type' => $type,
            'points' => AttendancePoint::POINT_VALUES[$type] ?? 0,
        ];
    }

    /**
     * Generate detailed violation description
     */
    private function generateViolationDetails(Attendance $attendance): string
    {
        $scheduledIn = $attendance->scheduled_time_in ? Carbon::parse($attendance->scheduled_time_in)->format('H:i') : 'N/A';
        $scheduledOut = $attendance->scheduled_time_out ? Carbon::parse($attendance->scheduled_time_out)->format('H:i') : 'N/A';
        $actualIn = $attendance->actual_time_in ? $attendance->actual_time_in->format('H:i') : 'No scan';
        $actualOut = $attendance->actual_time_out ? $attendance->actual_time_out->format('H:i') : 'No scan';

        // Get grace period from employee schedule
        $gracePeriod = $attendance->employeeSchedule?->grace_period_minutes ?? 15;

        return match ($attendance->status) {
            'ncns' => $attendance->is_advised
                ? "Failed to Notify (FTN): Employee did not report for work despite being advised. Scheduled: {$scheduledIn} - {$scheduledOut}. No biometric scans recorded."
                : "No Call, No Show (NCNS): Employee did not report for work and did not provide prior notice. Scheduled: {$scheduledIn} - {$scheduledOut}. No biometric scans recorded.",

            'half_day_absence' => sprintf(
                "Half-Day Absence: Arrived %d minutes late (more than %d minutes grace period). Scheduled: %s, Actual: %s.",
                $attendance->tardy_minutes ?? 0,
                $gracePeriod,
                $scheduledIn,
                $actualIn
            ),

            'tardy' => sprintf(
                "Tardy: Arrived %d minutes late. Scheduled time in: %s, Actual time in: %s.",
                $attendance->tardy_minutes ?? 0,
                $scheduledIn,
                $actualIn
            ),

            'undertime' => sprintf(
                "Undertime: Left %d minutes early (up to 1 hour before scheduled end). Scheduled: %s, Actual: %s.",
                $attendance->undertime_minutes ?? 0,
                $scheduledOut,
                $actualOut
            ),

            'undertime_more_than_hour' => sprintf(
                "Undertime (>1 Hour): Left %d minutes early (more than 1 hour before scheduled end). Scheduled: %s, Actual: %s.",
                $attendance->undertime_minutes ?? 0,
                $scheduledOut,
                $actualOut
            ),

            default => sprintf("Attendance violation on %s", Carbon::parse($attendance->shift_date)->format('Y-m-d')),
        };
    }

    private function calculateStats(Request $request, $userId = null)
    {
        $query = AttendancePoint::query();

        // If userId is provided (for restricted users), filter by that user
        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->dateRange($request->date_from, $request->date_to);
        }

        $allPoints = $query->get();

        return [
            'total_points' => $allPoints->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'excused_points' => $allPoints->where('is_excused', true)->sum('points'),
            'expired_points' => $allPoints->where('is_expired', true)->sum('points'),
            'total_violations' => $allPoints->where('is_excused', false)->where('is_expired', false)->count(),
            'by_type' => [
                'whole_day_absence' => $allPoints->where('point_type', 'whole_day_absence')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'half_day_absence' => $allPoints->where('point_type', 'half_day_absence')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'undertime' => $allPoints->where('point_type', 'undertime')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'undertime_more_than_hour' => $allPoints->where('point_type', 'undertime_more_than_hour')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'tardy' => $allPoints->where('point_type', 'tardy')->where('is_excused', false)->where('is_expired', false)->sum('points'),
            ],
            'high_points_employees' => $this->getHighPointsEmployees(),
        ];
    }

    /**
     * Get employees with 6 or more active attendance points
     */
    private function getHighPointsEmployees(): array
    {
        // Get user IDs with 6+ points
        $highPointsUserIds = AttendancePoint::where('is_excused', false)
            ->where('is_expired', false)
            ->selectRaw('user_id, SUM(points) as total_points, COUNT(*) as violations_count')
            ->groupBy('user_id')
            ->havingRaw('SUM(points) >= 6')
            ->orderByDesc('total_points')
            ->pluck('user_id')
            ->toArray();

        if (empty($highPointsUserIds)) {
            return [];
        }

        // Get all active points for these users
        $allPoints = AttendancePoint::with('user')
            ->whereIn('user_id', $highPointsUserIds)
            ->where('is_excused', false)
            ->where('is_expired', false)
            ->orderBy('shift_date', 'desc')
            ->get();

        // Group by user and format
        return collect($highPointsUserIds)->map(function ($userId) use ($allPoints) {
            $userPoints = $allPoints->where('user_id', $userId);
            $user = $userPoints->first()?->user;

            return [
                'user_id' => $userId,
                'user_name' => $user ? ($user->last_name . ', ' . $user->first_name) : 'Unknown',
                'total_points' => round($userPoints->sum('points'), 2),
                'violations_count' => $userPoints->count(),
                'points' => $userPoints->map(function ($point) {
                    return [
                        'id' => $point->id,
                        'shift_date' => $point->shift_date,
                        'point_type' => $point->point_type,
                        'points' => $point->points,
                        'violation_details' => $point->violation_details,
                        'expires_at' => $point->expires_at,
                    ];
                })->values()->toArray(),
            ];
        })->sortByDesc('total_points')->values()->toArray();
    }

    /**
     * Get statistics for a specific user's attendance points
     */
    public function statistics(User $user, Request $request)
    {
        // Authorization: Users can only view their own statistics unless they're admin/HR/Team Lead
        $currentUser = $request->user();
        if ($currentUser->id !== $user->id && !in_array($currentUser->role, ['Admin', 'Super Admin', 'HR', 'Team Lead'])) {
            abort(403, 'Unauthorized to view other user statistics');
        }

        $points = AttendancePoint::where('user_id', $user->id)->get();

        return response()->json([
            'total_points' => $points->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'active_points' => $points->where('is_excused', false)->where('is_expired', false)->count(),
            'expired_points' => $points->where('is_expired', true)->count(),
            'excused_points' => $points->where('is_excused', true)->count(),
            'by_type' => [
                'whole_day_absence' => $points->where('point_type', 'whole_day_absence')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'half_day_absence' => $points->where('point_type', 'half_day_absence')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'undertime' => $points->where('point_type', 'undertime')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'tardy' => $points->where('point_type', 'tardy')->where('is_excused', false)->where('is_expired', false)->sum('points'),
            ],
        ]);
    }

    /**
     * Export attendance points for a specific user
     */
    public function export(User $user, Request $request)
    {
        // Authorization: Users can only export their own points unless they're admin/HR/Team Lead
        $currentUser = $request->user();
        if ($currentUser->id !== $user->id && !in_array($currentUser->role, ['Admin', 'Super Admin', 'HR', 'Team Lead'])) {
            abort(403, 'Unauthorized to export other user points');
        }

        $points = AttendancePoint::where('user_id', $user->id)
            ->with(['attendance', 'excusedBy'])
            ->orderBy('shift_date', 'desc')
            ->get();

        // Generate CSV
        $filename = "attendance-points-{$user->id}-" . now()->format('Y-m-d') . ".csv";

        $handle = fopen('php://temp', 'w');

        // Headers
        fputcsv($handle, [
            'Date',
            'Type',
            'Points',
            'Status',
            'Violation Details',
            'Expires At',
            'Expiration Type',
            'Is Expired',
            'Expired At',
            'Is Excused',
            'Excuse Reason',
            'Excused By',
            'Excused At',
            'Tardy Minutes',
            'Undertime Minutes',
            'GBRO Eligible',
        ]);

        // Data
        foreach ($points as $point) {
            fputcsv($handle, [
                $point->shift_date,
                $point->point_type,
                $point->points,
                $point->is_expired ? 'Expired' : ($point->is_excused ? 'Excused' : 'Active'),
                $point->violation_details,
                $point->expires_at ? Carbon::parse($point->expires_at)->format('Y-m-d') : '',
                $point->expiration_type ?? '',
                $point->is_expired ? 'Yes' : 'No',
                $point->expired_at ? Carbon::parse($point->expired_at)->format('Y-m-d') : '',
                $point->is_excused ? 'Yes' : 'No',
                $point->excuse_reason ?? '',
                $point->excusedBy ? $point->excusedBy->name : '',
                $point->excused_at ? Carbon::parse($point->excused_at)->format('Y-m-d H:i:s') : '',
                $point->tardy_minutes ?? '',
                $point->undertime_minutes ?? '',
                $point->eligible_for_gbro ? 'Yes' : 'No',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Start Excel export job for a specific user's attendance points
     */
    public function startExportExcel(User $user, Request $request)
    {
        // Use policy-based authorization
        // Users can export their own points, or those with export permission can export any user
        $currentUser = $request->user();
        if ($currentUser->id !== $user->id) {
            $this->authorize('export', AttendancePoint::class);
        }

        $jobId = Str::uuid()->toString();

        $job = new GenerateAttendancePointsExportExcel($jobId, $user->id);

        // Use dispatchSync for immediate execution when queue is sync
        if (config('queue.default') === 'sync') {
            Bus::dispatchSync($job);
        } else {
            Bus::dispatch($job);
        }

        return response()->json([
            'jobId' => $jobId,
            'message' => 'Export job started',
        ]);
    }

    /**
     * Check single user export job progress
     */
    public function checkExportExcelStatus(string $jobId)
    {
        $progress = Cache::get("attendance_points_export:{$jobId}");

        if (!$progress) {
            // Job might still be starting, return pending status instead of error
            return response()->json([
                'percent' => 0,
                'status' => 'Initializing...',
                'finished' => false,
                'error' => false,
            ]);
        }

        return response()->json($progress);
    }

    /**
     * Download single user exported Excel file
     */
    public function downloadExportExcel(string $jobId)
    {
        $cacheKey = "attendance_points_export:{$jobId}";
        $progress = Cache::get($cacheKey);

        if (!$progress || !$progress['finished'] || empty($progress['downloadUrl'])) {
            abort(404, 'Export file not found or not ready');
        }

        $tempDir = storage_path('app/temp');
        $files = glob($tempDir . '/' . $jobId . '_*.xlsx');

        if (empty($files)) {
            // Clear cache since file no longer exists
            Cache::forget($cacheKey);
            abort(404, 'Export file not found. Please generate a new export.');
        }

        $filePath = $files[0];
        $filename = $progress['filename'] ?? basename($filePath);

        // Clear cache after download since file will be deleted
        Cache::forget($cacheKey);

        return response()->download($filePath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Start Excel export job for all attendance points (with filters)
     */
    public function startExportAllExcel(Request $request)
    {
        // Use policy-based authorization
        $this->authorize('export', AttendancePoint::class);

        $filters = [
            'user_id' => $request->user_id,
            'point_type' => $request->point_type,
            'status' => $request->status,
            'date_from' => $request->date_from,
            'date_to' => $request->date_to,
            'expiring_soon' => $request->expiring_soon,
            'gbro_eligible' => $request->gbro_eligible,
        ];

        // Check if there are any matching records before starting the export
        $query = AttendancePoint::query();

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['point_type'])) {
            $query->where('point_type', $filters['point_type']);
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->where('is_excused', false)->where('is_expired', false);
            } elseif ($filters['status'] === 'excused') {
                $query->where('is_excused', true);
            } elseif ($filters['status'] === 'expired') {
                $query->where('is_expired', true);
            }
        }

        if (!empty($filters['date_from'])) {
            $query->where('shift_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('shift_date', '<=', $filters['date_to']);
        }

        $recordCount = $query->count();

        if ($recordCount === 0) {
            return response()->json([
                'error' => true,
                'message' => 'No attendance points found matching your selected filters.',
            ], 422);
        }

        $jobId = Str::uuid()->toString();

        $job = new GenerateAllAttendancePointsExportExcel($jobId, $filters);

        // Use dispatchSync for immediate execution when queue is sync
        if (config('queue.default') === 'sync') {
            Bus::dispatchSync($job);
        } else {
            Bus::dispatch($job);
        }

        return response()->json([
            'jobId' => $jobId,
            'message' => 'Export job started',
        ]);
    }

    /**
     * Check all users export job progress
     */
    public function checkExportAllExcelStatus(string $jobId)
    {
        $progress = Cache::get("attendance_points_export_all:{$jobId}");

        if (!$progress) {
            // Job might still be starting, return pending status instead of error
            // Frontend will continue polling until job starts or timeout
            return response()->json([
                'percent' => 0,
                'status' => 'Initializing...',
                'finished' => false,
                'error' => false,
            ]);
        }

        return response()->json($progress);
    }

    /**
     * Download all users exported Excel file
     */
    public function downloadExportAllExcel(string $jobId)
    {
        $cacheKey = "attendance_points_export_all:{$jobId}";
        $progress = Cache::get($cacheKey);

        if (!$progress || !$progress['finished'] || empty($progress['downloadUrl'])) {
            abort(404, 'Export file not found or not ready');
        }

        $tempDir = storage_path('app/temp');
        $files = glob($tempDir . '/' . $jobId . '_*.xlsx');

        if (empty($files)) {
            // Clear cache since file no longer exists
            Cache::forget($cacheKey);
            abort(404, 'Export file not found. Please generate a new export.');
        }

        $filePath = $files[0];
        $filename = $progress['filename'] ?? basename($filePath);

        // Clear cache after download since file will be deleted
        Cache::forget($cacheKey);

        return response()->download($filePath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Get management statistics (duplicates count, pending expirations, etc.)
     */
    public function managementStats()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        // Count duplicates
        $duplicatesCount = DB::table('attendance_points')
            ->select('user_id', 'shift_date', 'point_type', DB::raw('COUNT(*) as count'))
            ->groupBy('user_id', 'shift_date', 'point_type')
            ->having('count', '>', 1)
            ->get()
            ->sum(function ($row) {
                return $row->count - 1; // Count excess entries
            });

        // Count pending expirations (should be expired but not marked)
        $pendingExpirationsCount = AttendancePoint::where('is_expired', false)
            ->where('is_excused', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();

        // Count expired points (only non-excused, as those can be reset)
        $expiredCount = AttendancePoint::where('is_expired', true)
            ->where('is_excused', false)
            ->count();

        // Count verified attendance records that should have points but don't
        // These are attendance records with point-worthy statuses (verified) but no corresponding attendance_point
        $missingPointsCount = Attendance::where('admin_verified', true)
            ->whereIn('status', ['ncns', 'advised_absence', 'half_day_absence', 'tardy', 'undertime', 'undertime_more_than_hour'])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('attendance_points')
                    ->whereColumn('attendance_points.attendance_id', 'attendances.id');
            })
            ->count();

        return response()->json([
            'duplicates_count' => $duplicatesCount,
            'pending_expirations_count' => $pendingExpirationsCount,
            'expired_count' => $expiredCount,
            'missing_points_count' => $missingPointsCount,
        ]);
    }

    /**
     * Remove duplicate attendance points (same user, date, type)
     * Note: Prioritizes keeping excused points when removing duplicates
     */
    public function removeDuplicates()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $duplicates = DB::table('attendance_points')
                ->select('user_id', 'shift_date', 'point_type', DB::raw('COUNT(*) as count'))
                ->groupBy('user_id', 'shift_date', 'point_type')
                ->having('count', '>', 1)
                ->get();

            if ($duplicates->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No duplicate attendance points found.',
                    'removed' => 0,
                ]);
            }

            $totalRemoved = 0;

            foreach ($duplicates as $dup) {
                // Get all duplicates for this combination
                $points = AttendancePoint::where('user_id', $dup->user_id)
                    ->where('shift_date', $dup->shift_date)
                    ->where('point_type', $dup->point_type)
                    ->orderByDesc('is_excused') // Prioritize excused points
                    ->orderBy('id') // Then oldest first
                    ->get();

                // Keep the first one (excused if exists, otherwise oldest)
                $keepId = $points->first()->id;

                // Delete the rest
                $deleted = AttendancePoint::where('user_id', $dup->user_id)
                    ->where('shift_date', $dup->shift_date)
                    ->where('point_type', $dup->point_type)
                    ->where('id', '!=', $keepId)
                    ->delete();

                $totalRemoved += $deleted;
            }

            return response()->json([
                'success' => true,
                'message' => "Removed {$totalRemoved} duplicate attendance points (excused points preserved).",
                'removed' => $totalRemoved,
            ]);
        } catch (\Exception $e) {
            Log::error('AttendancePointController removeDuplicates Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove duplicates.',
            ], 500);
        }
    }

    /**
     * Expire all pending attendance points (without notifications)
     * Supports expiration_type filter: 'sro', 'gbro', or 'both' (default)
     */
    public function expireAllPending(Request $request)
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $expirationType = $request->input('expiration_type', 'both'); // 'sro', 'gbro', or 'both'
            $sroExpired = 0;
            $gbroExpired = 0;

            // ===== STEP 1: Process SRO (Standard Roll Off) =====
            if ($expirationType === 'sro' || $expirationType === 'both') {
                $sroPoints = AttendancePoint::where('is_expired', false)
                    ->where('is_excused', false)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now())
                    ->get();

                foreach ($sroPoints as $point) {
                    $point->markAsExpired('sro');
                    $sroExpired++;
                }
            }

            // ===== STEP 2: Process GBRO (Good Behavior Roll Off) =====
            if ($expirationType === 'gbro' || $expirationType === 'both') {
                $batchId = now()->format('YmdHis');

                // Get all users with active GBRO-eligible points
                $usersWithPoints = User::whereHas('attendancePoints', function ($query) {
                    $query->where('is_expired', false)
                        ->where('is_excused', false)
                        ->where('eligible_for_gbro', true);
                })->get();

                foreach ($usersWithPoints as $user) {
                    // Get all active, non-excused, non-expired, GBRO-eligible points for this user
                    $activeGbroEligiblePoints = $user->attendancePoints()
                        ->where('is_expired', false)
                        ->where('is_excused', false)
                        ->where('eligible_for_gbro', true)
                        ->whereNull('gbro_applied_at')
                        ->orderBy('shift_date', 'desc')
                        ->get();

                    if ($activeGbroEligiblePoints->isEmpty()) {
                        continue;
                    }

                    // Calculate the GBRO reference date for this user
                    $gbroReferenceDate = $this->calculateGbroReferenceDate($user, $activeGbroEligiblePoints);
                    $gbroPredictionDate = $gbroReferenceDate->copy()->addDays(60);

                    // Update gbro_expires_at for all eligible points
                    $this->updateGbroExpiresAt($activeGbroEligiblePoints, $gbroPredictionDate);

                    $daysSinceReference = $gbroReferenceDate->diffInDays(now());

                    // Check if eligible for GBRO (60+ days since reference date)
                    if ($daysSinceReference >= 60) {
                        // Get the last 2 points (most recent) that are eligible for GBRO
                        $pointsToExpire = $activeGbroEligiblePoints->take(2);

                        foreach ($pointsToExpire as $point) {
                            $point->update([
                                'is_expired' => true,
                                'expired_at' => now(),
                                'expiration_type' => 'gbro',
                                'gbro_applied_at' => now(),
                                'gbro_batch_id' => $batchId,
                            ]);
                            $gbroExpired++;
                        }

                        // After expiring points, update remaining points' gbro_expires_at
                        $remainingPoints = $user->attendancePoints()
                            ->where('is_expired', false)
                            ->where('is_excused', false)
                            ->where('eligible_for_gbro', true)
                            ->whereNull('gbro_applied_at')
                            ->orderBy('shift_date', 'desc')
                            ->get();

                        if ($remainingPoints->isNotEmpty()) {
                            $newGbroPrediction = $gbroPredictionDate->copy()->addDays(60);
                            $this->updateGbroExpiresAt($remainingPoints, $newGbroPrediction);
                        }
                    }
                }
            }

            $totalExpired = $sroExpired + $gbroExpired;
            $typeLabel = match($expirationType) {
                'sro' => 'SRO only',
                'gbro' => 'GBRO only',
                default => 'SRO + GBRO',
            };

            if ($totalExpired === 0) {
                return response()->json([
                    'success' => true,
                    'message' => "No pending expirations found ({$typeLabel}).",
                    'expired' => 0,
                    'sro_expired' => 0,
                    'gbro_expired' => 0,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Expired {$totalExpired} attendance points ({$typeLabel}: SRO={$sroExpired}, GBRO={$gbroExpired}).",
                'expired' => $totalExpired,
                'sro_expired' => $sroExpired,
                'gbro_expired' => $gbroExpired,
            ]);
        } catch (\Exception $e) {
            Log::error('AttendancePointController expireAllPending Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to expire pending points.',
            ], 500);
        }
    }

    /**
     * Initialize GBRO expiration dates for existing active GBRO-eligible points
     */
    public function initializeGbroDates()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $totalUpdated = 0;

            // Get all users with active GBRO-eligible points (including excused for reference)
            $usersWithPoints = User::whereHas('attendancePoints', function ($query) {
                $query->where('is_expired', false)
                    ->where('eligible_for_gbro', true)
                    ->whereNull('gbro_applied_at');
            })->get();

            foreach ($usersWithPoints as $user) {
                // Get ALL active GBRO-eligible points (including excused) for date reference
                $allActivePoints = $user->attendancePoints()
                    ->where('is_expired', false)
                    ->where('eligible_for_gbro', true)
                    ->whereNull('gbro_applied_at')
                    ->orderBy('shift_date', 'desc')
                    ->get();

                // Get only non-excused points that can receive GBRO dates
                $activePoints = $allActivePoints->where('is_excused', false)->values();

                if ($activePoints->isEmpty()) {
                    continue;
                }

                // Calculate GBRO reference date (using ALL points including excused)
                $lastViolationDate = Carbon::parse($allActivePoints->first()->shift_date);

                $lastGbroDate = $user->attendancePoints()
                    ->whereNotNull('gbro_applied_at')
                    ->max('gbro_applied_at');

                $gbroReferenceDate = $lastViolationDate;
                if ($lastGbroDate) {
                    $lastGbroCarbon = Carbon::parse($lastGbroDate);
                    if ($lastGbroCarbon->greaterThan($lastViolationDate)) {
                        $gbroReferenceDate = $lastGbroCarbon;
                    }
                }

                $gbroPredictionDate = $gbroReferenceDate->copy()->addDays(60);

                // GBRO Rule: Only the first 2 NON-EXCUSED points (newest) get GBRO date
                // Excused points' dates count for reference but they don't get GBRO dates
                foreach ($activePoints as $index => $point) {
                    if ($index < 2) {
                        // First 2 points get the GBRO date
                        $pointGbroDate = $gbroPredictionDate->format('Y-m-d');
                        if (!$point->gbro_expires_at || $point->gbro_expires_at !== $pointGbroDate) {
                            $point->update(['gbro_expires_at' => $pointGbroDate]);
                            $totalUpdated++;
                        }
                    } else {
                        // Points beyond first 2 should have NULL gbro_expires_at
                        if ($point->gbro_expires_at !== null) {
                            $point->update(['gbro_expires_at' => null]);
                            $totalUpdated++;
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => $totalUpdated > 0
                    ? "Initialized GBRO dates for {$totalUpdated} points."
                    : "All GBRO dates are already initialized.",
                'updated' => $totalUpdated,
            ]);
        } catch (\Exception $e) {
            Log::error('AttendancePointController initializeGbroDates Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize GBRO dates.',
            ], 500);
        }
    }

    /**
     * Fix GBRO expiration dates for points that were updated with wrong reference
     */
    public function fixGbroDates()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $totalUpdated = 0;

            // Find all users who have points with gbro_applied_at set
            $usersWithGbroApplied = AttendancePoint::whereNotNull('gbro_applied_at')
                ->select('user_id')
                ->distinct()
                ->pluck('user_id');

            foreach ($usersWithGbroApplied as $userId) {
                // Get the scheduled GBRO date from the expired points
                $expiredPoint = AttendancePoint::where('user_id', $userId)
                    ->whereNotNull('gbro_applied_at')
                    ->whereNotNull('gbro_expires_at')
                    ->orderBy('gbro_applied_at', 'desc')
                    ->first();

                if (!$expiredPoint || !$expiredPoint->gbro_expires_at) {
                    continue;
                }

                // The new GBRO prediction should be: scheduled_gbro_date + 60 days
                $scheduledGbroDate = Carbon::parse($expiredPoint->gbro_expires_at);
                $newGbroPrediction = $scheduledGbroDate->copy()->addDays(60);

                // Get remaining active GBRO-eligible points (including excused for reference)
                $allActivePoints = AttendancePoint::where('user_id', $userId)
                    ->where('is_expired', false)
                    ->where('eligible_for_gbro', true)
                    ->whereNull('gbro_applied_at')
                    ->orderBy('shift_date', 'desc')
                    ->get();

                // Get only non-excused points that can receive GBRO dates
                $activePoints = $allActivePoints->where('is_excused', false)->values();

                // GBRO Rule: Only the first 2 NON-EXCUSED points (newest) get GBRO date
                foreach ($activePoints as $index => $point) {
                    if ($index < 2) {
                        $point->update(['gbro_expires_at' => $newGbroPrediction->format('Y-m-d')]);
                        $totalUpdated++;
                    } else {
                        // Points beyond first 2 should have NULL gbro_expires_at
                        if ($point->gbro_expires_at !== null) {
                            $point->update(['gbro_expires_at' => null]);
                            $totalUpdated++;
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => $totalUpdated > 0
                    ? "Fixed GBRO dates for {$totalUpdated} points."
                    : "No GBRO dates needed fixing.",
                'updated' => $totalUpdated,
            ]);
        } catch (\Exception $e) {
            Log::error('AttendancePointController fixGbroDates Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fix GBRO dates.',
            ], 500);
        }
    }

    /**
     * Calculate the GBRO reference date for a user.
     * Returns the MORE RECENT of: last GBRO application date OR last violation date.
     * Note: Excused points' dates still count for the reference calculation.
     */
    protected function calculateGbroReferenceDate(User $user, $activePoints): Carbon
    {
        // Get the most recent violation date from active GBRO-eligible points (including excused)
        $lastViolationDate = Carbon::parse($activePoints->first()->shift_date);

        // Get the most recent GBRO application date for this user (if any)
        $lastGbroDate = $user->attendancePoints()
            ->whereNotNull('gbro_applied_at')
            ->max('gbro_applied_at');

        // The GBRO clock starts from the MORE RECENT of the two dates
        if ($lastGbroDate) {
            $lastGbroCarbon = Carbon::parse($lastGbroDate);
            if ($lastGbroCarbon->greaterThan($lastViolationDate)) {
                return $lastGbroCarbon;
            }
        }

        return $lastViolationDate;
    }

    /**
     * Update gbro_expires_at for a collection of points.
     * GBRO Rule: Only the first 2 points (newest) get the GBRO date.
     * Points beyond first 2 have NULL gbro_expires_at.
     * Points must be sorted by shift_date DESC (newest first) before calling this method.
     */
    protected function updateGbroExpiresAt($points, Carbon $basePredictionDate): void
    {
        $gbroExpiresAt = $basePredictionDate->format('Y-m-d');

        foreach ($points as $index => $point) {
            if ($index < 2) {
                // First 2 points get the GBRO date
                if (!$point->gbro_expires_at || $point->gbro_expires_at !== $gbroExpiresAt) {
                    $point->update(['gbro_expires_at' => $gbroExpiresAt]);
                }
            } else {
                // Points beyond first 2 should have NULL gbro_expires_at
                if ($point->gbro_expires_at !== null) {
                    $point->update(['gbro_expires_at' => null]);
                }
            }
        }
    }

    /**
     * Reset expired attendance points back to active
     * Note: Excused points are NOT reset as they were intentionally excused
     * Supports optional user_ids filter for reprocessing specific users' points (multi-select)
     */
    public function resetExpired(Request $request)
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            // Only reset non-excused expired points
            $query = AttendancePoint::where('is_expired', true)
                ->where('is_excused', false);

            // Apply user filter if provided (supports both single user_id and multiple user_ids)
            if ($request->filled('user_ids') && is_array($request->user_ids)) {
                $query->whereIn('user_id', $request->user_ids);
            } elseif ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            $expiredPoints = $query->get();

            if ($expiredPoints->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No expired attendance points found (excused points are excluded).',
                    'reset' => 0,
                ]);
            }

            $resetCount = 0;

            foreach ($expiredPoints as $point) {
                $shiftDate = Carbon::parse($point->shift_date);
                $isNcnsOrFtn = $point->point_type === 'whole_day_absence' && !$point->is_advised;
                $newExpiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);

                $point->update([
                    'is_expired' => false,
                    'expired_at' => null,
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                    'gbro_applied_at' => null,
                    'gbro_batch_id' => null,
                    'expires_at' => $newExpiresAt,
                ]);

                $resetCount++;
            }

            return response()->json([
                'success' => true,
                'message' => "Reset {$resetCount} expired attendance points to active (excused points excluded).",
                'reset' => $resetCount,
            ]);
        } catch (\Exception $e) {
            Log::error('AttendancePointController resetExpired Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset expired points.',
            ], 500);
        }
    }

    /**
     * Regenerate attendance points from verified attendance records
     */
    public function regeneratePoints(Request $request)
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $query = Attendance::where('status', 'verified')
                ->where(function ($q) {
                    $q->where('is_absent', true)
                        ->orWhere('is_tardy', true)
                        ->orWhere('is_undertime', true);
                })
                ->whereDoesntHave('attendancePoints');

            // Apply date filters
            if ($request->filled('date_from')) {
                $query->whereDate('shift_date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('shift_date', '<=', $request->date_to);
            }
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            $attendances = $query->with('user')->get();

            if ($attendances->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No attendance records found that need points regeneration.',
                    'regenerated' => 0,
                ]);
            }

            $regeneratedCount = 0;

            foreach ($attendances as $attendance) {
                $pointsCreated = $this->createPointsForAttendance($attendance);
                $regeneratedCount += $pointsCreated;
            }

            return response()->json([
                'success' => true,
                'message' => "Regenerated {$regeneratedCount} attendance points from {$attendances->count()} attendance records.",
                'regenerated' => $regeneratedCount,
                'records_processed' => $attendances->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('AttendancePointController regeneratePoints Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to regenerate points.',
            ], 500);
        }
    }

    /**
     * Create attendance points for an attendance record
     */
    private function createPointsForAttendance(Attendance $attendance): int
    {
        $pointsCreated = 0;
        $shiftDate = Carbon::parse($attendance->shift_date);

        // Handle absences
        if ($attendance->is_absent) {
            $isNcnsOrFtn = !$attendance->is_advised;
            $expiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);
            $gbroExpiresAt = $isNcnsOrFtn ? null : $shiftDate->copy()->addDays(60)->format('Y-m-d');

            // Check if it's half day or whole day
            if ($attendance->remarks && str_contains(strtolower($attendance->remarks), 'half')) {
                AttendancePoint::create([
                    'user_id' => $attendance->user_id,
                    'attendance_id' => $attendance->id,
                    'shift_date' => $attendance->shift_date,
                    'point_type' => 'half_day_absence',
                    'points' => 0.50,
                    'is_advised' => $attendance->is_advised,
                    'is_manual' => false,
                    'expires_at' => $expiresAt,
                    'gbro_expires_at' => $gbroExpiresAt,
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                    'eligible_for_gbro' => !$isNcnsOrFtn,
                ]);

                // Update all existing GBRO-eligible points for this user
                if (!$isNcnsOrFtn) {
                    $this->updateUserGbroExpirationDates($attendance->user_id, $shiftDate);
                }
                $pointsCreated++;
            } else {
                AttendancePoint::create([
                    'user_id' => $attendance->user_id,
                    'attendance_id' => $attendance->id,
                    'shift_date' => $attendance->shift_date,
                    'point_type' => 'whole_day_absence',
                    'points' => 1.00,
                    'is_advised' => $attendance->is_advised,
                    'is_manual' => false,
                    'expires_at' => $expiresAt,
                    'gbro_expires_at' => $gbroExpiresAt,
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                    'eligible_for_gbro' => !$isNcnsOrFtn,
                ]);

                // Update all existing GBRO-eligible points for this user (only for non-NCNS)
                if (!$isNcnsOrFtn) {
                    $this->updateUserGbroExpirationDates($attendance->user_id, $shiftDate);
                }
                $pointsCreated++;
            }
        }

        // Handle tardy
        if ($attendance->is_tardy && $attendance->tardy_minutes > 0) {
            $gbroExpiresAt = $shiftDate->copy()->addDays(60)->format('Y-m-d');
            AttendancePoint::create([
                'user_id' => $attendance->user_id,
                'attendance_id' => $attendance->id,
                'shift_date' => $attendance->shift_date,
                'point_type' => 'tardy',
                'points' => 0.25,
                'tardy_minutes' => $attendance->tardy_minutes,
                'is_manual' => false,
                'expires_at' => $shiftDate->copy()->addMonths(6),
                'gbro_expires_at' => $gbroExpiresAt,
                'expiration_type' => 'sro',
                'eligible_for_gbro' => true,
            ]);

            // Update all existing GBRO-eligible points for this user
            $this->updateUserGbroExpirationDates($attendance->user_id, $shiftDate);
            $pointsCreated++;
        }

        // Handle undertime
        if ($attendance->is_undertime && $attendance->undertime_minutes > 0) {
            $isMoreThanHour = $attendance->undertime_minutes > 60;
            $gbroExpiresAt = $shiftDate->copy()->addDays(60)->format('Y-m-d');
            AttendancePoint::create([
                'user_id' => $attendance->user_id,
                'attendance_id' => $attendance->id,
                'shift_date' => $attendance->shift_date,
                'point_type' => $isMoreThanHour ? 'undertime_more_than_hour' : 'undertime',
                'points' => $isMoreThanHour ? 0.50 : 0.25,
                'undertime_minutes' => $attendance->undertime_minutes,
                'is_manual' => false,
                'expires_at' => $shiftDate->copy()->addMonths(6),
                'gbro_expires_at' => $gbroExpiresAt,
                'expiration_type' => 'sro',
                'eligible_for_gbro' => true,
            ]);

            // Update all existing GBRO-eligible points for this user
            $this->updateUserGbroExpirationDates($attendance->user_id, $shiftDate);
            $pointsCreated++;
        }

        return $pointsCreated;
    }

    /**
     * Full cleanup: remove duplicates + expire all pending (SRO + GBRO)
     * Note: Excused points are preserved during cleanup
     */
    public function cleanup()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $results = [
                'duplicates_removed' => 0,
                'sro_expired' => 0,
                'gbro_expired' => 0,
            ];

            // Step 1: Remove duplicates (preserving excused points)
            $duplicates = DB::table('attendance_points')
                ->select('user_id', 'shift_date', 'point_type', DB::raw('COUNT(*) as count'))
                ->groupBy('user_id', 'shift_date', 'point_type')
                ->having('count', '>', 1)
                ->get();

            foreach ($duplicates as $dup) {
                // Get all duplicates, prioritize excused points
                $points = AttendancePoint::where('user_id', $dup->user_id)
                    ->where('shift_date', $dup->shift_date)
                    ->where('point_type', $dup->point_type)
                    ->orderByDesc('is_excused')
                    ->orderBy('id')
                    ->get();

                $keepId = $points->first()->id;

                $deleted = AttendancePoint::where('user_id', $dup->user_id)
                    ->where('shift_date', $dup->shift_date)
                    ->where('point_type', $dup->point_type)
                    ->where('id', '!=', $keepId)
                    ->delete();

                $results['duplicates_removed'] += $deleted;
            }

            // Step 2: Expire all pending SRO (excluding excused points)
            $sroPoints = AttendancePoint::where('is_expired', false)
                ->where('is_excused', false)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->get();

            foreach ($sroPoints as $point) {
                $point->markAsExpired('sro');
                $results['sro_expired']++;
            }

            // Step 3: Expire all pending GBRO (Good Behavior Roll Off)
            $batchId = now()->format('YmdHis');

            $usersWithPoints = User::whereHas('attendancePoints', function ($query) {
                $query->where('is_expired', false)
                    ->where('is_excused', false)
                    ->where('eligible_for_gbro', true);
            })->get();

            foreach ($usersWithPoints as $user) {
                $activeGbroEligiblePoints = $user->attendancePoints()
                    ->where('is_expired', false)
                    ->where('is_excused', false)
                    ->where('eligible_for_gbro', true)
                    ->whereNull('gbro_applied_at')
                    ->orderBy('shift_date', 'desc')
                    ->get();

                if ($activeGbroEligiblePoints->isEmpty()) {
                    continue;
                }

                $gbroReferenceDate = $this->calculateGbroReferenceDate($user, $activeGbroEligiblePoints);
                $gbroPredictionDate = $gbroReferenceDate->copy()->addDays(60);

                $this->updateGbroExpiresAt($activeGbroEligiblePoints, $gbroPredictionDate);

                $daysSinceReference = $gbroReferenceDate->diffInDays(now());

                if ($daysSinceReference >= 60) {
                    $pointsToExpire = $activeGbroEligiblePoints->take(2);

                    foreach ($pointsToExpire as $point) {
                        $point->update([
                            'is_expired' => true,
                            'expired_at' => now(),
                            'expiration_type' => 'gbro',
                            'gbro_applied_at' => now(),
                            'gbro_batch_id' => $batchId,
                        ]);
                        $results['gbro_expired']++;
                    }

                    $remainingPoints = $user->attendancePoints()
                        ->where('is_expired', false)
                        ->where('is_excused', false)
                        ->where('eligible_for_gbro', true)
                        ->whereNull('gbro_applied_at')
                        ->orderBy('shift_date', 'desc')
                        ->get();

                    if ($remainingPoints->isNotEmpty()) {
                        $newGbroPrediction = $gbroPredictionDate->copy()->addDays(60);
                        $this->updateGbroExpiresAt($remainingPoints, $newGbroPrediction);
                    }
                }
            }

            $totalExpired = $results['sro_expired'] + $results['gbro_expired'];
            $totalActions = $results['duplicates_removed'] + $totalExpired;

            return response()->json([
                'success' => true,
                'message' => $totalActions > 0
                    ? "Cleanup complete: removed {$results['duplicates_removed']} duplicates, expired {$totalExpired} points (SRO: {$results['sro_expired']}, GBRO: {$results['gbro_expired']}). Excused points preserved."
                    : 'No cleanup actions needed. Database is already clean.',
                'duplicates_removed' => $results['duplicates_removed'],
                'points_expired' => $totalExpired,
                'sro_expired' => $results['sro_expired'],
                'gbro_expired' => $results['gbro_expired'],
            ]);
        } catch (\Exception $e) {
            Log::error('AttendancePointController cleanup Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to perform cleanup.',
            ], 500);
        }
    }
}
