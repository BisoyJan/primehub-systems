<?php

namespace App\Console\Commands;

use App\Models\BreakRetentionPolicy;
use App\Models\Site;
use App\Services\BreakRetentionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanOldBreakSessions extends Command
{
    protected $signature = 'break-timer:clean-old-sessions
                            {--force : Skip confirmation prompt}
                            {--dry-run : Simulate the cleanup without deleting records}
                            {--months= : Override retention policies and use specific months}';

    protected $description = 'Delete break sessions and events based on configured retention policies';

    public function handle(BreakRetentionService $retention): int
    {
        $this->info('Starting cleanup of break sessions based on retention policies...');

        $manualMonths = $this->option('months');

        if ($manualMonths) {
            $this->warn("Using manual override: {$manualMonths} months retention");

            $cutoffDate = $retention->cutoffDate((int) $manualMonths);
            $totalDeleted = $this->performDelete(
                fn () => $retention->expiredSessions('all', $cutoffDate),
                (int) $manualMonths,
                $cutoffDate,
                'all sessions'
            );
        } else {
            $totalDeleted = $this->cleanupByPolicies($retention);
        }

        if ($totalDeleted === 0) {
            $this->info('No old break sessions found to delete.');

            return self::SUCCESS;
        }

        $action = $this->option('dry-run') ? 'would be deleted' : 'deleted';
        $this->info("Cleanup complete. Total sessions {$action}: {$totalDeleted}");

        return self::SUCCESS;
    }

    /**
     * Each break type is resolved per site, then once more for sessions whose
     * user has no site, so the most specific matching policy always wins.
     */
    protected function cleanupByPolicies(BreakRetentionService $retention): int
    {
        $totalDeleted = 0;
        $sites = Site::all();

        foreach (BreakRetentionPolicy::BREAK_TYPES as $breakType => $config) {
            foreach ([...$sites, null] as $site) {
                $retentionMonths = BreakRetentionPolicy::getRetentionMonths($site?->id, $breakType);
                $cutoffDate = $retention->cutoffDate($retentionMonths);
                $siteName = $site?->name ?? 'No Site';

                $totalDeleted += $this->performDelete(
                    fn () => $retention->scopeToSite($retention->expiredSessions($breakType, $cutoffDate), $site?->id),
                    $retentionMonths,
                    $cutoffDate,
                    "{$config['label']} - {$siteName}"
                );
            }
        }

        return $totalDeleted;
    }

    protected function performDelete(\Closure $queryFactory, int $retentionMonths, Carbon $cutoffDate, string $label): int
    {
        $count = $queryFactory()->count();

        if ($count === 0) {
            return 0;
        }

        $this->warn("  Found {$count} sessions to delete for {$label} (older than {$retentionMonths} months, cutoff {$cutoffDate->format('Y-m-d')}).");

        if ($this->option('dry-run')) {
            $this->info("  [DRY RUN] Would delete {$count} sessions.");

            return $count;
        }

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
