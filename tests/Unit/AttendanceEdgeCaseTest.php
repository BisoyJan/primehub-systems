<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AttendanceFileParser;
use App\Services\AttendanceProcessor;
use App\Models\User;
use App\Models\EmployeeSchedule;
use App\Models\Attendance;
use App\Models\Site;
use App\Models\AttendanceUpload;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class AttendanceEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceFileParser $parser;
    private AttendanceProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AttendanceFileParser();
        $this->processor = new AttendanceProcessor($this->parser);
    }

    #[Test]
    public function it_flags_extreme_early_and_late_scans_for_manual_review()
    {
        Storage::fake('local');

        // Williams scenario: 05:00 and 22:25 scans for 08:00-17:00 shift
        $user = User::factory()->create(['first_name' => 'John', 'last_name' => 'Williams']);
        $site = Site::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00',
            'grace_period_minutes' => 15,
        ]);

        // Create biometric file content with extreme scans
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t5\tWilliams\tFP\t2025-11-10  05:00:15\n" .
                   "2\t1\t5\tWilliams\tFP\t2025-11-10  22:25:25\n";

        $filePath = storage_path('app/test_extreme_scans.txt');
        file_put_contents($filePath, $content);

        $upload = AttendanceUpload::create([
            'uploaded_by' => $user->id,
            'original_filename' => 'test_extreme_scans.txt',
            'stored_filename' => 'test_extreme_scans.txt',
            'file_path' => $filePath,
            'date_from' => '2025-11-10',
            'date_to' => '2025-11-10',
            'shift_date' => '2025-11-10',
            'biometric_site_id' => $site->id,
            'status' => 'pending',
        ]);

        // Process the upload
        $this->processor->processUpload($upload, $filePath);

        // Check that attendance was flagged for manual review
        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('shift_date', '2025-11-10')
            ->first();

        $this->assertNotNull($attendance);
        $this->assertEquals('needs_manual_review', $attendance->status);
        $this->assertNotNull($attendance->warnings);
        $this->assertIsArray($attendance->warnings);
        $this->assertNotEmpty($attendance->warnings);

        // Clean up
        @unlink($filePath);
    }

    #[Test]
    public function it_handles_normal_scans_without_flagging()
    {
        Storage::fake('local');

        // Normal scenario: 08:05 and 17:02 scans for 08:00-17:00 shift
        $user = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $site = Site::factory()->create();

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00',
            'grace_period_minutes' => 15,
        ]);

        // Create biometric file content with normal scans
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t5\tDoe\tFP\t2025-11-10  08:05:00\n" .
                   "2\t1\t5\tDoe\tFP\t2025-11-10  17:02:00\n";

        $filePath = storage_path('app/test_normal_scans.txt');
        file_put_contents($filePath, $content);

        $upload = AttendanceUpload::create([
            'uploaded_by' => $user->id,
            'original_filename' => 'test_normal_scans.txt',
            'stored_filename' => 'test_normal_scans.txt',
            'file_path' => $filePath,
            'date_from' => '2025-11-10',
            'date_to' => '2025-11-10',
            'shift_date' => '2025-11-10',
            'biometric_site_id' => $site->id,
            'status' => 'pending',
        ]);

        // Process the upload
        $this->processor->processUpload($upload, $filePath);

        // Check that attendance was NOT flagged
        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('shift_date', '2025-11-10')
            ->first();

        $this->assertNotNull($attendance);
        $this->assertNotEquals('needs_manual_review', $attendance->status);
        $this->assertTrue(in_array($attendance->status, ['on_time', 'tardy'])); // Normal statuses
        $this->assertNull($attendance->warnings); // No warnings

        // Clean up
        @unlink($filePath);
    }
}
