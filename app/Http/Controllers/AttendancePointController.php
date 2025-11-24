<?php

namespace App\Http\Controllers;

use App\Models\AttendancePoint;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class AttendancePointController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', AttendancePoint::class);

        // Redirect restricted roles to their own show page
        $restrictedRoles = ['Agent', 'IT', 'Utility'];
        if (in_array(auth()->user()->role, $restrictedRoles)) {
            return redirect()->route('attendance-points.show', ['user' => auth()->id()]);
        }

        $query = AttendancePoint::with(['user', 'attendance', 'excusedBy'])
            ->orderBy('shift_date', 'desc');

        if (true) {
            // Only allow user_id filter for non-restricted roles
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
        // Authorization: Users can only view their own points unless they're admin/HR
        $currentUser = $request->user();
        if ($currentUser->id !== $user->id && !in_array($currentUser->role, ['Admin', 'Super Admin', 'HR'])) {
            abort(403, 'Unauthorized to view other user points');
        }

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
            ->whereIn('status', ['ncns', 'advised_absence', 'half_day_absence', 'tardy', 'undertime'])
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
                "Undertime: Left %d minutes early (more than 1 hour before scheduled end). Scheduled: %s, Actual: %s.",
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
                'tardy' => $allPoints->where('point_type', 'tardy')->where('is_excused', false)->where('is_expired', false)->sum('points'),
            ],
        ];
    }

    /**
     * Get statistics for a specific user's attendance points
     */
    public function statistics(User $user, Request $request)
    {
        // Authorization: Users can only view their own statistics unless they're admin/HR
        $currentUser = $request->user();
        if ($currentUser->id !== $user->id && !in_array($currentUser->role, ['Admin', 'Super Admin', 'HR'])) {
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
        // Authorization: Users can only export their own points unless they're admin/HR
        $currentUser = $request->user();
        if ($currentUser->id !== $user->id && !in_array($currentUser->role, ['Admin', 'Super Admin', 'HR'])) {
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
     * Export attendance points for a specific user to Excel
     */
    public function exportExcel(User $user, Request $request)
    {
        // Authorization: Users can only export their own points unless they're admin/HR
        $currentUser = $request->user();
        if ($currentUser->id !== $user->id && !in_array($currentUser->role, ['Admin', 'Super Admin', 'HR'])) {
            abort(403, 'Unauthorized to export other user points');
        }

        $points = AttendancePoint::where('user_id', $user->id)
            ->with(['attendance', 'excusedBy'])
            ->orderBy('shift_date', 'desc')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendance Points');

        // Headers
        $headers = [
            'Date', 'Type', 'Points', 'Status', 'Violation Details',
            'Expires At', 'Expiration Type', 'Is Expired', 'Expired At',
            'Is Excused', 'Excuse Reason', 'Excused By', 'Excused At',
            'Tardy Minutes', 'Undertime Minutes', 'GBRO Eligible'
        ];

        $sheet->fromArray($headers, null, 'A1');

        // Style header row
        $headerRange = 'A1:P1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Data
        $row = 2;
        foreach ($points as $point) {
            $sheet->fromArray([
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
                $point->excusedBy?->name ?? '',
                $point->excused_at ? Carbon::parse($point->excused_at)->format('Y-m-d H:i:s') : '',
                $point->tardy_minutes ?? '',
                $point->undertime_minutes ?? '',
                $point->eligible_for_gbro ? 'Yes' : 'No',
            ], null, "A{$row}");
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'P') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = "attendance-points-{$user->id}-" . now()->format('Y-m-d') . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $temp_file = tempnam(sys_get_temp_dir(), $filename);
        $writer->save($temp_file);

        return response()->download($temp_file, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Export all attendance points to CSV (with filters)
     */
    public function exportAll(Request $request)
    {
        // Authorization: Only admin/HR can export all points
        $currentUser = $request->user();
        if (!in_array($currentUser->role, ['Admin', 'Super Admin', 'HR'])) {
            abort(403, 'Unauthorized to export all attendance points');
        }

        $query = AttendancePoint::with(['user', 'attendance', 'excusedBy'])
            ->orderBy('shift_date', 'desc');

        // Apply filters
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('point_type')) {
            $query->where('point_type', $request->point_type);
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_excused', false)->where('is_expired', false);
            } elseif ($request->status === 'excused') {
                $query->where('is_excused', true);
            }
        }

        if ($request->filled('date_from')) {
            $query->where('shift_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('shift_date', '<=', $request->date_to);
        }

        if ($request->filled('expiring_soon') && $request->expiring_soon === 'true') {
            $query->where('is_expired', false)
                ->where('is_excused', false)
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '<=', now()->addDays(30));
        }

        if ($request->filled('gbro_eligible') && $request->gbro_eligible === 'true') {
            $query->where('eligible_for_gbro', true)
                ->where('is_expired', false)
                ->where('is_excused', false);
        }

        $points = $query->get();

        // Generate CSV
        $filename = "attendance-points-all-" . now()->format('Y-m-d') . ".csv";

        $handle = fopen('php://temp', 'w');

        // Headers
        fputcsv($handle, [
            'Employee Name',
            'Employee ID',
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
                $point->user->name,
                $point->user->id,
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
                $point->excusedBy?->name ?? '',
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
     * Export all attendance points to Excel (with filters)
     */
    public function exportAllExcel(Request $request)
    {
        // Authorization: Only admin/HR can export all points
        $currentUser = $request->user();
        if (!in_array($currentUser->role, ['Admin', 'Super Admin', 'HR'])) {
            abort(403, 'Unauthorized to export all attendance points');
        }

        $query = AttendancePoint::with(['user', 'attendance', 'excusedBy'])
            ->orderBy('shift_date', 'desc');

        // Apply filters (same as exportAll)
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('point_type')) {
            $query->where('point_type', $request->point_type);
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_excused', false)->where('is_expired', false);
            } elseif ($request->status === 'excused') {
                $query->where('is_excused', true);
            }
        }

        if ($request->filled('date_from')) {
            $query->where('shift_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('shift_date', '<=', $request->date_to);
        }

        if ($request->filled('expiring_soon') && $request->expiring_soon === 'true') {
            $query->where('is_expired', false)
                ->where('is_excused', false)
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '<=', now()->addDays(30));
        }

        if ($request->filled('gbro_eligible') && $request->gbro_eligible === 'true') {
            $query->where('eligible_for_gbro', true)
                ->where('is_expired', false)
                ->where('is_excused', false);
        }

        $points = $query->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendance Points');

        // Headers
        $headers = [
            'Employee Name', 'Employee ID', 'Date', 'Type', 'Points', 'Status',
            'Violation Details', 'Expires At', 'Expiration Type', 'Is Expired',
            'Expired At', 'Is Excused', 'Excuse Reason', 'Excused By', 'Excused At',
            'Tardy Minutes', 'Undertime Minutes', 'GBRO Eligible'
        ];

        $sheet->fromArray($headers, null, 'A1');

        // Style header row
        $headerRange = 'A1:R1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Data
        $row = 2;
        foreach ($points as $point) {
            $sheet->fromArray([
                $point->user->name,
                $point->user->id,
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
                $point->excusedBy?->name ?? '',
                $point->excused_at ? Carbon::parse($point->excused_at)->format('Y-m-d H:i:s') : '',
                $point->tardy_minutes ?? '',
                $point->undertime_minutes ?? '',
                $point->eligible_for_gbro ? 'Yes' : 'No',
            ], null, "A{$row}");
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'R') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = "attendance-points-all-" . now()->format('Y-m-d') . ".xlsx";

        $writer = new Xlsx($spreadsheet);
        $temp_file = tempnam(sys_get_temp_dir(), $filename);
        $writer->save($temp_file);

        return response()->download($temp_file, $filename)->deleteFileAfterSend(true);
    }
}
