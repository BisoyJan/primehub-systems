<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateBreakTimerExportExcel;
use App\Models\BreakSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

class BreakDashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = now()->toDateString();
        $date = $request->query('date', $today);

        $query = BreakSession::query()
            ->with(['user', 'breakEvents' => fn ($q) => $q->whereIn('action', ['pause', 'resume'])->orderBy('occurred_at')])
            ->where('shift_date', $date)
            ->search($request->query('search'))
            ->orderBy('started_at', 'desc');

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->query('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->query('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        $paginated = $query->paginate(20)->withQueryString();

        $items = $paginated->getCollection()->map(function (BreakSession $session) {
            return [
                'id' => $session->id,
                'session_id' => $session->session_id,
                'user' => $session->user ? [
                    'id' => $session->user->id,
                    'first_name' => $session->user->first_name,
                    'last_name' => $session->user->last_name,
                ] : null,
                'station' => $session->station,
                'type' => $session->type,
                'status' => $session->status,
                'duration_seconds' => $session->duration_seconds,
                'started_at' => $session->started_at?->toDateTimeString(),
                'ended_at' => $session->ended_at?->toDateTimeString(),
                'remaining_seconds' => $session->remaining_seconds,
                'overage_seconds' => $session->overage_seconds,
                'total_paused_seconds' => $session->total_paused_seconds,
                'last_pause_reason' => $session->last_pause_reason,
                'pause_resume_events' => $session->breakEvents->map(fn ($event) => [
                    'action' => $event->action,
                    'occurred_at' => $event->occurred_at?->toDateTimeString(),
                    'reason' => $event->reason,
                ])->values()->toArray(),
            ];
        })->toArray();

        // Live stats for the date
        $sessionsForDate = BreakSession::query()->where('shift_date', $date);
        $stats = [
            'total_sessions' => (clone $sessionsForDate)->count(),
            'active_now' => (clone $sessionsForDate)->whereIn('status', ['active', 'paused'])->count(),
            'completed' => (clone $sessionsForDate)->where('status', 'completed')->count(),
            'overage' => (clone $sessionsForDate)->where('status', 'overage')->count(),
            'avg_overage_seconds' => (int) (clone $sessionsForDate)->where('overage_seconds', '>', 0)->avg('overage_seconds'),
        ];

        return Inertia::render('BreakTimer/Dashboard', [
            'sessions' => [
                'data' => $items,
                'links' => $paginated->toArray()['links'] ?? [],
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'stats' => $stats,
            'filters' => [
                'date' => $date,
                'search' => $request->query('search', ''),
                'status' => $request->query('status', ''),
                'type' => $request->query('type', ''),
                'user_id' => $request->query('user_id', ''),
            ],
            'users' => User::query()
                ->where('is_approved', true)
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function reports(Request $request)
    {
        $startDate = $request->query('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', now()->toDateString());

        $query = BreakSession::query()
            ->with(['user'])
            ->whereBetween('shift_date', [$startDate, $endDate])
            ->search($request->query('search'))
            ->orderBy('shift_date', 'desc')
            ->orderBy('started_at', 'desc');

        if ($request->query('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->query('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        $paginated = $query->paginate(25)->withQueryString();

        $items = $paginated->getCollection()->map(function (BreakSession $session) {
            return [
                'id' => $session->id,
                'session_id' => $session->session_id,
                'user' => $session->user ? [
                    'id' => $session->user->id,
                    'first_name' => $session->user->first_name,
                    'last_name' => $session->user->last_name,
                ] : null,
                'station' => $session->station,
                'type' => $session->type,
                'status' => $session->status,
                'duration_seconds' => $session->duration_seconds,
                'started_at' => $session->started_at?->toDateTimeString(),
                'ended_at' => $session->ended_at?->toDateTimeString(),
                'remaining_seconds' => $session->remaining_seconds,
                'overage_seconds' => $session->overage_seconds,
                'total_paused_seconds' => $session->total_paused_seconds,
                'shift_date' => $session->shift_date?->toDateString(),
                'last_pause_reason' => $session->last_pause_reason,
                'reset_approval' => $session->reset_approval,
            ];
        })->toArray();

        // Summary stats for the period
        $periodQuery = BreakSession::query()->whereBetween('shift_date', [$startDate, $endDate]);
        $summary = [
            'total_sessions' => (clone $periodQuery)->count(),
            'total_overage' => (clone $periodQuery)->where('status', 'overage')->count(),
            'avg_overage_seconds' => (int) (clone $periodQuery)->where('overage_seconds', '>', 0)->avg('overage_seconds'),
            'total_resets' => (clone $periodQuery)->whereNotNull('reset_approval')->count(),
        ];

        return Inertia::render('BreakTimer/Reports', [
            'sessions' => [
                'data' => $items,
                'links' => $paginated->toArray()['links'] ?? [],
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'summary' => $summary,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'search' => $request->query('search', ''),
                'user_id' => $request->query('user_id', ''),
                'type' => $request->query('type', ''),
                'status' => $request->query('status', ''),
            ],
            'users' => User::query()
                ->where('is_approved', true)
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function startExport(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_id' => 'nullable|integer|exists:users,id',
            'type' => 'nullable|string|in:1st_break,2nd_break,lunch,combined',
            'status' => 'nullable|string|in:active,paused,completed,overage',
            'search' => 'nullable|string|max:255',
        ]);

        $count = BreakSession::query()
            ->whereBetween('shift_date', [$request->input('start_date'), $request->input('end_date')])
            ->when($request->input('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->search($v))
            ->count();

        if ($count === 0) {
            return response()->json([
                'error' => true,
                'message' => 'No break session records found matching your selected filters.',
            ], 422);
        }

        $jobId = (string) Str::uuid();

        dispatch_sync(new GenerateBreakTimerExportExcel(
            $jobId,
            $request->input('start_date'),
            $request->input('end_date'),
            $request->input('user_id') ? (int) $request->input('user_id') : null,
            $request->input('type'),
            $request->input('status'),
            $request->input('search'),
        ));

        $tempDir = storage_path('app/temp');
        $pattern = $tempDir."/break_timer_export_*_{$jobId}.xlsx";
        $files = glob($pattern);

        if (empty($files)) {
            return response()->json([
                'error' => true,
                'message' => 'Export generation failed. Please try again.',
            ], 500);
        }

        return response()->download($files[0], basename($files[0]))->deleteFileAfterSend(true);
    }

    public function exportProgress(string $jobId)
    {
        $cacheKey = "break_timer_export_job:{$jobId}";

        return response()->json(Cache::get($cacheKey, [
            'percent' => 0,
            'status' => 'Not started',
            'finished' => false,
            'downloadUrl' => null,
        ]));
    }

    public function downloadExport(string $jobId)
    {
        $cacheKey = "break_timer_export_job:{$jobId}";
        $tempDir = storage_path('app/temp');
        $pattern = $tempDir."/break_timer_export_*_{$jobId}.xlsx";
        $files = glob($pattern);

        if (empty($files)) {
            Cache::forget($cacheKey);
            abort(404, 'Export file not found. Please generate a new export.');
        }

        $filePath = $files[0];
        $filename = basename($filePath);

        Cache::forget($cacheKey);

        return response()->download($filePath, $filename)->deleteFileAfterSend(true);
    }
}
