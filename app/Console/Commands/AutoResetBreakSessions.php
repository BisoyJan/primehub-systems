<?php

namespace App\Console\Commands;

use App\Models\BreakEvent;
use App\Models\BreakPolicy;
use App\Models\BreakSession;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoResetBreakSessions extends Command
{
    protected $signature = 'break-timer:auto-reset';

    protected $description = 'Auto-end orphaned break sessions from previous shifts based on the policy shift reset time';

    public function handle(): int
    {
        $policy = BreakPolicy::query()->where('is_active', true)->first();

        if (! $policy) {
            $this->info('No active break policy found. Skipping.');

            return self::SUCCESS;
        }

        $resetTime = $policy->shift_reset_time ?? '06:00';
        $now = Carbon::now();
        $todayReset = Carbon::today()->setTimeFromTimeString($resetTime);

        // Only run if we've passed the reset time for today
        if ($now->lt($todayReset)) {
            $this->info("Reset time {$resetTime} has not been reached yet. Skipping.");

            return self::SUCCESS;
        }

        $orphanedSessions = BreakSession::query()
            ->whereIn('status', ['active', 'paused'])
            ->where('shift_date', '<', $now->toDateString())
            ->get();

        if ($orphanedSessions->isEmpty()) {
            $this->info('No orphaned sessions found.');

            return self::SUCCESS;
        }

        $count = 0;

        DB::transaction(function () use ($orphanedSessions, &$count) {
            foreach ($orphanedSessions as $session) {
                $totalPaused = $session->total_paused_seconds;

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
                $overage = max(0, $elapsed - $session->duration_seconds);

                $session->update([
                    'status' => 'auto_ended',
                    'ended_at' => now(),
                    'remaining_seconds' => 0,
                    'overage_seconds' => $overage,
                    'total_paused_seconds' => $totalPaused,
                ]);

                BreakEvent::create([
                    'break_session_id' => $session->id,
                    'action' => 'auto_end',
                    'remaining_seconds' => 0,
                    'overage_seconds' => $overage,
                    'reason' => 'Automatic shift reset',
                    'occurred_at' => now(),
                ]);

                $count++;
            }
        });

        $this->info("Auto-ended {$count} orphaned session(s).");
        Log::info("Break timer auto-reset: ended {$count} orphaned session(s).");

        return self::SUCCESS;
    }
}
