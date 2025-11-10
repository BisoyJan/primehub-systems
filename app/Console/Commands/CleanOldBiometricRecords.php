<?php

namespace App\Console\Commands;

use App\Models\BiometricRecord;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanOldBiometricRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometric:clean-old-records {--months=3 : Number of months to retain records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete biometric records older than specified months (default: 3 months)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $months = (int) $this->option('months');

        $this->info("Starting cleanup of biometric records older than {$months} months...");

        $cutoffDate = Carbon::now()->subMonths($months);
        $this->info("Cutoff date: {$cutoffDate->format('Y-m-d H:i:s')}");

        // Get count before deletion
        $count = BiometricRecord::olderThan($months)->count();

        if ($count === 0) {
            $this->info('No old records found to delete.');
            return self::SUCCESS;
        }

        $this->warn("Found {$count} records to delete.");

        if ($this->confirm('Do you want to proceed with deletion?', true)) {
            // Delete old records
            $deleted = BiometricRecord::olderThan($months)->delete();

            $this->info("Successfully deleted {$deleted} biometric records.");

            // Log the cleanup
            \Log::info('Biometric records cleanup completed', [
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
