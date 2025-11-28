<?php

namespace Tests\Unit\Policies;

use App\Models\Campaign;
use App\Models\User;
use App\Policies\CampaignPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CampaignPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected CampaignPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new CampaignPolicy($this->permissionService);
    }

    #[Test]
    public function it_user_can_view_any_campaigns(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->viewAny($itUser));
    }

    #[Test]
    public function it_user_can_view_campaign(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $campaign = Campaign::factory()->create();

        $this->assertTrue($this->policy->view($itUser, $campaign));
    }

    #[Test]
    public function it_user_can_create_campaigns(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->create($itUser));
    }

    #[Test]
    public function it_user_can_update_campaigns(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $campaign = Campaign::factory()->create();

        $this->assertTrue($this->policy->update($itUser, $campaign));
    }

    #[Test]
    public function it_user_can_delete_campaigns(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $campaign = Campaign::factory()->create();

        $this->assertTrue($this->policy->delete($itUser, $campaign));
    }

    #[Test]
    public function agent_cannot_perform_campaign_actions(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $campaign = Campaign::factory()->create();

        $this->assertFalse($this->policy->viewAny($agent));
        $this->assertFalse($this->policy->view($agent, $campaign));
        $this->assertFalse($this->policy->create($agent));
        $this->assertFalse($this->policy->update($agent, $campaign));
        $this->assertFalse($this->policy->delete($agent, $campaign));
    }

    #[Test]
    public function super_admin_can_perform_all_campaign_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);
        $campaign = Campaign::factory()->create();

        $this->assertTrue($this->policy->viewAny($superAdmin));
        $this->assertTrue($this->policy->view($superAdmin, $campaign));
        $this->assertTrue($this->policy->create($superAdmin));
        $this->assertTrue($this->policy->update($superAdmin, $campaign));
        $this->assertTrue($this->policy->delete($superAdmin, $campaign));
    }
}
