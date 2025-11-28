<?php

namespace Tests\Feature\Controllers\Attendance;

use App\Models\User;
use App\Models\Attendance;
use App\Models\AttendanceUpload;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

class AttendanceControllerTest extends TestCase
{
    use RefreshDatabase;


    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }

    #[Test]
    public function it_displays_attendance_index_page()
    {
        Attendance::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Attendance/Main/Index')
                ->has('attendances')
        );
    }

    #[Test]
    public function it_filters_attendance_by_status()
    {
        Attendance::factory()->create(['status' => 'on_time']);
        Attendance::factory()->create(['status' => 'tardy']);
        Attendance::factory()->create(['status' => 'ncns']);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['status' => 'tardy']));

        $response->assertStatus(200);
    }

    #[Test]
    public function it_filters_attendance_by_date_range()
    {
        Attendance::factory()->create(['shift_date' => '2025-11-01']);
        Attendance::factory()->create(['shift_date' => '2025-11-05']);
        Attendance::factory()->create(['shift_date' => '2025-11-10']);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index', [
                'start_date' => '2025-11-05',
                'end_date' => '2025-11-10'
            ]));

        $response->assertStatus(200);
    }

    #[Test]
    public function it_searches_attendance_by_employee_name()
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe'
        ]);
        Attendance::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['search' => 'John']));

        $response->assertStatus(200);
    }

    #[Test]
    public function it_displays_import_page()
    {
        AttendanceUpload::factory()->count(5)->create();
        Site::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.import'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Attendance/Main/Import')
                ->has('recentUploads')
                ->has('sites')
        );
    }

    #[Test]
    public function it_validates_file_upload_requirements()
    {
        $site = Site::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.upload'), [
                'date_from' => '2025-11-05',
                'biometric_site_id' => $site->id,
            ]);

        $response->assertSessionHasErrors(['file']);
    }

    #[Test]
    public function it_validates_date_from_is_required()
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('attendance.txt', 100);
        $site = Site::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.upload'), [
                'file' => $file,
                'biometric_site_id' => $site->id,
            ]);

        $response->assertSessionHasErrors(['date_from']);
    }

    #[Test]
    public function it_validates_biometric_site_id_exists()
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('attendance.txt', 100);

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.upload'), [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => 9999, // Non-existent
            ]);

        $response->assertSessionHasErrors(['biometric_site_id']);
    }

    #[Test]
    public function it_uploads_and_stores_attendance_file()
    {
        Storage::fake('local');
        $site = Site::factory()->create();

        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25\n";

        $file = UploadedFile::fake()->createWithContent('attendance.txt', $content);

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.upload'), [
                'file' => $file,
                'date_from' => '2025-11-05',
                'date_to' => '2025-11-05',
                'biometric_site_id' => $site->id,
                'notes' => 'Test upload',
            ]);

        $this->assertDatabaseHas('attendance_uploads', [
            'uploaded_by' => $this->admin->id,
            'original_filename' => 'attendance.txt',
            'date_from' => '2025-11-05',
            'biometric_site_id' => $site->id,
        ]);

        $upload = AttendanceUpload::first();
        Storage::assertExists('attendance_uploads/' . $upload->stored_filename);
    }

    #[Test]
    public function it_displays_review_page_with_records_needing_verification()
    {
        Attendance::factory()->create(['status' => 'failed_bio_in', 'admin_verified' => false]);
        Attendance::factory()->create(['status' => 'ncns', 'admin_verified' => false]);
        Attendance::factory()->create(['status' => 'on_time', 'admin_verified' => true]);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.review'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Attendance/Main/Review')
                ->has('attendances')
        );
    }

    #[Test]
    public function it_verifies_and_updates_attendance_record()
    {
        $attendance = Attendance::factory()->create([
            'status' => 'failed_bio_in',
            'admin_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.verify', $attendance), [
                'status' => 'on_time',
                'actual_time_in' => '2025-11-05 09:00:00',
                'actual_time_out' => '2025-11-05 18:00:00',
                'verification_notes' => 'Manual verification',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $attendance->refresh();
        $this->assertEquals('on_time', $attendance->status);
        $this->assertTrue($attendance->admin_verified);
        $this->assertEquals('Manual verification', $attendance->verification_notes);
    }

    #[Test]
    public function it_validates_verification_data()
    {
        $attendance = Attendance::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.verify', $attendance), [
                'status' => 'invalid_status',
                'verification_notes' => '',
            ]);

        $response->assertSessionHasErrors(['status', 'verification_notes']);
    }

    #[Test]
    public function it_recalculates_tardy_minutes_on_verification()
    {
        $attendance = Attendance::factory()->create([
            'shift_date' => '2025-11-05',
            'scheduled_time_in' => '09:00:00',
            'status' => 'failed_bio_in',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.verify', $attendance), [
                'status' => 'tardy',
                'actual_time_in' => '2025-11-05 09:15:00', // 15 min late
                'verification_notes' => 'Manually verified',
            ]);

        $attendance->refresh();
        $this->assertEquals(15, $attendance->tardy_minutes);
    }

    #[Test]
    public function it_marks_attendance_as_advised_absence()
    {
        $attendance = Attendance::factory()->create([
            'status' => 'ncns',
            'is_advised' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.markAdvised', $attendance), [
                'notes' => 'Employee called in sick',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $attendance->refresh();
        $this->assertEquals('advised_absence', $attendance->status);
        $this->assertTrue($attendance->is_advised);
        $this->assertTrue($attendance->admin_verified);
        $this->assertEquals('Employee called in sick', $attendance->verification_notes);
    }

    #[Test]
    public function it_returns_attendance_statistics()
    {
        $startDate = '2025-11-01';
        $endDate = '2025-11-30';

        Attendance::factory()->create(['shift_date' => '2025-11-05', 'status' => 'on_time']);
        Attendance::factory()->create(['shift_date' => '2025-11-06', 'status' => 'tardy']);
        Attendance::factory()->create(['shift_date' => '2025-11-07', 'status' => 'ncns']);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.statistics', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]));

        $response->assertStatus(200);
        $response->assertJson([
            'total' => 3,
            'on_time' => 1,
            'tardy' => 1,
            'ncns' => 1,
        ]);
    }

    #[Test]
    public function it_bulk_deletes_attendance_records()
    {
        $attendance1 = Attendance::factory()->create();
        $attendance2 = Attendance::factory()->create();
        $attendance3 = Attendance::factory()->create();

        $response = $this->actingAs($this->admin)
            ->delete(route('attendance.bulkDelete'), [
                'ids' => [$attendance1->id, $attendance2->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('attendances', ['id' => $attendance1->id]);
        $this->assertDatabaseMissing('attendances', ['id' => $attendance2->id]);
        $this->assertDatabaseHas('attendances', ['id' => $attendance3->id]);
    }

    #[Test]
    public function it_validates_bulk_delete_ids()
    {
        $response = $this->actingAs($this->admin)
            ->delete(route('attendance.bulkDelete'), [
                'ids' => [],
            ]);

        $response->assertSessionHasErrors(['ids']);
    }

    #[Test]
    public function it_requires_authentication_for_all_routes()
    {
        $attendance = Attendance::factory()->create();

        $routes = [
            ['get', route('attendance.index')],
            ['get', route('attendance.import')],
            ['get', route('attendance.review')],
            ['get', route('attendance.statistics')],
        ];

        foreach ($routes as [$method, $url]) {
            $response = $this->$method($url);
            $response->assertRedirect(route('login'));
        }
    }

    #[Test]
    public function it_filters_records_needing_verification_in_review()
    {
        // Should appear in review
        $needsReview1 = Attendance::factory()->create([
            'status' => 'failed_bio_in',
            'admin_verified' => false
        ]);
        $needsReview2 = Attendance::factory()->create([
            'status' => 'ncns',
            'admin_verified' => false
        ]);

        // Should not appear
        Attendance::factory()->create([
            'status' => 'on_time',
            'admin_verified' => false
        ]);
        Attendance::factory()->create([
            'status' => 'failed_bio_in',
            'admin_verified' => true
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.review'));

        $response->assertStatus(200);
    }

    #[Test]
    public function it_handles_file_processing_errors_gracefully()
    {
        Storage::fake('local');
        $site = Site::factory()->create();

        // Create a malformed file
        $content = "Invalid content without proper structure";
        $file = UploadedFile::fake()->createWithContent('bad_file.txt', $content);

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.upload'), [
                'file' => $file,
                'date_from' => '2025-11-05',
                'biometric_site_id' => $site->id,
            ]);

        // Should handle error without crashing
        $response->assertStatus(302); // Redirect back
    }

    #[Test]
    public function it_paginates_attendance_records()
    {
        Attendance::factory()->count(30)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->has('attendances.data', 25) // Default pagination is 25
        );
    }

    #[Test]
    public function it_filters_by_needs_verification_flag()
    {
        Attendance::factory()->create(['status' => 'failed_bio_in', 'admin_verified' => false]);
        Attendance::factory()->create(['status' => 'on_time', 'admin_verified' => false]);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['needs_verification' => true]));

        $response->assertStatus(200);
    }

    #[Test]
    public function it_displays_calendar_view()
    {
        $user = User::factory()->create();
        Attendance::factory()->create([
            'user_id' => $user->id,
            'shift_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.calendar', ['user_id' => $user->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Attendance/Main/Calendar')
                ->has('attendances')
                ->has('users')
                ->has('selectedUser')
        );
    }

    #[Test]
    public function it_displays_create_page()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('attendance.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Attendance/Main/Create')
                ->has('users')
                ->has('campaigns')
        );
    }

    #[Test]
    public function it_stores_manually_created_attendance()
    {
        $user = User::factory()->create();
        $shiftDate = now()->format('Y-m-d');

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.store'), [
                'user_id' => $user->id,
                'shift_date' => $shiftDate,
                'status' => 'on_time',
                'actual_time_in_date' => $shiftDate,
                'actual_time_in_time' => '09:00',
                'actual_time_out_date' => $shiftDate,
                'actual_time_out_time' => '18:00',
                'notes' => 'Manual entry',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
            'status' => 'on_time',
            'admin_verified' => true,
        ]);
    }

    #[Test]
    public function it_bulk_stores_attendance_records()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $shiftDate = now()->format('Y-m-d');

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.bulkStore'), [
                'user_ids' => [$user1->id, $user2->id],
                'shift_date' => $shiftDate,
                'status' => 'on_time',
                'actual_time_in_date' => $shiftDate,
                'actual_time_in_time' => '09:00',
                'actual_time_out_date' => $shiftDate,
                'actual_time_out_time' => '18:00',
                'notes' => 'Bulk manual entry',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user1->id,
            'shift_date' => $shiftDate,
            'status' => 'on_time',
        ]);

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user2->id,
            'shift_date' => $shiftDate,
            'status' => 'on_time',
        ]);
    }

    #[Test]
    public function it_batch_verifies_attendance_records()
    {
        $attendance1 = Attendance::factory()->create(['status' => 'failed_bio_in', 'admin_verified' => false]);
        $attendance2 = Attendance::factory()->create(['status' => 'failed_bio_in', 'admin_verified' => false]);

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.batchVerify'), [
                'record_ids' => [$attendance1->id, $attendance2->id],
                'status' => 'on_time',
                'verification_notes' => 'Batch verified',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $attendance1->refresh();
        $attendance2->refresh();

        $this->assertTrue($attendance1->admin_verified);
        $this->assertEquals('on_time', $attendance1->status);
        $this->assertTrue($attendance2->admin_verified);
        $this->assertEquals('on_time', $attendance2->status);
    }

    #[Test]
    public function it_quick_approves_on_time_attendance()
    {
        $attendance = Attendance::factory()->create([
            'status' => 'on_time',
            'admin_verified' => false,
            'overtime_minutes' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.quickApprove', $attendance));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $attendance->refresh();
        $this->assertTrue($attendance->admin_verified);
    }

    #[Test]
    public function it_fails_quick_approve_for_non_on_time_status()
    {
        $attendance = Attendance::factory()->create([
            'status' => 'tardy',
            'admin_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.quickApprove', $attendance));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $attendance->refresh();
        $this->assertFalse($attendance->admin_verified);
    }

    #[Test]
    public function it_bulk_quick_approves_eligible_records()
    {
        $attendance1 = Attendance::factory()->create(['status' => 'on_time', 'admin_verified' => false]);
        $attendance2 = Attendance::factory()->create(['status' => 'on_time', 'admin_verified' => false]);
        $attendance3 = Attendance::factory()->create(['status' => 'tardy', 'admin_verified' => false]); // Ineligible

        $response = $this->actingAs($this->admin)
            ->post(route('attendance.bulkQuickApprove'), [
                'ids' => [$attendance1->id, $attendance2->id, $attendance3->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $attendance1->refresh();
        $attendance2->refresh();
        $attendance3->refresh();

        $this->assertTrue($attendance1->admin_verified);
        $this->assertTrue($attendance2->admin_verified);
        $this->assertFalse($attendance3->admin_verified);
    }
}
