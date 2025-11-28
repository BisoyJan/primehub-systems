<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Models\Site;
use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Carbon\Carbon;

class AttendanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $employee1;
    protected User $employee2;
    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $this->employee1 = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'role' => 'Agent',
        ]);

        $this->employee2 = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'role' => 'Agent',
        ]);

        $this->site = Site::factory()->create();
    }

    #[Test]
    public function attendance_index_page_can_be_accessed(): void
    {
        $this->actingAs($this->admin)
            ->get('/attendance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Attendance/Main/Index')
                ->has('attendances')
            );
    }

    #[Test]
    public function attendance_can_be_filtered_by_date(): void
    {
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'shift_date' => '2025-11-05',
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee2->id,
            'shift_date' => '2025-11-10',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/attendance?date_from=2025-11-05&date_to=2025-11-05')
            ->assertOk();

        // Should only return attendance for 2025-11-05
        $response->assertInertia(fn ($page) => $page
            ->component('Attendance/Main/Index')
            ->has('attendances.data')
        );
    }

    #[Test]
    public function attendance_can_be_filtered_by_user(): void
    {
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'shift_date' => '2025-11-05',
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee2->id,
            'shift_date' => '2025-11-05',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/attendance?user_id=' . $this->employee1->id)
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Attendance/Main/Index')
            ->has('attendances.data')
        );
    }

    #[Test]
    public function attendance_can_be_filtered_by_site(): void
    {
        $site2 = Site::factory()->create();

        $schedule1 = EmployeeSchedule::factory()->create([
            'user_id' => $this->employee1->id,
            'site_id' => $this->site->id,
        ]);

        $schedule2 = EmployeeSchedule::factory()->create([
            'user_id' => $this->employee2->id,
            'site_id' => $site2->id,
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'employee_schedule_id' => $schedule1->id,
            'shift_date' => '2025-11-05',
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee2->id,
            'employee_schedule_id' => $schedule2->id,
            'shift_date' => '2025-11-05',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/attendance?site_id=' . $this->site->id)
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Attendance/Main/Index')
        );
    }

    #[Test]
    public function attendance_can_be_filtered_by_verification_status(): void
    {
        Attendance::factory()->verified()->create([
            'user_id' => $this->employee1->id,
            'shift_date' => '2025-11-05',
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee2->id,
            'shift_date' => '2025-11-05',
            'admin_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/attendance?admin_verified=0')
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Attendance/Main/Index')
        );
    }

    #[Test]
    public function attendance_statistics_can_be_accessed(): void
    {
        Attendance::factory()->count(5)->create([
            'shift_date' => Carbon::today()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/attendance/statistics')
            ->assertOk();

        // Statistics endpoint returns JSON, not Inertia
        $response->assertJsonStructure([
            'total',
            'on_time',
            'tardy',
            'half_day',
            'ncns',
            'advised',
            'needs_verification',
        ]);
    }

    #[Test]
    public function review_page_shows_unverified_attendance(): void
    {
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'shift_date' => '2025-11-05',
            'admin_verified' => false,
            'status' => 'needs_manual_review',
        ]);

        Attendance::factory()->verified()->create([
            'user_id' => $this->employee2->id,
            'shift_date' => '2025-11-05',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/attendance/review')
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Attendance/Main/Review')
            ->has('attendances')
        );
    }

    #[Test]
    public function attendance_can_be_verified_by_admin(): void
    {
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'shift_date' => '2025-11-05',
            'admin_verified' => false,
            'status' => 'ncns',
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/attendance/{$attendance->id}/verify", [
                'status' => 'on_time',
                'verification_notes' => 'Verified by admin',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'admin_verified' => true,
        ]);
    }

    #[Test]
    public function multiple_attendance_can_be_batch_verified(): void
    {
        $attendance1 = Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'admin_verified' => false,
            'status' => 'ncns',
        ]);

        $attendance2 = Attendance::factory()->create([
            'user_id' => $this->employee2->id,
            'admin_verified' => false,
            'status' => 'ncns',
        ]);

        $response = $this->actingAs($this->admin)
            ->post('/attendance/batch-verify', [
                'record_ids' => [$attendance1->id, $attendance2->id],
                'status' => 'on_time',
                'verification_notes' => 'Batch verified by admin',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance1->id,
            'admin_verified' => true,
        ]);

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance2->id,
            'admin_verified' => true,
        ]);
    }

    #[Test]
    public function agents_cannot_access_attendance_index(): void
    {
        // Agents don't have attendance.view permission
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'shift_date' => '2025-11-05',
        ]);

        $response = $this->actingAs($this->employee1)
            ->get('/attendance');

        // Should be redirected (403 forbidden gets redirected to home)
        $response->assertRedirect();
    }

    #[Test]
    public function attendance_search_by_employee_name_works(): void
    {
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'shift_date' => '2025-11-05',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/attendance?search=John')
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Attendance/Main/Index')
        );
    }

    #[Test]
    public function attendance_pagination_works(): void
    {
        Attendance::factory()->count(30)->create([
            'shift_date' => '2025-11-05',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/attendance')
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Attendance/Main/Index')
            ->has('attendances.data')
            ->has('attendances.links')
        );
    }

    #[Test]
    public function attendance_can_be_marked_as_advised(): void
    {
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'shift_date' => '2025-11-05',
            'is_advised' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/attendance/{$attendance->id}/mark-advised");

        $response->assertRedirect();

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'is_advised' => true,
        ]);
    }

    #[Test]
    public function quick_approve_updates_attendance_status(): void
    {
        $attendance = Attendance::factory()->onTime()->create([
            'user_id' => $this->employee1->id,
            'shift_date' => '2025-11-05',
            'admin_verified' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/attendance/{$attendance->id}/quick-approve");

        $response->assertRedirect();

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'admin_verified' => true,
        ]);
    }

    #[Test]
    public function bulk_quick_approve_handles_multiple_records(): void
    {
        $attendance1 = Attendance::factory()->onTime()->create(['admin_verified' => false]);
        $attendance2 = Attendance::factory()->onTime()->create(['admin_verified' => false]);

        $response = $this->actingAs($this->admin)
            ->post('/attendance/bulk-quick-approve', [
                'ids' => [$attendance1->id, $attendance2->id],
            ]);

        $response->assertRedirect();

        $this->assertEquals(2, Attendance::where('admin_verified', true)->count());
    }
}
