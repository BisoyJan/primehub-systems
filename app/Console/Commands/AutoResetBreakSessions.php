<?php

namespace App\Console\Commands;

use App\Models\BreakEvent;
use App\Models\BreakPolicy;
use App\Models\BreakSession;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoResetBreakSessions extends Command
{
    /**
     * Maximum hours a break session can stay active/paused before being force-closed
     * regardless of policy or shift_date. Catches sessions that slip past the
     * shift-reset logic (missing/invalid shift_date, no active policy, scheduler
     * downtime, etc.).
     */
    private const MAX_AGE_HOURS = 12;

    protected $signature = 'break-timer:auto-reset
                            {--max-age-hours= : Override the hard age safety net (default 12)}
                            {--dry-run : Show what would be ended without making changes}';

    protected $description = 'Auto-end orphaned break sessions from previous shifts and any session older than the safety threshold';

    public function handle(): int
    {
        $maxAgeHours = (int) ($this->option('max-age-hours') ?: self::MAX_AGE_HOURS);
        $dryRun = (bool) $this->option('dry-run');

        $policy = BreakPolicy::query()->where('is_active', true)->first();
        $now = Carbon::now();

        // 1. Sessions older than the safety threshold (always run, even if no policy).
        $ageCutoff = $now->copy()->subHours($maxAgeHours);
        $aged = BreakSession::query()
            ->whereIn('status', ['active', 'paused'])
            ->where('started_at', '<', $ageCutoff)
            ->get();

        // 2. Sessions from previous shifts (only when an active policy exists).
        $previousShift = collect();
        if ($policy) {
            $resetTime = $policy->shift_reset_time ?? '06:00';
            $todayReset = Carbon::today()->setTimeFromTimeString($resetTime);
            $currentShiftDate = $now->lt($todayReset)
                ? Carbon::yesterday()->toDateString()
                : $now->toDateString();

            $previousShift = BreakSession::query()
                ->whereIn('status', ['active', 'paused'])
                ->where('shift_date', '<', $currentShiftDate)
                ->whereNotIn('id', $aged->pluck('id'))
                ->get();
        } else {
            $this->warn('No active break policy found — skipping shift-reset check (safety net still applies).');
        }

        $orphaned = $aged->merge($previousShift);

        if ($orphaned->isEmpty()) {
            $this->info('No orphaned sessions found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['ID', 'User', 'Type', 'Status', 'Started', 'Age (h)', 'Reason'],
                $orphaned->map(fn (BreakSession $s) => [
                    $s->id,
                    $s->user_id,
                    $s->type,
                    $s->status,
                    $s->started_at,
                    round($now->diffInMinutes($s->started_at, absolute: true) / 60, 1),
                    $aged->contains('id', $s->id) ? "Older than {$maxAgeHours}h" : 'Previous shift',
                ])->all()
            );
            $this->info("Dry-run: {$orphaned->count()} session(s) would be auto-ended.");

            return self::SUCCESS;
        }

        $count = $this->autoEnd($orphaned, $aged, $maxAgeHours);

        $this->info("Auto-ended {$count} orphaned session(s).");
        Log::info("Break timer auto-reset: ended {$count} orphaned session(s).");

        return self::SUCCESS;
    }

    private function autoEnd(Collection $orphaned, Collection $aged, int $maxAgeHours): int
    {
        $count = 0;

        DB::transaction(function () use ($orphaned, $aged, $maxAgeHours, &$count) {
            foreach ($orphaned as $session) {
                // Lock to prevent race with end / force-end.
                $locked = BreakSession::query()->whereKey($session->id)->lockForUpdate()->first();
                if (! $locked || ! in_array($locked->status, ['active', 'paused'], true)) {
                    continue;
                }
                $session = $locked;

                $totalPaused = (int) $session->total_paused_seconds;

                if ($session->status === 'paused') {
                    $lastPauseEvent = $session->breakEvents()
                        ->where('action', 'pause')
                        ->latest('occurred_at')
                        ->first();

                    if ($lastPauseEvent) {
                        $totalPaused += (int) now()->diffInSeconds($lastPauseEvent->occurred_at, absolute: true);
                    }
                }

                $elapsed = (int) now()->diffInSeconds($session->started_at, absolute: true) - $totalPaused;
                $overage = max(0, $elapsed - (int) $session->duration_seconds);

                $session->update([
                    'status' => 'auto_ended',
                    'ended_by' => 'system',
                    'ended_at' => now(),
                    'remaining_seconds' => 0,
                    'overage_seconds' => $overage,
                    'total_paused_seconds' => $totalPaused,
                ]);

                $reason = $aged->contains('id', $session->id)
                    ? "Auto-ended: session exceeded {$maxAgeHours}h safety threshold"
                    : 'Automatic shift reset';

                BreakEvent::create([
                    'break_session_id' => $session->id,
                    'action' => 'auto_end',
                    'remaining_seconds' => 0,
                    'overage_seconds' => $overage,
                    'reason' => $reason,
                    'occurred_at' => now(),
                ]);

                $count++;
            }
        });

        return $count;
    }
}
