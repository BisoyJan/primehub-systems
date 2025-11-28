<?php

namespace Tests\Unit\Policies;

use App\Models\Site;
use App\Models\User;
use App\Policies\SitePolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SitePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected SitePolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new SitePolicy($this->permissionService);
    }

    public function test_it_user_can_view_any_sites(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->viewAny($itUser));
    }

    public function test_it_user_can_view_site(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $site = Site::factory()->create();

        $this->assertTrue($this->policy->view($itUser, $site));
    }

    public function test_it_user_can_create_sites(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->create($itUser));
    }

    public function test_it_user_can_update_sites(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $site = Site::factory()->create();

        $this->assertTrue($this->policy->update($itUser, $site));
    }

    public function test_it_user_can_delete_sites(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $site = Site::factory()->create();

        $this->assertTrue($this->policy->delete($itUser, $site));
    }

    public function test_agent_cannot_perform_site_actions(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $site = Site::factory()->create();

        $this->assertFalse($this->policy->viewAny($agent));
        $this->assertFalse($this->policy->view($agent, $site));
        $this->assertFalse($this->policy->create($agent));
        $this->assertFalse($this->policy->update($agent, $site));
        $this->assertFalse($this->policy->delete($agent, $site));
    }

    public function test_super_admin_can_perform_all_site_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);
        $site = Site::factory()->create();

        $this->assertTrue($this->policy->viewAny($superAdmin));
        $this->assertTrue($this->policy->view($superAdmin, $site));
        $this->assertTrue($this->policy->create($superAdmin));
        $this->assertTrue($this->policy->update($superAdmin, $site));
        $this->assertTrue($this->policy->delete($superAdmin, $site));
    }
}
