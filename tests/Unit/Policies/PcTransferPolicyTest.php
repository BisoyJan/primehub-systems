<?php

namespace Tests\Unit\Policies;

use App\Models\PcTransfer;
use App\Models\User;
use App\Policies\PcTransferPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PcTransferPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected PcTransferPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new PcTransferPolicy($this->permissionService);
    }

    public function test_it_user_can_view_any_pc_transfers(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->viewAny($itUser));
    }

    public function test_it_user_can_view_pc_transfer(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $pcTransfer = PcTransfer::factory()->create();

        $this->assertTrue($this->policy->view($itUser, $pcTransfer));
    }

    public function test_it_user_can_create_pc_transfers(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->create($itUser));
    }

    public function test_it_user_can_remove_pcs_from_stations(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->remove($itUser));
    }

    public function test_it_user_can_view_transfer_history(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->history($itUser));
    }

    public function test_agent_cannot_perform_pc_transfer_actions(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $pcTransfer = PcTransfer::factory()->create();

        $this->assertFalse($this->policy->viewAny($agent));
        $this->assertFalse($this->policy->view($agent, $pcTransfer));
        $this->assertFalse($this->policy->create($agent));
        $this->assertFalse($this->policy->remove($agent));
        $this->assertFalse($this->policy->history($agent));
    }

    public function test_super_admin_can_perform_all_pc_transfer_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);
        $pcTransfer = PcTransfer::factory()->create();

        $this->assertTrue($this->policy->viewAny($superAdmin));
        $this->assertTrue($this->policy->view($superAdmin, $pcTransfer));
        $this->assertTrue($this->policy->create($superAdmin));
        $this->assertTrue($this->policy->remove($superAdmin));
        $this->assertTrue($this->policy->history($superAdmin));
    }
}
