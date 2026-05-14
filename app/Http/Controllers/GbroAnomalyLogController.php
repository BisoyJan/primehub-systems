<?php

namespace App\Http\Controllers;

use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\AttendancePoint;
use App\Models\GbroAnomalyLog;
use App\Services\AttendancePoint\GbroAnomalyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class GbroAnomalyLogController extends Controller
{
    use RedirectsWithFlashMessages;

    public function index(Request $request)
    {
        $this->authorize('manage', AttendancePoint::class);

        $filters = $request->only(['type', 'trigger', 'repaired', 'user_id', 'batch_id']);

        $logs = GbroAnomalyLog::query()
            ->with(['user:id,first_name,last_name,email', 'attendancePoint:id,user_id,shift_date,point_type'])
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['trigger'] ?? null, fn ($q, $v) => $q->where('trigger', $v))
            ->when(isset($filters['repaired']) && $filters['repaired'] !== '',
                fn ($q) => $q->where('repaired', filter_var($filters['repaired'], FILTER_VALIDATE_BOOLEAN)))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', (int) $v))
            ->when($filters['batch_id'] ?? null, fn ($q, $v) => $q->where('batch_id', $v))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'total' => GbroAnomalyLog::count(),
            'unrepaired' => GbroAnomalyLog::where('repaired', false)->count(),
            'last_24h' => GbroAnomalyLog::where('created_at', '>=', now()->subDay())->count(),
            'by_type' => GbroAnomalyLog::query()
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type'),
        ];

        return Inertia::render('Attendance/Points/AnomalyLogs/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'stats' => $stats,
            'types' => [
                'STALE_PENDING_GBRO',
                'STALE_PENDING_SRO',
                'ORPHAN_GBRO_DATE',
                'EXCUSED_HAS_GBRO_DATE',
                'GBRO_ELIGIBILITY_MISMATCH',
                'EXPIRES_AT_OVERFLOW',
            ],
            'triggers' => ['unexcuse', 'excuse', 'manual_write', 'manual_point_create', 'manual_point_update', 'manual_point_delete', 'scheduled', 'manual_run'],
        ]);
    }

    public function runAudit(Request $request, GbroAnomalyService $service)
    {
        $this->authorize('manage', AttendancePoint::class);

        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'dry_run' => 'nullable|boolean',
        ]);

        try {
            $result = $service->repair(
                $validated['user_id'] ?? null,
                'manual_run',
                (bool) ($validated['dry_run'] ?? false),
            );

            $msg = "Audit complete. Detected {$result['detected']} anomaly/anomalies across {$result['affected_users']} user(s); repaired {$result['repaired']} record(s).";

            return $this->backWithFlash($msg, $result['detected'] > 0 ? 'warning' : 'success');
        } catch (\Throwable $e) {
            Log::error('GbroAnomalyLogController runAudit Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to run anomaly audit.', 'error');
        }
    }

    public function clearLogs(Request $request)
    {
        $this->authorize('manage', AttendancePoint::class);

        $validated = $request->validate([
            'scope' => 'required|in:repaired,all',
        ]);

        try {
            $query = GbroAnomalyLog::query();

            if ($validated['scope'] === 'repaired') {
                $query->where('repaired', true);
            }

            $count = $query->count();
            $query->delete();

            $label = $validated['scope'] === 'repaired' ? 'repaired' : 'all';

            return $this->backWithFlash("Cleared {$count} {$label} log(s).", 'success');
        } catch (\Throwable $e) {
            Log::error('GbroAnomalyLogController clearLogs Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to clear logs.', 'error');
        }
    }
}
