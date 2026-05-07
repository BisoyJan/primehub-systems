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
            $this->cleanup((int) $manualMonths);

            return self::SUCCESS;
        }

        // Per-policy retention: each session is matched against ITS OWN policy's
        // retention_months. This keeps history correct when the active policy is
        // switched (sessions belonging to the previous policy are not silently
        // re-purged under the new policy's rules).
        $policies = BreakPolicy::query()
            ->whereNotNull('retention_months')
            ->where('retention_months', '>', 0)
            ->get();

        if ($policies->isEmpty()) {
            $this->warn('No break policies with retention configured. Skipping cleanup.');

            return self::SUCCESS;
        }

        $totalDeleted = 0;

        foreach ($policies as $policy) {
            $this->info("Policy \"{$policy->name}\" (id={$policy->id}) - Retention: {$policy->retention_months} months");
            $totalDeleted += $this->cleanupForPolicy($policy);
        }

        // Catch-all for sessions whose break_policy_id is NULL (orphaned policy)
        // — fall back to the active policy's retention, or skip if none.
        $activePolicy = $policies->firstWhere('is_active', true) ?? $policies->first();
        $totalDeleted += $this->cleanupOrphans($activePolicy);

        if ($totalDeleted === 0) {
            $this->info('No old break sessions found to delete.');
        } else {
            $this->info("Cleanup complete. Total sessions deleted: {$totalDeleted}");
        }

        return self::SUCCESS;
    }

    /**
     * Cleanup pre-grouped by a specific policy. Used for manual --months override
     * and applies to ALL sessions regardless of their break_policy_id.
     */
    protected function cleanup(int $retentionMonths): int
    {
        $cutoffDate = Carbon::now()->subMonths($retentionMonths)->startOfDay();

        $this->info("Cutoff date: {$cutoffDate->format('Y-m-d')}");

        // Defensive: never delete sessions that are still active/paused, regardless of
        // shift_date. The auto-reset cron should have closed them; if not, leave them
        // for investigation rather than silently wiping live data.
        $query = fn () => BreakSession::query()
            ->where('shift_date', '<', $cutoffDate->format('Y-m-d'))
            ->whereNotIn('status', ['active', 'paused']);

        return $this->performDelete($query, $retentionMonths, $cutoffDate);
    }

    protected function cleanupForPolicy(BreakPolicy $policy): int
    {
        $cutoffDate = Carbon::now()->subMonths($policy->retention_months)->startOfDay();

        $query = fn () => BreakSession::query()
            ->where('break_policy_id', $policy->id)
            ->where('shift_date', '<', $cutoffDate->format('Y-m-d'))
            ->whereNotIn('status', ['active', 'paused']);

        return $this->performDelete($query, $policy->retention_months, $cutoffDate);
    }

    protected function cleanupOrphans(BreakPolicy $fallback): int
    {
        $cutoffDate = Carbon::now()->subMonths($fallback->retention_months)->startOfDay();

        $query = fn () => BreakSession::query()
            ->whereNull('break_policy_id')
            ->where('shift_date', '<', $cutoffDate->format('Y-m-d'))
            ->whereNotIn('status', ['active', 'paused']);

        return $this->performDelete($query, $fallback->retention_months, $cutoffDate, label: 'orphan (no policy)');
    }

    protected function performDelete(\Closure $queryFactory, int $retentionMonths, Carbon $cutoffDate, ?string $label = null): int
    {
        $count = $queryFactory()->count();

        if ($count === 0) {
            $this->info('  No sessions to delete.');

            return 0;
        }

        $this->warn("  Found {$count} sessions to delete".($label ? " ({$label})" : '').'.');

        if (! $this->option('force') && $this->input->isInteractive()) {
            if (! $this->confirm('  Do you wish to continue?')) {
                $this->info('  Cleanup cancelled.');

                return 0;
            }
        }

        $deleted = DB::transaction(function () use ($queryFactory) {
            $sessions = $queryFactory();

            $eventCount = DB::table('break_events')
                ->whereIn('break_session_id', (clone $sessions)->select('id'))
                ->delete();

            $sessionCount = $sessions->delete();

            return ['sessions' => $sessionCount, 'events' => $eventCount];
        });

        $this->info("  Deleted {$deleted['sessions']} sessions and {$deleted['events']} events.");

        Log::info('Break sessions cleanup batch completed', [
            'sessions_deleted' => $deleted['sessions'],
            'events_deleted' => $deleted['events'],
            'cutoff_date' => $cutoffDate->format('Y-m-d'),
            'retention_months' => $retentionMonths,
            'label' => $label,
        ]);

        return $deleted['sessions'];
    }
}
