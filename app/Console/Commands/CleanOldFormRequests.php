<?php

namespace App\Console\Commands;

use App\Models\FormRequestRetentionPolicy;
use App\Models\ItConcern;
use App\Models\LeaveRequest;
use App\Models\MedicationRequest;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class CleanOldFormRequests extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'form-request:clean-old-records
                            {--force : Skip confirmation prompt}
                            {--dry-run : Simulate the cleanup without deleting records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete form request records based on configured retention policies';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting cleanup of form requests based on retention policies...');

        $totalDeleted = 0;
        $sites = Site::all();
        $formTypes = ['leave_request', 'it_concern', 'medication_request'];

        foreach ($formTypes as $formType) {
            $this->info("Processing form type: {$formType}");

            // Process site-specific policies
            foreach ($sites as $site) {
                $retentionMonths = FormRequestRetentionPolicy::getRetentionMonths($site->id, $formType);
                $deleted = $this->cleanupForSiteAndType($site, $formType, $retentionMonths);
                $totalDeleted += $deleted;
            }

            // Process global/no-site records
            $globalRetentionMonths = FormRequestRetentionPolicy::getRetentionMonths(null, $formType);
            $deleted = $this->cleanupForSiteAndType(null, $formType, $globalRetentionMonths);
            $totalDeleted += $deleted;
        }

        if ($totalDeleted === 0) {
            $this->info('No old records found to delete.');
            return self::SUCCESS;
        }

        $action = $this->option('dry-run') ? 'would be deleted' : 'deleted';
        $this->info("Successfully {$action} {$totalDeleted} form request records in total.");

        if (!$this->option('dry-run')) {
            \Log::info('Form request records cleanup completed', [
                'total_records_deleted' => $totalDeleted,
                'cleanup_date' => Carbon::now()->format('Y-m-d H:i:s'),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Clean up records for a specific site and form type
     */
    protected function cleanupForSiteAndType(?Site $site, string $formType, int $retentionMonths): int
    {
        $cutoffDate = Carbon::now()->subMonths($retentionMonths);
        $siteName = $site ? $site->name : 'No Site (Global)';
        $siteId = $site?->id;

        // Determine the model and query based on form type
        $query = match ($formType) {
            'leave_request' => LeaveRequest::query(),
            'it_concern' => ItConcern::query(),
            'medication_request' => MedicationRequest::query(),
            default => null,
        };

        if (!$query) {
            return 0;
        }

        // Apply date filter (using created_at for simplicity and consistency)
        $query->where('created_at', '<', $cutoffDate);

        // Apply site filter
        if ($formType === 'it_concern') {
            if ($siteId) {
                $query->where('site_id', $siteId);
            } else {
                $query->whereNull('site_id');
            }
        } else {
            // For LeaveRequest and MedicationRequest, filter by user's active schedule site
            if ($siteId) {
                $query->whereHas('user.activeSchedule', function (Builder $q) use ($siteId) {
                    $q->where('site_id', $siteId);
                });
            } else {
                // For global/no-site, include users with no active schedule or no site in schedule
                $query->where(function (Builder $q) {
                    $q->whereDoesntHave('user.activeSchedule')
                      ->orWhereHas('user.activeSchedule', function (Builder $sq) {
                          $sq->whereNull('site_id');
                      });
                });
            }
        }

        $count = $query->count();

        if ($count === 0) {
            return 0;
        }

        $this->line("  Found {$count} {$formType} records to delete for {$siteName} (older than {$retentionMonths} months)");

        if ($this->option('dry-run')) {
            $this->info("  [DRY RUN] Would delete {$count} records.");
            return $count;
        }

        // Skip confirmation if --force flag is used or running in scheduled context
        if ($this->option('force') || !$this->input->isInteractive()) {
            // Delete associated files before deleting records
            $filesDeleted = $this->deleteAssociatedFiles($query->clone(), $formType);
            if ($filesDeleted > 0) {
                $this->line("  Deleted {$filesDeleted} associated file(s).");
            }

            $deleted = $query->delete();
            $this->info("  Deleted {$deleted} records.");

            \Log::info('Form request records cleanup for site', [
                'site_id' => $siteId,
                'site_name' => $siteName,
                'form_type' => $formType,
                'records_deleted' => $deleted,
                'cutoff_date' => $cutoffDate->format('Y-m-d'),
                'retention_months' => $retentionMonths,
            ]);

            return $deleted;
        }

        return 0;
    }

    /**
     * Delete associated files for records that will be deleted
     */
    protected function deleteAssociatedFiles($query, string $formType): int
    {
        $filesDeleted = 0;

        // Only LeaveRequest has file attachments (medical certificates)
        if ($formType === 'leave_request') {
            $records = $query->whereNotNull('medical_cert_path')
                ->pluck('medical_cert_path');

            foreach ($records as $filePath) {
                if ($filePath && Storage::disk('local')->exists($filePath)) {
                    Storage::disk('local')->delete($filePath);
                    $filesDeleted++;
                }
            }
        }

        return $filesDeleted;
    }
}
