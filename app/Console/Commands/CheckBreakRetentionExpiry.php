<?php

namespace App\Console\Commands;

use App\Models\BreakRetentionPolicy;
use App\Models\Site;
use App\Services\BreakRetentionService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckBreakRetentionExpiry extends Command
{
    protected $signature = 'break-timer:check-expiry
                            {--days=7 : Days before data deletion to send notification}';

    protected $description = 'Check break retention policies and notify admins about sessions that will be deleted soon';

    public function __construct(
        protected NotificationService $notificationService,
        protected BreakRetentionService $retention,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $warningDays = (int) $this->option('days');
        $this->info("Checking for break sessions expiring within {$warningDays} days...");

        $sites = Site::all();
        $warnings = [];

        foreach (BreakRetentionPolicy::BREAK_TYPES as $breakType => $config) {
            foreach ([...$sites, null] as $site) {
                $retentionMonths = BreakRetentionPolicy::getRetentionMonths($site?->id, $breakType);
                $warning = $this->checkDataExpiry($site, $breakType, $retentionMonths, $warningDays);

                if ($warning) {
                    $warnings[] = $warning;
                }
            }
        }

        if (empty($warnings)) {
            $this->info('No break sessions are expiring soon. No notifications sent.');

            return self::SUCCESS;
        }

        $this->sendExpiryNotifications($warnings, $warningDays);

        $this->info('Break session expiry notifications sent to Admin and Super Admin.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function checkDataExpiry(?Site $site, string $breakType, int $retentionMonths, int $warningDays): ?array
    {
        $cutoffDate = $this->retention->cutoffDate($retentionMonths);
        $siteName = $site?->name ?? 'Global (No Site)';

        $query = $this->retention->scopeToSite(
            $this->retention->expiringSessions($breakType, $cutoffDate, $warningDays),
            $site?->id
        );

        $expiringCount = $query->count();

        if ($expiringCount === 0) {
            return null;
        }

        $oldestDate = $query->min('shift_date');
        $newestDate = $query->max('shift_date');
        $breakTypeLabel = BreakRetentionPolicy::BREAK_TYPES[$breakType]['label'];

        $this->warn("  {$siteName} - {$breakTypeLabel}: {$expiringCount} sessions expiring (from {$oldestDate} to {$newestDate})");

        return [
            'site_name' => $siteName,
            'site_id' => $site?->id,
            'break_type' => $breakType,
            'break_type_label' => $breakTypeLabel,
            'count' => $expiringCount,
            'oldest_date' => $oldestDate,
            'newest_date' => $newestDate,
            'deletion_date' => $cutoffDate->format('Y-m-d'),
            'retention_months' => $retentionMonths,
        ];
    }

    /**
     * Only Admin and Super Admin hold `break_timer.retention`, so only they are notified.
     *
     * @param  list<array<string, mixed>>  $warnings
     */
    protected function sendExpiryNotifications(array $warnings, int $warningDays): void
    {
        $totalRecords = array_sum(array_column($warnings, 'count'));
        $siteNames = array_values(array_unique(array_column($warnings, 'site_name')));
        $breakTypes = array_values(array_unique(array_column($warnings, 'break_type_label')));

        $title = 'Break Session Data Expiring Soon';
        $message = "{$totalRecords} break session(s) will be deleted in approximately {$warningDays} days based on retention policies. Affected types: ".implode(', ', $breakTypes).'. Please review and export important data before it is removed.';

        $data = [
            'warnings' => $warnings,
            'total_records' => $totalRecords,
            'warning_days' => $warningDays,
            'sites_affected' => $siteNames,
            'break_types_affected' => $breakTypes,
            'link' => route('break-timer.policies.index', ['tab' => 'retention']),
        ];

        $this->notificationService->notifyUsersByRole('Admin', 'system', $title, $message, $data);
        $this->notificationService->notifyUsersByRole('Super Admin', 'system', $title, $message, $data);

        Log::info('Break retention policy expiry notifications sent', [
            'total_records' => $totalRecords,
            'warning_days' => $warningDays,
            'sites_affected' => $siteNames,
            'break_types_affected' => $breakTypes,
        ]);
    }
}
