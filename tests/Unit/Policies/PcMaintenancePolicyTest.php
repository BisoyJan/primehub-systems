<?php

namespace Tests\Unit\Policies;

use App\Models\PcMaintenance;
use App\Models\User;
use App\Policies\PcMaintenancePolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PcMaintenancePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected PcMaintenancePolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new PcMaintenancePolicy($this->permissionService);
    }

    public function test_it_user_can_view_any_pc_maintenance(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->viewAny($itUser));
    }

    public function test_it_user_can_view_pc_maintenance(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $pcMaintenance = PcMaintenance::factory()->create();

        $this->assertTrue($this->policy->view($itUser, $pcMaintenance));
    }

    public function test_it_user_can_create_pc_maintenance(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->create($itUser));
    }

    public function test_it_user_can_update_pc_maintenance(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $pcMaintenance = PcMaintenance::factory()->create();

        $this->assertTrue($this->policy->update($itUser, $pcMaintenance));
    }

    public function test_it_user_can_delete_pc_maintenance(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $pcMaintenance = PcMaintenance::factory()->create();

        $this->assertTrue($this->policy->delete($itUser, $pcMaintenance));
    }

    public function test_agent_cannot_perform_pc_maintenance_actions(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $pcMaintenance = PcMaintenance::factory()->create();

        $this->assertFalse($this->policy->viewAny($agent));
        $this->assertFalse($this->policy->view($agent, $pcMaintenance));
        $this->assertFalse($this->policy->create($agent));
        $this->assertFalse($this->policy->update($agent, $pcMaintenance));
        $this->assertFalse($this->policy->delete($agent, $pcMaintenance));
    }

    public function test_super_admin_can_perform_all_pc_maintenance_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);
        $pcMaintenance = PcMaintenance::factory()->create();

        $this->assertTrue($this->policy->viewAny($superAdmin));
        $this->assertTrue($this->policy->view($superAdmin, $pcMaintenance));
        $this->assertTrue($this->policy->create($superAdmin));
        $this->assertTrue($this->policy->update($superAdmin, $pcMaintenance));
        $this->assertTrue($this->policy->delete($superAdmin, $pcMaintenance));
    }
}
