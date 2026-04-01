<?php

namespace App\Console\Commands;

use App\Models\BreakPolicy;
use App\Models\BreakSession;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanOldBreakSessions extends Command
{
    protected $signature = 'break-timer:clean-old-sessions
                            {--force : Skip confirmation prompt}
                            {--months= : Override retention policy and use specific months}';

    protected $description = 'Delete break sessions and events older than the configured retention period';

    public function handle(): int
    {
        $this->info('Starting cleanup of break sessions based on retention policy...');

        $manualMonths = $this->option('months');

        if ($manualMonths) {
            $this->warn("Using manual override: {$manualMonths} months retention");

            return $this->cleanup((int) $manualMonths);
        }

        $policy = BreakPolicy::query()->where('is_active', true)->first();

        if (! $policy) {
            $this->warn('No active break policy found. Skipping cleanup.');

            return self::SUCCESS;
        }

        if (! $policy->retention_months) {
            $this->info('Active policy has no retention period configured. Skipping cleanup.');

            return self::SUCCESS;
        }

        $this->info("Using active policy \"{$policy->name}\" - Retention: {$policy->retention_months} months");

        return $this->cleanup($policy->retention_months);
    }

    protected function cleanup(int $retentionMonths): int
    {
        $cutoffDate = Carbon::now()->subMonths($retentionMonths)->startOfDay();

        $this->info("Cutoff date: {$cutoffDate->format('Y-m-d')}");

        $count = BreakSession::query()
            ->where('shift_date', '<', $cutoffDate->format('Y-m-d'))
            ->count();

        if ($count === 0) {
            $this->info('No old break sessions found to delete.');

            return self::SUCCESS;
        }

        $this->warn("Found {$count} break sessions to delete.");

        if (! $this->option('force') && $this->input->isInteractive()) {
            if (! $this->confirm('Do you wish to continue?')) {
                $this->info('Cleanup cancelled.');

                return self::SUCCESS;
            }
        }

        $deleted = DB::transaction(function () use ($cutoffDate) {
            $sessions = BreakSession::query()
                ->where('shift_date', '<', $cutoffDate->format('Y-m-d'));

            // Break events cascade on session delete via foreign key,
            // but we explicitly delete them for accurate count logging
            $eventCount = DB::table('break_events')
                ->whereIn('break_session_id', (clone $sessions)->select('id'))
                ->delete();

            $sessionCount = $sessions->delete();

            return ['sessions' => $sessionCount, 'events' => $eventCount];
        });

        $this->info("Deleted {$deleted['sessions']} break sessions and {$deleted['events']} break events.");

        Log::info('Break sessions cleanup completed', [
            'sessions_deleted' => $deleted['sessions'],
            'events_deleted' => $deleted['events'],
            'cutoff_date' => $cutoffDate->format('Y-m-d'),
            'retention_months' => $retentionMonths,
            'cleanup_date' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        return self::SUCCESS;
    }
}
