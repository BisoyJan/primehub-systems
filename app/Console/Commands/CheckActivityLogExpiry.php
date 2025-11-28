<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

class CheckActivityLogExpiry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitylog:check-expiry
                            {--days=7 : Days before data deletion to send notification}
                            {--retention=90 : Days to retain activity logs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check activity logs and notify admins about data that will be deleted soon';

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
        $retentionDays = (int) $this->option('retention');

        $this->info("Checking for activity logs expiring within {$warningDays} days...");

        // Calculate the date range for data that will be deleted
        $cutoffDate = Carbon::now()->subDays($retentionDays);
        $warningCutoffDate = Carbon::now()->subDays($retentionDays)->addDays($warningDays);

        // Find records that will be deleted in the next X days
        $expiringCount = Activity::whereBetween('created_at', [
            $cutoffDate,
            $warningCutoffDate
        ])->count();

        if ($expiringCount === 0) {
            $this->info('No activity logs are expiring soon. No notifications sent.');
            return self::SUCCESS;
        }

        $oldestDate = Activity::whereBetween('created_at', [$cutoffDate, $warningCutoffDate])
            ->min('created_at');
        $newestDate = Activity::whereBetween('created_at', [$cutoffDate, $warningCutoffDate])
            ->max('created_at');

        $this->warn("Found {$expiringCount} activity log entries expiring (from {$oldestDate} to {$newestDate})");

        // Send notifications
        $this->sendExpiryNotifications($expiringCount, $warningDays, $retentionDays, $oldestDate, $newestDate);

        $this->info('Expiry notifications sent to Admin and Super Admin.');
        return self::SUCCESS;
    }

    /**
     * Send notifications to Admin and Super Admin
     */
    protected function sendExpiryNotifications(int $count, int $warningDays, int $retentionDays, string $oldestDate, string $newestDate): void
    {
        $title = 'Activity Logs Expiring Soon';
        $message = "{$count} activity log entries will be deleted in approximately {$warningDays} days (retention policy: {$retentionDays} days). Please export if needed.";

        $data = [
            'total_records' => $count,
            'warning_days' => $warningDays,
            'retention_days' => $retentionDays,
            'oldest_date' => $oldestDate,
            'newest_date' => $newestDate,
        ];

        $this->notificationService->notifyUsersByRole('Admin', 'system', $title, $message, $data);
        $this->notificationService->notifyUsersByRole('Super Admin', 'system', $title, $message, $data);

        \Log::info('Activity log expiry notifications sent', [
            'total_records' => $count,
            'warning_days' => $warningDays,
            'retention_days' => $retentionDays,
        ]);
    }
}
