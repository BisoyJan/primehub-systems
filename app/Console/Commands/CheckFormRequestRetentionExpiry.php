<?php

namespace App\Console\Commands;

use App\Models\FormRequestRetentionPolicy;
use App\Models\ItConcern;
use App\Models\LeaveRequest;
use App\Models\MedicationRequest;
use App\Models\Site;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class CheckFormRequestRetentionExpiry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'form-request:check-expiry
                            {--days=7 : Days before data deletion to send notification}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check form request retention policies and notify admins about data that will be deleted soon';

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
        $this->info("Checking for form request data expiring within {$warningDays} days...");

        $sites = Site::all();
        $warnings = [];
        $formTypes = ['leave_request', 'it_concern', 'medication_request'];

        foreach ($formTypes as $formType) {
            // Check site-specific policies
            foreach ($sites as $site) {
                $retentionMonths = FormRequestRetentionPolicy::getRetentionMonths($site->id, $formType);
                $warning = $this->checkDataExpiry($site, $formType, $retentionMonths, $warningDays);
                if ($warning) {
                    $warnings[] = $warning;
                }
            }

            // Check global policy (records without site)
            $globalRetentionMonths = FormRequestRetentionPolicy::getRetentionMonths(null, $formType);
            $warning = $this->checkDataExpiry(null, $formType, $globalRetentionMonths, $warningDays);
            if ($warning) {
                $warnings[] = $warning;
            }
        }

        if (empty($warnings)) {
            $this->info('No form request data is expiring soon. No notifications sent.');
            return self::SUCCESS;
        }

        // Send notifications to Admin, Super Admin, and IT
        $this->sendExpiryNotifications($warnings, $warningDays);

        $this->info('Form request expiry notifications sent to Admin, Super Admin, and IT.');
        return self::SUCCESS;
    }

    /**
     * Check if data is expiring soon for a specific site and form type
     */
    protected function checkDataExpiry(?Site $site, string $formType, int $retentionMonths, int $warningDays): ?array
    {
        // Calculate the date range for data that will be deleted
        $cutoffDate = Carbon::now()->subMonths($retentionMonths);
        $warningCutoffDate = Carbon::now()->subMonths($retentionMonths)->addDays($warningDays);

        $siteName = $site ? $site->name : 'Global (No Site)';
        $siteId = $site?->id;

        // Get the appropriate query based on form type
        $query = match ($formType) {
            'leave_request' => LeaveRequest::query(),
            'it_concern' => ItConcern::query(),
            'medication_request' => MedicationRequest::query(),
            default => null,
        };

        if (!$query) {
            return null;
        }

        // Find records that will be deleted in the next X days
        $query->whereBetween('created_at', [
            $cutoffDate->format('Y-m-d H:i:s'),
            $warningCutoffDate->format('Y-m-d H:i:s')
        ]);

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

        $expiringCount = $query->count();

        if ($expiringCount > 0) {
            $oldestDate = $query->min('created_at');
            $newestDate = $query->max('created_at');

            $formTypeLabel = $this->getFormTypeLabel($formType);
            $this->warn("  {$siteName} - {$formTypeLabel}: {$expiringCount} records expiring (from {$oldestDate} to {$newestDate})");

            return [
                'site_name' => $siteName,
                'site_id' => $siteId,
                'form_type' => $formType,
                'form_type_label' => $formTypeLabel,
                'count' => $expiringCount,
                'oldest_date' => $oldestDate,
                'newest_date' => $newestDate,
                'deletion_date' => $cutoffDate->format('Y-m-d'),
                'retention_months' => $retentionMonths,
            ];
        }

        return null;
    }

    /**
     * Get human-readable label for form type
     */
    protected function getFormTypeLabel(string $formType): string
    {
        return match ($formType) {
            'leave_request' => 'Leave Requests',
            'it_concern' => 'IT Concerns',
            'medication_request' => 'Medication Requests',
            default => ucfirst(str_replace('_', ' ', $formType)),
        };
    }

    /**
     * Send notifications to Admin, Super Admin, and IT
     */
    protected function sendExpiryNotifications(array $warnings, int $warningDays): void
    {
        $totalRecords = array_sum(array_column($warnings, 'count'));
        $siteNames = array_unique(array_column($warnings, 'site_name'));
        $formTypes = array_unique(array_column($warnings, 'form_type_label'));

        $title = 'Form Request Data Expiring Soon';
        $message = "{$totalRecords} form request record(s) will be deleted in approximately {$warningDays} days based on retention policies. Affected types: " . implode(', ', $formTypes) . ". Please review and export important data before it is removed.";

        $data = [
            'warnings' => $warnings,
            'total_records' => $totalRecords,
            'warning_days' => $warningDays,
            'sites_affected' => $siteNames,
            'form_types_affected' => $formTypes,
            'link' => route('form-requests.retention-policies.index')
        ];

        // Notify Admin, Super Admin, and IT roles
        $this->notificationService->notifyUsersByRole('Admin', 'system', $title, $message, $data);
        $this->notificationService->notifyUsersByRole('Super Admin', 'system', $title, $message, $data);
        $this->notificationService->notifyUsersByRole('IT', 'system', $title, $message, $data);

        \Log::info('Form request retention policy expiry notifications sent', [
            'total_records' => $totalRecords,
            'warning_days' => $warningDays,
            'sites_affected' => $siteNames,
            'form_types_affected' => $formTypes,
        ]);
    }
}
