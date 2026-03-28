<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckNotificationRetentionExpiry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:check-expiry
                            {--days=7 : Days before data deletion to send notification}
                            {--read-retention=90 : Days to retain read notifications}
                            {--unread-retention=180 : Days to retain unread notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check notification retention and notify admins about notifications that will be deleted soon';

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
        $readRetention = (int) $this->option('read-retention');
        $unreadRetention = (int) $this->option('unread-retention');

        $this->info("Checking for notifications expiring within {$warningDays} days...");

        $warnings = [];

        // Check read notifications expiring soon
        $readWarning = $this->checkExpiry(
            isRead: true,
            retentionDays: $readRetention,
            warningDays: $warningDays,
        );
        if ($readWarning) {
            $warnings[] = $readWarning;
        }

        // Check unread notifications expiring soon
        $unreadWarning = $this->checkExpiry(
            isRead: false,
            retentionDays: $unreadRetention,
            warningDays: $warningDays,
        );
        if ($unreadWarning) {
            $warnings[] = $unreadWarning;
        }

        if (empty($warnings)) {
            $this->info('No notifications are expiring soon. No alerts sent.');

            return self::SUCCESS;
        }

        $this->sendExpiryNotifications($warnings, $warningDays);

        $this->info('Notification retention expiry alerts sent to Admin and Super Admin.');

        return self::SUCCESS;
    }

    /**
     * Check if notifications are expiring soon.
     *
     * @return array{type: string, count: int, retention_days: int, oldest_date: string, newest_date: string}|null
     */
    protected function checkExpiry(bool $isRead, int $retentionDays, int $warningDays): ?array
    {
        $cutoffDate = Carbon::now()->subDays($retentionDays);
        $warningCutoffDate = Carbon::now()->subDays($retentionDays)->addDays($warningDays);
        $type = $isRead ? 'read' : 'unread';

        $query = Notification::whereBetween('created_at', [
            $cutoffDate,
            $warningCutoffDate,
        ]);

        if ($isRead) {
            $query->whereNotNull('read_at');
        } else {
            $query->whereNull('read_at');
        }

        $count = $query->count();

        if ($count === 0) {
            return null;
        }

        $oldestDate = (clone $query)->min('created_at');
        $newestDate = (clone $query)->max('created_at');

        $this->warn("  {$count} {$type} notifications expiring (from {$oldestDate} to {$newestDate})");

        return [
            'type' => $type,
            'count' => $count,
            'retention_days' => $retentionDays,
            'oldest_date' => $oldestDate,
            'newest_date' => $newestDate,
        ];
    }

    /**
     * Send expiry notifications to Admin and Super Admin.
     *
     * @param  array<int, array{type: string, count: int, retention_days: int, oldest_date: string, newest_date: string}>  $warnings
     */
    protected function sendExpiryNotifications(array $warnings, int $warningDays): void
    {
        $details = collect($warnings)
            ->map(fn (array $w) => "{$w['count']} {$w['type']} notifications (retention: {$w['retention_days']} days)")
            ->join('; ');

        $title = 'Notifications Expiring Soon';
        $message = "The following notifications will be auto-deleted in approximately {$warningDays} days: {$details}.";

        $data = [
            'warnings' => $warnings,
            'warning_days' => $warningDays,
        ];

        $this->notificationService->notifyUsersByRole('Admin', 'system', $title, $message, $data);
        $this->notificationService->notifyUsersByRole('Super Admin', 'system', $title, $message, $data);

        \Log::info('Notification retention expiry alerts sent', [
            'warnings' => $warnings,
            'warning_days' => $warningDays,
        ]);
    }
}
