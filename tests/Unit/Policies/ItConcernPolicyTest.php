<?php

namespace Tests\Unit\Policies;

use App\Models\ItConcern;
use App\Models\User;
use App\Policies\ItConcernPolicy;
use App\Services\PermissionService;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ItConcernPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected ItConcernPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new ItConcernPolicy($this->permissionService);
    }

    #[Test]
    public function agent_can_view_their_own_concern(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $concern = ItConcern::factory()->create(['user_id' => $agent->id]);

        $this->assertTrue($this->policy->view($agent, $concern));
    }

    #[Test]
    public function agent_cannot_view_other_users_concern(): void
    {
        // Agents don't have it_concerns.view permission, so they can only view their own
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $concern = ItConcern::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($agent, $concern));
    }

    #[Test]
    public function it_user_can_view_concern_without_being_owner(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $itUser = User::factory()->create(['role' => 'IT']);
        $concern = ItConcern::factory()->create([
            'user_id' => $agent->id,
        ]);

        $this->assertTrue($this->policy->view($itUser, $concern));
    }

    #[Test]
    public function it_user_can_view_all_concerns(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $agent = User::factory()->create(['role' => 'Agent']);
        $concern = ItConcern::factory()->create(['user_id' => $agent->id]);

        $this->assertTrue($this->policy->view($itUser, $concern));
    }

    #[Test]
    public function agent_can_create_concerns(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->create($agent));
    }

    #[Test]
    public function it_user_can_assign_concerns(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->assign($itUser));
    }

    #[Test]
    public function agent_cannot_assign_concerns(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->assign($agent));
    }

    #[Test]
    public function it_user_can_resolve_concern_with_permission(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $itUser = User::factory()->create(['role' => 'IT']);
        $concern = ItConcern::factory()->create([
            'user_id' => $agent->id,
        ]);

        $this->assertTrue($this->policy->resolve($itUser, $concern));
    }

    #[Test]
    public function it_user_can_resolve_any_concern(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $agent = User::factory()->create(['role' => 'Agent']);
        $concern = ItConcern::factory()->create(['user_id' => $agent->id]);

        $this->assertTrue($this->policy->resolve($itUser, $concern));
    }

    #[Test]
    public function agent_cannot_resolve_concerns(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $concern = ItConcern::factory()->create(['user_id' => $agent->id]);

        $this->assertFalse($this->policy->resolve($agent, $concern));
    }
}
