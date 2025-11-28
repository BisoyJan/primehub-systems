<?php

namespace Tests\Unit\Policies;

use App\Models\MedicationRequest;
use App\Models\User;
use App\Policies\MedicationRequestPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MedicationRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected MedicationRequestPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new MedicationRequestPolicy($this->permissionService);
    }

    public function test_admin_can_view_any_medication_requests(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->viewAny($admin));
    }

    public function test_hr_can_view_any_medication_requests(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);

        $this->assertTrue($this->policy->viewAny($hr));
    }

    public function test_agent_can_view_own_medication_request(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $medicationRequest = MedicationRequest::factory()->create(['user_id' => $agent->id]);

        $this->assertTrue($this->policy->view($agent, $medicationRequest));
    }

    public function test_agent_cannot_view_other_users_medication_request(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $medicationRequest = MedicationRequest::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($agent, $medicationRequest));
    }

    public function test_admin_can_view_any_medication_request(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $medicationRequest = MedicationRequest::factory()->create(['user_id' => $otherUser->id]);

        $this->assertTrue($this->policy->view($admin, $medicationRequest));
    }

    public function test_agent_can_create_medication_requests(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertTrue($this->policy->create($agent));
    }

    public function test_admin_can_update_medication_requests(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $medicationRequest = MedicationRequest::factory()->create();

        $this->assertTrue($this->policy->update($admin, $medicationRequest));
    }

    public function test_hr_can_update_medication_requests(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);
        $medicationRequest = MedicationRequest::factory()->create();

        $this->assertTrue($this->policy->update($hr, $medicationRequest));
    }

    public function test_agent_cannot_update_medication_requests(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $medicationRequest = MedicationRequest::factory()->create(['user_id' => $agent->id]);

        $this->assertFalse($this->policy->update($agent, $medicationRequest));
    }

    public function test_agent_can_delete_own_pending_medication_request(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
        ]);

        $this->assertTrue($this->policy->delete($agent, $medicationRequest));
    }

    public function test_agent_cannot_delete_own_approved_medication_request(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'approved',
        ]);

        $this->assertFalse($this->policy->delete($agent, $medicationRequest));
    }

    public function test_agent_cannot_delete_other_users_medication_request(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $otherUser = User::factory()->create(['role' => 'Agent']);
        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'pending',
        ]);

        $this->assertFalse($this->policy->delete($agent, $medicationRequest));
    }

    public function test_admin_can_delete_any_medication_request(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $medicationRequest = MedicationRequest::factory()->create(['status' => 'approved']);

        $this->assertTrue($this->policy->delete($admin, $medicationRequest));
    }

    public function test_super_admin_can_perform_all_medication_request_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);
        $medicationRequest = MedicationRequest::factory()->create();

        $this->assertTrue($this->policy->viewAny($superAdmin));
        $this->assertTrue($this->policy->view($superAdmin, $medicationRequest));
        $this->assertTrue($this->policy->create($superAdmin));
        $this->assertTrue($this->policy->update($superAdmin, $medicationRequest));
        $this->assertTrue($this->policy->delete($superAdmin, $medicationRequest));
    }
}
