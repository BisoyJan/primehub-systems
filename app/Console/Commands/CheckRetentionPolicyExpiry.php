<?php

namespace App\Console\Commands;

use App\Models\BiometricRecord;
use App\Models\BiometricRetentionPolicy;
use App\Models\Site;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckRetentionPolicyExpiry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'retention:check-expiry
                            {--days=7 : Days before data deletion to send notification}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check retention policies and notify admins about data that will be deleted soon';

    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $warningDays = (int) $this->option('days');
        $this->info("Checking for data expiring within {$warningDays} days...");

        $sites = Site::all();
        $warnings = [];

        // Check site-specific policies
        foreach ($sites as $site) {
            $retentionMonths = BiometricRetentionPolicy::getRetentionMonths($site->id);
            $warning = $this->checkDataExpiry($site, $retentionMonths, $warningDays);
            if ($warning) {
                $warnings[] = $warning;
            }
        }

        // Check global policy (records without site_id)
        $globalRetentionMonths = BiometricRetentionPolicy::getRetentionMonths(null);
        $warning = $this->checkDataExpiry(null, $globalRetentionMonths, $warningDays);
        if ($warning) {
            $warnings[] = $warning;
        }

        if (empty($warnings)) {
            $this->info('No data is expiring soon. No notifications sent.');
            return self::SUCCESS;
        }

        // Send notifications to Admin and Super Admin
        $this->sendExpiryNotifications($warnings, $warningDays);

        $this->info('Expiry notifications sent to Admin and Super Admin.');
        return self::SUCCESS;
    }

    /**
     * Check if data is expiring soon for a specific site
     */
    protected function checkDataExpiry(?Site $site, int $retentionMonths, int $warningDays): ?array
    {
        // Calculate the date range for data that will be deleted
        $cutoffDate = Carbon::now()->subMonths($retentionMonths);
        $warningCutoffDate = Carbon::now()->subMonths($retentionMonths)->addDays($warningDays);

        $siteName = $site ? $site->name : 'Global (No Site)';
        $siteId = $site?->id;

        // Find records that will be deleted in the next X days
        $query = BiometricRecord::whereBetween('record_date', [
            $cutoffDate->format('Y-m-d'),
            $warningCutoffDate->format('Y-m-d')
        ]);

        if ($siteId) {
            $query->where('site_id', $siteId);
        } else {
            $query->whereNull('site_id');
        }

        $expiringCount = $query->count();

        if ($expiringCount > 0) {
            $oldestDate = $query->min('record_date');
            $newestDate = $query->max('record_date');

            $this->warn("  {$siteName}: {$expiringCount} records expiring (from {$oldestDate} to {$newestDate})");

            return [
                'site_name' => $siteName,
                'site_id' => $siteId,
                'count' => $expiringCount,
                'oldest_date' => $oldestDate,
                'newest_date' => $newestDate,
                'deletion_date' => $cutoffDate->format('Y-m-d'),
                'retention_months' => $retentionMonths,
            ];
        }

        return null;
    }

    /**
     * Send notifications to Admin and Super Admin
     */
    protected function sendExpiryNotifications(array $warnings, int $warningDays): void
    {
        $totalRecords = array_sum(array_column($warnings, 'count'));
        $siteNames = array_column($warnings, 'site_name');

        $title = 'Biometric Data Expiring Soon';
        $message = "{$totalRecords} biometric record(s) will be deleted in approximately {$warningDays} days. Please backup important attendance data before it is removed.";

        $data = [
            'warnings' => $warnings,
            'total_records' => $totalRecords,
            'warning_days' => $warningDays,
            'sites_affected' => $siteNames,
            'link' => route('biometric-export.index')
        ];

        $this->notificationService->notifyUsersByRole('Admin', 'system', $title, $message, $data);
        $this->notificationService->notifyUsersByRole('Super Admin', 'system', $title, $message, $data);

        \Log::info('Retention policy expiry notifications sent', [
            'total_records' => $totalRecords,
            'warning_days' => $warningDays,
            'sites_affected' => $siteNames,
        ]);
    }
}
