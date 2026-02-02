<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendancePointRequest;
use App\Http\Requests\UpdateAttendancePointRequest;
use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AttendancePoint\AttendancePointCreationService;
use App\Services\AttendancePoint\AttendancePointExportService;
use App\Services\AttendancePoint\AttendancePointMaintenanceService;
use App\Services\AttendancePoint\AttendancePointStatsService;
use App\Services\AttendancePoint\GbroCalculationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AttendancePointController extends Controller
{
    use RedirectsWithFlashMessages;

    public function __construct(
        protected GbroCalculationService $gbroService,
        protected AttendancePointStatsService $statsService,
        protected AttendancePointMaintenanceService $maintenanceService,
        protected AttendancePointExportService $exportService,
        protected AttendancePointCreationService $creationService
    ) {}

    /**
     * Display a listing of attendance points.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', AttendancePoint::class);

        $user = auth()->user();

        // Determine Team Lead's campaign (if applicable)
        $teamLeadCampaignId = $this->getTeamLeadCampaignId($user);

        // Redirect restricted roles to their own show page
        $restrictedRoles = ['Agent', 'IT', 'Utility'];
        if (in_array($user->role, $restrictedRoles)) {
            return redirect()->route('attendance-points.show', ['user' => auth()->id()]);
        }

        $query = $this->buildIndexQuery($request, $user, $teamLeadCampaignId);
        $points = $query->paginate(25)->appends($request->except('page'));

        $users = User::orderBy('first_name')->get();
        $campaigns = Campaign::orderBy('name')->get();

        $statsUserId = in_array(auth()->user()->role, $restrictedRoles) ? auth()->id() : null;
        $stats = $this->statsService->calculateStats($request, $statsUserId);

        return Inertia::render('Attendance/Points/Index', [
            'points' => $points,
            'users' => $users,
            'campaigns' => $campaigns,
            'stats' => $stats,
            'teamLeadCampaignId' => $teamLeadCampaignId,
            'filters' => $this->buildFiltersArray($request),
        ]);
    }

    /**
     * Display the specified user's attendance points.
     */
    public function show(User $user, Request $request)
    {
        $this->authorizeUserView($request->user(), $user);

        $showAll = $request->boolean('show_all', false);

        $startDate = $request->filled('date_from')
            ? Carbon::parse($request->date_from)
            : Carbon::now()->startOfMonth();

        $endDate = $request->filled('date_to')
            ? Carbon::parse($request->date_to)
            : Carbon::now()->endOfMonth();

        $pointsQuery = AttendancePoint::with(['attendance', 'excusedBy'])
            ->where('user_id', $user->id);

        if (! $showAll) {
            $pointsQuery->dateRange($startDate, $endDate);
        }

        $points = $pointsQuery->orderBy('shift_date', 'desc')->get();
        $totals = $this->statsService->calculateTotals($points);
        $gbroStats = $this->gbroService->calculateGbroStats($user);

        return Inertia::render('Attendance/Points/Show', [
            'user' => $user,
            'points' => $points,
            'totals' => $totals,
            'dateRange' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'gbroStats' => $gbroStats,
            'filters' => [
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'show_all' => $showAll,
            ],
        ]);
    }

    /**
     * Store a new manual attendance point.
     */
    public function store(StoreAttendancePointRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $this->creationService->createManualPoint(
                    $request->validated(),
                    $request->user()->id
                );
            });

            return $this->redirectWithFlash('attendance-points.index', 'Manual attendance point created successfully. Employee notified.');
        } catch (\Exception $e) {
            Log::error('AttendancePointController Store Error: '.$e->getMessage());

            return $this->redirectWithFlash('attendance-points.index', 'Failed to create manual attendance point.', 'error');
        }
    }

    /**
     * Update a manual attendance point.
     */
    public function update(UpdateAttendancePointRequest $request, AttendancePoint $point)
    {
        if (! $point->is_manual) {
            return $this->backWithFlash('Cannot edit auto-generated attendance points.', 'error');
        }

        try {
            DB::transaction(function () use ($request, $point) {
                $this->creationService->updateManualPoint($point, $request->validated());
            });

            return $this->backWithFlash('Manual attendance point updated successfully. Employee notified.');
        } catch (\Exception $e) {
            Log::error('AttendancePointController Update Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to update manual attendance point.', 'error');
        }
    }

    /**
     * Delete a manual attendance point.
     */
    public function destroy(AttendancePoint $point)
    {
        $this->authorize('delete', $point);

        if (! $point->is_manual) {
            return $this->backWithFlash('Cannot delete auto-generated attendance points.', 'error');
        }

        try {
            $this->creationService->deleteManualPoint($point);

            return $this->redirectWithFlash('attendance-points.index', 'Manual attendance point deleted successfully.');
        } catch (\Exception $e) {
            Log::error('AttendancePointController Destroy Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to delete manual attendance point.', 'error');
        }
    }

    /**
     * Excuse an attendance point.
     */
    public function excuse(Request $request, AttendancePoint $point)
    {
        $this->authorizeAdminHrAction($request->user(), 'excuse points');

        $request->validate([
            'excuse_reason' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        $point->update([
            'is_excused' => true,
            'excused_by' => $request->user()->id,
            'excused_at' => now(),
            'excuse_reason' => $request->excuse_reason,
            'notes' => $request->notes,
        ]);

        $this->gbroService->cascadeRecalculateGbro($point->user_id);

        return redirect()->back()->with('success', 'Attendance point excused successfully.');
    }

    /**
     * Remove excuse from an attendance point.
     */
    public function unexcuse(Request $request, AttendancePoint $point)
    {
        $this->authorizeAdminHrAction($request->user(), 'unexcuse points');

        $point->update([
            'is_excused' => false,
            'excused_by' => null,
            'excused_at' => null,
            'excuse_reason' => null,
        ]);

        $this->gbroService->cascadeRecalculateGbro($point->user_id);

        return redirect()->back()->with('success', 'Excuse removed successfully.');
    }

    /**
     * Recalculate GBRO dates for a specific user.
     */
    public function recalculateGbro(Request $request, User $user)
    {
        $this->authorizeAdminHrAction($request->user(), 'recalculate GBRO');

        try {
            $this->gbroService->cascadeRecalculateGbro($user->id);

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
            Log::error('AttendancePointController recalculateGbro Error: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to recalculate GBRO dates.');
        }
    }

    /**
     * Rescan attendance records and regenerate points.
     */
    public function rescan(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $startDate = Carbon::parse($request->date_from);
        $endDate = Carbon::parse($request->date_to);

        $attendances = Attendance::with('user')
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->whereIn('status', ['ncns', 'advised_absence', 'half_day_absence', 'tardy', 'undertime', 'undertime_more_than_hour'])
            ->where('admin_verified', true)
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($attendances as $attendance) {
            $existingPoint = AttendancePoint::where('attendance_id', $attendance->id)->first();

            if ($existingPoint) {
                $skipped++;

                continue;
            }

            $pointData = $this->creationService->determinePointType($attendance);

            if ($pointData) {
                $this->createRescanPoint($attendance, $pointData);
                $created++;
            }
        }

        return redirect()->back()->with('success', "Rescan completed. Created: {$created} points, Skipped: {$skipped} existing points.");
    }

    /**
     * Get statistics for a specific user's attendance points.
     */
    public function statistics(User $user, Request $request)
    {
        $this->authorizeUserView($request->user(), $user);

        return response()->json($this->statsService->getUserStatistics($user->id));
    }

    /**
     * Export attendance points for a specific user as CSV.
     */
    public function export(User $user, Request $request)
    {
        $this->authorizeUserView($request->user(), $user);

        $export = $this->exportService->exportCsv($user);

        return response($export['content'], 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$export['filename']}\"",
        ]);
    }

    /**
     * Start Excel export job for a specific user's attendance points.
     */
    public function startExportExcel(User $user, Request $request)
    {
        $currentUser = $request->user();
        if ($currentUser->id !== $user->id) {
            $this->authorize('export', AttendancePoint::class);
        }

        return response()->json($this->exportService->startUserExportExcel($user->id));
    }

    /**
     * Check single user export job progress.
     */
    public function checkExportExcelStatus(string $jobId)
    {
        return response()->json($this->exportService->checkUserExportStatus($jobId));
    }

    /**
     * Download single user exported Excel file.
     */
    public function downloadExportExcel(string $jobId)
    {
        $download = $this->exportService->getUserExportDownload($jobId);

        if (! $download) {
            abort(404, 'Export file not found or not ready');
        }

        return response()->download($download['filePath'], $download['filename'])->deleteFileAfterSend(true);
    }

    /**
     * Start Excel export job for all attendance points (with filters).
     */
    public function startExportAllExcel(Request $request)
    {
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

        $result = $this->exportService->startAllExportExcel($filters);

        if (isset($result['error']) && $result['error']) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    /**
     * Check all users export job progress.
     */
    public function checkExportAllExcelStatus(string $jobId)
    {
        return response()->json($this->exportService->checkAllExportStatus($jobId));
    }

    /**
     * Download all users exported Excel file.
     */
    public function downloadExportAllExcel(string $jobId)
    {
        $download = $this->exportService->getAllExportDownload($jobId);

        if (! $download) {
            abort(404, 'Export file not found or not ready');
        }

        return response()->download($download['filePath'], $download['filename'])->deleteFileAfterSend(true);
    }

    /**
     * Get management statistics.
     */
    public function managementStats()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        return response()->json($this->maintenanceService->getManagementStats());
    }

    /**
     * Remove duplicate attendance points.
     */
    public function removeDuplicates()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            return response()->json($this->maintenanceService->removeDuplicates());
        } catch (\Exception $e) {
            Log::error('AttendancePointController removeDuplicates Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove duplicates.',
            ], 500);
        }
    }

    /**
     * Expire all pending attendance points.
     */
    public function expireAllPending(Request $request)
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $expirationType = $request->input('expiration_type', 'both');

            return response()->json($this->maintenanceService->expireAllPending($expirationType));
        } catch (\Exception $e) {
            Log::error('AttendancePointController expireAllPending Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to expire pending points.',
            ], 500);
        }
    }

    /**
     * Initialize GBRO expiration dates for existing active points.
     */
    public function initializeGbroDates()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            return response()->json($this->maintenanceService->initializeGbroDates());
        } catch (\Exception $e) {
            Log::error('AttendancePointController initializeGbroDates Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize GBRO dates.',
            ], 500);
        }
    }

    /**
     * Fix GBRO expiration dates for points with wrong reference.
     */
    public function fixGbroDates()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            return response()->json($this->maintenanceService->fixGbroDates());
        } catch (\Exception $e) {
            Log::error('AttendancePointController fixGbroDates Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fix GBRO dates.',
            ], 500);
        }
    }

    /**
     * Reset expired attendance points back to active.
     */
    public function resetExpired(Request $request)
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $userIds = $request->filled('user_ids') && is_array($request->user_ids) ? $request->user_ids : null;
            $userId = $request->filled('user_id') ? $request->user_id : null;

            return response()->json($this->maintenanceService->resetExpired($userIds, $userId));
        } catch (\Exception $e) {
            Log::error('AttendancePointController resetExpired Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset expired points.',
            ], 500);
        }
    }

    /**
     * Regenerate attendance points from verified attendance records.
     */
    public function regeneratePoints(Request $request)
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $result = $this->maintenanceService->regeneratePoints(
                $this->creationService,
                $request->date_from,
                $request->date_to,
                $request->user_id
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('AttendancePointController regeneratePoints Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to regenerate points.',
            ], 500);
        }
    }

    /**
     * Full cleanup: remove duplicates + expire all pending.
     */
    public function cleanup()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            return response()->json($this->maintenanceService->cleanup());
        } catch (\Exception $e) {
            Log::error('AttendancePointController cleanup Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to perform cleanup.',
            ], 500);
        }
    }

    // ========== Private Helper Methods ==========

    /**
     * Get team lead's campaign ID if applicable.
     */
    private function getTeamLeadCampaignId($user): ?int
    {
        if ($user->role !== 'Team Lead') {
            return null;
        }

        $activeSchedule = $user->activeSchedule;

        return $activeSchedule?->campaign_id;
    }

    /**
     * Build the query for the index page.
     */
    private function buildIndexQuery(Request $request, $user, ?int $teamLeadCampaignId)
    {
        $query = AttendancePoint::with(['user', 'attendance', 'excusedBy', 'createdBy'])
            ->orderBy('shift_date', 'desc');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('point_type')) {
            $query->where('point_type', $request->point_type);
        }

        if ($request->filled('status')) {
            $this->applyStatusFilter($query, $request->status);
        }

        $campaignIdToFilter = ($request->filled('campaign_id') && $request->campaign_id !== 'all')
            ? $request->campaign_id
            : null;

        if (! $campaignIdToFilter && $user->role === 'Team Lead' && $teamLeadCampaignId) {
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

        if ($request->boolean('expiring_soon')) {
            $query->where('is_expired', false)
                ->where('expires_at', '<=', Carbon::now()->addDays(30))
                ->where('expires_at', '>=', Carbon::now());
        }

        if ($request->boolean('gbro_eligible')) {
            $query->where('eligible_for_gbro', true)
                ->where('is_excused', false)
                ->where('is_expired', false);
        }

        return $query;
    }

    /**
     * Apply status filter to query.
     */
    private function applyStatusFilter($query, string $status): void
    {
        match ($status) {
            'active' => $query->active()->nonExpired(),
            'excused' => $query->where('is_excused', true),
            'expired' => $query->expired(),
            default => null,
        };
    }

    /**
     * Build filters array for frontend.
     */
    private function buildFiltersArray(Request $request): array
    {
        return [
            'user_id' => $request->user_id,
            'campaign_id' => $request->campaign_id,
            'point_type' => $request->point_type,
            'status' => $request->status,
            'date_from' => $request->date_from,
            'date_to' => $request->date_to,
            'expiring_soon' => $request->boolean('expiring_soon') ? 'true' : null,
            'gbro_eligible' => $request->boolean('gbro_eligible') ? 'true' : null,
        ];
    }

    /**
     * Authorize user view (own or admin/HR/Team Lead).
     */
    private function authorizeUserView($currentUser, User $targetUser): void
    {
        if ($currentUser->id !== $targetUser->id && ! in_array($currentUser->role, ['Admin', 'Super Admin', 'HR', 'Team Lead'])) {
            abort(403, 'Unauthorized to view other user points');
        }
    }

    /**
     * Authorize admin/HR action.
     */
    private function authorizeAdminHrAction($user, string $action): void
    {
        if (! in_array($user->role, ['Admin', 'Super Admin', 'HR'])) {
            abort(403, "Unauthorized to {$action}");
        }
    }

    /**
     * Create a point from rescan operation.
     */
    private function createRescanPoint(Attendance $attendance, array $pointData): void
    {
        $isNcnsOrFtn = $pointData['type'] === 'whole_day_absence' && ! $attendance->is_advised;
        $shiftDate = Carbon::parse($attendance->shift_date);
        $expiresAt = $isNcnsOrFtn ? $shiftDate->copy()->addYear() : $shiftDate->copy()->addMonths(6);
        $gbroExpiresAt = $isNcnsOrFtn ? null : $shiftDate->copy()->addDays(60)->format('Y-m-d');

        $violationDetails = $this->creationService->generateViolationDetails($attendance);

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
            'eligible_for_gbro' => ! $isNcnsOrFtn,
        ]);

        if (! $isNcnsOrFtn) {
            $this->gbroService->updateUserGbroExpirationDates($attendance->user_id, $shiftDate);
        }
    }
}
