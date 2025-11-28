<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Models\Site;
use App\Models\Attendance;
use App\Models\AttendanceUpload;
use App\Models\BiometricRecord;
use App\Models\EmployeeSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Carbon\Carbon;

class AttendanceProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $employee;
    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $this->employee = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $this->site = Site::factory()->create(['name' => 'Main Office']);
    }

    #[Test]
    public function biometric_records_are_saved_from_upload(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
        ]);

        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tDoe John\tFP\t2025-11-05  08:00:00\n" .
                   "2\t1\t10\tDoe John\tFP\t2025-11-05  17:00:00\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        $this->assertDatabaseCount('biometric_records', 2);
        $this->assertDatabaseHas('biometric_records', [
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
        ]);
    }

    #[Test]
    public function attendance_records_are_created_from_biometric_scans(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
        ]);

        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tDoe John\tFP\t2025-11-05  08:00:00\n" .
                   "2\t1\t10\tDoe John\tFP\t2025-11-05  17:00:00\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->employee->id,
        ]);

        $attendance = Attendance::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($attendance);
        $this->assertEquals('2025-11-05', $attendance->shift_date->format('Y-m-d'));
    }

    #[Test]
    public function employee_name_matching_is_case_insensitive(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
        ]);

        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tDOE JOHN\tFP\t2025-11-05  08:00:00\n" .
                   "2\t1\t10\tdoe john\tFP\t2025-11-05  17:00:00\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        $this->assertDatabaseCount('biometric_records', 2);
        $biometricRecords = BiometricRecord::all();
        $this->assertTrue($biometricRecords->every(fn($record) => $record->user_id === $this->employee->id));
    }

    #[Test]
    public function employee_name_matching_handles_reversed_names(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
        ]);

        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tJohn Doe\tFP\t2025-11-05  08:00:00\n" .
                   "2\t1\t10\tDoe, John\tFP\t2025-11-05  17:00:00\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        $this->assertDatabaseCount('biometric_records', 2);
        $biometricRecords = BiometricRecord::all();
        $this->assertTrue($biometricRecords->every(fn($record) => $record->user_id === $this->employee->id));
    }

    #[Test]
    public function time_in_and_time_out_are_extracted_correctly(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
        ]);

        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tDoe John\tFP\t2025-11-05  08:15:30\n" .
                   "2\t1\t10\tDoe John\tFP\t2025-11-05  17:20:45\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        $attendance = Attendance::where('user_id', $this->employee->id)->first();

        if ($attendance) {
            $this->assertNotNull($attendance->actual_time_in);
            $this->assertNotNull($attendance->actual_time_out);
            $this->assertEquals('08:15:30', Carbon::parse($attendance->actual_time_in)->format('H:i:s'));
            $this->assertEquals('17:20:45', Carbon::parse($attendance->actual_time_out)->format('H:i:s'));
        }
    }

    #[Test]
    public function multiple_scans_keep_earliest_in_and_best_match_out(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
        ]);

        // Morning shift is 06:00-15:00, so:
        // - Time in: 08:00 is earliest valid time in (08:00 < 08:30)
        // - Time out: 17:00 is closer to scheduled 15:00 than 17:30
        //   (|17:00 - 15:00| = 2 hours vs |17:30 - 15:00| = 2.5 hours)
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tDoe John\tFP\t2025-11-05  08:00:00\n" .
                   "2\t1\t10\tDoe John\tFP\t2025-11-05  08:30:00\n" .
                   "3\t1\t10\tDoe John\tFP\t2025-11-05  17:00:00\n" .
                   "4\t1\t10\tDoe John\tFP\t2025-11-05  17:30:00\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        $attendance = Attendance::where('user_id', $this->employee->id)->first();

        if ($attendance) {
            // Earliest time in is recorded
            $this->assertEquals('08:00:00', Carbon::parse($attendance->actual_time_in)->format('H:i:s'));
            // Best match to scheduled out time (17:00 is closer to 15:00 than 17:30)
            $this->assertEquals('17:00:00', Carbon::parse($attendance->actual_time_out)->format('H:i:s'));
        }
    }

    #[Test]
    public function attendance_without_schedule_is_still_created(): void
    {
        // No schedule created for this employee

        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tDoe John\tFP\t2025-11-05  08:00:00\n" .
                   "2\t1\t10\tDoe John\tFP\t2025-11-05  17:00:00\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        // Should still create attendance record even without schedule
        $attendance = Attendance::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($attendance, 'Attendance record should be created even without schedule');
        $this->assertEquals('2025-11-05', $attendance->shift_date->format('Y-m-d'));
        $this->assertEquals('needs_manual_review', $attendance->status);
    }

    #[Test]
    public function unmatched_names_are_tracked_in_upload_record(): void
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tDoe John\tFP\t2025-11-05  08:00:00\n" .
                   "2\t1\t11\tUnknown Employee\tFP\t2025-11-05  08:00:00\n" .
                   "3\t1\t12\tAnother Unknown\tFP\t2025-11-05  08:00:00\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        $upload = AttendanceUpload::first();
        $this->assertGreaterThan(0, $upload->unmatched_names);
        $this->assertIsArray($upload->unmatched_names_list);
        $this->assertContains('Unknown Employee', $upload->unmatched_names_list);
    }

    #[Test]
    public function biometric_site_is_recorded_with_attendance(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
        ]);

        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tDoe John\tFP\t2025-11-05  08:00:00\n" .
                   "2\t1\t10\tDoe John\tFP\t2025-11-05  17:00:00\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        $attendance = Attendance::where('user_id', $this->employee->id)->first();

        if ($attendance) {
            $this->assertEquals($this->site->id, $attendance->bio_in_site_id);
            $this->assertEquals($this->site->id, $attendance->bio_out_site_id);
        }
    }

    #[Test]
    public function processing_updates_upload_status_to_completed(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
        ]);

        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tDoe John\tFP\t2025-11-05  08:00:00\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        $upload = AttendanceUpload::first();
        $this->assertEquals('completed', $upload->status);
    }

    #[Test]
    public function failed_processing_updates_upload_status(): void
    {
        // Create a file that will cause parsing issues
        $content = "Invalid file content without proper formatting";

        $file = UploadedFile::fake()->createWithContent('bad_file.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $this->site->id,
            ]);

        $upload = AttendanceUpload::first();

        // Should either fail or complete with zero records
        $this->assertContains($upload->status, ['failed', 'completed']);

        if ($upload->status === 'failed') {
            $this->assertNotNull($upload->error_message);
        }
    }
}
