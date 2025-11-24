<?php

namespace App\Console\Commands;

use App\Models\BiometricRecord;
use App\Models\BiometricRetentionPolicy;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanOldBiometricRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometric:clean-old-records
                            {--force : Skip confirmation prompt}
                            {--months= : Override retention policy and use specific months}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete biometric records based on configured retention policies';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting cleanup of biometric records based on retention policies...');

        // Check if manual months override is provided
        $manualMonths = $this->option('months');

        if ($manualMonths) {
            $this->warn("Using manual override: {$manualMonths} months retention");
            return $this->cleanupWithMonths((int) $manualMonths);
        }

        // Use retention policies
        return $this->cleanupWithPolicies();
    }

    /**
     * Clean up records based on retention policies
     */
    protected function cleanupWithPolicies(): int
    {
        $totalDeleted = 0;
        $sites = Site::all();

        // Process site-specific policies
        foreach ($sites as $site) {
            $retentionMonths = BiometricRetentionPolicy::getRetentionMonths($site->id);
            $deleted = $this->cleanupForSite($site, $retentionMonths);
            $totalDeleted += $deleted;
        }

        // Process records without site_id (use global policy)
        $globalRetentionMonths = BiometricRetentionPolicy::getRetentionMonths(null);
        $deleted = $this->cleanupForSite(null, $globalRetentionMonths);
        $totalDeleted += $deleted;

        if ($totalDeleted === 0) {
            $this->info('No old records found to delete.');
            return self::SUCCESS;
        }

        $this->info("Successfully deleted {$totalDeleted} biometric records in total.");

        // Log the cleanup
        \Log::info('Biometric records cleanup completed', [
            'total_records_deleted' => $totalDeleted,
            'cleanup_date' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        return self::SUCCESS;
    }

    /**
     * Clean up records for a specific site or null site
     */
    protected function cleanupForSite(?Site $site, int $retentionMonths): int
    {
        $cutoffDate = Carbon::now()->subMonths($retentionMonths);
        $siteName = $site ? $site->name : 'No Site (Global)';
        $siteId = $site?->id;

        $this->info("Processing {$siteName} - Retention: {$retentionMonths} months");
        $this->info("  Cutoff date: {$cutoffDate->format('Y-m-d')}");

        // Count records to delete
        $query = BiometricRecord::where('record_date', '<', $cutoffDate->format('Y-m-d'));

        if ($siteId) {
            $query->where('site_id', $siteId);
        } else {
            $query->whereNull('site_id');
        }

        $count = $query->count();

        if ($count === 0) {
            $this->line("  No old records found for {$siteName}");
            return 0;
        }

        $this->warn("  Found {$count} records to delete for {$siteName}");

        // Skip confirmation if --force flag is used or running in scheduled context
        if ($this->option('force') || !$this->input->isInteractive()) {
            $deleted = $query->delete();
            $this->info("  Deleted {$deleted} records from {$siteName}");

            \Log::info('Biometric records cleanup for site', [
                'site_id' => $siteId,
                'site_name' => $siteName,
                'records_deleted' => $deleted,
                'cutoff_date' => $cutoffDate->format('Y-m-d'),
                'retention_months' => $retentionMonths,
            ]);

            return $deleted;
        }

        return 0;
    }

    /**
     * Clean up with manual months override (legacy support)
     */
    protected function cleanupWithMonths(int $months): int
    {
        $cutoffDate = Carbon::now()->subMonths($months);
        $this->info("Cutoff date: {$cutoffDate->format('Y-m-d H:i:s')}");

        // Get count before deletion
        $count = BiometricRecord::olderThan($months)->count();

        if ($count === 0) {
            $this->info('No old records found to delete.');
            return self::SUCCESS;
        }

        $this->warn("Found {$count} records to delete.");

        if ($this->option('force') || $this->confirm('Do you want to proceed with deletion?', true)) {
            // Delete old records
            $deleted = BiometricRecord::olderThan($months)->delete();

            $this->info("Successfully deleted {$deleted} biometric records.");

            // Log the cleanup
            \Log::info('Biometric records cleanup completed (manual override)', [
                'records_deleted' => $deleted,
                'cutoff_date' => $cutoffDate->format('Y-m-d'),
                'months_retained' => $months,
            ]);

            return self::SUCCESS;
        }

        $this->info('Deletion cancelled.');
        return self::FAILURE;
    }
}
