<?php

namespace App\Jobs;

use App\Models\LeaveCredit;
use App\Models\User;
use App\Services\LeaveCreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateLeaveCreditsExportExcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $jobId;
    protected int $year;

    public function __construct(string $jobId, int $year)
    {
        $this->jobId = $jobId;
        $this->year = $year;
    }

    public function handle(): void
    {
        $cacheKey = "leave_credits_export_job:{$this->jobId}";

        try {
            // Update progress: Starting
            $this->updateProgress($cacheKey, 5, 'Fetching employees...');

            // Get all users with hire dates
            $users = User::whereNotNull('hired_date')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get();

            $this->updateProgress($cacheKey, 10, 'Generating missing credits...');

            // Backfill credits for all users for the specified year
            $leaveCreditService = app(LeaveCreditService::class);
            $usersCount = $users->count();
            $processed = 0;

            foreach ($users as $user) {
                $hireDate = Carbon::parse($user->hired_date);
                $targetYear = $this->year;

                // Only backfill if:
                // 1. User was hired before or during the target year
                // 2. Target year is not in the future beyond current year
                if ($hireDate->year <= $targetYear && $targetYear <= now()->year) {
                    // Determine start month for this user in the target year
                    $startMonth = ($hireDate->year == $targetYear) ? $hireDate->month : 1;

                    // Determine end month (current month for current year, December for past years)
                    $endMonth = ($targetYear == now()->year) ? now()->month - 1 : 12;

                    // Accrue credits for each month in the target year
                    for ($month = $startMonth; $month <= $endMonth; $month++) {
                        $monthEndDate = Carbon::create($targetYear, $month, 1)->endOfMonth();

                        // Only accrue if the month has ended
                        if ($monthEndDate->lte(now())) {
                            $leaveCreditService->accrueMonthly($user, $targetYear, $month);
                        }
                    }
                }

                $processed++;
                if ($processed % 10 == 0 || $processed == $usersCount) {
                    $progress = 10 + (($processed / $usersCount) * 10);
                    $this->updateProgress($cacheKey, (int)$progress, "Generated credits for {$processed}/{$usersCount} employees...");
                }
            }

            $this->updateProgress($cacheKey, 20, 'Collecting leave credits data...');

            // Prepare data - now include ALL users with hire dates
            $data = [];
            foreach ($users as $user) {
                $hireDate = Carbon::parse($user->hired_date);

                // Only include users who were hired before or during the target year
                // and target year is not beyond current year
                if ($hireDate->year <= $this->year && $this->year <= now()->year) {
                    $totalEarned = LeaveCredit::getTotalEarned($user->id, $this->year);
                    $totalUsed = LeaveCredit::getTotalUsed($user->id, $this->year);
                    $balance = LeaveCredit::getTotalBalance($user->id, $this->year);

                    // Check eligibility (6 months after hire)
                    $eligibilityDate = $hireDate->copy()->addMonths(6);
                    $isEligible = $eligibilityDate->year < $this->year ||
                                  ($eligibilityDate->year == $this->year && $eligibilityDate->month <= now()->month);

                    $data[] = [
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'hired_date' => $user->hired_date->format('Y-m-d'),
                        'eligible' => $isEligible ? 'Yes' : 'No',
                        'eligibility_date' => $eligibilityDate->format('Y-m-d'),
                        'total_earned' => $totalEarned,
                        'total_used' => $totalUsed,
                        'balance' => $balance,
                    ];
                }
            }

            $this->updateProgress($cacheKey, 40, 'Creating Excel file...');

            // Create spreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle("Leave Credits {$this->year}");

            // Set headers
            $headers = [
                'Employee Name',
                'Email',
                'Role',
                'Hire Date',
                'Eligible',
                'Eligibility Date',
                'Total Earned',
                'Total Used',
                'Balance',
            ];

            $sheet->fromArray($headers, null, 'A1');

            // Style headers
            $headerRange = 'A1:I1';
            $sheet->getStyle($headerRange)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);

            $this->updateProgress($cacheKey, 60, 'Writing data to Excel...');

            // Write data
            $row = 2;
            foreach ($data as $record) {
                $sheet->setCellValue("A{$row}", $record['name']);
                $sheet->setCellValue("B{$row}", $record['email']);
                $sheet->setCellValue("C{$row}", $record['role']);
                $sheet->setCellValue("D{$row}", $record['hired_date']);
                $sheet->setCellValue("E{$row}", $record['eligible']);
                $sheet->setCellValue("F{$row}", $record['eligibility_date']);
                $sheet->setCellValue("G{$row}", $record['total_earned']);
                $sheet->setCellValue("H{$row}", $record['total_used']);
                $sheet->setCellValue("I{$row}", $record['balance']);

                // Number format for credit columns
                $sheet->getStyle("G{$row}:I{$row}")->getNumberFormat()
                    ->setFormatCode('0.00');

                // Highlight ineligible employees
                if ($record['eligible'] === 'No') {
                    $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'FFF3CD'],
                        ],
                    ]);
                }

                $row++;
            }

            $this->updateProgress($cacheKey, 80, 'Formatting Excel file...');

            // Auto-size columns
            foreach (range('A', 'I') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Add borders to all data
            if ($row > 2) {
                $dataRange = "A1:I" . ($row - 1);
                $sheet->getStyle($dataRange)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);
            }

            // Add summary row
            $summaryRow = $row + 1;
            $sheet->setCellValue("A{$summaryRow}", 'TOTAL');
            $sheet->mergeCells("A{$summaryRow}:F{$summaryRow}");

            // Calculate totals
            if ($row > 2) {
                $lastDataRow = $row - 1;
                $sheet->setCellValue("G{$summaryRow}", "=SUM(G2:G{$lastDataRow})");
                $sheet->setCellValue("H{$summaryRow}", "=SUM(H2:H{$lastDataRow})");
                $sheet->setCellValue("I{$summaryRow}", "=SUM(I2:I{$lastDataRow})");
            } else {
                $sheet->setCellValue("G{$summaryRow}", 0);
                $sheet->setCellValue("H{$summaryRow}", 0);
                $sheet->setCellValue("I{$summaryRow}", 0);
            }

            // Style summary row
            $summaryRange = "A{$summaryRow}:I{$summaryRow}";
            $sheet->getStyle($summaryRange)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E7E6E6'],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_MEDIUM,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);

            $sheet->getStyle("G{$summaryRow}:I{$summaryRow}")->getNumberFormat()
                ->setFormatCode('0.00');

            $this->updateProgress($cacheKey, 90, 'Saving file...');

            // Save file
            $filename = "leave_credits_{$this->year}_" . now()->format('Y-m-d_His') . '.xlsx';
            $tempPath = storage_path('app/temp/' . $filename);

            // Ensure temp directory exists
            if (!file_exists(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save($tempPath);

            $this->updateProgress($cacheKey, 100, 'Complete', true, $filename);
        } catch (\Exception $e) {
            $this->updateProgress($cacheKey, 0, 'Error: ' . $e->getMessage(), false, null, true);
            throw $e;
        }
    }

    protected function updateProgress(string $cacheKey, int $percent, string $status, bool $finished = false, ?string $filename = null, bool $error = false): void
    {
        $data = [
            'percent' => $percent,
            'status' => $status,
            'finished' => $finished,
            'error' => $error,
        ];

        if ($filename) {
            $data['downloadUrl'] = route('leave-requests.export.download', ['filename' => $filename]);
        }

        Cache::put($cacheKey, $data, 3600);
    }
}
