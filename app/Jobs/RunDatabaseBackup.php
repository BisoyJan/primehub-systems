<?php

namespace App\Jobs;

use App\Models\DatabaseBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunDatabaseBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        protected int $backupId,
        protected string $jobId,
    ) {}

    public function handle(): void
    {
        $cacheKey = "database_backup:{$this->jobId}";
        $backup = DatabaseBackup::findOrFail($this->backupId);

        try {
            $backup->update(['status' => 'in_progress']);
            $this->updateProgress($cacheKey, 10, 'Starting database backup...');

            $this->updateProgress($cacheKey, 30, 'Running Spatie backup (database only)...');

            // Delegate to Spatie's backup:run for a database-only backup
            // Dump options (routines, triggers, events, timeout, etc.) are configured
            // via the 'dump' key in config/database.php connections.
            $exitCode = Artisan::call('backup:run', [
                '--only-db' => true,
                '--disable-notifications' => true,
                '--filename' => $backup->filename,
            ]);

            if ($exitCode !== 0) {
                $output = Artisan::output();
                throw new \RuntimeException("backup:run failed (exit code {$exitCode}): {$output}");
            }

            $this->updateProgress($cacheKey, 80, 'Verifying backup file...');

            // Verify the backup file was created at the expected path
            $backupName = config('backup.backup.name', 'backups');
            $filePath = storage_path("app/{$backupName}/{$backup->filename}");

            if (! file_exists($filePath)) {
                throw new \RuntimeException('Backup file not found at: '.$filePath);
            }

            $fileSize = filesize($filePath);

            $this->updateProgress($cacheKey, 90, 'Finalizing...');

            $backup->update([
                'status' => 'completed',
                'size' => $fileSize,
                'path' => "{$backupName}/{$backup->filename}",
                'completed_at' => now(),
            ]);

            $this->updateProgress($cacheKey, 100, 'Backup completed successfully.', true);
        } catch (\Exception $e) {
            Log::error('Database Backup Error: '.$e->getMessage());

            $backup->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Cache::put($cacheKey, [
                'percent' => 0,
                'status' => 'Backup failed: '.$e->getMessage(),
                'finished' => true,
                'error' => true,
            ], 3600);
        }
    }

    protected function updateProgress(string $cacheKey, int $percent, string $status, bool $finished = false): void
    {
        Cache::put($cacheKey, [
            'percent' => $percent,
            'status' => $status,
            'finished' => $finished,
        ], 3600);
    }
}
