<?php

namespace App\Jobs;

use App\Models\AttendancePoint;
use App\Models\User;
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

class GenerateAttendancePointsExportExcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $jobId;
    protected int $userId;

    public function __construct(string $jobId, int $userId)
    {
        $this->jobId = $jobId;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        $cacheKey = "attendance_points_export:{$this->jobId}";

        try {
            $this->updateProgress($cacheKey, 5, 'Loading user data...');

            $user = User::findOrFail($this->userId);

            $this->updateProgress($cacheKey, 10, 'Fetching attendance points...');

            $points = AttendancePoint::where('user_id', $user->id)
                ->with(['attendance', 'excusedBy'])
                ->orderBy('shift_date', 'desc')
                ->get();

            $this->updateProgress($cacheKey, 20, 'Calculating statistics...');

            // Calculate statistics
            $activePoints = $points->where('is_excused', false)->where('is_expired', false);
            $stats = [
                'total_active_points' => $activePoints->sum('points'),
                'total_records' => $points->count(),
                'active_count' => $activePoints->count(),
                'excused_count' => $points->where('is_excused', true)->count(),
                'excused_points' => $points->where('is_excused', true)->sum('points'),
                'expired_count' => $points->where('is_expired', true)->count(),
                'expired_points' => $points->where('is_expired', true)->sum('points'),
                'by_type' => [
                    'whole_day_absence' => [
                        'count' => $activePoints->where('point_type', 'whole_day_absence')->count(),
                        'points' => $activePoints->where('point_type', 'whole_day_absence')->sum('points'),
                    ],
                    'half_day_absence' => [
                        'count' => $activePoints->where('point_type', 'half_day_absence')->count(),
                        'points' => $activePoints->where('point_type', 'half_day_absence')->sum('points'),
                    ],
                    'tardy' => [
                        'count' => $activePoints->where('point_type', 'tardy')->count(),
                        'points' => $activePoints->where('point_type', 'tardy')->sum('points'),
                    ],
                    'undertime' => [
                        'count' => $activePoints->where('point_type', 'undertime')->count(),
                        'points' => $activePoints->where('point_type', 'undertime')->sum('points'),
                    ],
                    'undertime_more_than_hour' => [
                        'count' => $activePoints->where('point_type', 'undertime_more_than_hour')->count(),
                        'points' => $activePoints->where('point_type', 'undertime_more_than_hour')->sum('points'),
                    ],
                ],
                'gbro_eligible_count' => $activePoints->where('eligible_for_gbro', true)->count(),
            ];

            $this->updateProgress($cacheKey, 30, 'Creating spreadsheet...');

            $spreadsheet = new Spreadsheet();

            // ========================================
            // SHEET 1: Attendance Points Details
            // ========================================
            $sheet1 = $spreadsheet->getActiveSheet();
            $sheet1->setTitle('Attendance Points');

            // Title Section
            $sheet1->setCellValue('A1', 'ATTENDANCE POINTS REPORT');
            $sheet1->mergeCells('A1:H1');
            $sheet1->getStyle('A1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '1F2937']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet1->setCellValue('A2', "Employee: {$user->name}");
            $sheet1->mergeCells('A2:H2');
            $sheet1->getStyle('A2')->applyFromArray([
                'font' => ['size' => 12, 'color' => ['rgb' => '6B7280']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet1->setCellValue('A3', 'Generated: ' . now()->format('F j, Y \a\t g:i A'));
            $sheet1->mergeCells('A3:H3');
            $sheet1->getStyle('A3')->applyFromArray([
                'font' => ['size' => 10, 'italic' => true, 'color' => ['rgb' => '9CA3AF']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            // Summary Stats Row
            $sheet1->setCellValue('A5', 'SUMMARY');
            $sheet1->getStyle('A5')->applyFromArray([
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '374151']],
            ]);

            $sheet1->setCellValue('A6', 'Total Active Points:');
            $sheet1->setCellValue('B6', number_format($stats['total_active_points'], 2));
            $sheet1->getStyle('B6')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'DC2626']],
            ]);

            $sheet1->setCellValue('C6', 'Active Records:');
            $sheet1->setCellValue('D6', $stats['active_count']);

            $sheet1->setCellValue('E6', 'Excused:');
            $sheet1->setCellValue('F6', $stats['excused_count']);
            $sheet1->getStyle('F6')->applyFromArray([
                'font' => ['color' => ['rgb' => '059669']],
            ]);

            $sheet1->setCellValue('G6', 'Expired:');
            $sheet1->setCellValue('H6', $stats['expired_count']);
            $sheet1->getStyle('H6')->applyFromArray([
                'font' => ['color' => ['rgb' => '6B7280']],
            ]);

            $this->updateProgress($cacheKey, 40, 'Writing data rows...');

            // Headers for data table
            $headers = ['Date', 'Type', 'Points', 'Status', 'Violation Details', 'Expires At', 'Excused By', 'Notes'];
            $sheet1->fromArray($headers, null, 'A8');

            $sheet1->getStyle('A8:H8')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '4F46E5']],
                ],
            ]);
            $sheet1->getRowDimension(8)->setRowHeight(25);

            // Data rows
            $row = 9;
            $total = $points->count();
            $processed = 0;

            foreach ($points as $point) {
                $status = $point->is_expired ? 'Expired' : ($point->is_excused ? 'Excused' : 'Active');
                $typeLabel = match ($point->point_type) {
                    'whole_day_absence' => 'Whole Day Absence',
                    'half_day_absence' => 'Half-Day Absence',
                    'tardy' => 'Tardy',
                    'undertime' => 'Undertime (Hour)',
                    'undertime_more_than_hour' => 'Undertime (>Hour)',
                    default => $point->point_type,
                };

                $sheet1->fromArray([
                    Carbon::parse($point->shift_date)->format('M j, Y'),
                    $typeLabel,
                    number_format((float) ($point->points ?? 0), 2),
                    $status,
                    $point->violation_details ? substr($point->violation_details, 0, 50) . (strlen($point->violation_details) > 50 ? '...' : '') : '-',
                    $point->expires_at ? Carbon::parse($point->expires_at)->format('M j, Y') : '-',
                    $point->excusedBy?->name ?? '-',
                    $point->notes ?? '-',
                ], null, "A{$row}");

                // Status-based row coloring
                if ($point->is_expired) {
                    $sheet1->getStyle("A{$row}:H{$row}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
                        'font' => ['color' => ['rgb' => '9CA3AF']],
                    ]);
                } elseif ($point->is_excused) {
                    $sheet1->getStyle("A{$row}:H{$row}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1FAE5']],
                        'font' => ['color' => ['rgb' => '065F46']],
                    ]);
                } else {
                    // Active - alternate row colors
                    if ($row % 2 === 0) {
                        $sheet1->getStyle("A{$row}:H{$row}")->applyFromArray([
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
                        ]);
                    }
                }

                // Add borders to data rows
                $sheet1->getStyle("A{$row}:H{$row}")->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']],
                    ],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);

                $row++;
                $processed++;

                // Update progress every 50 records
                if ($processed % 50 === 0) {
                    $percent = 40 + intval(($processed / max($total, 1)) * 30);
                    $this->updateProgress($cacheKey, $percent, "Processing record {$processed}/{$total}...");
                }
            }

            $this->updateProgress($cacheKey, 75, 'Auto-sizing columns...');

            // Auto-size columns
            foreach (range('A', 'H') as $col) {
                $sheet1->getColumnDimension($col)->setAutoSize(true);
            }

            $this->updateProgress($cacheKey, 80, 'Creating statistics sheet...');

            // ========================================
            // SHEET 2: Statistics
            // ========================================
            $sheet2 = $spreadsheet->createSheet();
            $sheet2->setTitle('Statistics');

            // Title
            $sheet2->setCellValue('A1', 'ATTENDANCE POINTS STATISTICS');
            $sheet2->mergeCells('A1:D1');
            $sheet2->getStyle('A1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '1F2937']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet2->setCellValue('A2', "Employee: {$user->name}");
            $sheet2->mergeCells('A2:D2');
            $sheet2->getStyle('A2')->applyFromArray([
                'font' => ['size' => 12, 'color' => ['rgb' => '6B7280']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet2->setCellValue('A3', 'Generated: ' . now()->format('F j, Y \a\t g:i A'));
            $sheet2->mergeCells('A3:D3');
            $sheet2->getStyle('A3')->applyFromArray([
                'font' => ['size' => 10, 'italic' => true, 'color' => ['rgb' => '9CA3AF']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            // Overview Section
            $sheet2->setCellValue('A5', 'OVERVIEW');
            $sheet2->mergeCells('A5:D5');
            $sheet2->getStyle('A5')->applyFromArray([
                'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $overviewData = [
                ['Metric', 'Count', 'Points', ''],
                ['Total Active', $stats['active_count'], number_format($stats['total_active_points'], 2), ''],
                ['Excused', $stats['excused_count'], number_format($stats['excused_points'], 2), ''],
                ['Expired', $stats['expired_count'], number_format($stats['expired_points'], 2), ''],
                ['Total Records', $stats['total_records'], '', ''],
            ];

            $sheet2->fromArray($overviewData, null, 'A6');
            $sheet2->getStyle('A6:D6')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '374151']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
            ]);
            $sheet2->getStyle('A7:D10')->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
            ]);

            // Highlight total active points
            $sheet2->getStyle('C7')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'DC2626'], 'size' => 12],
            ]);

            // Breakdown by Type Section
            $sheet2->setCellValue('A13', 'BREAKDOWN BY TYPE');
            $sheet2->mergeCells('A13:D13');
            $sheet2->getStyle('A13')->applyFromArray([
                'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $typeData = [
                ['Violation Type', 'Occurrences', 'Points', 'Point Value'],
                ['Whole Day Absence', $stats['by_type']['whole_day_absence']['count'], number_format($stats['by_type']['whole_day_absence']['points'], 2), '1.00'],
                ['Half-Day Absence', $stats['by_type']['half_day_absence']['count'], number_format($stats['by_type']['half_day_absence']['points'], 2), '0.50'],
                ['Tardy', $stats['by_type']['tardy']['count'], number_format($stats['by_type']['tardy']['points'], 2), '0.25'],
                ['Undertime (Hour)', $stats['by_type']['undertime']['count'], number_format($stats['by_type']['undertime']['points'], 2), '0.25'],
                ['Undertime (>Hour)', $stats['by_type']['undertime_more_than_hour']['count'] ?? 0, number_format($stats['by_type']['undertime_more_than_hour']['points'] ?? 0, 2), '0.50'],
            ];

            $sheet2->fromArray($typeData, null, 'A14');
            $sheet2->getStyle('A14:D14')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '374151']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FECACA']]],
            ]);
            $sheet2->getStyle('A15:D19')->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
            ]);

            // Color code the type rows
            $typeColors = [
                15 => 'FEF2F2', // Whole Day - Red tint
                16 => 'FFF7ED', // Half Day - Orange tint
                17 => 'FEFCE8', // Tardy - Yellow tint
                18 => 'FEFCE8', // Undertime (Hour) - Yellow tint
                19 => 'FFF7ED', // Undertime (>Hour) - Orange tint
            ];
            foreach ($typeColors as $rowNum => $color) {
                $sheet2->getStyle("A{$rowNum}:D{$rowNum}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
                ]);
            }

            // GBRO Status Section
            $sheet2->setCellValue('A21', 'GBRO STATUS');
            $sheet2->mergeCells('A21:D21');
            $sheet2->getStyle('A21')->applyFromArray([
                'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '059669']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet2->setCellValue('A22', 'GBRO Eligible Points:');
            $sheet2->setCellValue('B22', $stats['gbro_eligible_count']);
            $sheet2->getStyle('A22:D22')->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1FAE5']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'A7F3D0']]],
            ]);

            $sheet2->setCellValue('A23', 'Note: Good Behavior Roll Off (GBRO) removes the last 2 eligible points after 60 days without violations.');
            $sheet2->mergeCells('A23:D23');
            $sheet2->getStyle('A23')->applyFromArray([
                'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '6B7280']],
            ]);

            // Legend Section
            $sheet2->setCellValue('A26', 'LEGEND');
            $sheet2->mergeCells('A26:D26');
            $sheet2->getStyle('A26')->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '374151']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
            ]);

            $legendData = [
                ['Active', 'Points currently counting against the employee'],
                ['Excused', 'Points removed due to valid excuse (shown in green)'],
                ['Expired', 'Points expired via SRO or GBRO (shown in gray)'],
                ['SRO', 'Standard Roll Off - 6 months for regular violations, 1 year for NCNS'],
                ['GBRO', 'Good Behavior Roll Off - 60 days clean removes last 2 points'],
            ];

            $legendRow = 27;
            foreach ($legendData as $legend) {
                $sheet2->setCellValue("A{$legendRow}", $legend[0]);
                $sheet2->setCellValue("B{$legendRow}", $legend[1]);
                $sheet2->mergeCells("B{$legendRow}:D{$legendRow}");
                $sheet2->getStyle("A{$legendRow}")->applyFromArray([
                    'font' => ['bold' => true],
                ]);
                // Enable text wrap for description
                $sheet2->getStyle("B{$legendRow}")->getAlignment()->setWrapText(true);
                $legendRow++;
            }

            // Set fixed column widths for sheet 2 to ensure all content is visible
            $sheet2->getColumnDimension('A')->setWidth(20);
            $sheet2->getColumnDimension('B')->setWidth(25);
            $sheet2->getColumnDimension('C')->setWidth(15);
            $sheet2->getColumnDimension('D')->setWidth(40);

            // Set active sheet back to first sheet
            $spreadsheet->setActiveSheetIndex(0);

            $this->updateProgress($cacheKey, 90, 'Saving Excel file...');

            $filename = sprintf(
                'attendance-points-%s-%s.xlsx',
                preg_replace('/[^a-zA-Z0-9\-_]/', '-', $user->name),
                now()->format('Y-m-d')
            );

            $tempDir = storage_path('app/temp');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            $filePath = $tempDir . '/' . $this->jobId . '_' . $filename;
            $writer = new Xlsx($spreadsheet);
            $writer->save($filePath);

            $this->updateProgress(
                $cacheKey,
                100,
                'Complete',
                true,
                route('attendance-points.export-excel.download', ['jobId' => $this->jobId]),
                $filename
            );

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

    protected function updateProgress(
        string $cacheKey,
        int $percent,
        string $status,
        bool $finished = false,
        ?string $downloadUrl = null,
        ?string $filename = null
    ): void {
        Cache::put($cacheKey, [
            'percent' => $percent,
            'status' => $status,
            'finished' => $finished,
            'downloadUrl' => $downloadUrl,
            'filename' => $filename,
        ], 600);
    }
}
