<?php

namespace Tests\Feature\Controllers\Biometrics;

use App\Models\BiometricRetentionPolicy;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BiometricRetentionPolicyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $this->agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }

    #[Test]
    public function it_displays_retention_policies_page()
    {
        BiometricRetentionPolicy::create([
            'name' => 'Global Policy',
            'retention_months' => 6,
            'applies_to_type' => 'global',
            'is_active' => true,
            'priority' => 10,
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-retention-policies.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/RetentionPolicies')
            ->has('policies', 1)
            ->has('sites')
        );
    }

    #[Test]
    public function it_can_create_retention_policy()
    {
        $site = Site::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('biometric-retention-policies.store'), [
            'name' => 'Site Policy',
            'description' => 'Policy for site',
            'retention_months' => 12,
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'priority' => 5,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('biometric_retention_policies', [
            'name' => 'Site Policy',
            'retention_months' => 12,
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
        ]);
    }

    #[Test]
    public function it_can_update_retention_policy()
    {
        $policy = BiometricRetentionPolicy::create([
            'name' => 'Old Name',
            'retention_months' => 3,
            'applies_to_type' => 'global',
            'is_active' => true,
            'priority' => 1,
        ]);

        $response = $this->actingAs($this->admin)->put(route('biometric-retention-policies.update', $policy), [
            'name' => 'New Name',
            'retention_months' => 6,
            'applies_to_type' => 'global',
            'priority' => 2,
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('biometric_retention_policies', [
            'id' => $policy->id,
            'name' => 'New Name',
            'retention_months' => 6,
        ]);
    }

    #[Test]
    public function it_can_delete_retention_policy()
    {
        $policy = BiometricRetentionPolicy::create([
            'name' => 'To Delete',
            'retention_months' => 3,
            'applies_to_type' => 'global',
            'is_active' => true,
            'priority' => 1,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('biometric-retention-policies.destroy', $policy));

        $response->assertRedirect();
        $this->assertDatabaseMissing('biometric_retention_policies', [
            'id' => $policy->id,
        ]);
    }

    #[Test]
    public function it_can_toggle_retention_policy_status()
    {
        $policy = BiometricRetentionPolicy::create([
            'name' => 'Toggle Me',
            'retention_months' => 3,
            'applies_to_type' => 'global',
            'is_active' => true,
            'priority' => 1,
        ]);

        $response = $this->actingAs($this->admin)->post(route('biometric-retention-policies.toggle', $policy));

        $response->assertRedirect();
        $this->assertDatabaseHas('biometric_retention_policies', [
            'id' => $policy->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->admin)->post(route('biometric-retention-policies.toggle', $policy));
        $this->assertDatabaseHas('biometric_retention_policies', [
            'id' => $policy->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_validates_retention_policy_input()
    {
        $response = $this->actingAs($this->admin)->post(route('biometric-retention-policies.store'), [
            'name' => '', // Required
            'retention_months' => 0, // Min 1
            'applies_to_type' => 'invalid', // In global,site
        ]);

        $response->assertSessionHasErrors(['name', 'retention_months', 'applies_to_type']);
    }
}
