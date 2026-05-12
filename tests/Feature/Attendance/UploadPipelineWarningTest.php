<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\BiometricRecord;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use App\ValueObjects\AttendanceWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 4.3 — Upload pipeline warning tests.
 *
 * Covers: structured AttendanceWarning VO format in DB,
 * no_valid_scan warning, utility-24h undertime warning,
 * typedWarnings() accessor, and last_processed_at stamp.
 */
class UploadPipelineWarningTest extends TestCase
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
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $this->site = Site::factory()->create(['name' => 'Test Site']);
    }

    /** Build a minimal biometric file for the given date/time tuples. */
    private function buildFileContent(array $scans): string
    {
        $header = "No\tDevNo\tUserId\tName\tMode\tDateTime\n";
        $rows = '';
        foreach ($scans as $i => $scan) {
            $rows .= ($i + 1)."\t1\t10\tSmith Jane\tFP\t{$scan['date']}  {$scan['time']}\n";
        }

        return $header.$rows;
    }

    private function upload(string $dateFrom, string $dateTo, string $content): void
    {
        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $this->actingAs($this->admin)
            ->post('/attendance/upload', [
                'file' => $file,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'biometric_site_id' => $this->site->id,
            ]);
    }

    /**
     * A scan far outside the shift window (02:00 for a 06:00-15:00 shift)
     * cannot be matched as time-in or time-out.
     * detectExtremeScanPatterns Warning 5 fires with no_valid_scan type.
     */
    #[Test]
    public function scan_outside_shift_window_produces_no_valid_scan_warning(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            'effective_date' => '2025-01-01',
        ]);

        // Morning shift 06:00-15:00. Scan at 02:00 is below the 2h-before cutoff (04:00).
        // findTimeInRecord rejects it; findTimeOutRecord rejects it (>8h from 15:00).
        // detectExtremeScanPatterns Warning 5 fires → no_valid_scan.
        $date = '2025-11-05';
        $content = $this->buildFileContent([
            ['date' => $date, 'time' => '02:00:00'],
        ]);
        $this->upload($date, $date, $content);

        $attendance = Attendance::where('user_id', $this->employee->id)
            ->where('shift_date', $date)
            ->first();

        $this->assertNotNull($attendance);
        $this->assertSame('needs_manual_review', $attendance->status);
        $this->assertNotEmpty($attendance->warnings);

        // All warnings must be structured VO arrays (not plain strings)
        foreach ($attendance->warnings as $w) {
            $this->assertIsArray($w, 'Warning must be a structured VO array, not a plain string.');
            $this->assertArrayHasKey('type', $w);
            $this->assertArrayHasKey('message', $w);
            $this->assertArrayHasKey('severity', $w);
            $this->assertArrayHasKey('raised_at', $w);
        }

        // The scan far outside the window must produce a no_valid_scan warning
        $types = array_column($attendance->warnings, 'type');
        $this->assertContains('no_valid_scan', $types,
            'no_valid_scan warning must be present when all scans are outside the shift window.');
    }

    /**
     * Utility-24h shift with 3+ scans triggers the first/last scan logic.
     * If total hours < 8, status = undertime and utility_undertime warning is appended.
     */
    #[Test]
    public function utility_24h_undertime_adds_informational_warning(): void
    {
        EmployeeSchedule::factory()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'shift_type' => 'utility_24h',
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '08:00:00', // 24-hour shift (wraps to next day)
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            'effective_date' => '2025-01-01',
        ]);

        // 3 scans triggers first/last override: 08:00 → 14:00 = 6h < 8h minimum.
        $date = '2025-11-05';
        $content = $this->buildFileContent([
            ['date' => $date, 'time' => '08:00:00'],
            ['date' => $date, 'time' => '12:00:00'],
            ['date' => $date, 'time' => '14:00:00'],
        ]);
        $this->upload($date, $date, $content);

        $attendance = Attendance::where('user_id', $this->employee->id)
            ->where('shift_date', $date)
            ->first();

        $this->assertNotNull($attendance);
        $this->assertSame('undertime', $attendance->status,
            'Utility 24h with <8h worked should be undertime (utility_24h block overrides scan-pattern status).');
        $this->assertNotEmpty($attendance->warnings);

        $types = array_column($attendance->warnings, 'type');
        $this->assertContains('utility_undertime', $types, 'utility_undertime warning must be present.');

        $utilWarning = array_values(array_filter(
            $attendance->warnings,
            fn ($w) => is_array($w) && ($w['type'] ?? '') === 'utility_undertime'
        ))[0];
        $this->assertSame('info', $utilWarning['severity']);
    }

    /**
     * AttendanceWarning VO round-trips through the JSON column correctly.
     * typedWarnings() reconstructs VO objects from the stored arrays.
     */
    #[Test]
    public function typed_warnings_accessor_works_for_new_vo_format(): void
    {
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'warnings' => [
                AttendanceWarning::make('double_punch', 'Double punch detected.')->toArray(),
                AttendanceWarning::make('excessive_duration', 'Excessive shift duration.', 'critical')->toArray(),
            ],
        ]);

        $typed = $attendance->typedWarnings();

        $this->assertCount(2, $typed);
        $this->assertInstanceOf(AttendanceWarning::class, $typed[0]);
        $this->assertSame('double_punch', $typed[0]->type);
        $this->assertSame('warning', $typed[0]->severity);
        $this->assertSame('excessive_duration', $typed[1]->type);
        $this->assertSame('critical', $typed[1]->severity);
    }

    /**
     * typedWarnings() handles legacy plain-string warnings (backward compat).
     */
    #[Test]
    public function typed_warnings_accessor_handles_legacy_string_warnings(): void
    {
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'warnings' => ['DOUBLE PUNCH DETECTED: 08:00 -> 08:05 (5 min).'],
        ]);

        $typed = $attendance->typedWarnings();

        $this->assertCount(1, $typed);
        $this->assertInstanceOf(AttendanceWarning::class, $typed[0]);
        $this->assertSame('legacy', $typed[0]->type);
        $this->assertStringContainsString('DOUBLE PUNCH', $typed[0]->message);
    }

    #[Test]
    public function biometric_records_get_last_processed_at_stamped(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            'effective_date' => '2025-01-01',
        ]);

        $date = '2025-11-05';
        $content = $this->buildFileContent([
            ['date' => $date, 'time' => '06:00:00'],
            ['date' => $date, 'time' => '15:00:00'],
        ]);
        $this->upload($date, $date, $content);

        $records = BiometricRecord::where('user_id', $this->employee->id)->get();
        $this->assertNotEmpty($records);

        foreach ($records as $record) {
            $this->assertNotNull(
                $record->last_processed_at,
                "BiometricRecord #{$record->id} should have last_processed_at set after upload."
            );
        }
    }
}
