<?php

namespace App\Jobs;

use App\Models\AttendancePoint;
use App\Models\Campaign;
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

class GenerateAllAttendancePointsExportExcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $jobId;
    protected array $filters;

    public function __construct(string $jobId, array $filters = [])
    {
        $this->jobId = $jobId;
        $this->filters = $filters;
    }

    public function handle(): void
    {
        $cacheKey = "attendance_points_export_all:{$this->jobId}";

        try {
            $this->updateProgress($cacheKey, 5, 'Building query...');

            $query = AttendancePoint::with(['user', 'attendance', 'excusedBy'])
                ->orderBy('shift_date', 'desc');

            // Apply filters
            if (!empty($this->filters['user_id'])) {
                $query->where('user_id', $this->filters['user_id']);
            }

            if (!empty($this->filters['point_type'])) {
                $query->where('point_type', $this->filters['point_type']);
            }

            if (!empty($this->filters['status'])) {
                if ($this->filters['status'] === 'active') {
                    $query->where('is_excused', false)->where('is_expired', false);
                } elseif ($this->filters['status'] === 'excused') {
                    $query->where('is_excused', true);
                } elseif ($this->filters['status'] === 'expired') {
                    $query->where('is_expired', true);
                }
            }

            if (!empty($this->filters['date_from'])) {
                $query->where('shift_date', '>=', $this->filters['date_from']);
            }

            if (!empty($this->filters['date_to'])) {
                $query->where('shift_date', '<=', $this->filters['date_to']);
            }

            if (!empty($this->filters['expiring_soon']) && $this->filters['expiring_soon'] === 'true') {
                $query->where('is_expired', false)
                    ->where('is_excused', false)
                    ->whereNotNull('expires_at')
                    ->whereDate('expires_at', '<=', now()->addDays(30));
            }

            if (!empty($this->filters['gbro_eligible']) && $this->filters['gbro_eligible'] === 'true') {
                $query->where('eligible_for_gbro', true)
                    ->where('is_expired', false)
                    ->where('is_excused', false);
            }

            $this->updateProgress($cacheKey, 10, 'Fetching attendance points...');

            $points = $query->get();

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
                'unique_employees' => $points->pluck('user_id')->unique()->count(),
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

            // Get top employees by points
            $topEmployees = $activePoints->groupBy('user_id')->map(function ($userPoints) {
                return [
                    'name' => $userPoints->first()->user->name,
                    'count' => $userPoints->count(),
                    'points' => $userPoints->sum('points'),
                ];
            })->sortByDesc('points')->take(10)->values();

            // Calculate per-employee statistics with campaign info
            $allEmployeeStats = $points->groupBy('user_id')->map(function ($userPoints) {
                $user = $userPoints->first()->user;
                $activeUserPoints = $userPoints->where('is_excused', false)->where('is_expired', false);

                // Get the user's current campaign from their active schedule
                $currentSchedule = $user->employeeSchedules()
                    ->where('is_active', true)
                    ->with('campaign')
                    ->first();
                $campaignName = $currentSchedule?->campaign?->name ?? 'No Campaign';

                return [
                    'name' => $user->name,
                    'campaign' => $campaignName,
                    'total_violations' => $userPoints->count(),
                    'active_violations' => $activeUserPoints->count(),
                    'active_points' => $activeUserPoints->sum('points'),
                    'excused_count' => $userPoints->where('is_excused', true)->count(),
                    'expired_count' => $userPoints->where('is_expired', true)->count(),
                    'whole_day_count' => $activeUserPoints->where('point_type', 'whole_day_absence')->count(),
                    'half_day_count' => $activeUserPoints->where('point_type', 'half_day_absence')->count(),
                    'tardy_count' => $activeUserPoints->where('point_type', 'tardy')->count(),
                    'undertime_count' => $activeUserPoints->where('point_type', 'undertime')->count(),
                    'gbro_eligible' => $activeUserPoints->where('eligible_for_gbro', true)->count(),
                ];
            })->sortByDesc('active_points')->values();

            // Calculate campaign statistics
            $campaignStats = $allEmployeeStats->groupBy('campaign')->map(function ($campaignUsers, $campaignName) {
                return [
                    'campaign' => $campaignName,
                    'employee_count' => $campaignUsers->count(),
                    'total_violations' => $campaignUsers->sum('total_violations'),
                    'active_violations' => $campaignUsers->sum('active_violations'),
                    'total_active_points' => $campaignUsers->sum('active_points'),
                    'avg_points_per_employee' => $campaignUsers->count() > 0
                        ? round($campaignUsers->sum('active_points') / $campaignUsers->count(), 2)
                        : 0,
                ];
            })->sortByDesc('total_active_points')->values();

            $this->updateProgress($cacheKey, 30, 'Creating spreadsheet...');

            $spreadsheet = new Spreadsheet();

            // ========================================
            // SHEET 1: Attendance Points Details
            // ========================================
            $sheet1 = $spreadsheet->getActiveSheet();
            $sheet1->setTitle('Attendance Points');

            // Title Section
            $sheet1->setCellValue('A1', 'ATTENDANCE POINTS REPORT - ALL EMPLOYEES');
            $sheet1->mergeCells('A1:I1');
            $sheet1->getStyle('A1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '1F2937']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet1->setCellValue('A2', 'Generated: ' . now()->format('F j, Y \a\t g:i A'));
            $sheet1->mergeCells('A2:I2');
            $sheet1->getStyle('A2')->applyFromArray([
                'font' => ['size' => 10, 'italic' => true, 'color' => ['rgb' => '9CA3AF']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            // Applied Filters Info
            $filtersApplied = [];
            if (!empty($this->filters['user_id'])) {
                $filterUser = User::find($this->filters['user_id']);
                $filtersApplied[] = 'Employee: ' . ($filterUser->name ?? $this->filters['user_id']);
            }
            if (!empty($this->filters['point_type'])) {
                $filtersApplied[] = 'Type: ' . ucfirst(str_replace('_', ' ', $this->filters['point_type']));
            }
            if (!empty($this->filters['status'])) {
                $filtersApplied[] = 'Status: ' . ucfirst($this->filters['status']);
            }
            if (!empty($this->filters['date_from']) || !empty($this->filters['date_to'])) {
                $filtersApplied[] = 'Date: ' . ($this->filters['date_from'] ?? 'Start') . ' to ' . ($this->filters['date_to'] ?? 'End');
            }

            if (!empty($filtersApplied)) {
                $sheet1->setCellValue('A3', 'Filters: ' . implode(' | ', $filtersApplied));
                $sheet1->mergeCells('A3:I3');
                $sheet1->getStyle('A3')->applyFromArray([
                    'font' => ['size' => 9, 'color' => ['rgb' => '6B7280']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
            }

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

            $sheet1->setCellValue('C6', 'Employees:');
            $sheet1->setCellValue('D6', $stats['unique_employees']);

            $sheet1->setCellValue('E6', 'Active:');
            $sheet1->setCellValue('F6', $stats['active_count']);

            $sheet1->setCellValue('G6', 'Excused:');
            $sheet1->setCellValue('H6', $stats['excused_count']);
            $sheet1->getStyle('H6')->applyFromArray([
                'font' => ['color' => ['rgb' => '059669']],
            ]);

            $sheet1->setCellValue('I6', 'Expired:');
            $sheet1->setCellValue('J6', $stats['expired_count']);
            $sheet1->getStyle('J6')->applyFromArray([
                'font' => ['color' => ['rgb' => '6B7280']],
            ]);

            $this->updateProgress($cacheKey, 40, 'Writing data rows...');

            // Headers for data table
            $headers = ['Employee', 'Date', 'Type', 'Points', 'Status', 'Violation Details', 'Expires At', 'Excused By', 'Notes'];
            $sheet1->fromArray($headers, null, 'A8');

            $sheet1->getStyle('A8:I8')->applyFromArray([
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
                    $point->user->name,
                    Carbon::parse($point->shift_date)->format('M j, Y'),
                    $typeLabel,
                    number_format((float) ($point->points ?? 0), 2),
                    $status,
                    $point->violation_details ? substr($point->violation_details, 0, 70) . (strlen($point->violation_details) > 70 ? '...' : '') : '-',
                    $point->expires_at ? Carbon::parse($point->expires_at)->format('M j, Y') : '-',
                    $point->excusedBy?->name ?? '-',
                    $point->notes ?? '-',
                ], null, "A{$row}");

                // Status-based row coloring
                if ($point->is_expired) {
                    $sheet1->getStyle("A{$row}:I{$row}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
                        'font' => ['color' => ['rgb' => '9CA3AF']],
                    ]);
                } elseif ($point->is_excused) {
                    $sheet1->getStyle("A{$row}:I{$row}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1FAE5']],
                        'font' => ['color' => ['rgb' => '065F46']],
                    ]);
                } else {
                    // Active - alternate row colors
                    if ($row % 2 === 0) {
                        $sheet1->getStyle("A{$row}:I{$row}")->applyFromArray([
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
                        ]);
                    }
                }

                // Add borders to data rows
                $sheet1->getStyle("A{$row}:I{$row}")->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']],
                    ],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);

                $row++;
                $processed++;

                // Update progress every 100 records
                if ($processed % 100 === 0) {
                    $percent = 40 + intval(($processed / max($total, 1)) * 30);
                    $this->updateProgress($cacheKey, $percent, "Processing record {$processed}/{$total}...");
                }
            }

            $this->updateProgress($cacheKey, 75, 'Auto-sizing columns...');

            // Auto-size columns for sheet 1
            foreach (range('A', 'I') as $col) {
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

            $sheet2->setCellValue('A2', 'All Employees Summary');
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
                ['Unique Employees', $stats['unique_employees'], '', ''],
            ];

            $sheet2->fromArray($overviewData, null, 'A6');
            $sheet2->getStyle('A6:D6')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '374151']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
            ]);
            $sheet2->getStyle('A7:D11')->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
            ]);

            // Highlight total active points
            $sheet2->getStyle('C7')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'DC2626'], 'size' => 12],
            ]);

            // Breakdown by Type Section
            $sheet2->setCellValue('A14', 'BREAKDOWN BY TYPE');
            $sheet2->mergeCells('A14:D14');
            $sheet2->getStyle('A14')->applyFromArray([
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
                ['Undertime (>Hour)', $stats['by_type']['undertime_more_than_hour']['count'], number_format($stats['by_type']['undertime_more_than_hour']['points'], 2), '0.50'],
            ];

            $sheet2->fromArray($typeData, null, 'A15');
            $sheet2->getStyle('A15:D15')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '374151']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FECACA']]],
            ]);
            $sheet2->getStyle('A16:D20')->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
            ]);

            // Color code the type rows
            $typeColors = [
                16 => 'FEF2F2', // Whole Day - Red tint
                17 => 'FFF7ED', // Half Day - Orange tint
                18 => 'FEFCE8', // Tardy - Yellow tint
                19 => 'FEFCE8', // Undertime (Hour) - Yellow tint
                20 => 'FFF7ED', // Undertime (>Hour) - Orange tint
            ];
            foreach ($typeColors as $rowNum => $color) {
                $sheet2->getStyle("A{$rowNum}:D{$rowNum}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
                ]);
            }

            // Campaign Statistics Section (NEW)
            $sheet2->setCellValue('A22', 'BREAKDOWN BY CAMPAIGN');
            $sheet2->mergeCells('A22:F22');
            $sheet2->getStyle('A22')->applyFromArray([
                'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '7C3AED']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $campaignHeaders = ['Campaign', 'Employees', 'Total Violations', 'Active Violations', 'Active Points', 'Avg Points/Employee'];
            $sheet2->fromArray($campaignHeaders, null, 'A23');
            $sheet2->getStyle('A23:F23')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '374151']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDE9FE']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C4B5FD']]],
            ]);

            $campaignRow = 24;
            foreach ($campaignStats as $campaign) {
                $sheet2->fromArray([
                    $campaign['campaign'],
                    $campaign['employee_count'],
                    $campaign['total_violations'],
                    $campaign['active_violations'],
                    number_format($campaign['total_active_points'], 2),
                    number_format($campaign['avg_points_per_employee'], 2),
                ], null, "A{$campaignRow}");
                $sheet2->getStyle("A{$campaignRow}:F{$campaignRow}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
                ]);
                // Highlight campaigns with high points
                if ($campaign['total_active_points'] > 5) {
                    $sheet2->getStyle("E{$campaignRow}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => 'DC2626']],
                    ]);
                }
                $campaignRow++;
            }

            // Top 10 Employees Section
            $top10StartRow = $campaignRow + 2;
            $sheet2->setCellValue("A{$top10StartRow}", 'TOP 10 EMPLOYEES BY POINTS');
            $sheet2->mergeCells("A{$top10StartRow}:E{$top10StartRow}");
            $sheet2->getStyle("A{$top10StartRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F59E0B']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $top10HeaderRow = $top10StartRow + 1;
            $sheet2->setCellValue("A{$top10HeaderRow}", 'Rank');
            $sheet2->setCellValue("B{$top10HeaderRow}", 'Employee Name');
            $sheet2->setCellValue("C{$top10HeaderRow}", 'Violations');
            $sheet2->setCellValue("D{$top10HeaderRow}", 'Total Points');
            $sheet2->getStyle("A{$top10HeaderRow}:D{$top10HeaderRow}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '374151']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FCD34D']]],
            ]);

            $topRow = $top10HeaderRow + 1;
            $rank = 1;
            foreach ($topEmployees as $employee) {
                $sheet2->setCellValue("A{$topRow}", $rank);
                $sheet2->setCellValue("B{$topRow}", $employee['name']);
                $sheet2->setCellValue("C{$topRow}", $employee['count']);
                $sheet2->setCellValue("D{$topRow}", number_format($employee['points'], 2));
                $sheet2->getStyle("A{$topRow}:D{$topRow}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
                ]);
                if ($rank <= 3) {
                    $sheet2->getStyle("A{$topRow}:D{$topRow}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFBEB']],
                        'font' => ['bold' => true],
                    ]);
                }
                $topRow++;
                $rank++;
            }

            // GBRO Status Section
            $gbroRow = $topRow + 2;
            $sheet2->setCellValue("A{$gbroRow}", 'GBRO STATUS');
            $sheet2->mergeCells("A{$gbroRow}:D{$gbroRow}");
            $sheet2->getStyle("A{$gbroRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '059669']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $gbroRow++;
            $sheet2->setCellValue("A{$gbroRow}", 'Total GBRO Eligible Points:');
            $sheet2->setCellValue("B{$gbroRow}", $stats['gbro_eligible_count']);
            $sheet2->getStyle("A{$gbroRow}:D{$gbroRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1FAE5']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'A7F3D0']]],
            ]);

            $gbroRow++;
            $sheet2->setCellValue("A{$gbroRow}", 'Note: Good Behavior Roll Off (GBRO) removes the last 2 eligible points after 60 days without violations.');
            $sheet2->mergeCells("A{$gbroRow}:D{$gbroRow}");
            $sheet2->getStyle("A{$gbroRow}")->applyFromArray([
                'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '6B7280']],
            ]);

            // Legend Section
            $legendStartRow = $gbroRow + 3;
            $sheet2->setCellValue("A{$legendStartRow}", 'LEGEND');
            $sheet2->mergeCells("A{$legendStartRow}:D{$legendStartRow}");
            $sheet2->getStyle("A{$legendStartRow}")->applyFromArray([
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

            $legendRow = $legendStartRow + 1;
            foreach ($legendData as $legend) {
                $sheet2->setCellValue("A{$legendRow}", $legend[0]);
                $sheet2->setCellValue("B{$legendRow}", $legend[1]);
                $sheet2->mergeCells("B{$legendRow}:D{$legendRow}");
                $sheet2->getStyle("A{$legendRow}")->applyFromArray([
                    'font' => ['bold' => true],
                ]);
                $sheet2->getStyle("B{$legendRow}")->getAlignment()->setWrapText(true);
                $legendRow++;
            }

            // Set fixed column widths for sheet 2
            $sheet2->getColumnDimension('A')->setWidth(25);
            $sheet2->getColumnDimension('B')->setWidth(30);
            $sheet2->getColumnDimension('C')->setWidth(18);
            $sheet2->getColumnDimension('D')->setWidth(18);
            $sheet2->getColumnDimension('E')->setWidth(15);
            $sheet2->getColumnDimension('F')->setWidth(20);

            $this->updateProgress($cacheKey, 85, 'Creating employee statistics sheet...');

            // ========================================
            // SHEET 3: Employee Statistics (Searchable)
            // ========================================
            $sheet3 = $spreadsheet->createSheet();
            $sheet3->setTitle('Employee Statistics');

            // Title
            $sheet3->setCellValue('A1', 'EMPLOYEE STATISTICS - SEARCHABLE');
            $sheet3->mergeCells('A1:L1');
            $sheet3->getStyle('A1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '1F2937']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet3->setCellValue('A2', 'Use Excel\'s Filter (Ctrl+Shift+L) or Data > Filter to search by employee name or campaign');
            $sheet3->mergeCells('A2:L2');
            $sheet3->getStyle('A2')->applyFromArray([
                'font' => ['size' => 11, 'italic' => true, 'color' => ['rgb' => '6B7280']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet3->setCellValue('A3', 'Generated: ' . now()->format('F j, Y \a\t g:i A'));
            $sheet3->mergeCells('A3:L3');
            $sheet3->getStyle('A3')->applyFromArray([
                'font' => ['size' => 10, 'italic' => true, 'color' => ['rgb' => '9CA3AF']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            // Headers for employee statistics
            $empHeaders = [
                'Employee Name',
                'Campaign',
                'Total Violations',
                'Active Violations',
                'Active Points',
                'Excused',
                'Expired',
                'Whole Day',
                'Half Day',
                'Tardy',
                'Undertime',
                'GBRO Eligible'
            ];
            $sheet3->fromArray($empHeaders, null, 'A5');
            $sheet3->getStyle('A5:L5')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '4F46E5']],
                ],
            ]);
            $sheet3->getRowDimension(5)->setRowHeight(25);

            // Data rows for all employees
            $empRow = 6;
            foreach ($allEmployeeStats as $empStat) {
                $sheet3->fromArray([
                    $empStat['name'],
                    $empStat['campaign'],
                    $empStat['total_violations'],
                    $empStat['active_violations'],
                    number_format($empStat['active_points'], 2),
                    $empStat['excused_count'],
                    $empStat['expired_count'],
                    $empStat['whole_day_count'],
                    $empStat['half_day_count'],
                    $empStat['tardy_count'],
                    $empStat['undertime_count'],
                    $empStat['gbro_eligible'],
                ], null, "A{$empRow}");

                // Alternate row colors
                $bgColor = ($empRow % 2 === 0) ? 'F9FAFB' : 'FFFFFF';
                $sheet3->getStyle("A{$empRow}:L{$empRow}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]],
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']],
                    ],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);

                // Highlight high points
                if ($empStat['active_points'] >= 3) {
                    $sheet3->getStyle("E{$empRow}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => 'DC2626']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']],
                    ]);
                } elseif ($empStat['active_points'] >= 2) {
                    $sheet3->getStyle("E{$empRow}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => 'D97706']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
                    ]);
                }

                $empRow++;
            }

            // Enable AutoFilter for search functionality
            $lastEmpRow = $empRow - 1;
            if ($lastEmpRow >= 6) {
                $sheet3->setAutoFilter("A5:L{$lastEmpRow}");
            }

            // Freeze pane to keep headers visible
            $sheet3->freezePane('A6');

            // Auto-size columns for sheet 3
            $sheet3->getColumnDimension('A')->setWidth(25);
            $sheet3->getColumnDimension('B')->setWidth(20);
            foreach (range('C', 'L') as $col) {
                $sheet3->getColumnDimension($col)->setWidth(15);
            }

            // Set active sheet back to first sheet
            $spreadsheet->setActiveSheetIndex(0);

            $this->updateProgress($cacheKey, 90, 'Saving Excel file...');

            $filename = sprintf(
                'attendance-points-all-%s.xlsx',
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
                route('attendance-points.export-all-excel.download', ['jobId' => $this->jobId]),
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
