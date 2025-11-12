<?php

namespace App\Http\Controllers;

use App\Models\AttendancePoint;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class AttendancePointController extends Controller
{
    public function index(Request $request)
    {
        $query = AttendancePoint::with(['user', 'attendance', 'excusedBy'])
            ->orderBy('shift_date', 'desc');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
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

        $points = $query->paginate(50);

        $users = User::orderBy('first_name')->get();

        $stats = $this->calculateStats($request);

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
        $startDate = $request->filled('date_from')
            ? Carbon::parse($request->date_from)
            : Carbon::now()->startOfMonth();

        $endDate = $request->filled('date_to')
            ? Carbon::parse($request->date_to)
            : Carbon::now()->endOfMonth();

        $points = AttendancePoint::with(['attendance', 'excusedBy'])
            ->where('user_id', $user->id)
            ->dateRange($startDate, $endDate)
            ->orderBy('shift_date', 'desc')
            ->get();

        $totals = [
            'total_points' => $points->where('is_excused', false)->where('is_expired', false)->sum('points'),
            'excused_points' => $points->where('is_excused', true)->sum('points'),
            'expired_points' => $points->where('is_expired', true)->sum('points'),
            'by_type' => [
                'whole_day_absence' => $points->where('point_type', 'whole_day_absence')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'half_day_absence' => $points->where('point_type', 'half_day_absence')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'undertime' => $points->where('point_type', 'undertime')->where('is_excused', false)->where('is_expired', false)->sum('points'),
                'tardy' => $points->where('point_type', 'tardy')->where('is_excused', false)->where('is_expired', false)->sum('points'),
            ],
            'count_by_type' => [
                'whole_day_absence' => $points->where('point_type', 'whole_day_absence')->where('is_excused', false)->where('is_expired', false)->count(),
                'half_day_absence' => $points->where('point_type', 'half_day_absence')->where('is_excused', false)->where('is_expired', false)->count(),
                'undertime' => $points->where('point_type', 'undertime')->where('is_excused', false)->where('is_expired', false)->count(),
                'tardy' => $points->where('point_type', 'tardy')->where('is_excused', false)->where('is_expired', false)->count(),
            ],
        ];

        // Calculate GBRO statistics
        $lastViolationDate = AttendancePoint::where('user_id', $user->id)
            ->where('is_excused', false)
            ->where('is_expired', false)
            ->max('shift_date');

        $daysClean = 0;
        $daysUntilGbro = 60;
        $eligiblePointsCount = 0;

        if ($lastViolationDate) {
            $daysClean = Carbon::parse($lastViolationDate)->diffInDays(Carbon::now());
            $daysUntilGbro = max(0, 60 - $daysClean);

            // Count eligible points (last 2 points that can be removed by GBRO)
            $eligiblePointsCount = AttendancePoint::where('user_id', $user->id)
                ->where('is_excused', false)
                ->where('is_expired', false)
                ->where('eligible_for_gbro', true)
                ->orderBy('shift_date', 'desc')
                ->limit(2)
                ->count();
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
                'last_violation_date' => $lastViolationDate,
                'is_gbro_ready' => $daysClean >= 60 && $eligiblePointsCount > 0,
            ],
        ]);
    }

    public function excuse(Request $request, AttendancePoint $point)
    {
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

    public function unexcuse(AttendancePoint $point)
    {
        $point->update([
            'is_excused' => false,
            'excused_by' => null,
            'excused_at' => null,
            'excuse_reason' => null,
        ]);

        return redirect()->back()->with('success', 'Excuse removed successfully.');
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
        $attendances = Attendance::with('user')
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->whereIn('status', ['ncns', 'advised_absence', 'half_day_absence', 'tardy', 'undertime'])
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

        return match ($attendance->status) {
            'ncns' => $attendance->is_advised
                ? "Failed to Notify (FTN): Employee did not report for work despite being advised. Scheduled: {$scheduledIn} - {$scheduledOut}. No biometric scans recorded."
                : "No Call, No Show (NCNS): Employee did not report for work and did not provide prior notice. Scheduled: {$scheduledIn} - {$scheduledOut}. No biometric scans recorded.",

            'half_day_absence' => sprintf(
                "Half-Day Absence: Arrived %d minutes late (more than 15 minutes). Scheduled: %s, Actual: %s.",
                $attendance->tardy_minutes ?? 0,
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
                "Undertime: Left %d minutes early (more than 1 hour before scheduled end). Scheduled: %s, Actual: %s.",
                $attendance->undertime_minutes ?? 0,
                $scheduledOut,
                $actualOut
            ),

            default => sprintf("Attendance violation on %s", Carbon::parse($attendance->shift_date)->format('Y-m-d')),
        };
    }

    private function calculateStats(Request $request)
    {
        $query = AttendancePoint::query();

        if ($request->filled('user_id')) {
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
                'tardy' => $allPoints->where('point_type', 'tardy')->where('is_excused', false)->where('is_expired', false)->sum('points'),
            ],
        ];
    }
}
