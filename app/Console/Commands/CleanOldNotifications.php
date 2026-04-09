<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanOldNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:clean
                            {--force : Skip confirmation prompt}
                            {--days=30 : Delete read notifications older than this many days}
                            {--unread-days=30 : Delete unread notifications older than this many days}
                            {--dry-run : Simulate the cleanup without deleting records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete old notifications based on retention policy (read: 30 days, unread: 30 days)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $readDays = (int) $this->option('days');
        $unreadDays = (int) $this->option('unread-days');

        $this->info('Starting cleanup of old notifications...');
        $this->info("  Read notification retention: {$readDays} days");
        $this->info("  Unread notification retention: {$unreadDays} days");

        $totalDeleted = 0;

        // Clean old read notifications
        $readDeleted = $this->cleanReadNotifications($readDays);
        $totalDeleted += $readDeleted;

        // Clean old unread notifications
        $unreadDeleted = $this->cleanUnreadNotifications($unreadDays);
        $totalDeleted += $unreadDeleted;

        if ($totalDeleted === 0) {
            $this->info('No old notifications found to delete.');

            return self::SUCCESS;
        }

        $action = $this->option('dry-run') ? 'would be deleted' : 'deleted';
        $this->info("Successfully {$action} {$totalDeleted} notifications in total.");

        if (! $this->option('dry-run')) {
            \Log::info('Notification cleanup completed', [
                'read_notifications_deleted' => $readDeleted,
                'unread_notifications_deleted' => $unreadDeleted,
                'total_deleted' => $totalDeleted,
                'read_retention_days' => $readDays,
                'unread_retention_days' => $unreadDays,
                'cleanup_date' => Carbon::now()->format('Y-m-d H:i:s'),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Clean old read notifications.
     */
    protected function cleanReadNotifications(int $days): int
    {
        $cutoffDate = Carbon::now()->subDays($days);

        $query = Notification::whereNotNull('read_at')
            ->where('created_at', '<', $cutoffDate);

        $count = $query->count();

        if ($count === 0) {
            $this->line('  No old read notifications found.');

            return 0;
        }

        $this->line("  Found {$count} read notifications older than {$days} days.");

        if ($this->option('dry-run')) {
            $this->info("  [DRY RUN] Would delete {$count} read notifications.");

            return $count;
        }

        if ($this->option('force') || ! $this->input->isInteractive()) {
            $deleted = $query->delete();
            $this->info("  Deleted {$deleted} read notifications.");

            return $deleted;
        }

        if ($this->confirm("Delete {$count} read notifications older than {$days} days?")) {
            $deleted = $query->delete();
            $this->info("  Deleted {$deleted} read notifications.");

            return $deleted;
        }

        $this->info('  Skipped read notification cleanup.');

        return 0;
    }

    /**
     * Clean old unread notifications.
     */
    protected function cleanUnreadNotifications(int $days): int
    {
        $cutoffDate = Carbon::now()->subDays($days);

        $query = Notification::whereNull('read_at')
            ->where('created_at', '<', $cutoffDate);

        $count = $query->count();

        if ($count === 0) {
            $this->line('  No old unread notifications found.');

            return 0;
        }

        $this->line("  Found {$count} unread notifications older than {$days} days.");

        if ($this->option('dry-run')) {
            $this->info("  [DRY RUN] Would delete {$count} unread notifications.");

            return $count;
        }

        if ($this->option('force') || ! $this->input->isInteractive()) {
            $deleted = $query->delete();
            $this->info("  Deleted {$deleted} unread notifications.");

            return $deleted;
        }

        if ($this->confirm("Delete {$count} unread notifications older than {$days} days?")) {
            $deleted = $query->delete();
            $this->info("  Deleted {$deleted} unread notifications.");

            return $deleted;
        }

        $this->info('  Skipped unread notification cleanup.');

        return 0;
    }
}
