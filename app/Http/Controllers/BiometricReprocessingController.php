<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\BiometricRecord;
use App\Models\User;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BiometricReprocessingController extends Controller
{
    protected AttendanceProcessor $processor;

    public function __construct(AttendanceProcessor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Display the reprocessing interface
     */
    public function index()
    {
        return Inertia::render('Attendance/BiometricRecords/Reprocessing', [
            'stats' => [
                'total_records' => BiometricRecord::count(),
                'oldest_record' => BiometricRecord::orderBy('datetime')->first()?->datetime,
                'newest_record' => BiometricRecord::orderBy('datetime', 'desc')->first()?->datetime,
            ]
        ]);
    }

    /**
     * Preview what will be reprocessed
     */
    public function preview(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $query = BiometricRecord::whereBetween('record_date', [$startDate, $endDate]);

        if ($request->user_ids) {
            $query->whereIn('user_id', $request->user_ids);
        }

        $records = $query->with('user:id,first_name,last_name')->get();

        $affectedUsers = $records->pluck('user')->unique('id')->values();
        $affectedDates = $records->pluck('record_date')->unique()->sort()->values();

        return back()->with([
            'preview' => [
                'total_records' => $records->count(),
                'affected_users' => $affectedUsers->count(),
                'affected_dates' => $affectedDates->count(),
                'date_range' => [
                    'start' => $affectedDates->first(),
                    'end' => $affectedDates->last(),
                ],
                'users' => $affectedUsers->map(fn($u) => [
                    'id' => $u->id,
                    'name' => $u->first_name . ' ' . $u->last_name,
                    'record_count' => $records->where('user_id', $u->id)->count(),
                ])->toArray(),
            ],
        ]);
    }

    /**
     * Execute reprocessing
     */
    public function reprocess(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
            'delete_existing' => 'boolean',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $deleteExisting = $request->boolean('delete_existing', true);

        // Get affected users
        $userIds = $request->user_ids ?: BiometricRecord::whereBetween('record_date', [$startDate, $endDate])
            ->distinct()
            ->pluck('user_id')
            ->filter()
            ->toArray();

        $users = User::whereIn('id', $userIds)->get();

        $results = [
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
            'details' => [],
        ];

        foreach ($users as $user) {
            try {
                // Get biometric records for this user in date range
                $records = BiometricRecord::where('user_id', $user->id)
                    ->whereBetween('record_date', [$startDate, $endDate])
                    ->orderBy('datetime')
                    ->get()
                    ->map(fn($r) => [
                        'datetime' => $r->datetime,
                        'normalized_name' => $r->employee_name,
                        'employee_name' => $r->employee_name,
                    ]);

                if ($records->isEmpty()) {
                    continue;
                }

                // Delete existing attendance if requested
                if ($deleteExisting) {
                    $deleted = Attendance::where('user_id', $user->id)
                        ->whereBetween('shift_date', [$startDate, $endDate])
                        ->delete();
                }

                // Group and process records by shift date
                $reflection = new \ReflectionClass($this->processor);

                $groupMethod = $reflection->getMethod('groupRecordsByShiftDate');
                $groupMethod->setAccessible(true);
                $grouped = $groupMethod->invoke($this->processor, $records, $user);

                $processMethod = $reflection->getMethod('processShift');
                $processMethod->setAccessible(true);

                $processedShifts = 0;
                foreach ($grouped as $shiftDate => $shiftRecords) {
                    $result = $processMethod->invoke($this->processor, $user, $shiftRecords, Carbon::parse($shiftDate));
                    if ($result['processed']) {
                        $processedShifts++;
                    }
                }

                $results['processed']++;
                $results['details'][] = [
                    'user' => $user->first_name . ' ' . $user->last_name,
                    'shifts_processed' => $processedShifts,
                    'records_count' => $records->count(),
                ];

            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'user' => $user->first_name . ' ' . $user->last_name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return back()->with([
            'success' => "Reprocessed {$results['processed']} employees successfully",
            'results' => $results,
        ]);
    }
}
