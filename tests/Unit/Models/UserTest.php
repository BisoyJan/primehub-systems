<?php

namespace Tests\Unit\Models;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\EmployeeSchedule;
use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\User;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'middle_name' => 'M',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2024-01-15'),
            'is_approved' => true,
        ]);

        $this->assertEquals('John', $user->first_name);
        $this->assertEquals('M', $user->middle_name);
        $this->assertEquals('Doe', $user->last_name);
        $this->assertEquals('john@example.com', $user->email);
        $this->assertEquals('Agent', $user->role);
        $this->assertInstanceOf(Carbon::class, $user->hired_date);
        /** @var Carbon $hiredDate */
        $hiredDate = $user->hired_date;
        $this->assertEquals('2024-01-15', $hiredDate->toDateString());
        $this->assertTrue($user->is_approved);
    }

    #[Test]
    public function it_casts_hired_date_to_date(): void
    {
        $user = User::factory()->create([
            'hired_date' => '2024-01-15',
        ]);

        $this->assertInstanceOf(Carbon::class, $user->hired_date);
        /** @var Carbon $hiredDate */
        $hiredDate = $user->hired_date;
        $this->assertEquals('2024-01-15', $hiredDate->toDateString());
    }

    #[Test]
    public function it_casts_is_approved_to_boolean(): void
    {
        $user = User::factory()->create(['is_approved' => 1]);
        $this->assertIsBool($user->is_approved);
        $this->assertTrue($user->is_approved);

        $user2 = User::factory()->create(['is_approved' => 0]);
        $this->assertFalse($user2->is_approved);
    }

    #[Test]
    public function it_hashes_password_attribute(): void
    {
        $user = User::factory()->create([
            'password' => 'plain-password',
        ]);

        $this->assertNotEquals('plain-password', $user->password);
        $this->assertTrue(\Hash::check('plain-password', $user->password));
    }

    #[Test]
    public function it_generates_full_name_accessor(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'middle_name' => 'M',
            'last_name' => 'Doe',
        ]);

        $this->assertEquals('John M. Doe', $user->name);
    }

    #[Test]
    public function it_generates_full_name_without_middle_name(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Doe',
        ]);

        $this->assertEquals('John Doe', $user->name);
    }

    #[Test]
    public function it_appends_name_to_array(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Doe',
        ]);

        $array = $user->toArray();
        $this->assertArrayHasKey('name', $array);
        $this->assertEquals('John Doe', $array['name']);
    }

    #[Test]
    public function it_has_employee_schedules_relationship(): void
    {
        $user = User::factory()->create();
        $schedule = EmployeeSchedule::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->employeeSchedules()->exists());
        $this->assertEquals($schedule->id, $user->employeeSchedules->first()->id);
    }

    #[Test]
    public function it_has_active_schedule_relationship(): void
    {
        $user = User::factory()->create();

        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
        ]);

        $activeSchedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'effective_date' => Carbon::now()->subDays(10),
            'end_date' => null,
        ]);

        $this->assertNotNull($user->activeSchedule);
        $this->assertEquals($activeSchedule->id, $user->activeSchedule->id);
    }

    #[Test]
    public function it_has_attendances_relationship(): void
    {
        $user = User::factory()->create();
        $attendance = Attendance::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->attendances()->exists());
        $this->assertEquals($attendance->id, $user->attendances->first()->id);
    }

    #[Test]
    public function it_has_attendance_points_relationship(): void
    {
        $user = User::factory()->create();
        $point = AttendancePoint::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->attendancePoints()->exists());
        $this->assertEquals($point->id, $user->attendancePoints->first()->id);
    }

    #[Test]
    public function it_has_leave_credits_relationship(): void
    {
        $user = User::factory()->create();
        LeaveCredit::create([
            'user_id' => $user->id,
            'year' => 2024,
            'month' => 6,
            'credits_earned' => 1.25,
            'accrued_at' => now(),
        ]);

        $this->assertTrue($user->leaveCredits()->exists());
        $this->assertEquals(1, $user->leaveCredits->count());
    }

    #[Test]
    public function it_has_leave_requests_relationship(): void
    {
        $user = User::factory()->create();
        $leaveRequest = LeaveRequest::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->leaveRequests()->exists());
        $this->assertEquals($leaveRequest->id, $user->leaveRequests->first()->id);
    }

    #[Test]
    public function it_has_reviewed_leave_requests_relationship(): void
    {
        $reviewer = User::factory()->create();
        $requester = User::factory()->create();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $requester->id,
            'reviewed_by' => $reviewer->id,
        ]);

        $this->assertTrue($reviewer->reviewedLeaveRequests()->exists());
        $this->assertEquals($leaveRequest->id, $reviewer->reviewedLeaveRequests->first()->id);
    }

    #[Test]
    public function it_has_notifications_relationship(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->notifications()->exists());
        $this->assertEquals($notification->id, $user->notifications->first()->id);
    }

    #[Test]
    public function it_has_unread_notifications_relationship(): void
    {
        $user = User::factory()->create();

        Notification::factory()->create([
            'user_id' => $user->id,
            'read_at' => Carbon::now(),
        ]);

        $unreadNotification = Notification::factory()->create([
            'user_id' => $user->id,
            'read_at' => null,
        ]);

        $this->assertEquals(1, $user->unreadNotifications()->count());
        $this->assertEquals($unreadNotification->id, $user->unreadNotifications->first()->id);
    }

    #[Test]
    public function it_checks_user_has_permission(): void
    {
        $user = User::factory()->create(['role' => 'Super Admin']);

        $this->assertTrue($user->hasPermission('dashboard.view'));
    }

    #[Test]
    public function it_checks_user_has_any_permission(): void
    {
        $user = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($user->hasAnyPermission(['accounts.view', 'accounts.create']));
    }

    #[Test]
    public function it_checks_user_has_all_permissions(): void
    {
        $user = User::factory()->create(['role' => 'Super Admin']);

        $this->assertTrue($user->hasAllPermissions(['accounts.view', 'accounts.create', 'accounts.delete']));
    }

    #[Test]
    public function it_checks_user_has_role(): void
    {
        $user = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($user->hasRole('Admin'));
        $this->assertFalse($user->hasRole('Agent'));
    }

    #[Test]
    public function it_checks_user_has_role_array(): void
    {
        $user = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($user->hasRole(['Admin', 'Super Admin']));
        $this->assertFalse($user->hasRole(['Agent', 'Team Lead']));
    }

    #[Test]
    public function it_gets_permissions_for_user_role(): void
    {
        $user = User::factory()->create(['role' => 'Admin']);

        $permissions = $user->getPermissions();

        $this->assertIsArray($permissions);
        $this->assertNotEmpty($permissions);
    }

    #[Test]
    public function it_hides_password_in_array(): void
    {
        $user = User::factory()->create();
        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
    }

    #[Test]
    public function it_hides_remember_token_in_array(): void
    {
        $user = User::factory()->create();
        $array = $user->toArray();

        $this->assertArrayNotHasKey('remember_token', $array);
    }
}
