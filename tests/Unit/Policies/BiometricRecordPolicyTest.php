<?php

namespace Tests\Unit\Policies;

use App\Models\BiometricRecord;
use App\Models\User;
use App\Policies\BiometricRecordPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BiometricRecordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected BiometricRecordPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new BiometricRecordPolicy($this->permissionService);
    }

    #[Test]
    public function admin_can_view_any_biometric_records(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->viewAny($admin));
    }

    #[Test]
    public function team_lead_can_view_any_biometric_records(): void
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead']);

        $this->assertTrue($this->policy->viewAny($teamLead));
    }

    #[Test]
    public function hr_can_view_any_biometric_records(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);

        $this->assertTrue($this->policy->viewAny($hr));
    }

    #[Test]
    public function agent_cannot_view_any_biometric_records(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->viewAny($agent));
    }

    #[Test]
    public function admin_can_view_biometric_record(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $biometricRecord = BiometricRecord::factory()->create();

        $this->assertTrue($this->policy->view($admin, $biometricRecord));
    }

    #[Test]
    public function agent_cannot_view_biometric_record(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $biometricRecord = BiometricRecord::factory()->create();

        $this->assertFalse($this->policy->view($agent, $biometricRecord));
    }

    #[Test]
    public function admin_can_reprocess_biometric_data(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->reprocess($admin));
    }

    #[Test]
    public function team_lead_can_reprocess_biometric_data(): void
    {
        $teamLead = User::factory()->create(['role' => 'Team Lead']);

        $this->assertTrue($this->policy->reprocess($teamLead));
    }

    #[Test]
    public function agent_cannot_reprocess_biometric_data(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->reprocess($agent));
    }

    #[Test]
    public function admin_can_view_anomalies(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->anomalies($admin));
    }

    #[Test]
    public function agent_cannot_view_anomalies(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->anomalies($agent));
    }

    #[Test]
    public function admin_can_export_biometric_data(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->export($admin));
    }

    #[Test]
    public function hr_can_export_biometric_data(): void
    {
        $hr = User::factory()->create(['role' => 'HR']);

        $this->assertTrue($this->policy->export($hr));
    }

    #[Test]
    public function agent_cannot_export_biometric_data(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->export($agent));
    }

    #[Test]
    public function admin_can_manage_retention_policies(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $this->assertTrue($this->policy->retention($admin));
    }

    #[Test]
    public function agent_cannot_manage_retention_policies(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);

        $this->assertFalse($this->policy->retention($agent));
    }

    #[Test]
    public function super_admin_can_perform_all_biometric_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);
        $biometricRecord = BiometricRecord::factory()->create();

        $this->assertTrue($this->policy->viewAny($superAdmin));
        $this->assertTrue($this->policy->view($superAdmin, $biometricRecord));
        $this->assertTrue($this->policy->reprocess($superAdmin));
        $this->assertTrue($this->policy->anomalies($superAdmin));
        $this->assertTrue($this->policy->export($superAdmin));
        $this->assertTrue($this->policy->retention($superAdmin));
    }
}
