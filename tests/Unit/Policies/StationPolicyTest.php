<?php

namespace Tests\Unit\Policies;

use App\Models\Station;
use App\Models\User;
use App\Policies\StationPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class StationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected StationPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new StationPolicy($this->permissionService);
    }

    public function test_it_user_can_view_any_stations(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->viewAny($itUser));
    }

    public function test_it_user_can_view_station(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $station = Station::factory()->create();

        $this->assertTrue($this->policy->view($itUser, $station));
    }

    public function test_it_user_can_create_stations(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->create($itUser));
    }

    public function test_it_user_can_bulk_create_stations(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->bulk($itUser));
    }

    public function test_it_user_can_update_stations(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $station = Station::factory()->create();

        $this->assertTrue($this->policy->update($itUser, $station));
    }

    public function test_it_user_can_delete_stations(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $station = Station::factory()->create();

        $this->assertTrue($this->policy->delete($itUser, $station));
    }

    public function test_it_user_can_generate_qr_codes(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->qrcode($itUser));
    }

    public function test_agent_cannot_perform_station_actions(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $station = Station::factory()->create();

        $this->assertFalse($this->policy->viewAny($agent));
        $this->assertFalse($this->policy->view($agent, $station));
        $this->assertFalse($this->policy->create($agent));
        $this->assertFalse($this->policy->bulk($agent));
        $this->assertFalse($this->policy->update($agent, $station));
        $this->assertFalse($this->policy->delete($agent, $station));
        $this->assertFalse($this->policy->qrcode($agent));
    }

    public function test_super_admin_can_perform_all_station_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);
        $station = Station::factory()->create();

        $this->assertTrue($this->policy->viewAny($superAdmin));
        $this->assertTrue($this->policy->view($superAdmin, $station));
        $this->assertTrue($this->policy->create($superAdmin));
        $this->assertTrue($this->policy->bulk($superAdmin));
        $this->assertTrue($this->policy->update($superAdmin, $station));
        $this->assertTrue($this->policy->delete($superAdmin, $station));
        $this->assertTrue($this->policy->qrcode($superAdmin));
    }
}
