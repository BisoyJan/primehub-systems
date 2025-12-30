<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendancePointRequest;
use App\Http\Requests\UpdateAttendancePointRequest;
use App\Http\Traits\RedirectsWithFlashMessages;
use App\Jobs\GenerateAttendancePointsExportExcel;
use App\Jobs\GenerateAllAttendancePointsExportExcel;
use App\Models\AttendancePoint;
use App\Models\Attendance;
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

        // Redirect restricted roles to their own show page
        $restrictedRoles = ['Agent', 'IT', 'Utility'];
        if (in_array(auth()->user()->role, $restrictedRoles)) {
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

        $points = $query->paginate(25);

        $users = User::orderBy('first_name')->get();

        // Pass user_id for stats calculation when restricted
        $statsUserId = in_array(auth()->user()->role, $restrictedRoles) ? auth()->id() : null;
        $stats = $this->calculateStats($request, $statsUserId);

        return Inertia::render('Attendance/Points/Index', [
            'points' => $points,
            'users' => $users,
            'stats' => $stats,
            'filters' => [
                'user_id' => $request->user_id,
                'point_type' => $request->point_type,
                'status' => $request->status,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
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

        $daysClean = 0;
        $daysUntilGbro = 60;
        $eligiblePointsCount = 0;
        $eligiblePointsSum = 0;

        if ($lastViolationDate) {
            $daysClean = Carbon::parse($lastViolationDate)->diffInDays(Carbon::now());
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

        $point->update([
            'is_excused' => true,
            'excused_by' => $request->user()->id,
            'excused_at' => now(),
            'excuse_reason' => $request->excuse_reason,
            'notes' => $request->notes,
        ]);

        return redirect()->back()->with('success', 'Attendance point excused successfully.');
    }

    public function unexcuse(Request $request, AttendancePoint $point)
    {
        // Authorization: Only Admin, Super Admin, or HR can unexcuse points
        if (!in_array($request->user()->role, ['Admin', 'Super Admin', 'HR'])) {
            abort(403, 'Unauthorized to unexcuse points');
        }

        $point->update([
            'is_excused' => false,
            'excused_by' => null,
            'excused_at' => null,
            'excuse_reason' => null,
        ]);

        return redirect()->back()->with('success', 'Excuse removed successfully.');
    }

    /**
     * Store a new manual attendance point
     */
    public function store(StoreAttendancePointRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $pointType = $request->point_type;
                $isAdvised = $request->boolean('is_advised', false);
                $shiftDate = Carbon::parse($request->shift_date);

                // Determine if this is a NCNS/FTN type (1-year expiration, not GBRO eligible)
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

                $point = AttendancePoint::create([
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
                    'eligible_for_gbro' => !$isNcnsOrFtn,
                ]);

                // Send notification to the employee about the manually created attendance point
                $this->notificationService->notifyManualAttendancePoint(
                    $request->user_id,
                    $pointType,
                    Carbon::parse($request->shift_date)->format('M d, Y'),
                    $point->points ?? 0
                );
            });

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
            $point->delete();
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
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                    'violation_details' => $violationDetails,
                    'tardy_minutes' => $attendance->tardy_minutes,
                    'undertime_minutes' => $attendance->undertime_minutes,
                    'eligible_for_gbro' => !$isNcnsOrFtn,
                ]);
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
     */
    public function expireAllPending()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $pendingPoints = AttendancePoint::where('is_expired', false)
                ->where('is_excused', false)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->get();

            if ($pendingPoints->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No pending expirations found.',
                    'expired' => 0,
                ]);
            }

            $expiredCount = 0;

            foreach ($pendingPoints as $point) {
                $point->markAsExpired('sro');
                $expiredCount++;
            }

            return response()->json([
                'success' => true,
                'message' => "Expired {$expiredCount} attendance points.",
                'expired' => $expiredCount,
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
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                ]);
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
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                ]);
                $pointsCreated++;
            }
        }

        // Handle tardy
        if ($attendance->is_tardy && $attendance->tardy_minutes > 0) {
            AttendancePoint::create([
                'user_id' => $attendance->user_id,
                'attendance_id' => $attendance->id,
                'shift_date' => $attendance->shift_date,
                'point_type' => 'tardy',
                'points' => 0.25,
                'tardy_minutes' => $attendance->tardy_minutes,
                'is_manual' => false,
                'expires_at' => $shiftDate->copy()->addMonths(6),
                'expiration_type' => 'sro',
            ]);
            $pointsCreated++;
        }

        // Handle undertime
        if ($attendance->is_undertime && $attendance->undertime_minutes > 0) {
            $isMoreThanHour = $attendance->undertime_minutes > 60;
            AttendancePoint::create([
                'user_id' => $attendance->user_id,
                'attendance_id' => $attendance->id,
                'shift_date' => $attendance->shift_date,
                'point_type' => $isMoreThanHour ? 'undertime_more_than_hour' : 'undertime',
                'points' => $isMoreThanHour ? 0.50 : 0.25,
                'undertime_minutes' => $attendance->undertime_minutes,
                'is_manual' => false,
                'expires_at' => $shiftDate->copy()->addMonths(6),
                'expiration_type' => 'sro',
            ]);
            $pointsCreated++;
        }

        return $pointsCreated;
    }

    /**
     * Full cleanup: remove duplicates + expire all pending
     * Note: Excused points are preserved during cleanup
     */
    public function cleanup()
    {
        $this->authorize('viewAny', AttendancePoint::class);

        try {
            $results = [
                'duplicates_removed' => 0,
                'points_expired' => 0,
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

            // Step 2: Expire all pending (excluding excused points)
            $pendingPoints = AttendancePoint::where('is_expired', false)
                ->where('is_excused', false)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->get();

            foreach ($pendingPoints as $point) {
                $point->markAsExpired('sro');
                $results['points_expired']++;
            }

            $totalActions = $results['duplicates_removed'] + $results['points_expired'];

            return response()->json([
                'success' => true,
                'message' => $totalActions > 0
                    ? "Cleanup complete: removed {$results['duplicates_removed']} duplicates, expired {$results['points_expired']} points (excused points preserved)."
                    : 'No cleanup actions needed. Database is already clean.',
                'duplicates_removed' => $results['duplicates_removed'],
                'points_expired' => $results['points_expired'],
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
