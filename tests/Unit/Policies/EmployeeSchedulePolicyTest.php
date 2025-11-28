<?php

namespace Tests\Unit\Policies;

use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Policies\EmployeeSchedulePolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EmployeeSchedulePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected EmployeeSchedulePolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new EmployeeSchedulePolicy($this->permissionService);
    }

    public function test_admin_can_view_any_employee_schedules(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->viewAny($admin));
    }

    public function test_team_lead_can_view_any_employee_schedules(): void
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead']);

        $this->assertTrue($this->policy->viewAny($teamLead));
    }

    public function test_hr_can_view_any_employee_schedules(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);

        $this->assertTrue($this->policy->viewAny($hr));
    }

    public function test_agent_cannot_view_any_employee_schedules(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->viewAny($agent));
    }

    public function test_admin_can_view_employee_schedule(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $schedule = EmployeeSchedule::factory()->create();

        $this->assertTrue($this->policy->view($admin, $schedule));
    }

    public function test_admin_can_create_employee_schedules(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->create($admin));
    }

    public function test_team_lead_can_create_employee_schedules(): void
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead']);

        $this->assertTrue($this->policy->create($teamLead));
    }

    public function test_agent_cannot_create_employee_schedules(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->create($agent));
    }

    public function test_admin_can_update_employee_schedules(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $schedule = EmployeeSchedule::factory()->create();

        $this->assertTrue($this->policy->update($admin, $schedule));
    }

    public function test_agent_cannot_update_employee_schedules(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $schedule = EmployeeSchedule::factory()->create();

        $this->assertFalse($this->policy->update($agent, $schedule));
    }

    public function test_admin_can_toggle_employee_schedules(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->toggle($admin));
    }

    public function test_agent_cannot_toggle_employee_schedules(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->toggle($agent));
    }

    public function test_admin_can_delete_employee_schedules(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $schedule = EmployeeSchedule::factory()->create();

        $this->assertTrue($this->policy->delete($admin, $schedule));
    }

    public function test_agent_cannot_delete_employee_schedules(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $schedule = EmployeeSchedule::factory()->create();

        $this->assertFalse($this->policy->delete($agent, $schedule));
    }

    public function test_super_admin_can_perform_all_schedule_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);
        $schedule = EmployeeSchedule::factory()->create();

        $this->assertTrue($this->policy->viewAny($superAdmin));
        $this->assertTrue($this->policy->view($superAdmin, $schedule));
        $this->assertTrue($this->policy->create($superAdmin));
        $this->assertTrue($this->policy->update($superAdmin, $schedule));
        $this->assertTrue($this->policy->toggle($superAdmin));
        $this->assertTrue($this->policy->delete($superAdmin, $schedule));
    }
}
