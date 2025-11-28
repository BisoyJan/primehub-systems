<?php

namespace Tests\Unit\Policies;

use App\Models\PcSpec;
use App\Models\User;
use App\Policies\PcSpecPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PcSpecPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected PcSpecPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new PcSpecPolicy($this->permissionService);
    }

    public function test_it_user_can_view_any_pc_specs(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->viewAny($itUser));
    }

    public function test_agent_cannot_view_any_pc_specs(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->viewAny($agent));
    }

    public function test_it_user_can_view_pc_spec(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $pcSpec = PcSpec::factory()->create();

        $this->assertTrue($this->policy->view($itUser, $pcSpec));
    }

    public function test_it_user_can_create_pc_specs(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->create($itUser));
    }

    public function test_it_user_can_update_pc_specs(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $pcSpec = PcSpec::factory()->create();

        $this->assertTrue($this->policy->update($itUser, $pcSpec));
    }

    public function test_it_user_can_update_pc_issues(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->updateIssue($itUser));
    }

    public function test_it_user_can_delete_pc_specs(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);
        $pcSpec = PcSpec::factory()->create();

        $this->assertTrue($this->policy->delete($itUser, $pcSpec));
    }

    public function test_it_user_can_generate_qr_codes(): void
    {
        $itUser = User::factory()->create(['role' => 'IT']);

        $this->assertTrue($this->policy->qrcode($itUser));
    }

    public function test_agent_cannot_perform_pc_spec_actions(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $pcSpec = PcSpec::factory()->create();

        $this->assertFalse($this->policy->viewAny($agent));
        $this->assertFalse($this->policy->view($agent, $pcSpec));
        $this->assertFalse($this->policy->create($agent));
        $this->assertFalse($this->policy->update($agent, $pcSpec));
        $this->assertFalse($this->policy->updateIssue($agent));
        $this->assertFalse($this->policy->delete($agent, $pcSpec));
        $this->assertFalse($this->policy->qrcode($agent));
    }
}
