<?php

namespace Tests\Unit\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Services\PermissionService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    /** @test */
    public function super_admin_can_view_any_attendance()
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);

        $this->assertTrue($this->policy->viewAny($superAdmin));
    }

    /** @test */
    public function agent_can_view_any_attendance()
    {
        // Agent has attendance.view permission
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->viewAny($agent));
    }

    /** @test */
    public function agent_can_view_their_own_attendance()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $attendance = Attendance::factory()->create(['user_id' => $agent->id]);

        $this->assertTrue($this->policy->view($agent, $attendance));
    }

    /** @test */
    public function agent_cannot_view_other_users_attendance()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendance = Attendance::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($agent, $attendance));
    }

    /** @test */
    public function it_user_can_view_their_own_attendance()
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $attendance = Attendance::factory()->create(['user_id' => $itUser->id]);

        $this->assertTrue($this->policy->view($itUser, $attendance));
    }

    /** @test */
    public function it_user_cannot_view_other_users_attendance()
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendance = Attendance::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($itUser, $attendance));
    }

    /** @test */
    public function utility_user_can_view_their_own_attendance()
    {
        $utilityUser = User::factory()->create(['role' => 'Utility']);
        $attendance = Attendance::factory()->create(['user_id' => $utilityUser->id]);

        $this->assertTrue($this->policy->view($utilityUser, $attendance));
    }

    /** @test */
    public function admin_can_view_any_attendance()
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendance = Attendance::factory()->create(['user_id' => $otherUser->id]);

        $this->assertTrue($this->policy->view($admin, $attendance));
    }

    /** @test */
    public function team_lead_can_view_any_attendance()
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $attendance = Attendance::factory()->create(['user_id' => $otherUser->id]);

        $this->assertTrue($this->policy->view($teamLead, $attendance));
    }

    /** @test */
    public function admin_can_approve_attendance()
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->approve($admin));
    }

    /** @test */
    public function team_lead_can_approve_attendance()
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead']);

        $this->assertTrue($this->policy->approve($teamLead));
    }

    /** @test */
    public function agent_cannot_approve_attendance()
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->approve($agent));
    }

    /** @test */
    public function admin_can_import_attendance()
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->import($admin));
    }

    /** @test */
    public function agent_cannot_import_attendance()
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->import($agent));
    }
}
