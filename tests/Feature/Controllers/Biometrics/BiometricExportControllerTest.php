<?php

namespace Tests\Feature\Controllers\Biometrics;

use App\Models\Attendance;
use App\Models\Site;
use App\Models\User;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BiometricExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $this->agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }

    #[Test]
    public function it_displays_export_page()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Export')
            ->has('users', 1)
            ->has('sites', 1)
        );
    }

    #[Test]
    public function it_exports_records_to_xlsx_with_full_details()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        // Create various attendance records
        Attendance::factory()->onTime()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);

        Attendance::factory()->tardy()->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);

        Attendance::factory()->ncns()->create([
            'user_id' => $user->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('Content-Disposition', 'attachment; filename="attendance_export_' . now()->format('Y-m-d') . '_to_' . now()->format('Y-m-d') . '.xlsx"');
    }

    #[Test]
    public function it_exports_records_with_statistics_section()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        // Create multiple attendance records with different statuses
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

        Attendance::factory()->ncns()->count(2)->create([
            'user_id' => $user->id,
            'shift_date' => $date,
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => $date,
            'end_date' => $date,
        ]));

        $response->assertStatus(200);

        // Save response to temp file and read with PhpSpreadsheet
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tempFile, $response->getContent());

        $spreadsheet = IOFactory::load($tempFile);

        // Check that there are 2 sheets
        $this->assertEquals(2, $spreadsheet->getSheetCount());
        $this->assertEquals('Attendance Records', $spreadsheet->getSheet(0)->getTitle());
        $this->assertEquals('Statistics', $spreadsheet->getSheet(1)->getTitle());

        // Check that the statistics section exists on 2nd sheet
        $statsSheet = $spreadsheet->getSheet(1);
        $statsTitle = $statsSheet->getCell('A1')->getValue();
        $this->assertEquals('ATTENDANCE STATISTICS', $statsTitle);

        // Clean up
        unlink($tempFile);
    }

    #[Test]
    public function it_filters_export_by_user_and_site()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $site1 = Site::factory()->create();
        $site2 = Site::factory()->create();

        $date = now()->toDateString();

        // Create attendance for user1 at site1
        $att1 = Attendance::factory()->create([
            'user_id' => $user1->id,
            'bio_in_site_id' => $site1->id,
            'shift_date' => $date,
            'status' => 'on_time',
            'actual_time_in' => now()->setTime(8, 0, 0),
        ]);

        // Create attendance for user2 at site1 (should be filtered out)
        Attendance::factory()->create([
            'user_id' => $user2->id,
            'bio_in_site_id' => $site1->id,
            'shift_date' => $date,
            'status' => 'on_time',
            'actual_time_in' => now()->setTime(8, 0, 0),
        ]);

        // Create attendance for user1 at site2 (should be filtered out)
        Attendance::factory()->create([
            'user_id' => $user1->id,
            'bio_in_site_id' => $site2->id,
            'shift_date' => $date,
            'status' => 'on_time',
            'actual_time_in' => now()->setTime(8, 0, 0),
        ]);

        // Verify records were created
        $this->assertEquals(3, Attendance::count());
        $this->assertEquals(1, Attendance::where('user_id', $user1->id)->where('bio_in_site_id', $site1->id)->count());

        // Filter for user1 and site1 only
        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => $date,
            'end_date' => $date,
            'user_ids' => [$user1->id],
            'site_ids' => [$site1->id],
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Save response to temp file and read with PhpSpreadsheet
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tempFile, $response->getContent());

        $spreadsheet = IOFactory::load($tempFile);
        $sheet = $spreadsheet->getActiveSheet();

        // Check headers are present
        $this->assertEquals('User ID', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Employee Name', $sheet->getCell('B1')->getValue());

        // Check total records in stats sheet - should be 1 record
        $statsSheet = $spreadsheet->getSheet(1);
        // Total Records is at row 4 (title row 1, blank row 2, date range row 3, total row 4)
        $this->assertEquals('Total Records:', $statsSheet->getCell('A4')->getValue());

        // The total is calculated via formula =COUNTA(...), which returns the count
        $totalRecordsFormula = $statsSheet->getCell('B4')->getValue();
        $this->assertStringContainsString('COUNTA', $totalRecordsFormula);

        // Check that only 1 data row exists (header + 1 data row = row 2 has data, row 3 should be empty)
        $this->assertNotEmpty($sheet->getCell('A2')->getValue());
        $this->assertEmpty($sheet->getCell('A3')->getValue());

        // Clean up
        unlink($tempFile);
    }

    #[Test]
    public function it_includes_time_in_and_time_out_in_export()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->format('Y-m-d');

        $attendance = Attendance::factory()->create([
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

        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => $date,
            'end_date' => $date,
        ]));

        $response->assertStatus(200);

        // Save response to temp file and read with PhpSpreadsheet
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tempFile, $response->getContent());

        $spreadsheet = IOFactory::load($tempFile);
        $sheet = $spreadsheet->getActiveSheet();

        // Check headers include time in/out columns
        $headers = [];
        for ($col = 'A'; $col <= 'S'; $col++) {
            $headers[] = $sheet->getCell($col . '1')->getValue();
        }

        $this->assertContains('Scheduled Time In', $headers);
        $this->assertContains('Scheduled Time Out', $headers);
        $this->assertContains('Actual Time In', $headers);
        $this->assertContains('Actual Time Out', $headers);
        $this->assertContains('Status', $headers);

        // Clean up
        unlink($tempFile);
    }

    #[Test]
    public function it_includes_status_breakdown_in_statistics()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->toDateString();

        // Create records with different statuses
        Attendance::factory()->count(10)->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
            'status' => 'on_time',
            'actual_time_in' => now()->setTime(8, 0, 0),
        ]);

        Attendance::factory()->count(5)->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
            'status' => 'tardy',
            'tardy_minutes' => 10,
            'actual_time_in' => now()->setTime(8, 15, 0),
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => $date,
            'end_date' => $date,
        ]));

        $response->assertStatus(200);

        // Save response to temp file and read with PhpSpreadsheet
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tempFile, $response->getContent());

        $spreadsheet = IOFactory::load($tempFile);

        // Get the Statistics sheet (2nd sheet)
        $statsSheet = $spreadsheet->getSheet(1);

        // Verify the statistics title exists
        $this->assertEquals('ATTENDANCE STATISTICS', $statsSheet->getCell('A1')->getValue());

        // Find STATUS BREAKDOWN header dynamically
        $statusBreakdownRow = null;
        for ($row = 1; $row <= 20; $row++) {
            if ($statsSheet->getCell('A' . $row)->getValue() === 'STATUS BREAKDOWN') {
                $statusBreakdownRow = $row;
                break;
            }
        }
        $this->assertNotNull($statusBreakdownRow, 'STATUS BREAKDOWN header should exist');

        // Verify column headers (next row after STATUS BREAKDOWN)
        $headerRow = $statusBreakdownRow + 1;
        $this->assertEquals('Status', $statsSheet->getCell('A' . $headerRow)->getValue());
        $this->assertEquals('Count', $statsSheet->getCell('B' . $headerRow)->getValue());
        $this->assertEquals('Percentage', $statsSheet->getCell('C' . $headerRow)->getValue());

        // Verify On Time row has a COUNTIF formula
        $onTimeRow = $headerRow + 1;
        $this->assertEquals('On Time', $statsSheet->getCell('A' . $onTimeRow)->getValue());
        $onTimeFormula = $statsSheet->getCell('B' . $onTimeRow)->getValue();
        $this->assertStringContainsString('COUNTIF', $onTimeFormula);

        // Verify Tardy row has a COUNTIF formula
        $tardyRow = $onTimeRow + 1;
        $this->assertEquals('Tardy', $statsSheet->getCell('A' . $tardyRow)->getValue());
        $tardyFormula = $statsSheet->getCell('B' . $tardyRow)->getValue();
        $this->assertStringContainsString('COUNTIF', $tardyFormula);

        // Clean up
        unlink($tempFile);
    }

    #[Test]
    public function it_validates_required_date_fields()
    {
        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => '',
            'end_date' => '',
        ]));

        $response->assertStatus(302); // Validation redirect
    }

    #[Test]
    public function it_validates_end_date_is_after_start_date()
    {
        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->subDays(5)->format('Y-m-d'),
        ]));

        $response->assertStatus(302); // Validation redirect
    }

    #[Test]
    public function it_calculates_percentages_in_statistics()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $date = now()->toDateString();

        // Create exactly 10 on_time and 10 tardy for easy percentage calculation (50/50)
        Attendance::factory()->count(10)->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
            'status' => 'on_time',
            'actual_time_in' => now()->setTime(8, 0, 0),
        ]);

        Attendance::factory()->count(10)->create([
            'user_id' => $user->id,
            'bio_in_site_id' => $site->id,
            'shift_date' => $date,
            'status' => 'tardy',
            'tardy_minutes' => 10,
            'actual_time_in' => now()->setTime(8, 15, 0),
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => $date,
            'end_date' => $date,
        ]));

        $response->assertStatus(200);

        // Save response to temp file and read with PhpSpreadsheet
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tempFile, $response->getContent());

        $spreadsheet = IOFactory::load($tempFile);

        // Get the Statistics sheet (2nd sheet)
        $statsSheet = $spreadsheet->getSheet(1);

        // Verify KEY METRICS section exists
        $foundKeyMetrics = false;
        $foundOnTimeRate = false;
        $foundTardyRate = false;

        for ($row = 1; $row <= 50; $row++) {
            $cellA = $statsSheet->getCell('A' . $row)->getValue();
            if ($cellA === 'KEY METRICS') {
                $foundKeyMetrics = true;
            }
            if ($cellA === 'On Time Rate') {
                $foundOnTimeRate = true;
                // Check that it has a formula
                $formula = $statsSheet->getCell('B' . $row)->getValue();
                $this->assertStringContainsString('IF', $formula);
            }
            if ($cellA === 'Tardy Rate') {
                $foundTardyRate = true;
                // Check that it has a formula
                $formula = $statsSheet->getCell('B' . $row)->getValue();
                $this->assertStringContainsString('IF', $formula);
            }
        }

        $this->assertTrue($foundKeyMetrics, 'KEY METRICS header should be present');
        $this->assertTrue($foundOnTimeRate, 'On Time Rate should be present');
        $this->assertTrue($foundTardyRate, 'Tardy Rate should be present');

        // Verify percentage column exists in status breakdown (find it dynamically)
        $foundPercentageHeader = false;
        for ($row = 1; $row <= 20; $row++) {
            if ($statsSheet->getCell('C' . $row)->getValue() === 'Percentage') {
                $foundPercentageHeader = true;
                break;
            }
        }
        $this->assertTrue($foundPercentageHeader, 'Percentage column header should exist');

        // Clean up
        unlink($tempFile);
    }
}
