<?php

namespace App\Http\Controllers;

use App\Http\Traits\RedirectsWithFlashMessages;
use App\Jobs\RunDatabaseBackup;
use App\Models\DatabaseBackup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DatabaseBackupController extends Controller
{
    use RedirectsWithFlashMessages;

    public function index(Request $request)
    {
        // Auto-mark stale in-progress backups as failed (stuck longer than 15 minutes)
        DatabaseBackup::where('status', 'in_progress')
            ->where('updated_at', '<', now()->subMinutes(20))
            ->update([
                'status' => 'failed',
                'error_message' => 'Backup timed out or the queue worker was interrupted.',
            ]);

        $backups = DatabaseBackup::with('creator')
            ->when($request->input('search'), function ($query, $search) {
                $query->where('filename', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // Append formatted size to each backup
        $backups->getCollection()->transform(function ($backup) {
            $backup->formatted_size = $backup->getFormattedSize();

            return $backup;
        });

        return inertia('Admin/DatabaseBackups/Index', [
            'backups' => $backups,
            'search' => $request->input('search'),
        ]);
    }

    public function store(Request $request)
    {
        try {
            $jobId = Str::uuid()->toString();
            $filename = 'backup-'.now()->format('Y-m-d-His').'.zip';
            $backupName = config('backup.backup.name', 'backups');

            $backup = DatabaseBackup::create([
                'filename' => $filename,
                'disk' => 'local',
                'path' => "{$backupName}/{$filename}",
                'status' => 'pending',
                'created_by' => auth()->id(),
            ]);

            RunDatabaseBackup::dispatch($backup->id, $jobId);

            return redirect()->route('database-backups.index')
                ->with('message', 'Backup started. It will be available shortly.')
                ->with('type', 'success')
                ->with('backup_job_id', $jobId);
        } catch (\Exception $e) {
            Log::error('DatabaseBackup Store Error: '.$e->getMessage());

            return $this->redirectWithFlash('database-backups.index', 'Failed to start backup.', 'error');
        }
    }

    public function progress(string $jobId)
    {
        $cacheKey = "database_backup:{$jobId}";
        $progress = Cache::get($cacheKey, [
            'percent' => 0,
            'status' => 'Waiting...',
            'finished' => false,
        ]);

        return response()->json($progress);
    }

    public function download(DatabaseBackup $databaseBackup)
    {
        if ($databaseBackup->status !== 'completed') {
            return $this->redirectWithFlash('database-backups.index', 'Backup is not ready for download.', 'error');
        }

        $filePath = Storage::disk('local')->path($databaseBackup->path);

        if (! file_exists($filePath)) {
            return $this->redirectWithFlash('database-backups.index', 'Backup file not found.', 'error');
        }

        return response()->download($filePath, $databaseBackup->filename);
    }

    public function destroy(DatabaseBackup $databaseBackup)
    {
        try {
            // Delete the file from storage
            if (Storage::disk('local')->exists($databaseBackup->path)) {
                Storage::disk('local')->delete($databaseBackup->path);
            }

            $databaseBackup->delete();

            return $this->redirectWithFlash('database-backups.index', 'Backup deleted successfully.');
        } catch (\Exception $e) {
            Log::error('DatabaseBackup Delete Error: '.$e->getMessage());

            return $this->redirectWithFlash('database-backups.index', 'Failed to delete backup.', 'error');
        }
    }

    public function cleanOld(Request $request)
    {
        $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        try {
            $cutoff = now()->subDays($request->input('days'));
            $oldBackups = DatabaseBackup::where('created_at', '<', $cutoff)->get();
            $count = $oldBackups->count();

            foreach ($oldBackups as $backup) {
                if (Storage::disk('local')->exists($backup->path)) {
                    Storage::disk('local')->delete($backup->path);
                }
                $backup->delete();
            }

            return $this->redirectWithFlash('database-backups.index', "{$count} old backup(s) cleaned up successfully.");
        } catch (\Exception $e) {
            Log::error('DatabaseBackup CleanOld Error: '.$e->getMessage());

            return $this->redirectWithFlash('database-backups.index', 'Failed to clean old backups.', 'error');
        }
    }
}
