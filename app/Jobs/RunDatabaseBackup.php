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
use Illuminate\Support\Facades\Process;
use Spatie\DbDumper\Databases\MySql;

class RunDatabaseBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

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
            Cache::put($cacheKey, [
                'percent' => 10,
                'status' => 'Creating database dump...',
                'finished' => false,
            ], 3600);

            $dbConnection = config('database.default');
            $dbConfig = config("database.connections.{$dbConnection}");

            $backupDir = storage_path('app/backups');
            if (! file_exists($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $filename = $backup->filename;
            $filePath = "{$backupDir}/{$filename}";
            $sqlPath = str_replace('.sql.gz', '.sql', $filePath);

            Cache::put($cacheKey, [
                'percent' => 30,
                'status' => 'Dumping database...',
                'finished' => false,
            ], 3600);

            $driver = $dbConfig['driver'] ?? 'mysql';

            if ($driver === 'mysql') {
                $this->dumpMysql($dbConfig, $sqlPath);
            } elseif ($driver === 'pgsql') {
                $this->dumpPostgres($dbConfig, $sqlPath);
            } elseif ($driver === 'sqlite') {
                $this->dumpSqlite($dbConfig, $sqlPath);
            } else {
                throw new \RuntimeException("Unsupported database driver: {$driver}");
            }

            Cache::put($cacheKey, [
                'percent' => 70,
                'status' => 'Compressing backup...',
                'finished' => false,
            ], 3600);

            // Compress the SQL file
            $sqlContent = file_get_contents($sqlPath);
            $gzContent = gzencode($sqlContent, 9);
            file_put_contents($filePath, $gzContent);
            @unlink($sqlPath);

            $fileSize = filesize($filePath);

            Cache::put($cacheKey, [
                'percent' => 90,
                'status' => 'Finalizing...',
                'finished' => false,
            ], 3600);

            $backup->update([
                'status' => 'completed',
                'size' => $fileSize,
                'path' => "backups/{$filename}",
                'completed_at' => now(),
            ]);

            Cache::put($cacheKey, [
                'percent' => 100,
                'status' => 'Backup completed successfully.',
                'finished' => true,
            ], 3600);
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

    protected function dumpMysql(array $config, string $outputPath): void
    {
        $dumper = MySql::create()
            ->setHost($config['host'] ?? '127.0.0.1')
            ->setPort((int) ($config['port'] ?? 3306))
            ->setDbName($config['database'])
            ->setUserName($config['username'])
            ->setPassword($config['password'] ?? '')
            ->addExtraOption('--routines')
            ->addExtraOption('--triggers')
            ->addExtraOption('--quick')
            ->addExtraOption('--max-allowed-packet=256M');

        // Set mysqldump binary path on Windows if not in PATH
        $mysqldumpPath = $this->findMysqldump();
        if ($mysqldumpPath !== 'mysqldump') {
            $dumper->setDumpBinaryPath(dirname($mysqldumpPath));
        }

        $dumper->dumpToFile($outputPath);

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new \RuntimeException('mysqldump produced an empty output file.');
        }
    }

    protected function findMysqldump(): string
    {
        // Check if mysqldump is in PATH
        $which = PHP_OS_FAMILY === 'Windows' ? 'where mysqldump 2>nul' : 'which mysqldump 2>/dev/null';
        $result = Process::run($which);
        if ($result->successful() && trim($result->output()) !== '') {
            return trim(explode("\n", $result->output())[0]);
        }

        // Common Windows paths
        if (PHP_OS_FAMILY === 'Windows') {
            $commonPaths = [
                'C:\\Program Files\\MySQL\\MySQL Server 9.4\\bin\\mysqldump.exe',
                'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
                'C:\\xampp\\mysql\\bin\\mysqldump.exe',
                'C:\\laragon\\bin\\mysql\\mysql-8.0\\bin\\mysqldump.exe',
                'C:\\wamp64\\bin\\mysql\\mysql8.0\\bin\\mysqldump.exe',
            ];

            $programFiles = glob('C:\\Program Files\\MySQL\\MySQL Server *\\bin\\mysqldump.exe');
            if ($programFiles) {
                $commonPaths = array_merge($programFiles, $commonPaths);
            }

            foreach ($commonPaths as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        // Return default and let spatie/db-dumper try the system PATH
        return 'mysqldump';
    }

    protected function dumpPostgres(array $config, string $outputPath): void
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? '5432';
        $database = $config['database'];
        $username = $config['username'];

        $env = [];
        if (! empty($config['password'])) {
            $env['PGPASSWORD'] = $config['password'];
        }

        $command = sprintf(
            'pg_dump --host=%s --port=%s --username=%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database)
        );

        $result = Process::env($env)->run($command);

        if (! $result->successful()) {
            throw new \RuntimeException('pg_dump failed: '.$result->errorOutput());
        }

        file_put_contents($outputPath, $result->output());
    }

    protected function dumpSqlite(array $config, string $outputPath): void
    {
        $databasePath = $config['database'];

        if (! file_exists($databasePath)) {
            throw new \RuntimeException("SQLite database not found: {$databasePath}");
        }

        $command = sprintf('sqlite3 %s .dump', escapeshellarg($databasePath));
        $result = Process::run($command);

        if (! $result->successful()) {
            throw new \RuntimeException('sqlite3 dump failed: '.$result->errorOutput());
        }

        file_put_contents($outputPath, $result->output());
    }
}
