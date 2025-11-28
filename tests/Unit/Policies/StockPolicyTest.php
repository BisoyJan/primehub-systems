<?php

namespace Tests\Unit\Policies;

use App\Models\Stock;
use App\Models\User;
use App\Policies\StockPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class StockPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected StockPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new StockPolicy($this->permissionService);
    }

    public function test_it_user_can_view_any_stocks(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->viewAny($itUser));
    }

    public function test_agent_cannot_view_any_stocks(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->viewAny($agent));
    }

    public function test_it_user_can_view_stock(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $stock = Stock::factory()->create();

        $this->assertTrue($this->policy->view($itUser, $stock));
    }

    public function test_agent_cannot_view_stock(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $stock = Stock::factory()->create();

        $this->assertFalse($this->policy->view($agent, $stock));
    }

    public function test_it_user_can_create_stocks(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->create($itUser));
    }

    public function test_agent_cannot_create_stocks(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->create($agent));
    }

    public function test_it_user_can_update_stocks(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $stock = Stock::factory()->create();

        $this->assertTrue($this->policy->update($itUser, $stock));
    }

    public function test_agent_cannot_update_stocks(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $stock = Stock::factory()->create();

        $this->assertFalse($this->policy->update($agent, $stock));
    }

    public function test_it_user_can_adjust_stocks(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->adjust($itUser));
    }

    public function test_agent_cannot_adjust_stocks(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->adjust($agent));
    }

    public function test_it_user_can_delete_stocks(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $stock = Stock::factory()->create();

        $this->assertTrue($this->policy->delete($itUser, $stock));
    }

    public function test_agent_cannot_delete_stocks(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $stock = Stock::factory()->create();

        $this->assertFalse($this->policy->delete($agent, $stock));
    }

    public function test_super_admin_can_perform_all_stock_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);
        $stock = Stock::factory()->create();

        $this->assertTrue($this->policy->viewAny($superAdmin));
        $this->assertTrue($this->policy->view($superAdmin, $stock));
        $this->assertTrue($this->policy->create($superAdmin));
        $this->assertTrue($this->policy->update($superAdmin, $stock));
        $this->assertTrue($this->policy->adjust($superAdmin));
        $this->assertTrue($this->policy->delete($superAdmin, $stock));
    }
}
