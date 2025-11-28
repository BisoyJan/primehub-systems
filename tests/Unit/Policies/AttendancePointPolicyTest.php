<?php

namespace Tests\Unit\Policies;

use App\Models\AttendancePoint;
use App\Models\User;
use App\Policies\AttendancePointPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AttendancePointPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected AttendancePointPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new AttendancePointPolicy($this->permissionService);
    }

    #[Test]
    public function admin_can_view_any_attendance_points(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->viewAny($admin));
    }

    #[Test]
    public function team_lead_can_view_any_attendance_points(): void
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead']);

        $this->assertTrue($this->policy->viewAny($teamLead));
    }

    #[Test]
    public function hr_can_view_any_attendance_points(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);

        $this->assertTrue($this->policy->viewAny($hr));
    }

    #[Test]
    public function agent_can_view_own_attendance_points(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $attendancePoint = AttendancePoint::factory()->create(['user_id' => $agent->id]);

        $this->assertTrue($this->policy->view($agent, $attendancePoint));
    }

    #[Test]
    public function agent_cannot_view_other_users_attendance_points(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendancePoint = AttendancePoint::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($agent, $attendancePoint));
    }

    #[Test]
    public function it_user_can_view_own_attendance_points(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $attendancePoint = AttendancePoint::factory()->create(['user_id' => $itUser->id]);

        $this->assertTrue($this->policy->view($itUser, $attendancePoint));
    }

    #[Test]
    public function it_user_cannot_view_other_users_attendance_points(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendancePoint = AttendancePoint::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($itUser, $attendancePoint));
    }

    #[Test]
    public function utility_user_can_view_own_attendance_points(): void
    {
        $utilityUser = User::factory()->create(['role' => 'Utility']);
        $attendancePoint = AttendancePoint::factory()->create(['user_id' => $utilityUser->id]);

        $this->assertTrue($this->policy->view($utilityUser, $attendancePoint));
    }

    #[Test]
    public function admin_can_view_any_users_attendance_points(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendancePoint = AttendancePoint::factory()->create(['user_id' => $otherUser->id]);

        $this->assertTrue($this->policy->view($admin, $attendancePoint));
    }

    #[Test]
    public function admin_can_excuse_attendance_points(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->excuse($admin));
    }

    #[Test]
    public function team_lead_can_excuse_attendance_points(): void
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead']);

        $this->assertFalse($this->policy->excuse($teamLead));
    }

    #[Test]
    public function hr_can_excuse_attendance_points(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);

        $this->assertTrue($this->policy->excuse($hr));
    }

    #[Test]
    public function agent_cannot_excuse_attendance_points(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->excuse($agent));
    }

    #[Test]
    public function admin_can_export_attendance_points(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->export($admin));
    }

    #[Test]
    public function agent_cannot_export_attendance_points(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->export($agent));
    }

    #[Test]
    public function admin_can_rescan_attendance_points(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->rescan($admin));
    }

    #[Test]
    public function agent_cannot_rescan_attendance_points(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->rescan($agent));
    }
}
