<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceUpload;
use App\Models\AttendancePoint;
use App\Services\AttendanceProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    protected AttendanceProcessor $processor;

    public function __construct(AttendanceProcessor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Display a listing of attendance records.
     */
    public function index(Request $request)
    {
        $query = Attendance::with([
            'user',
            'employeeSchedule.site',
            'bioInSite',
            'bioOutSite'
        ]);

        // Search by employee name
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere(\DB::raw("CONCAT(first_name, ' ', last_name)"), 'like', "%{$search}%");
            });
        }

        // Filters
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        if ($request->has('user_id') && $request->user_id !== 'all') {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('needs_verification') && $request->needs_verification) {
            $query->needsVerification();
        }

        $attendances = $query->orderBy('shift_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(50)
            ->withQueryString();

        // Get all users for manual attendance creation
        $users = \App\Models\User::select('id', 'first_name', 'last_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                ];
            });

        return Inertia::render('Attendance/Main/Index', [
            'attendances' => $attendances,
            'users' => $users,
            'filters' => $request->only(['search', 'status', 'start_date', 'end_date', 'user_id', 'needs_verification']),
        ]);
    }

    /**
     * Store a manually created attendance record.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'shift_date' => 'required|date',
            'status' => 'required|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,failed_bio_in,failed_bio_out,present_no_bio,non_work_day',
            'actual_time_in' => 'nullable|date_format:Y-m-d\\TH:i',
            'actual_time_out' => 'nullable|date_format:Y-m-d\\TH:i',
            'notes' => 'nullable|string|max:500',
        ]);

        // Get employee schedule for the date
        $schedule = \App\Models\EmployeeSchedule::where('user_id', $validated['user_id'])
            ->where('is_active', true)
            ->where('effective_date', '<=', $validated['shift_date'])
            ->where(function ($query) use ($validated) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $validated['shift_date']);
            })
            ->first();

        // Convert datetime strings to Carbon instances
        $actualTimeIn = $validated['actual_time_in'] ? Carbon::parse($validated['actual_time_in']) : null;
        $actualTimeOut = $validated['actual_time_out'] ? Carbon::parse($validated['actual_time_out']) : null;

        // Calculate tardy, undertime, overtime if schedule exists and times are provided
        $tardyMinutes = null;
        $undertimeMinutes = null;
        $overtimeMinutes = null;

        if ($schedule && $actualTimeIn && $actualTimeOut) {
            $shiftDate = Carbon::parse($validated['shift_date']);
            $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
            $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);

            // Handle night shift (time out is next day)
            if ($schedule->shift_type === 'night_shift' && $scheduledTimeOut->lt($scheduledTimeIn)) {
                $scheduledTimeOut->addDay();
            }

            // Calculate tardy (late arrival)
            $gracePeriod = $schedule->grace_period_minutes ?? 0;
            if ($actualTimeIn->gt($scheduledTimeIn->copy()->addMinutes($gracePeriod))) {
                $tardyMinutes = $actualTimeIn->diffInMinutes($scheduledTimeIn);
            }

            // Calculate undertime (early leave) and overtime (late leave)
            if ($actualTimeOut->lt($scheduledTimeOut)) {
                $undertimeMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut);
            } else if ($actualTimeOut->gt($scheduledTimeOut)) {
                $overtimeMinutes = $actualTimeOut->diffInMinutes($scheduledTimeOut);
            }
        }

        $attendance = Attendance::create([
            'user_id' => $validated['user_id'],
            'employee_schedule_id' => $schedule?->id,
            'shift_date' => $validated['shift_date'],
            'scheduled_time_in' => $schedule?->scheduled_time_in,
            'scheduled_time_out' => $schedule?->scheduled_time_out,
            'actual_time_in' => $actualTimeIn,
            'actual_time_out' => $actualTimeOut,
            'status' => $validated['status'],
            'tardy_minutes' => $tardyMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'admin_verified' => true, // Manual entries are pre-verified
            'verification_notes' => 'Manually created by ' . auth()->user()->name,
            'notes' => $validated['notes'],
        ]);

        return redirect()->back()->with('success', 'Attendance record created successfully.');
    }

    public function import()
    {
        $recentUploads = AttendanceUpload::with(['uploader', 'biometricSite'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $sites = \App\Models\Site::orderBy('name')->get();

        return Inertia::render('Attendance/Main/Import', [
            'recentUploads' => $recentUploads,
            'sites' => $sites,
        ]);
    }

    /**
     * Handle the file upload and processing.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:txt|max:10240', // Max 10MB
            'date_from' => 'required|date|before_or_equal:today',
            'date_to' => 'nullable|date|after_or_equal:date_from|before_or_equal:today',
            'biometric_site_id' => 'required|exists:sites,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Store the file
        $file = $request->file('file');
        $filename = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('attendance_uploads', $filename);

        // Create upload record
        $upload = AttendanceUpload::create([
            'uploaded_by' => auth()->id(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $filename,
            'date_from' => $request->date_from,
            'date_to' => $request->date_to ?? $request->date_from, // If null, use date_from (single day)
            'shift_date' => $request->date_from, // Keep for backward compatibility
            'biometric_site_id' => $request->biometric_site_id,
            'notes' => $request->notes,
            'status' => 'pending',
        ]);

        try {
            // Process the file
            $filePath = Storage::path($path);
            $stats = $this->processor->processUpload($upload, $filePath);

            // Prepare success message with more details
            $message = sprintf(
                'Attendance file processed successfully. Total records: %d, Matched: %d employees, Unmatched: %d names',
                $stats['total_records'],
                $stats['matched_employees'],
                count($stats['unmatched_names'])
            );

            // Add date validation warnings if any
            if (!empty($stats['date_warnings'])) {
                $warningMessage = 'Date Validation Warnings: ' . implode(' ', $stats['date_warnings']);
                session()->flash('warning', $warningMessage);
            }

            // Add unmatched names to flash message for debugging
            if (!empty($stats['unmatched_names'])) {
                $unmatchedList = implode(', ', array_slice($stats['unmatched_names'], 0, 10));
                if (count($stats['unmatched_names']) > 10) {
                    $unmatchedList .= '... and ' . (count($stats['unmatched_names']) - 10) . ' more';
                }
                $message .= '. Unmatched: ' . $unmatchedList;
            }

            return redirect()->route('attendance.import')
                ->with('success', $message);

        } catch (\Exception $e) {
            // Update upload record to show failure
            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            \Log::error('Attendance upload failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()
                ->with('error', 'Failed to process attendance file: ' . $e->getMessage());
        }
    }

    /**
     * Show records that need verification.
     */
    public function review(Request $request)
    {
        $query = Attendance::with([
            'user',
            'employeeSchedule.site',
            'bioInSite',
            'bioOutSite'
        ]);

        // Filter by verification status
        if ($request->filled('verified')) {
            if ($request->verified === 'verified') {
                $query->where('admin_verified', true);
            } elseif ($request->verified === 'pending') {
                $query->where('admin_verified', false);
            }
            // 'all' means no filter
        } else {
            // Default: only show unverified records (needsVerification scope)
            $query->needsVerification();
        }

        // Search by employee name
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('shift_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('shift_date', '<=', $request->date_to);
        }

        $attendances = $query->orderBy('shift_date', 'desc')->paginate(50);

        return Inertia::render('Attendance/Main/Review', [
            'attendances' => $attendances,
            'filters' => [
                'search' => $request->search,
                'status' => $request->status,
                'verified' => $request->verified,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
            ],
        ]);
    }

    /**
     * Verify and update an attendance record.
     */
    public function verify(Request $request, Attendance $attendance)
    {
        // Load employee schedule for shift type checking
        $attendance->load('employeeSchedule');

        $request->validate([
            'status' => 'required|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,failed_bio_in,failed_bio_out,present_no_bio',
            'actual_time_in' => 'nullable|date',
            'actual_time_out' => 'nullable|date',
            'verification_notes' => 'required|string|max:1000',
            'overtime_approved' => 'nullable|boolean',
        ]);

        // Note: Allow re-verification of already verified records
        // This is intentional - admins can update verified records through this interface

        $oldStatus = $attendance->status;

        $updates = [
            'status' => $request->status,
            'actual_time_in' => $request->actual_time_in,
            'actual_time_out' => $request->actual_time_out,
            'admin_verified' => true,
            'verification_notes' => $request->verification_notes,
        ];

        // Set is_advised flag for advised_absence status
        if ($request->status === 'advised_absence') {
            $updates['is_advised'] = true;
        }

        // Handle overtime approval
        if ($request->has('overtime_approved')) {
            $updates['overtime_approved'] = $request->overtime_approved;
            if ($request->overtime_approved) {
                $updates['overtime_approved_at'] = now();
                $updates['overtime_approved_by'] = auth()->id();
            } else {
                $updates['overtime_approved_at'] = null;
                $updates['overtime_approved_by'] = null;
            }
        }

        $attendance->update($updates);

        // Recalculate tardy/undertime/overtime if times provided
        if ($request->actual_time_in && $attendance->scheduled_time_in) {
            $shiftDate = Carbon::parse($attendance->shift_date);
            $scheduled = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attendance->scheduled_time_in);
            $actual = Carbon::parse($request->actual_time_in);
            $tardyMinutes = $scheduled->diffInMinutes($actual, false);

            if ($tardyMinutes > 0) {
                $attendance->update(['tardy_minutes' => $tardyMinutes]);
            } else {
                $attendance->update(['tardy_minutes' => null]);
            }
        }

        // Recalculate undertime and overtime if time out provided
        if ($request->actual_time_out && $attendance->scheduled_time_out) {
            $actualTimeOut = Carbon::parse($request->actual_time_out);

            // Build scheduled time out based on shift date and scheduled time
            $shiftDate = Carbon::parse($attendance->shift_date);
            $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attendance->scheduled_time_out);

            // Handle night shift - if scheduled time out is earlier than scheduled time in,
            // it means the shift ends the next day
            if ($attendance->scheduled_time_in && $attendance->scheduled_time_out) {
                $scheduledIn = Carbon::parse($attendance->scheduled_time_in);
                $scheduledOut = Carbon::parse($attendance->scheduled_time_out);

                // If time out is before time in (e.g., 07:00 < 22:00), shift crosses midnight
                if ($scheduledOut->format('H:i:s') < $scheduledIn->format('H:i:s')) {
                    $scheduledTimeOut->addDay();
                }
            }

            // Calculate difference: positive means overtime (left late), negative means undertime (left early)
            // Using scheduledTimeOut->diffInMinutes($actualTimeOut):
            // - Positive if actualTimeOut > scheduledTimeOut (overtime)
            // - Negative if actualTimeOut < scheduledTimeOut (undertime)
            $timeDiff = $scheduledTimeOut->diffInMinutes($actualTimeOut, false);

            // If negative and more than 60 minutes (left early), it's undertime
            if ($timeDiff < -60) {
                $attendance->update([
                    'undertime_minutes' => abs($timeDiff),
                    'overtime_minutes' => null,
                ]);
            }
            // If positive and more than 60 minutes (left late), it's overtime
            elseif ($timeDiff > 60) {
                $attendance->update([
                    'undertime_minutes' => null,
                    'overtime_minutes' => $timeDiff,
                ]);
            }
            // If within threshold (-60 to 60), clear both
            else {
                $attendance->update([
                    'undertime_minutes' => null,
                    'overtime_minutes' => null,
                ]);
            }
        }

        // Regenerate attendance points after verification
        // Delete existing points for this attendance record
        AttendancePoint::where('attendance_id', $attendance->id)->delete();

        // Generate points if the status requires them (and record is now verified)
        if (in_array($request->status, ['ncns', 'half_day_absence', 'tardy', 'undertime', 'advised_absence'])) {
            $this->processor->regeneratePointsForAttendance($attendance);
        }

        return redirect()->back()
            ->with('success', 'Attendance record verified and updated successfully.');
    }

    /**
     * Batch verify multiple attendance records.
     */
    public function batchVerify(Request $request)
    {
        $validated = $request->validate([
            'record_ids' => 'required|array|min:1',
            'record_ids.*' => 'required|exists:attendances,id',
            'status' => 'required|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,failed_bio_in,failed_bio_out,present_no_bio',
            'verification_notes' => 'required|string|max:1000',
            'overtime_approved' => 'nullable|boolean',
        ]);

        $recordIds = $validated['record_ids'];
        $verifiedCount = 0;

        foreach ($recordIds as $id) {
            $attendance = Attendance::with('employeeSchedule')->find($id);
            if (!$attendance) {
                continue;
            }

            $oldStatus = $attendance->status;

            $updates = [
                'status' => $validated['status'],
                'admin_verified' => true,
                'verification_notes' => $validated['verification_notes'],
            ];

            // Set is_advised flag for advised_absence status
            if ($validated['status'] === 'advised_absence') {
                $updates['is_advised'] = true;
            }

            // Handle overtime approval
            if (isset($validated['overtime_approved'])) {
                $updates['overtime_approved'] = $validated['overtime_approved'];
                if ($validated['overtime_approved']) {
                    $updates['overtime_approved_at'] = now();
                    $updates['overtime_approved_by'] = auth()->id();
                } else {
                    $updates['overtime_approved_at'] = null;
                    $updates['overtime_approved_by'] = null;
                }
            }

            $attendance->update($updates);

            // Regenerate attendance points after verification
            // Delete existing points for this attendance record
            AttendancePoint::where('attendance_id', $attendance->id)->delete();

            // Generate points if the status requires them (and record is now verified)
            if (in_array($validated['status'], ['ncns', 'half_day_absence', 'tardy', 'undertime', 'advised_absence'])) {
                $this->processor->regeneratePointsForAttendance($attendance);
            }

            $verifiedCount++;
        }

        return redirect()->back()
            ->with('success', "Successfully verified {$verifiedCount} attendance record" . ($verifiedCount === 1 ? '' : 's') . ".");
    }

    /**
     * Mark an attendance as advised absence.
     */
    public function markAdvised(Request $request, Attendance $attendance)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $attendance->update([
            'status' => 'advised_absence',
            'is_advised' => true,
            'admin_verified' => true,
            'verification_notes' => $request->notes,
        ]);

        return redirect()->back()
            ->with('success', 'Attendance marked as advised absence.');
    }

    /**
     * Quick approve an on-time attendance record without overtime issues.
     */
    public function quickApprove(Request $request, Attendance $attendance)
    {
        // Validate that the record is eligible for quick approval
        if ($attendance->status !== 'on_time') {
            return redirect()->back()
                ->with('error', 'Only on-time records can be quick approved.');
        }

        if ($attendance->admin_verified) {
            return redirect()->back()
                ->with('error', 'This record has already been verified.');
        }

        // Check for unapproved overtime
        if ($attendance->overtime_minutes && $attendance->overtime_minutes > 0 && !$attendance->overtime_approved) {
            return redirect()->back()
                ->with('error', 'Records with unapproved overtime need manual review.');
        }

        $attendance->update([
            'admin_verified' => true,
            'verification_notes' => 'Quick approved by admin',
        ]);

        return redirect()->back()
            ->with('success', 'Attendance record approved successfully.');
    }

    /**
     * Bulk quick approve multiple on-time attendance records without overtime issues.
     */
    public function bulkQuickApprove(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:attendances,id',
        ]);

        $attendances = Attendance::whereIn('id', $request->ids)->get();

        $approved = 0;
        $skipped = 0;
        $skippedReasons = [];

        foreach ($attendances as $attendance) {
            // Check eligibility
            if ($attendance->status !== 'on_time') {
                $skipped++;
                $skippedReasons[] = "{$attendance->user->name} - Not on-time status";
                continue;
            }

            if ($attendance->admin_verified) {
                $skipped++;
                continue;
            }

            if ($attendance->overtime_minutes && $attendance->overtime_minutes > 0 && !$attendance->overtime_approved) {
                $skipped++;
                $skippedReasons[] = "{$attendance->user->name} - Has unapproved overtime";
                continue;
            }

            // Approve the record
            $attendance->update([
                'admin_verified' => true,
                'verification_notes' => 'Bulk quick approved by admin',
            ]);

            $approved++;
        }

        $message = "Successfully approved {$approved} record(s).";
        if ($skipped > 0) {
            $message .= " Skipped {$skipped} ineligible record(s).";
            if (!empty($skippedReasons)) {
                $message .= " Reasons: " . implode('; ', $skippedReasons);
            }
        }

        return redirect()->back()
            ->with('success', $message);
    }

    /**
     * Get attendance statistics.
     */
    public function statistics(Request $request)
    {
        $startDate = $request->input('start_date', now()->startOfMonth());
        $endDate = $request->input('end_date', now()->endOfMonth());

        $stats = [
            'total' => Attendance::dateRange($startDate, $endDate)->count(),
            'on_time' => Attendance::dateRange($startDate, $endDate)->byStatus('on_time')->count(),
            'tardy' => Attendance::dateRange($startDate, $endDate)->byStatus('tardy')->count(),
            'half_day' => Attendance::dateRange($startDate, $endDate)->byStatus('half_day_absence')->count(),
            'ncns' => Attendance::dateRange($startDate, $endDate)->byStatus('ncns')->count(),
            'advised' => Attendance::dateRange($startDate, $endDate)->byStatus('advised_absence')->count(),
            'needs_verification' => Attendance::dateRange($startDate, $endDate)->needsVerification()->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Display the attendance dashboard with statistics.
     */
    public function dashboard(Request $request)
    {
        $startDate = $request->input('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->endOfMonth()->format('Y-m-d'));

        $stats = [
            'total' => Attendance::dateRange($startDate, $endDate)->count(),
            'on_time' => Attendance::dateRange($startDate, $endDate)->byStatus('on_time')->count(),
            'tardy' => Attendance::dateRange($startDate, $endDate)->byStatus('tardy')->count(),
            'half_day' => Attendance::dateRange($startDate, $endDate)->byStatus('half_day_absence')->count(),
            'ncns' => Attendance::dateRange($startDate, $endDate)->byStatus('ncns')->count(),
            'advised' => Attendance::dateRange($startDate, $endDate)->byStatus('advised_absence')->count(),
            'needs_verification' => Attendance::dateRange($startDate, $endDate)->needsVerification()->count(),
        ];

        return Inertia::render('Attendance/Dashboard', [
            'statistics' => $stats,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    /**
     * Delete multiple attendance records.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:attendances,id',
        ]);

        $count = Attendance::whereIn('id', $request->ids)->delete();

        return redirect()->back()
            ->with('success', "Successfully deleted {$count} attendance record" . ($count === 1 ? '' : 's') . '.');
    }
}
