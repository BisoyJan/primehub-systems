<?php

namespace Tests\Unit\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Services\PermissionService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class AttendancePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected AttendancePolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new AttendancePolicy($this->permissionService);
    }

    #[Test]
    public function super_admin_can_view_any_attendance(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);

        $this->assertTrue($this->policy->viewAny($superAdmin));
    }

    #[Test]
    public function agent_can_view_any_attendance(): void
    {
        // Agent has attendance.view permission
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->viewAny($agent));
    }

    #[Test]
    public function agent_can_view_their_own_attendance(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $attendance = Attendance::factory()->create(['user_id' => $agent->id]);

        $this->assertTrue($this->policy->view($agent, $attendance));
    }

    #[Test]
    public function agent_cannot_view_other_users_attendance(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendance = Attendance::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($agent, $attendance));
    }

    #[Test]
    public function it_user_can_view_their_own_attendance(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $attendance = Attendance::factory()->create(['user_id' => $itUser->id]);

        $this->assertTrue($this->policy->view($itUser, $attendance));
    }

    #[Test]
    public function it_user_cannot_view_other_users_attendance(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendance = Attendance::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($itUser, $attendance));
    }

    #[Test]
    public function utility_user_can_view_their_own_attendance(): void
    {
        $utilityUser = User::factory()->create(['role' => 'Utility']);
        $attendance = Attendance::factory()->create(['user_id' => $utilityUser->id]);

        $this->assertTrue($this->policy->view($utilityUser, $attendance));
    }

    #[Test]
    public function admin_can_view_any_attendance(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendance = Attendance::factory()->create(['user_id' => $otherUser->id]);

        $this->assertTrue($this->policy->view($admin, $attendance));
    }

    #[Test]
    public function team_lead_can_view_any_attendance(): void
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendance = Attendance::factory()->create(['user_id' => $otherUser->id]);

        $this->assertTrue($this->policy->view($teamLead, $attendance));
    }

    #[Test]
    public function admin_can_approve_attendance(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->approve($admin));
    }

    #[Test]
    public function team_lead_can_approve_attendance(): void
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead']);

        $this->assertTrue($this->policy->approve($teamLead));
    }

    #[Test]
    public function agent_cannot_approve_attendance(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->approve($agent));
    }

    #[Test]
    public function admin_can_import_attendance(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->import($admin));
    }

    #[Test]
    public function agent_cannot_import_attendance(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->import($agent));
    }
}
