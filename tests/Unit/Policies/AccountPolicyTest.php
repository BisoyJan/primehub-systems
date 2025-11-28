<?php

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\AccountPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AccountPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected AccountPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new AccountPolicy($this->permissionService);
    }

    #[Test]
    public function admin_can_view_any_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->viewAny($admin));
    }

    #[Test]
    public function super_admin_can_view_any_accounts(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);

        $this->assertTrue($this->policy->viewAny($superAdmin));
    }

    #[Test]
    public function agent_cannot_view_any_accounts(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->viewAny($agent));
    }

    #[Test]
    public function user_can_view_their_own_account(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->view($user, $user));
    }

    #[Test]
    public function admin_can_view_other_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->view($admin, $otherUser));
    }

    #[Test]
    public function agent_cannot_view_other_accounts(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->view($agent, $otherUser));
    }

    #[Test]
    public function admin_can_create_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->create($admin));
    }

    #[Test]
    public function agent_cannot_create_accounts(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->create($agent));
    }

    #[Test]
    public function admin_can_update_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->update($admin, $otherUser));
    }

    #[Test]
    public function agent_cannot_update_accounts(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->update($agent, $otherUser));
    }

    #[Test]
    public function admin_can_delete_other_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->delete($admin, $otherUser));
    }

    #[Test]
    public function user_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertFalse($this->policy->delete($admin, $admin));
    }

    #[Test]
    public function agent_cannot_delete_accounts(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->delete($agent, $otherUser));
    }
}
