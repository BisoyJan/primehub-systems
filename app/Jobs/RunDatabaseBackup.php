<?php

namespace App\Jobs;

use App\Models\DatabaseBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class RunDatabaseBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $backoff = 10;

    /**
     * Interval (seconds) between heartbeat updates while the backup
     * subprocess is running. Keeps `updated_at` fresh so the stale-job
     * sweeper in DatabaseBackupController::index() doesn't kill a healthy run.
     */
    protected const HEARTBEAT_INTERVAL = 10;

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

            // Run backup:run as a detached subprocess so we can heartbeat the
            // backup record while mysqldump is working. Using Artisan::call()
            // blocks the whole job, which leaves `updated_at` frozen and lets
            // the stale-job sweeper incorrectly mark long-running backups as
            // "timed out". Dump options (routines, triggers, events, timeout)
            // remain driven by the 'dump' key in config/database.php.
            $php = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;

            $process = new Process([
                $php,
                base_path('artisan'),
                'backup:run',
                '--only-db',
                '--disable-notifications',
                '--filename='.$backup->filename,
            ], base_path());

            $process->setTimeout($this->timeout);
            $process->start();

            $lastHeartbeat = 0;
            $elapsed = 0;

            while ($process->isRunning()) {
                $now = time();

                if ($now - $lastHeartbeat >= self::HEARTBEAT_INTERVAL) {
                    // Touch the row so the stale-job sweeper sees recent activity.
                    $backup->touch();

                    // Slowly walk the progress bar between 30% and 75% so the UI
                    // reflects that work is still happening even though mysqldump
                    // does not emit granular progress we can forward.
                    $elapsed += self::HEARTBEAT_INTERVAL;
                    $animated = min(75, 30 + intdiv($elapsed, 15));
                    $this->updateProgress(
                        $cacheKey,
                        $animated,
                        'Dumping database... ('.$elapsed.'s elapsed)'
                    );

                    $lastHeartbeat = $now;
                }

                usleep(500_000); // 0.5s
            }

            $exitCode = $process->getExitCode();

            if ($exitCode !== 0) {
                $output = trim($process->getOutput()."\n".$process->getErrorOutput());
                throw new \RuntimeException("backup:run failed (exit code {$exitCode}): {$output}");
            }

            $this->updateProgress($cacheKey, 80, 'Verifying backup file...');

            // Verify the backup file was created at the expected path
            // Spatie writes to the 'local' disk, which in Laravel 11+ defaults to storage/app/private
            $backupName = config('backup.backup.name', 'backups');
            $diskPath = "{$backupName}/{$backup->filename}";
            $filePath = Storage::disk('local')->path($diskPath);

            if (! file_exists($filePath)) {
                throw new \RuntimeException('Backup file not found at: '.$filePath);
            }

            $fileSize = filesize($filePath);

            $this->updateProgress($cacheKey, 90, 'Finalizing...');

            $backup->update([
                'status' => 'completed',
                'size' => $fileSize,
                'path' => $diskPath,
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

    public function failed(?\Throwable $exception): void
    {
        $cacheKey = "database_backup:{$this->jobId}";
        $backup = DatabaseBackup::find($this->backupId);

        if ($backup && $backup->status !== 'completed') {
            $backup->update([
                'status' => 'failed',
                'error_message' => $exception?->getMessage() ?? 'Job failed after all retries.',
            ]);
        }

        Cache::put($cacheKey, [
            'percent' => 0,
            'status' => 'Backup failed: '.($exception?->getMessage() ?? 'Unknown error'),
            'finished' => true,
            'error' => true,
        ], 3600);

        Log::error('Database Backup Job Failed: '.($exception?->getMessage() ?? 'Unknown error'));
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
