<?php

namespace App\Services\AttendancePoint;

use App\Models\AttendancePoint;
use App\Models\GbroAnomalyLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detects and (optionally) repairs GBRO/SRO data drift.
 *
 * This service is a thin orchestrator on top of:
 *   - AttendancePointMaintenanceService::fixAnomalies() (eligibility flags, expires_at overflow,
 *     stale gbro_expires_at on non-eligible rows, overdue SRO expirations)
 *   - GbroCalculationService::cascadeRecalculateGbro($userId) (recompute GBRO per user)
 *
 * Detection is pure read; repair persists log rows then invokes the existing
 * fixers + cascade. Designed to fire automatically on the unexcuse and manual
 * write paths so freshly-shifted state cannot drift between user actions.
 *
 * Anomaly types:
 *   STALE_PENDING_GBRO     gbro_expires_at <= today, not expired, not excused
 *   STALE_PENDING_SRO      expires_at <= today, not expired, not excused
 *   ORPHAN_GBRO_DATE       eligible_for_gbro=false but gbro_expires_at set
 *   EXCUSED_HAS_GBRO_DATE  is_excused=true but gbro_expires_at set
 *   GBRO_ELIGIBILITY_MISMATCH  eligible_for_gbro flag wrong vs. point_type+is_advised
 *   EXPIRES_AT_OVERFLOW    expires_at doesn't match shift_date + (1y FTN/NCNS or 6mo)
 */
class GbroAnomalyService
{
    public function __construct(
        protected GbroCalculationService $gbroService,
        protected AttendancePointMaintenanceService $maintenance,
    ) {}

    /**
     * Detect anomalies (read-only).
     *
     * @return Collection<int, array{type:string,user_id:?int,attendance_point_id:?int,expected:?string,actual:?string,context:?array}>
     */
    public function detect(?int $userId = null): Collection
    {
        $anomalies = collect();

        // STALE_PENDING_GBRO
        $stalePendingGbro = AttendancePoint::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where('is_expired', false)
            ->where('is_excused', false)
            ->whereNotNull('gbro_expires_at')
            ->whereDate('gbro_expires_at', '<=', today())
            ->get(['id', 'user_id', 'gbro_expires_at']);
        foreach ($stalePendingGbro as $p) {
            $anomalies->push([
                'type' => 'STALE_PENDING_GBRO',
                'user_id' => $p->user_id,
                'attendance_point_id' => $p->id,
                'expected' => 'is_expired=true',
                'actual' => 'is_expired=false; gbro_expires_at='.optional($p->gbro_expires_at)->format('Y-m-d'),
                'context' => null,
            ]);
        }

        // STALE_PENDING_SRO
        $stalePendingSro = AttendancePoint::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where('is_expired', false)
            ->where('is_excused', false)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', today())
            ->get(['id', 'user_id', 'expires_at']);
        foreach ($stalePendingSro as $p) {
            $anomalies->push([
                'type' => 'STALE_PENDING_SRO',
                'user_id' => $p->user_id,
                'attendance_point_id' => $p->id,
                'expected' => 'is_expired=true',
                'actual' => 'is_expired=false; expires_at='.optional($p->expires_at)->format('Y-m-d'),
                'context' => null,
            ]);
        }

        // ORPHAN_GBRO_DATE
        $orphans = AttendancePoint::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where('eligible_for_gbro', false)
            ->whereNotNull('gbro_expires_at')
            ->get(['id', 'user_id', 'gbro_expires_at']);
        foreach ($orphans as $p) {
            $anomalies->push([
                'type' => 'ORPHAN_GBRO_DATE',
                'user_id' => $p->user_id,
                'attendance_point_id' => $p->id,
                'expected' => 'gbro_expires_at=null',
                'actual' => optional($p->gbro_expires_at)->format('Y-m-d'),
                'context' => null,
            ]);
        }

        // EXCUSED_HAS_GBRO_DATE
        $excusedWithDate = AttendancePoint::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where('is_excused', true)
            ->whereNotNull('gbro_expires_at')
            ->get(['id', 'user_id', 'gbro_expires_at']);
        foreach ($excusedWithDate as $p) {
            $anomalies->push([
                'type' => 'EXCUSED_HAS_GBRO_DATE',
                'user_id' => $p->user_id,
                'attendance_point_id' => $p->id,
                'expected' => 'gbro_expires_at=null',
                'actual' => optional($p->gbro_expires_at)->format('Y-m-d'),
                'context' => null,
            ]);
        }

        // GBRO_ELIGIBILITY_MISMATCH
        // Rule (mirrors fixAnomalies):
        //  - whole_day_absence + is_advised=true  → eligible=true
        //  - whole_day_absence + is_advised=false → eligible=false (FTN/NCNS)
        //  - any other point_type                 → eligible=true
        $mismatched = AttendancePoint::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where('is_expired', false)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('point_type', 'whole_day_absence')
                        ->where('is_advised', true)
                        ->where('eligible_for_gbro', false);
                })->orWhere(function ($q2) {
                    $q2->where('point_type', 'whole_day_absence')
                        ->where('is_advised', false)
                        ->where('eligible_for_gbro', true);
                })->orWhere(function ($q2) {
                    $q2->where('point_type', '!=', 'whole_day_absence')
                        ->where('eligible_for_gbro', false);
                });
            })
            ->get(['id', 'user_id', 'point_type', 'is_advised', 'eligible_for_gbro']);
        foreach ($mismatched as $p) {
            $expected = ($p->point_type === 'whole_day_absence' && ! $p->is_advised) ? 'false' : 'true';
            $anomalies->push([
                'type' => 'GBRO_ELIGIBILITY_MISMATCH',
                'user_id' => $p->user_id,
                'attendance_point_id' => $p->id,
                'expected' => 'eligible_for_gbro='.$expected,
                'actual' => 'eligible_for_gbro='.($p->eligible_for_gbro ? 'true' : 'false'),
                'context' => ['point_type' => $p->point_type, 'is_advised' => (bool) $p->is_advised],
            ]);
        }

        // EXPIRES_AT_OVERFLOW (month-end overflow correction).
        $candidates = AttendancePoint::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->whereNotNull('expires_at')
            ->get(['id', 'user_id', 'shift_date', 'expires_at', 'point_type', 'eligible_for_gbro']);
        foreach ($candidates as $p) {
            $shift = Carbon::parse($p->shift_date);
            $expectedExpires = ($p->point_type === 'whole_day_absence' && ! $p->eligible_for_gbro)
                ? $shift->copy()->addYearNoOverflow()
                : $shift->copy()->addMonthsNoOverflow(6);
            $expectedFmt = $expectedExpires->format('Y-m-d');
            $actualFmt = optional($p->expires_at)->format('Y-m-d');
            if ($actualFmt !== $expectedFmt) {
                $anomalies->push([
                    'type' => 'EXPIRES_AT_OVERFLOW',
                    'user_id' => $p->user_id,
                    'attendance_point_id' => $p->id,
                    'expected' => $expectedFmt,
                    'actual' => $actualFmt,
                    'context' => ['shift_date' => $shift->format('Y-m-d')],
                ]);
            }
        }

        return $anomalies;
    }

    /**
     * Detect + repair. Returns summary with counts. Always persists found
     * anomalies (to gbro_anomaly_logs) and writes a Log::warning.
     *
     * @return array{batch_id:string,detected:int,repaired:int,affected_users:int,by_type:array<string,int>,maintenance:?array}
     */
    public function repair(?int $userId = null, string $trigger = 'manual_run', bool $dryRun = false): array
    {
        $batchId = 'audit_'.now()->format('YmdHis').'_'.bin2hex(random_bytes(3));

        $anomalies = $this->detect($userId);
        $detected = $anomalies->count();
        $byType = $anomalies->countBy('type')->toArray();
        $affectedUsers = $anomalies->pluck('user_id')->filter()->unique()->values();

        if ($detected === 0) {
            return [
                'batch_id' => $batchId,
                'detected' => 0,
                'repaired' => 0,
                'affected_users' => 0,
                'by_type' => [],
                'maintenance' => null,
            ];
        }

        $this->persistAnomalies($anomalies, $batchId, $trigger, repaired: ! $dryRun);

        if ($dryRun) {
            Log::warning('GBRO anomaly audit (dry-run) detected drift', [
                'batch_id' => $batchId,
                'trigger' => $trigger,
                'user_id' => $userId,
                'detected' => $detected,
                'by_type' => $byType,
            ]);

            return [
                'batch_id' => $batchId,
                'detected' => $detected,
                'repaired' => 0,
                'affected_users' => $affectedUsers->count(),
                'by_type' => $byType,
                'maintenance' => null,
            ];
        }

        // Repair pipeline:
        //  1) Run global maintenance fixAnomalies() — handles eligibility flags,
        //     overflow, orphan dates, overdue SRO. Already cascade-recalculates
        //     for users whose eligibility flags changed.
        //  2) Cascade-recalc GBRO for every other affected user (covers stale
        //     pending GBRO, excused-with-date, etc.) so their state is rebuilt.
        //  3) Sweep stale-pending GBRO that may remain after cascade (defensive).
        $maintenance = $this->maintenance->fixAnomalies();

        DB::transaction(function () use ($affectedUsers) {
            foreach ($affectedUsers as $uid) {
                try {
                    $this->gbroService->cascadeRecalculateGbro((int) $uid);
                } catch (\Throwable $e) {
                    Log::error('GbroAnomalyService cascade error', [
                        'user_id' => $uid,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });

        // Defensive sweep: any GBRO date still in the past on an active row → expire.
        $sweptGbro = 0;
        AttendancePoint::query()
            ->where('is_expired', false)
            ->where('is_excused', false)
            ->whereNotNull('gbro_expires_at')
            ->whereDate('gbro_expires_at', '<=', today())
            ->cursor()
            ->each(function (AttendancePoint $p) use (&$sweptGbro) {
                DB::transaction(function () use ($p) {
                    $p->update([
                        'is_expired' => true,
                        'expiration_type' => 'gbro',
                        'expired_at' => now(),
                        'gbro_applied_at' => $p->gbro_expires_at,
                    ]);
                });
                $sweptGbro++;
            });

        Log::warning('GBRO anomaly audit repaired drift', [
            'batch_id' => $batchId,
            'trigger' => $trigger,
            'user_id' => $userId,
            'detected' => $detected,
            'by_type' => $byType,
            'affected_users' => $affectedUsers->count(),
            'maintenance_summary' => $maintenance,
            'swept_gbro_after_cascade' => $sweptGbro,
        ]);

        return [
            'batch_id' => $batchId,
            'detected' => $detected,
            'repaired' => $detected + $sweptGbro,
            'affected_users' => $affectedUsers->count(),
            'by_type' => $byType,
            'maintenance' => $maintenance,
        ];
    }

    /**
     * Insert one log row per detected anomaly.
     */
    protected function persistAnomalies(Collection $anomalies, string $batchId, string $trigger, bool $repaired): void
    {
        $now = now();
        $rows = $anomalies->map(fn (array $a) => [
            'batch_id' => $batchId,
            'trigger' => $trigger,
            'user_id' => $a['user_id'] ?? null,
            'attendance_point_id' => $a['attendance_point_id'] ?? null,
            'type' => $a['type'],
            'expected' => $a['expected'] ?? null,
            'actual' => $a['actual'] ?? null,
            'repaired' => $repaired,
            'context' => isset($a['context']) ? json_encode($a['context']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // Batched insert; chunk to keep parameter count safe.
        foreach (array_chunk($rows, 200) as $chunk) {
            GbroAnomalyLog::insert($chunk);
        }
    }
}
