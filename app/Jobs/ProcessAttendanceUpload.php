<?php

namespace App\Jobs;

use App\Models\AttendanceUpload;
use App\Services\AttendanceProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessAttendanceUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Allow up to 30 minutes for very large biometric export files.
     */
    public int $timeout = 1800;

    /**
     * Do not retry on failure — re-processing a partially committed upload
     * can produce duplicates. Admins should re-upload if needed.
     */
    public int $tries = 1;

    public function __construct(
        protected AttendanceUpload $upload,
        protected bool $filterByDate = true,
    ) {}

    public function handle(AttendanceProcessor $processor): void
    {
        $this->upload->update(['status' => 'processing']);

        try {
            $filePath = Storage::path('attendance_uploads/'.$this->upload->stored_filename);

            $processor->processUpload($this->upload, $filePath, $this->filterByDate);

            // processUpload already updates the upload record to 'completed' internally.
        } catch (\Throwable $e) {
            Log::error('ProcessAttendanceUpload job failed', [
                'upload_id' => $this->upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure (e.g. timeout, worker killed).
     */
    public function failed(\Throwable $exception): void
    {
        $this->upload->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }
}
