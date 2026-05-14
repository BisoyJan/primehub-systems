<?php

namespace App\Console\Commands;

use App\Services\AttendancePoint\GbroAnomalyService;
use Illuminate\Console\Command;

class AuditGbroAnomalies extends Command
{
    protected $signature = 'points:audit-gbro
                            {--user= : Restrict the audit to a single user id}
                            {--dry-run : Detect and persist anomalies without repairing}';

    protected $description = 'Detect and (optionally) repair GBRO/SRO data drift across attendance points.';

    public function handle(GbroAnomalyService $service): int
    {
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $dryRun = (bool) $this->option('dry-run');

        $this->info('GBRO Anomaly Audit'.($userId ? " (user #{$userId})" : ' (all users)').($dryRun ? ' [DRY RUN]' : ''));
        $this->line('====================================');

        $result = $service->repair($userId, 'scheduled', $dryRun);

        $this->newLine();
        $this->line("Batch: <comment>{$result['batch_id']}</comment>");
        $this->line("Detected anomalies: <info>{$result['detected']}</info>");
        $this->line("Affected users: <info>{$result['affected_users']}</info>");
        $this->line('Repaired records: <info>'.$result['repaired'].'</info>');

        if (! empty($result['by_type'])) {
            $this->newLine();
            $this->table(
                ['Anomaly Type', 'Count'],
                collect($result['by_type'])->map(fn ($n, $t) => [$t, $n])->values()->all()
            );
        }

        if (! empty($result['maintenance'])) {
            $this->newLine();
            $this->line('Maintenance summary:');
            $this->line('  SRO expired: '.($result['maintenance']['sro_expired'] ?? 0));
            $this->line('  GBRO dates cleared: '.($result['maintenance']['gbro_dates_cleared'] ?? 0));
            $this->line('  eligible_for_gbro fixed: '.($result['maintenance']['gbro_eligible_fixed'] ?? 0));
            $this->line('  expires_at corrected: '.($result['maintenance']['expires_at_fixed'] ?? 0));
        }

        if ($dryRun && $result['detected'] > 0) {
            $this->warn('Dry run -- no repairs applied. Re-run without --dry-run to fix.');
        }

        return self::SUCCESS;
    }
}
