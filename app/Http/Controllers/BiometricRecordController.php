<?php

namespace App\Http\Controllers;

use App\Models\BiometricRecord;
use App\Models\User;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class BiometricRecordController extends Controller
{
    /**
     * Display a listing of biometric records.
     */
    public function index(Request $request)
    {
        $query = BiometricRecord::with(['user', 'site', 'attendanceUpload']);

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by site
        if ($request->filled('site_id')) {
            $query->where('site_id', $request->site_id);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('record_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('record_date', '<=', $request->date_to);
        }

        // Search by employee name
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('employee_name', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('first_name', 'like', "%{$search}%")
                               ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        // Order by datetime descending (most recent first)
        $query->orderBy('datetime', 'desc');

        $records = $query->paginate(50)->withQueryString();

        // Get statistics
        $stats = $this->getStatistics();

        // Get filter options
        $users = User::orderBy('last_name')->orderBy('first_name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                ];
            });
        $sites = Site::orderBy('name')->get(['id', 'name']);

        return Inertia::render('BiometricRecords/Index', [
            'records' => $records,
            'stats' => $stats,
            'filters' => [
                'users' => $users,
                'sites' => $sites,
                'user_id' => $request->user_id,
                'site_id' => $request->site_id,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'search' => $request->search,
            ],
        ]);
    }

    /**
     * Get statistics about biometric records.
     */
    protected function getStatistics(): array
    {
        $total = BiometricRecord::count();
        $today = BiometricRecord::whereDate('record_date', Carbon::today())->count();
        $thisWeek = BiometricRecord::whereBetween('record_date', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek()
        ])->count();
        $thisMonth = BiometricRecord::whereYear('record_date', Carbon::now()->year)
            ->whereMonth('record_date', Carbon::now()->month)
            ->count();

        // Records older than 3 months (eligible for deletion)
        $oldRecords = BiometricRecord::olderThan(3)->count();

        // Oldest record date
        $oldestRecord = BiometricRecord::orderBy('record_date')->first();
        $oldestDate = $oldestRecord ? $oldestRecord->record_date->format('M d, Y') : 'N/A';

        // Newest record date
        $newestRecord = BiometricRecord::orderBy('record_date', 'desc')->first();
        $newestDate = $newestRecord ? $newestRecord->record_date->format('M d, Y') : 'N/A';

        // Next cleanup date (assuming daily at 2 AM)
        $nextCleanup = Carbon::tomorrow()->setTime(2, 0, 0)->format('M d, Y H:i A');

        return [
            'total' => $total,
            'today' => $today,
            'this_week' => $thisWeek,
            'this_month' => $thisMonth,
            'old_records' => $oldRecords,
            'oldest_date' => $oldestDate,
            'newest_date' => $newestDate,
            'next_cleanup' => $nextCleanup,
        ];
    }

    /**
     * Show records for a specific user on a specific date.
     */
    public function show(Request $request, int $userId, string $date)
    {
        $user = User::findOrFail($userId);
        $targetDate = Carbon::parse($date);

        $records = BiometricRecord::with(['site', 'attendanceUpload'])
            ->where('user_id', $userId)
            ->whereDate('record_date', $targetDate)
            ->orderBy('datetime')
            ->get();

        return Inertia::render('BiometricRecords/Show', [
            'user' => $user,
            'date' => $targetDate->format('Y-m-d'),
            'records' => $records,
        ]);
    }
}
