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

        return Inertia::render('Attendance/Main/Index', [
            'attendances' => $attendances,
            'filters' => $request->only(['search', 'status', 'start_date', 'end_date', 'user_id', 'needs_verification']),
        ]);
    }

    /**
     * Show the upload form.
     */
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
            'shift_date' => 'required|date',
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
            'shift_date' => $request->shift_date,
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
        ])
            ->needsVerification();

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
        $request->validate([
            'status' => 'required|in:on_time,tardy,half_day_absence,advised_absence,ncns,undertime,failed_bio_in,failed_bio_out,present_no_bio',
            'actual_time_in' => 'nullable|date',
            'actual_time_out' => 'nullable|date',
            'verification_notes' => 'required|string|max:1000',
        ]);

        $oldStatus = $attendance->status;

        $attendance->update([
            'status' => $request->status,
            'actual_time_in' => $request->actual_time_in,
            'actual_time_out' => $request->actual_time_out,
            'admin_verified' => true,
            'verification_notes' => $request->verification_notes,
        ]);

        // Recalculate tardy/undertime if times provided
        if ($request->actual_time_in && $attendance->scheduled_time_in) {
            $shiftDate = Carbon::parse($attendance->shift_date);
            $scheduled = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attendance->scheduled_time_in);
            $actual = Carbon::parse($request->actual_time_in);
            $tardyMinutes = $scheduled->diffInMinutes($actual, false);

            if ($tardyMinutes > 0) {
                $attendance->update(['tardy_minutes' => $tardyMinutes]);
            }
        }

        // Regenerate attendance points if status changed
        if ($oldStatus !== $request->status) {
            // Delete existing points for this attendance record
            AttendancePoint::where('attendance_id', $attendance->id)->delete();

            // Generate new points if the new status requires them
            if (in_array($request->status, ['ncns', 'half_day_absence', 'tardy', 'undertime'])) {
                $this->processor->regeneratePointsForAttendance($attendance);
            }
        }

        return redirect()->back()
            ->with('success', 'Attendance record verified and updated successfully.');
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
