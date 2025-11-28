<?php

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\HardwareSpecPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class HardwareSpecPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected HardwareSpecPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new HardwareSpecPolicy($this->permissionService);
    }

    public function test_it_user_can_view_any_hardware_specs(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->viewAny($itUser));
    }

    public function test_it_user_can_view_hardware_specs(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->view($itUser));
    }

    public function test_it_user_can_create_hardware_specs(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->create($itUser));
    }

    public function test_it_user_can_update_hardware_specs(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->update($itUser));
    }

    public function test_it_user_can_delete_hardware_specs(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->delete($itUser));
    }

    public function test_agent_cannot_view_any_hardware_specs(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->viewAny($agent));
    }

    public function test_agent_cannot_view_hardware_specs(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->view($agent));
    }

    public function test_agent_cannot_create_hardware_specs(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->create($agent));
    }

    public function test_agent_cannot_update_hardware_specs(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->update($agent));
    }

    public function test_agent_cannot_delete_hardware_specs(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->delete($agent));
    }

    public function test_super_admin_can_perform_all_hardware_spec_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);

        $this->assertTrue($this->policy->viewAny($superAdmin));
        $this->assertTrue($this->policy->view($superAdmin));
        $this->assertTrue($this->policy->create($superAdmin));
        $this->assertTrue($this->policy->update($superAdmin));
        $this->assertTrue($this->policy->delete($superAdmin));
    }
}
