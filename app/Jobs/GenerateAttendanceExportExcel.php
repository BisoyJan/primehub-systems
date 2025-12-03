<?php

namespace App\Jobs;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateAttendanceExportExcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $jobId;
    protected ?string $startDate;
    protected ?string $endDate;
    protected array $userIds;
    protected array $siteIds;
    protected array $campaignIds;

    public function __construct(
        string $jobId,
        ?string $startDate,
        ?string $endDate,
        array $userIds = [],
        array $siteIds = [],
        array $campaignIds = []
    ) {
        $this->jobId = $jobId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->userIds = $userIds;
        $this->siteIds = $siteIds;
        $this->campaignIds = $campaignIds;
    }

    public function handle(): void
    {
        $cacheKey = "attendance_export_job:{$this->jobId}";

        try {
            // Update progress: Starting
            $this->updateProgress($cacheKey, 5, 'Fetching attendance records...');

            $startDate = $this->startDate ? Carbon::parse($this->startDate) : null;
            $endDate = $this->endDate ? Carbon::parse($this->endDate) : null;

            // Build query for attendance records
            $query = Attendance::with([
                    'user:id,first_name,last_name',
                    'bioInSite:id,name',
                    'bioOutSite:id,name',
                    'employeeSchedule:id,campaign_id,site_id',
                    'employeeSchedule.campaign:id,name',
                    'employeeSchedule.site:id,name',
                ]);

            if ($startDate && $endDate) {
                $query->whereBetween('shift_date', [
                    $startDate->toDateString(),
                    $endDate->toDateString()
                ]);
            } elseif ($startDate) {
                $query->where('shift_date', '>=', $startDate->toDateString());
            } elseif ($endDate) {
                $query->where('shift_date', '<=', $endDate->toDateString());
            }

            if (!empty($this->userIds)) {
                $query->whereIn('user_id', $this->userIds);
            }

            if (!empty($this->siteIds)) {
                $query->where(function ($q) {
                    $q->whereIn('bio_in_site_id', $this->siteIds)
                      ->orWhereIn('bio_out_site_id', $this->siteIds)
                      ->orWhereHas('employeeSchedule', function ($subQ) {
                          $subQ->whereIn('site_id', $this->siteIds);
                      });
                });
            }

            if (!empty($this->campaignIds)) {
                $query->whereHas('employeeSchedule', function ($subQ) {
                    $subQ->whereIn('campaign_id', $this->campaignIds);
                });
            }

            $records = $query->orderBy('shift_date')->orderBy('user_id')->get();
            $total = $records->count();

            $this->updateProgress($cacheKey, 15, "Processing {$total} records...");

            // Create spreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Attendance Records');

            // Set headers
            $headers = [
                'User ID', 'Employee Name', 'Campaign', 'Shift Date',
                'Scheduled Time In', 'Scheduled Time Out', 'Actual Time In', 'Actual Time Out',
                'Time In Site', 'Time Out Site', 'Status', 'Secondary Status',
                'Tardy (mins)', 'Undertime (mins)', 'Overtime (mins)',
                'OT Approved', 'Cross-Site Bio', 'Admin Verified',
            ];

            $sheet->fromArray($headers, null, 'A1');
            $this->styleHeaderRow($sheet, 'A1:R1');

            $this->updateProgress($cacheKey, 25, 'Writing attendance data...');

            // Add data rows
            $row = 2;
            $processed = 0;
            foreach ($records as $record) {
                $timeInSite = $record->bioInSite?->name ?? $record->employeeSchedule?->site?->name ?? 'N/A';
                $timeOutSite = $record->bioOutSite?->name ?? $record->employeeSchedule?->site?->name ?? 'N/A';

                $sheet->fromArray([
                    $record->user_id ?? 'N/A',
                    $record->user ? $record->user->first_name . ' ' . $record->user->last_name : 'Unknown',
                    $record->employeeSchedule?->campaign?->name ?? 'N/A',
                    $record->shift_date?->format('Y-m-d') ?? 'N/A',
                    $record->scheduled_time_in ?? 'N/A',
                    $record->scheduled_time_out ?? 'N/A',
                    $record->actual_time_in?->format('Y-m-d H:i:s') ?? 'N/A',
                    $record->actual_time_out?->format('Y-m-d H:i:s') ?? 'N/A',
                    $timeInSite,
                    $timeOutSite,
                    $this->formatStatus($record->status),
                    $this->formatStatus($record->secondary_status ?? 'N/A'),
                    $record->tardy_minutes ?? 0,
                    $record->undertime_minutes ?? 0,
                    $record->overtime_minutes ?? 0,
                    $record->overtime_approved ? 'Yes' : 'No',
                    $record->is_cross_site_bio ? 'Yes' : 'No',
                    $record->admin_verified ? 'Yes' : 'No',
                ], null, 'A' . $row);

                $this->applyStatusColor($sheet, $row, $record->status);
                $row++;
                $processed++;

                // Update progress every 100 records
                if ($processed % 100 === 0) {
                    $percent = 25 + intval(($processed / max($total, 1)) * 50);
                    $this->updateProgress($cacheKey, $percent, "Processing record {$processed}/{$total}...");
                }
            }

            $this->updateProgress($cacheKey, 80, 'Auto-sizing columns...');

            // Auto-size columns
            foreach (range('A', 'R') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $lastDataRow = $row - 1;

            $this->updateProgress($cacheKey, 85, 'Creating statistics sheet...');

            // Add statistics sheet
            $statsSheet = $spreadsheet->createSheet();
            $statsSheet->setTitle('Statistics');
            $this->addStatisticsSheet($statsSheet, $startDate, $endDate, $lastDataRow);

            $spreadsheet->setActiveSheetIndex(0);

            $this->updateProgress($cacheKey, 90, 'Saving Excel file...');

            // Generate filename and save
            $dateRangeStr = '';
            if ($startDate && $endDate) {
                $dateRangeStr = $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d');
            } elseif ($startDate) {
                $dateRangeStr = 'from_' . $startDate->format('Y-m-d');
            } elseif ($endDate) {
                $dateRangeStr = 'to_' . $endDate->format('Y-m-d');
            } else {
                $dateRangeStr = 'all_records';
            }

            $filename = sprintf(
                'attendance_export_%s_%s.xlsx',
                $dateRangeStr,
                $this->jobId
            );

            $tempDir = storage_path('app/temp');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            $filePath = $tempDir . '/' . $filename;
            $writer = new Xlsx($spreadsheet);
            $writer->save($filePath);

            $this->updateProgress($cacheKey, 100, 'Finished', true, url("/biometric-export/download/{$this->jobId}"));

        } catch (\Exception $e) {
            Cache::put($cacheKey, [
                'percent' => 0,
                'status' => 'Error: ' . $e->getMessage(),
                'finished' => true,
                'downloadUrl' => null,
                'error' => true,
            ], 600);
        }
    }

    protected function updateProgress(string $cacheKey, int $percent, string $status, bool $finished = false, ?string $downloadUrl = null): void
    {
        Cache::put($cacheKey, [
            'percent' => $percent,
            'status' => $status,
            'finished' => $finished,
            'downloadUrl' => $downloadUrl,
        ], 600);
    }

    protected function styleHeaderRow($sheet, string $range): void
    {
        $headerStyle = $sheet->getStyle($range);
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF4CAF50');
        $headerStyle->getFont()->getColor()->setARGB('FFFFFFFF');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    protected function formatStatus(?string $status): string
    {
        if (!$status) {
            return 'Unknown';
        }

        return match ($status) {
            'on_time' => 'On Time',
            'tardy' => 'Tardy',
            'half_day_absence' => 'Half Day Absence',
            'ncns' => 'NCNS',
            'advised_absence' => 'Advised Absence',
            'on_leave' => 'On Leave',
            'undertime' => 'Undertime',
            'failed_bio_in' => 'Failed Bio In',
            'failed_bio_out' => 'Failed Bio Out',
            'needs_manual_review' => 'Needs Manual Review',
            'present_no_bio' => 'Present (No Bio)',
            'non_work_day' => 'Non-Work Day',
            default => ucwords(str_replace('_', ' ', $status)),
        };
    }

    protected function applyStatusColor($sheet, int $row, ?string $status): void
    {
        $statusCol = 'K';
        $cell = $statusCol . $row;

        $color = match ($status) {
            'on_time' => 'FF4CAF50',
            'tardy' => 'FFFFC107',
            'half_day_absence' => 'FFFF9800',
            'ncns' => 'FFF44336',
            'advised_absence', 'on_leave' => 'FF2196F3',
            'undertime' => 'FFFF5722',
            'failed_bio_in', 'failed_bio_out' => 'FF9C27B0',
            'needs_manual_review' => 'FFFFC107',
            default => null,
        };

        if ($color) {
            $sheet->getStyle($cell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($color);

            if (in_array($status, ['on_time', 'ncns', 'advised_absence', 'on_leave', 'failed_bio_in', 'failed_bio_out'])) {
                $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFFFF');
            }
        }
    }

    protected function addStatisticsSheet($sheet, ?Carbon $startDate, ?Carbon $endDate, int $lastDataRow): void
    {
        $row = 1;
        $dataRange = "'Attendance Records'!K2:K" . $lastDataRow;

        // Title
        $sheet->setCellValue('A' . $row, 'ATTENDANCE STATISTICS');
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;

        // Date Range
        $sheet->setCellValue('A' . $row, 'Date Range:');
        $dateRangeText = '';
        if ($startDate && $endDate) {
            $dateRangeText = $startDate->format('M d, Y') . ' - ' . $endDate->format('M d, Y');
        } elseif ($startDate) {
            $dateRangeText = 'From ' . $startDate->format('M d, Y');
        } elseif ($endDate) {
            $dateRangeText = 'Until ' . $endDate->format('M d, Y');
        } else {
            $dateRangeText = 'All Records';
        }
        $sheet->setCellValue('B' . $row, $dateRangeText);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        // Total Records
        $sheet->setCellValue('A' . $row, 'Total Records:');
        $sheet->setCellValue('B' . $row, "=COUNTA({$dataRange})");
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $totalRecordsRow = $row;
        $row += 2;

        // Status breakdown header
        $sheet->setCellValue('A' . $row, 'STATUS BREAKDOWN');
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $this->styleStatHeader($sheet, 'A' . $row . ':C' . $row);
        $row++;

        // Column headers
        $sheet->setCellValue('A' . $row, 'Status');
        $sheet->setCellValue('B' . $row, 'Count');
        $sheet->setCellValue('C' . $row, 'Percentage');
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':C' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E0E0');
        $this->addBorder($sheet, 'A' . $row . ':C' . $row);
        $row++;

        $totalCell = 'B' . $totalRecordsRow;

        $statusStats = [
            ['On Time', 'On Time', 'FF4CAF50'],
            ['Tardy', 'Tardy', 'FFFFC107'],
            ['Half Day Absence', 'Half Day Absence', 'FFFF9800'],
            ['NCNS', 'NCNS', 'FFF44336'],
            ['Advised Absence', 'Advised Absence', 'FF2196F3'],
            ['On Leave', 'On Leave', 'FF2196F3'],
            ['Undertime', 'Undertime', 'FFFF5722'],
            ['Failed Bio In', 'Failed Bio In', 'FF9C27B0'],
            ['Failed Bio Out', 'Failed Bio Out', 'FF9C27B0'],
            ['Needs Manual Review', 'Needs Manual Review', 'FFFFC107'],
        ];

        foreach ($statusStats as [$label, $searchValue, $color]) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, "=COUNTIF({$dataRange},\"{$searchValue}\")");
            $sheet->setCellValue('C' . $row, "=IF({$totalCell}=0,0,B{$row}/{$totalCell})");

            $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('0.0%');
            $sheet->getStyle('A' . $row)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($color);

            if (in_array($color, ['FF4CAF50', 'FFF44336', 'FF2196F3', 'FF9C27B0'])) {
                $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
            }

            $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->addBorder($sheet, 'A' . $row . ':C' . $row);
            $row++;
        }

        $row++;

        // Time statistics
        $sheet->setCellValue('A' . $row, 'TIME STATISTICS');
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $this->styleStatHeader($sheet, 'A' . $row . ':C' . $row);
        $row++;

        $tardyRange = "'Attendance Records'!M2:M" . $lastDataRow;
        $undertimeRange = "'Attendance Records'!N2:N" . $lastDataRow;
        $overtimeRange = "'Attendance Records'!O2:O" . $lastDataRow;
        $otApprovedRange = "'Attendance Records'!P2:P" . $lastDataRow;
        $adminVerifiedRange = "'Attendance Records'!R2:R" . $lastDataRow;

        $timeStats = [
            ['Total Tardy Minutes', "=SUM({$tardyRange})"],
            ['Total Undertime Minutes', "=SUM({$undertimeRange})"],
            ['Total Overtime Minutes', "=SUM({$overtimeRange})"],
            ['Records with Overtime', "=COUNTIF({$overtimeRange},\">0\")"],
            ['OT Approved Records', "=COUNTIF({$otApprovedRange},\"Yes\")"],
            ['Admin Verified Records', "=COUNTIF({$adminVerifiedRange},\"Yes\")"],
            ['Pending Verification', "=COUNTIF({$adminVerifiedRange},\"No\")"],
        ];

        foreach ($timeStats as [$label, $formula]) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $formula);
            $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->addBorder($sheet, 'A' . $row . ':B' . $row);
            $row++;
        }

        // Auto-size columns
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(15);
    }

    protected function styleStatHeader($sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF1976D2');
        $sheet->getStyle($range)->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->addBorder($sheet, $range);
    }

    protected function addBorder($sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
