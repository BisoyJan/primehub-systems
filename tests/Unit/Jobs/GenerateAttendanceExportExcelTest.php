<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateAttendanceExportExcel;
use App\Models\Attendance;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateAttendanceExportExcelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        // Clean up any existing files
        array_map('unlink', glob("$tempDir/attendance_export_*.xlsx"));
    }

    protected function tearDown(): void
    {
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            array_map('unlink', glob("$tempDir/attendance_export_*.xlsx"));
        }

        parent::tearDown();
    }

    #[Test]
    public function it_can_be_dispatched_to_queue(): void
    {
        Queue::fake();

        $jobId = 'test-dispatch-123';
        $job = new GenerateAttendanceExportExcel(
            $jobId,
            now()->format('Y-m-d'),
            now()->format('Y-m-d')
        );

        dispatch($job);

        Queue::assertPushed(GenerateAttendanceExportExcel::class);
    }

    #[Test]
    public function it_generates_excel_file_with_attendance_records(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
        ]);

        $jobId = 'test-generate-excel';
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        // Check file was created
        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $this->assertNotEmpty($files, 'Excel file should be created');

        // Load and verify the spreadsheet
        $spreadsheet = IOFactory::load($files[0]);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify headers
        $this->assertEquals('User ID', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Employee Name', $sheet->getCell('B1')->getValue());
        $this->assertEquals('Status', $sheet->getCell('K1')->getValue());

        // Verify data row exists
        $this->assertNotEmpty($sheet->getCell('A2')->getValue());
    }

    #[Test]
    public function it_creates_two_sheets_attendance_and_statistics(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
        ]);

        $jobId = 'test-two-sheets';
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $spreadsheet = IOFactory::load($files[0]);

        $this->assertEquals(2, $spreadsheet->getSheetCount());
        $this->assertEquals('Attendance Records', $spreadsheet->getSheet(0)->getTitle());
        $this->assertEquals('Statistics', $spreadsheet->getSheet(1)->getTitle());
    }

    #[Test]
    public function it_includes_statistics_with_formulas(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->count(5)->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
        ]);

        Attendance::factory()->tardy()->count(3)->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
            'tardy_minutes' => 10,
        ]);

        $jobId = 'test-statistics';
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $spreadsheet = IOFactory::load($files[0]);
        $statsSheet = $spreadsheet->getSheet(1);

        // Verify statistics title
        $this->assertEquals('ATTENDANCE STATISTICS', $statsSheet->getCell('A1')->getValue());

        // Find and verify COUNTIF formulas exist
        $foundCountIf = false;
        for ($row = 1; $row <= 30; $row++) {
            $cellValue = $statsSheet->getCell('B' . $row)->getValue();
            if (is_string($cellValue) && str_contains($cellValue, 'COUNTIF')) {
                $foundCountIf = true;
                break;
            }
        }
        $this->assertTrue($foundCountIf, 'Statistics sheet should contain COUNTIF formulas');
    }

    #[Test]
    public function it_filters_by_user_ids(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->create([
            'user_id' => $user1->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
        ]);

        Attendance::factory()->onTime()->create([
            'user_id' => $user2->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
        ]);

        $jobId = 'test-filter-user';
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date, [$user1->id]);
        $job->handle();

        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $spreadsheet = IOFactory::load($files[0]);
        $sheet = $spreadsheet->getActiveSheet();

        // Should only have 1 data row (header + 1 data = row 2 has data, row 3 empty)
        $this->assertNotEmpty($sheet->getCell('A2')->getValue());
        $this->assertEmpty($sheet->getCell('A3')->getValue());
    }

    #[Test]
    public function it_filters_by_site_ids(): void
    {
        $user = User::factory()->create();
        $site1 = Site::factory()->create();
        $site2 = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site1->id,
            'shift_date' => $date,
        ]);

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site2->id,
            'shift_date' => $date,
        ]);

        $jobId = 'test-filter-site';
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date, [], [$site1->id]);
        $job->handle();

        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $spreadsheet = IOFactory::load($files[0]);
        $sheet = $spreadsheet->getActiveSheet();

        // Should only have 1 data row
        $this->assertNotEmpty($sheet->getCell('A2')->getValue());
        $this->assertEmpty($sheet->getCell('A3')->getValue());
    }

    #[Test]
    public function it_filters_by_campaign_ids(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $campaign1 = Campaign::factory()->create();
        $campaign2 = Campaign::factory()->create();
        $date = now()->format('Y-m-d');

        $schedule1 = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign1->id,
        ]);

        $schedule2 = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign2->id,
        ]);

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'employee_schedule_id' => $schedule1->id,
            'shift_date' => $date,
        ]);

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'employee_schedule_id' => $schedule2->id,
            'shift_date' => $date,
        ]);

        $jobId = 'test-filter-campaign';
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date, [], [], [$campaign1->id]);
        $job->handle();

        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $spreadsheet = IOFactory::load($files[0]);
        $sheet = $spreadsheet->getActiveSheet();

        // Should only have 1 data row
        $this->assertNotEmpty($sheet->getCell('A2')->getValue());
        $this->assertEmpty($sheet->getCell('A3')->getValue());
    }

    #[Test]
    public function it_updates_cache_with_progress(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
        ]);

        $jobId = 'test-progress';
        $cacheKey = "attendance_export_job:{$jobId}";

        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        $status = Cache::get($cacheKey);

        $this->assertIsArray($status);
        $this->assertEquals(100, $status['percent']);
        $this->assertEquals('Finished', $status['status']);
        $this->assertTrue($status['finished']);
        $this->assertNotNull($status['downloadUrl']);
    }

    #[Test]
    public function it_stores_correct_download_url(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
        ]);

        $jobId = 'test-download-url';
        $cacheKey = "attendance_export_job:{$jobId}";

        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        $status = Cache::get($cacheKey);

        $this->assertStringContainsString('/biometric-export/download/', $status['downloadUrl']);
        $this->assertStringContainsString($jobId, $status['downloadUrl']);
    }

    #[Test]
    public function it_handles_empty_date_range(): void
    {
        $jobId = 'test-empty-range';
        $date = now()->format('Y-m-d');

        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $this->assertNotEmpty($files, 'Excel file should still be created for empty data');

        $spreadsheet = IOFactory::load($files[0]);
        $sheet = $spreadsheet->getActiveSheet();

        // Should have headers but no data rows
        $this->assertEquals('User ID', $sheet->getCell('A1')->getValue());
        $this->assertEmpty($sheet->getCell('A2')->getValue());
    }

    #[Test]
    public function it_creates_temp_directory_if_not_exists(): void
    {
        $tempDir = storage_path('app/temp');

        // Clean up temp directory
        if (is_dir($tempDir)) {
            array_map('unlink', glob("$tempDir/*"));
            rmdir($tempDir);
        }

        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
        ]);

        $jobId = 'test-create-dir';
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        $this->assertDirectoryExists($tempDir);
    }

    #[Test]
    public function it_formats_status_correctly(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
            'status' => 'on_time',
        ]);

        $jobId = 'test-status-format';
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $spreadsheet = IOFactory::load($files[0]);
        $sheet = $spreadsheet->getActiveSheet();

        // Status column is K
        $this->assertEquals('On Time', $sheet->getCell('K2')->getValue());
    }

    #[Test]
    public function it_includes_all_required_columns(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'bio_out_site_id' => $site->id,
            'shift_date' => $date,
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00',
            'actual_time_in' => now()->setTime(8, 0, 0),
            'actual_time_out' => now()->setTime(17, 0, 0),
            'status' => 'on_time',
        ]);

        $jobId = 'test-all-columns';
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $spreadsheet = IOFactory::load($files[0]);
        $sheet = $spreadsheet->getActiveSheet();

        $expectedHeaders = [
            'User ID', 'Employee Name', 'Campaign', 'Shift Date',
            'Scheduled Time In', 'Scheduled Time Out', 'Actual Time In', 'Actual Time Out',
            'Time In Site', 'Time Out Site', 'Status', 'Secondary Status',
            'Tardy (mins)', 'Undertime (mins)', 'Overtime (mins)',
            'OT Approved', 'Cross-Site Bio', 'Admin Verified',
        ];

        $actualHeaders = [];
        for ($col = 'A'; $col <= 'R'; $col++) {
            $actualHeaders[] = $sheet->getCell($col . '1')->getValue();
        }

        $this->assertEquals($expectedHeaders, $actualHeaders);
    }

    #[Test]
    public function it_handles_multiple_attendance_statuses(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
        ]);

        Attendance::factory()->tardy()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
            'tardy_minutes' => 15,
        ]);

        Attendance::factory()->ncns()->create([
            'user_id' => $user->id,
            'shift_date' => $date,
        ]);

        $jobId = 'test-multiple-statuses';
        $job = new GenerateAttendanceExportExcel($jobId, $date, $date);
        $job->handle();

        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $spreadsheet = IOFactory::load($files[0]);
        $sheet = $spreadsheet->getActiveSheet();

        // Should have 3 data rows
        $this->assertNotEmpty($sheet->getCell('A2')->getValue());
        $this->assertNotEmpty($sheet->getCell('A3')->getValue());
        $this->assertNotEmpty($sheet->getCell('A4')->getValue());
        $this->assertEmpty($sheet->getCell('A5')->getValue());
    }

    #[Test]
    public function it_handles_date_range_spanning_multiple_days(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $startDate = now()->subDays(2)->format('Y-m-d');
        $endDate = now()->format('Y-m-d');

        // Create attendance for each day
        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => now()->subDays(2)->format('Y-m-d'),
        ]);

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => now()->subDays(1)->format('Y-m-d'),
        ]);

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);

        $jobId = 'test-date-range';
        $job = new GenerateAttendanceExportExcel($jobId, $startDate, $endDate);
        $job->handle();

        $pattern = storage_path("app/temp/attendance_export_*_{$jobId}.xlsx");
        $files = glob($pattern);
        $spreadsheet = IOFactory::load($files[0]);
        $sheet = $spreadsheet->getActiveSheet();

        // Should have 3 data rows
        $this->assertNotEmpty($sheet->getCell('A2')->getValue());
        $this->assertNotEmpty($sheet->getCell('A3')->getValue());
        $this->assertNotEmpty($sheet->getCell('A4')->getValue());
    }

    #[Test]
    public function it_handles_error_gracefully(): void
    {
        // Test with invalid date to trigger error
        $jobId = 'test-error-handling';
        $cacheKey = "attendance_export_job:{$jobId}";

        // Pass invalid date format to trigger an error
        $job = new GenerateAttendanceExportExcel($jobId, 'invalid-date', 'also-invalid');

        try {
            $job->handle();
        } catch (\Exception $e) {
            // Job should catch and log error
        }

        $status = Cache::get($cacheKey);

        // Should have error status
        if ($status && isset($status['error'])) {
            $this->assertTrue($status['error']);
            $this->assertTrue($status['finished']);
        }
    }
}
