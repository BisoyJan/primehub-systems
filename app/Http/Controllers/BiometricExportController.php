<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BiometricExportController extends Controller
{
    /**
     * Display the export interface
     */
    public function index()
    {
        // Get all users who have attendance records with their campaign assignments
        $users = User::whereHas('attendances')
            ->select('id', 'first_name', 'last_name')
            ->with(['employeeSchedules' => function ($query) {
                $query->select('id', 'user_id', 'campaign_id', 'site_id')
                    ->whereHas('attendances');
            }])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($user) {
                // Get unique campaign IDs for this user
                $campaignIds = $user->employeeSchedules->pluck('campaign_id')->unique()->filter()->values()->toArray();
                // Get unique site IDs for this user
                $siteIds = $user->employeeSchedules->pluck('site_id')->unique()->filter()->values()->toArray();

                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'employee_number' => (string) $user->id,
                    'campaign_ids' => $campaignIds,
                    'site_ids' => $siteIds,
                ];
            });

        // Get all sites that have attendance records with their campaign associations
        $sites = Site::whereHas('employeeSchedules.attendances')
            ->select('id', 'name')
            ->with(['employeeSchedules' => function ($query) {
                $query->select('id', 'site_id', 'campaign_id')
                    ->whereHas('attendances');
            }])
            ->orderBy('name')
            ->get()
            ->map(function ($site) {
                $campaignIds = $site->employeeSchedules->pluck('campaign_id')->unique()->filter()->values()->toArray();
                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'campaign_ids' => $campaignIds,
                ];
            });

        // Get all campaigns that have employee schedules with attendance records
        $campaignIds = EmployeeSchedule::whereHas('attendances')
            ->distinct()
            ->pluck('campaign_id')
            ->filter();

        $campaigns = Campaign::whereIn('id', $campaignIds)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('Attendance/BiometricRecords/Export', [
            'users' => $users,
            'sites' => $sites,
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * Export attendance records to Excel
     */
    public function export(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
            'site_ids' => 'nullable|array',
            'site_ids.*' => 'exists:sites,id',
            'campaign_ids' => 'nullable|array',
            'campaign_ids.*' => 'exists:campaigns,id',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        // Get filter arrays (handle both 'user_ids' and 'user_ids[]' parameter formats)
        $userIds = $request->input('user_ids', []);
        $siteIds = $request->input('site_ids', []);
        $campaignIds = $request->input('campaign_ids', []);

        // Filter out empty values
        $userIds = array_filter($userIds, fn($id) => !empty($id));
        $siteIds = array_filter($siteIds, fn($id) => !empty($id));
        $campaignIds = array_filter($campaignIds, fn($id) => !empty($id));

        // Build query for attendance records with full details
        // Use date strings for SQLite compatibility
        $query = Attendance::whereBetween('shift_date', [
                $startDate->toDateString(),
                $endDate->toDateString()
            ])
            ->with([
                'user:id,first_name,last_name',
                'bioInSite:id,name',
                'bioOutSite:id,name',
                'employeeSchedule:id,campaign_id,site_id',
                'employeeSchedule.campaign:id,name',
                'employeeSchedule.site:id,name',
            ]);

        if (!empty($userIds)) {
            $query->whereIn('user_id', $userIds);
        }

        if (!empty($siteIds)) {
            // Filter by site: check bio_in_site, bio_out_site, OR employee_schedule site
            // This ensures NCNS records (which have no bio sites) are included if their schedule matches
            $query->where(function ($q) use ($siteIds) {
                $q->whereIn('bio_in_site_id', $siteIds)
                  ->orWhereIn('bio_out_site_id', $siteIds)
                  ->orWhereHas('employeeSchedule', function ($subQ) use ($siteIds) {
                      $subQ->whereIn('site_id', $siteIds);
                  });
            });
        }

        if (!empty($campaignIds)) {
            // Filter by campaign via employee schedule
            $query->whereHas('employeeSchedule', function ($subQ) use ($campaignIds) {
                $subQ->whereIn('campaign_id', $campaignIds);
            });
        }

        $records = $query->orderBy('shift_date')->orderBy('user_id')->get();

        // Calculate statistics
        $statistics = $this->calculateStatistics($records);

        // Generate filename
        $filename = sprintf(
            'attendance_export_%s_to_%s.xlsx',
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );

        return $this->exportExcel($records, $statistics, $filename, $startDate, $endDate);
    }

    /**
     * Calculate attendance statistics
     */
    protected function calculateStatistics($records): array
    {
        $total = $records->count();

        return [
            'total' => $total,
            'on_time' => $records->where('status', 'on_time')->count(),
            'tardy' => $records->where('status', 'tardy')->count(),
            'half_day' => $records->where('status', 'half_day_absence')->count(),
            'ncns' => $records->where('status', 'ncns')->count(),
            'advised' => $records->where('status', 'advised_absence')->count(),
            'on_leave' => $records->where('status', 'on_leave')->count(),
            'undertime' => $records->where('status', 'undertime')->count(),
            'overtime' => $records->where('overtime_minutes', '>', 0)->count(),
            'failed_bio_in' => $records->where('status', 'failed_bio_in')->count(),
            'failed_bio_out' => $records->where('status', 'failed_bio_out')->count(),
            'needs_verification' => $records->where('admin_verified', false)->filter(function ($r) {
                return in_array($r->status, ['failed_bio_in', 'failed_bio_out', 'ncns', 'half_day_absence', 'tardy', 'undertime', 'needs_manual_review'])
                    || $r->is_cross_site_bio
                    || !empty($r->warnings);
            })->count(),
            'total_tardy_minutes' => $records->sum('tardy_minutes'),
            'total_undertime_minutes' => $records->sum('undertime_minutes'),
            'total_overtime_minutes' => $records->sum('overtime_minutes'),
        ];
    }

    /**
     * Export as Excel with full details and statistics on separate sheet
     */
    protected function exportExcel($records, array $statistics, string $filename, Carbon $startDate, Carbon $endDate)
    {
        $spreadsheet = new Spreadsheet();

        // First sheet: Attendance Records
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendance Records');

        // Set headers for attendance data
        $headers = [
            'User ID',
            'Employee Name',
            'Campaign',
            'Shift Date',
            'Scheduled Time In',
            'Scheduled Time Out',
            'Actual Time In',
            'Actual Time Out',
            'Time In Site',
            'Time Out Site',
            'Status',
            'Secondary Status',
            'Tardy (mins)',
            'Undertime (mins)',
            'Overtime (mins)',
            'OT Approved',
            'Cross-Site Bio',
            'Admin Verified',
        ];

        $sheet->fromArray($headers, null, 'A1');

        // Style the header row
        $lastDataCol = 'R'; // Column R for 18 columns
        $headerRange = "A1:{$lastDataCol}1";
        $this->styleHeaderRow($sheet, $headerRange);

        // Add data rows
        $row = 2;
        foreach ($records as $record) {
            // For NCNS/absent records, use the scheduled site if no bio site
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

            // Color-code status column based on status
            $this->applyStatusColor($sheet, $row, $record->status);

            $row++;
        }

        // Auto-size data columns
        foreach (range('A', $lastDataCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $lastDataRow = $row - 1;

        // Second sheet: Statistics with formulas
        $statsSheet = $spreadsheet->createSheet();
        $statsSheet->setTitle('Statistics');
        $this->addStatisticsSheet($statsSheet, $startDate, $endDate, $lastDataRow);

        // Set the first sheet as active
        $spreadsheet->setActiveSheetIndex(0);

        // Create writer and output
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Style header row
     */
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

    /**
     * Format status for display
     */
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

    /**
     * Apply color to status cell based on status value
     */
    protected function applyStatusColor($sheet, int $row, ?string $status): void
    {
        $statusCol = 'K'; // Status column
        $cell = $statusCol . $row;

        $color = match ($status) {
            'on_time' => 'FF4CAF50', // Green
            'tardy' => 'FFFFC107', // Yellow/Amber
            'half_day_absence' => 'FFFF9800', // Orange
            'ncns' => 'FFF44336', // Red
            'advised_absence', 'on_leave' => 'FF2196F3', // Blue
            'undertime' => 'FFFF5722', // Deep Orange
            'failed_bio_in', 'failed_bio_out' => 'FF9C27B0', // Purple
            'needs_manual_review' => 'FFFFC107', // Amber
            default => null,
        };

        if ($color) {
            $sheet->getStyle($cell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($color);

            // Use white text for dark backgrounds
            if (in_array($status, ['on_time', 'ncns', 'advised_absence', 'on_leave', 'failed_bio_in', 'failed_bio_out'])) {
                $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFFFF');
            }
        }
    }

    /**
     * Add statistics sheet with Excel formulas
     */
    protected function addStatisticsSheet($sheet, Carbon $startDate, Carbon $endDate, int $lastDataRow): void
    {
        $row = 1;
        $dataRange = "'Attendance Records'!K2:K" . $lastDataRow; // Status column range

        // Title
        $sheet->setCellValue('A' . $row, 'ATTENDANCE STATISTICS');
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;

        // Date Range
        $sheet->setCellValue('A' . $row, 'Date Range:');
        $sheet->setCellValue('B' . $row, $startDate->format('M d, Y') . ' - ' . $endDate->format('M d, Y'));
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        // Total Records with formula
        $sheet->setCellValue('A' . $row, 'Total Records:');
        $sheet->setCellValue('B' . $row, "=COUNTA({$dataRange})");
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $totalRecordsRow = $row; // Save for reference in formulas
        $row += 2;

        // Status breakdown header
        $sheet->setCellValue('A' . $row, 'STATUS BREAKDOWN');
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $this->styleStatHeader($sheet, 'A' . $row . ':C' . $row);
        $row++;

        // Column headers for status breakdown
        $sheet->setCellValue('A' . $row, 'Status');
        $sheet->setCellValue('B' . $row, 'Count');
        $sheet->setCellValue('C' . $row, 'Percentage');
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':C' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E0E0');
        $this->addBorder($sheet, 'A' . $row . ':C' . $row);
        $row++;

        // Status statistics with COUNTIF formulas
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

        $totalCell = 'B' . $totalRecordsRow; // Reference to total records cell

        foreach ($statusStats as [$label, $searchValue, $color]) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, "=COUNTIF({$dataRange},\"{$searchValue}\")");
            $sheet->setCellValue('C' . $row, "=IF({$totalCell}=0,0,B{$row}/{$totalCell})");

            // Format percentage
            $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('0.0%');

            // Add color indicator
            $sheet->getStyle('A' . $row)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($color);

            // Use white text for dark backgrounds
            if (in_array($color, ['FF4CAF50', 'FFF44336', 'FF2196F3', 'FF9C27B0'])) {
                $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
            }

            $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->addBorder($sheet, 'A' . $row . ':C' . $row);
            $row++;
        }

        $row++;

        // Time statistics header
        $sheet->setCellValue('A' . $row, 'TIME STATISTICS');
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $this->styleStatHeader($sheet, 'A' . $row . ':C' . $row);
        $row++;

        // Time ranges for SUM formulas
        $tardyRange = "'Attendance Records'!M2:M" . $lastDataRow;
        $undertimeRange = "'Attendance Records'!N2:N" . $lastDataRow;
        $overtimeRange = "'Attendance Records'!O2:O" . $lastDataRow;
        $otApprovedRange = "'Attendance Records'!P2:P" . $lastDataRow;
        $adminVerifiedRange = "'Attendance Records'!R2:R" . $lastDataRow;

        // Time statistics with formulas
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

        $row++;

        // Key Metrics header
        $sheet->setCellValue('A' . $row, 'KEY METRICS');
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $this->styleStatHeader($sheet, 'A' . $row . ':C' . $row);
        $row++;

        // Key metrics with formulas
        $onTimeRow = 8; // Row where On Time count is
        $tardyRow = 9;  // Row where Tardy count is
        $ncnsRow = 11;  // Row where NCNS count is
        $undertimeRow = 14; // Row where Undertime count is

        $metrics = [
            ['On Time Rate', "=IF({$totalCell}=0,0,B{$onTimeRow}/{$totalCell})"],
            ['Tardy Rate', "=IF({$totalCell}=0,0,B{$tardyRow}/{$totalCell})"],
            ['NCNS Rate', "=IF({$totalCell}=0,0,B{$ncnsRow}/{$totalCell})"],
            ['Attendance Rate', "=IF({$totalCell}=0,0,(B{$onTimeRow}+B{$tardyRow}+B{$undertimeRow})/{$totalCell})"],
            ['Avg Tardy Minutes', "=IF({$totalCell}=0,0,SUM({$tardyRange})/{$totalCell})"],
            ['Avg Overtime Minutes', "=IF({$totalCell}=0,0,SUM({$overtimeRange})/{$totalCell})"],
        ];

        foreach ($metrics as [$label, $formula]) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $formula);

            // Format as percentage for rate metrics
            if (str_contains($label, 'Rate')) {
                $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('0.0%');
            } else {
                $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.0');
            }

            $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->addBorder($sheet, 'A' . $row . ':B' . $row);
            $row++;
        }

        $row += 2;

        // Formula reference note
        $sheet->setCellValue('A' . $row, 'Note: All values are calculated using Excel formulas.');
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF666666'));
        $row++;
        $sheet->setCellValue('A' . $row, 'Data source: "Attendance Records" sheet');
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF666666'));

        // Auto-size columns
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(15);
    }

    /**
     * Get next column letter
     */
    protected function nextCol(string $col): string
    {
        return chr(ord($col) + 1);
    }

    /**
     * Style statistics header
     */
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

    /**
     * Add border to cells
     */
    protected function addBorder($sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
