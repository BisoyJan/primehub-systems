<?php

namespace App\Jobs;

use App\Models\BreakSession;
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

class GenerateBreakTimerExportExcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $jobId,
        protected ?string $startDate,
        protected ?string $endDate,
        protected ?int $userId = null,
        protected ?string $type = null,
        protected ?string $status = null,
        protected ?string $search = null,
    ) {}

    public function handle(): void
    {
        $cacheKey = "break_timer_export_job:{$this->jobId}";

        try {
            $this->updateProgress($cacheKey, 5, 'Fetching break session records...');

            $query = BreakSession::query()
                ->with(['user'])
                ->whereBetween('shift_date', [$this->startDate, $this->endDate])
                ->orderBy('shift_date', 'desc')
                ->orderBy('started_at', 'desc');

            if ($this->userId) {
                $query->where('user_id', $this->userId);
            }

            if ($this->type) {
                $query->where('type', $this->type);
            }

            if ($this->status) {
                $query->where('status', $this->status);
            }

            if ($this->search) {
                $query->search($this->search);
            }

            $records = $query->get();
            $total = $records->count();

            $this->updateProgress($cacheKey, 15, "Processing {$total} records...");

            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Break Sessions');

            $headers = [
                'Session ID', 'Employee Name', 'Station', 'Shift Date',
                'Type', 'Status', 'Duration (min)', 'Started At', 'Ended At',
                'Remaining (sec)', 'Overage (sec)', 'Total Paused (sec)',
                'Last Pause Reason', 'Reset Approval',
            ];

            $sheet->fromArray($headers, null, 'A1');
            $this->styleHeaderRow($sheet, 'A1:N1');

            $this->updateProgress($cacheKey, 25, 'Writing break session data...');

            $row = 2;
            $processed = 0;
            foreach ($records as $record) {
                $sheet->fromArray([
                    $record->session_id ?? 'N/A',
                    $record->user ? $record->user->first_name.' '.$record->user->last_name : 'Unknown',
                    $record->station ?? 'N/A',
                    $record->shift_date?->format('Y-m-d') ?? 'N/A',
                    $this->formatType($record->type),
                    ucfirst($record->status ?? 'N/A'),
                    $record->duration_seconds ? round($record->duration_seconds / 60, 1) : 0,
                    $record->started_at?->format('Y-m-d H:i:s') ?? 'N/A',
                    $record->ended_at?->format('Y-m-d H:i:s') ?? 'N/A',
                    $record->remaining_seconds ?? 0,
                    $record->overage_seconds ?? 0,
                    $record->total_paused_seconds ?? 0,
                    $record->last_pause_reason ?? '',
                    $record->reset_approval ?? '',
                ], null, 'A'.$row);

                $this->applyStatusColor($sheet, $row, $record->status);
                $row++;
                $processed++;

                if ($processed % 100 === 0) {
                    $percent = 25 + intval(($processed / max($total, 1)) * 50);
                    $this->updateProgress($cacheKey, $percent, "Processing record {$processed}/{$total}...");
                }
            }

            $this->updateProgress($cacheKey, 80, 'Auto-sizing columns...');

            foreach (range('A', 'N') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Add summary sheet
            $this->updateProgress($cacheKey, 85, 'Creating summary sheet...');
            $summarySheet = $spreadsheet->createSheet();
            $summarySheet->setTitle('Summary');
            $this->addSummarySheet($summarySheet, $records);

            $spreadsheet->setActiveSheetIndex(0);

            $this->updateProgress($cacheKey, 90, 'Saving Excel file...');

            $dateRange = $this->startDate.'_to_'.$this->endDate;
            $filename = "break_timer_export_{$dateRange}_{$this->jobId}.xlsx";

            $tempDir = storage_path('app/temp');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            $filePath = $tempDir.'/'.$filename;
            $writer = new Xlsx($spreadsheet);
            $writer->save($filePath);

            $this->updateProgress($cacheKey, 100, 'Finished', true, url("/break-timer/reports/export/download/{$this->jobId}"));

        } catch (\Exception $e) {
            Cache::put($cacheKey, [
                'percent' => 0,
                'status' => 'Error: '.$e->getMessage(),
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
            ->getStartColor()->setARGB('FF2196F3');
        $headerStyle->getFont()->getColor()->setARGB('FFFFFFFF');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    protected function formatType(?string $type): string
    {
        return match ($type) {
            '1st_break' => '1st Break',
            '2nd_break' => '2nd Break',
            'lunch' => 'Lunch',
            'combined' => 'Combined',
            default => ucfirst($type ?? 'Unknown'),
        };
    }

    protected function applyStatusColor($sheet, int $row, ?string $status): void
    {
        $color = match ($status) {
            'completed' => 'FF4CAF50',
            'overage' => 'FFF44336',
            'active' => 'FF2196F3',
            'paused' => 'FFFFC107',
            default => null,
        };

        if ($color) {
            $sheet->getStyle("F{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($color);

            if (in_array($status, ['completed', 'overage', 'active'])) {
                $sheet->getStyle("F{$row}")->getFont()->getColor()->setARGB('FFFFFFFF');
            }
        }
    }

    protected function addSummarySheet($sheet, $records): void
    {
        $sheet->setCellValue('A1', 'Break Timer Export Summary');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A3', 'Total Sessions');
        $sheet->setCellValue('B3', $records->count());

        $sheet->setCellValue('A4', 'Completed');
        $sheet->setCellValue('B4', $records->where('status', 'completed')->count());

        $sheet->setCellValue('A5', 'Overage');
        $sheet->setCellValue('B5', $records->where('status', 'overage')->count());

        $sheet->setCellValue('A6', 'Active');
        $sheet->setCellValue('B6', $records->where('status', 'active')->count());

        $sheet->setCellValue('A7', 'Paused');
        $sheet->setCellValue('B7', $records->where('status', 'paused')->count());

        $sheet->setCellValue('A9', 'By Type');
        $sheet->getStyle('A9')->getFont()->setBold(true);

        $sheet->setCellValue('A10', '1st Break');
        $sheet->setCellValue('B10', $records->where('type', '1st_break')->count());

        $sheet->setCellValue('A11', '2nd Break');
        $sheet->setCellValue('B11', $records->where('type', '2nd_break')->count());

        $sheet->setCellValue('A12', 'Lunch');
        $sheet->setCellValue('B12', $records->where('type', 'lunch')->count());

        $sheet->setCellValue('A13', 'Combined');
        $sheet->setCellValue('B13', $records->where('type', 'combined')->count());

        $overageRecords = $records->where('overage_seconds', '>', 0);
        $sheet->setCellValue('A15', 'Overage Statistics');
        $sheet->getStyle('A15')->getFont()->setBold(true);

        $sheet->setCellValue('A16', 'Total Overage Sessions');
        $sheet->setCellValue('B16', $overageRecords->count());

        $sheet->setCellValue('A17', 'Average Overage (sec)');
        $sheet->setCellValue('B17', $overageRecords->count() > 0 ? round($overageRecords->avg('overage_seconds')) : 0);

        $sheet->setCellValue('A18', 'Max Overage (sec)');
        $sheet->setCellValue('B18', $overageRecords->max('overage_seconds') ?? 0);

        foreach (range('A', 'B') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
