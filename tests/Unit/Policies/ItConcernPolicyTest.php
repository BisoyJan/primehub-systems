<?php

namespace Tests\Unit\Policies;

use App\Models\ItConcern;
use App\Models\User;
use App\Policies\ItConcernPolicy;
use App\Services\PermissionService;
use Tests\TestCase;
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

    /** @test */
    public function agent_can_view_their_own_concern()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $concern = ItConcern::factory()->create(['user_id' => $agent->id]);

        $this->assertTrue($this->policy->view($agent, $concern));
    }

    /** @test */
    public function agent_cannot_view_other_users_concern()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $concern = ItConcern::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($agent, $concern));
    }

    /** @test */
    public function assigned_user_can_view_concern_assigned_to_them()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $itUser = User::factory()->create(['role' => 'IT']);
        $concern = ItConcern::factory()->create([
            'user_id' => $agent->id,
            'assigned_to' => $itUser->id,
        ]);

        $this->assertTrue($this->policy->view($itUser, $concern));
    }

    /** @test */
    public function it_user_can_view_all_concerns()
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $agent = User::factory()->create(['role' => 'Agent']);
        $concern = ItConcern::factory()->create(['user_id' => $agent->id]);

        $this->assertTrue($this->policy->view($itUser, $concern));
    }

    /** @test */
    public function agent_can_create_concerns()
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->create($agent));
    }

    /** @test */
    public function it_user_can_assign_concerns()
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->assign($itUser));
    }

    /** @test */
    public function agent_cannot_assign_concerns()
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->assign($agent));
    }

    /** @test */
    public function assigned_user_can_resolve_their_concern()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $itUser = User::factory()->create(['role' => 'IT']);
        $concern = ItConcern::factory()->create([
            'user_id' => $agent->id,
            'assigned_to' => $itUser->id,
        ]);

        $this->assertTrue($this->policy->resolve($itUser, $concern));
    }

    /** @test */
    public function it_user_can_resolve_any_concern()
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $agent = User::factory()->create(['role' => 'Agent']);
        $concern = ItConcern::factory()->create(['user_id' => $agent->id]);

        $this->assertTrue($this->policy->resolve($itUser, $concern));
    }

    /** @test */
    public function agent_cannot_resolve_concerns()
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $concern = ItConcern::factory()->create(['user_id' => $agent->id]);

        $this->assertFalse($this->policy->resolve($agent, $concern));
    }
}
