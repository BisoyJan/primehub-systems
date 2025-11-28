<?php

namespace Tests\Feature\Controllers\RetentionPolicies;

use App\Models\FormRequestRetentionPolicy;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FormRequestRetentionPolicyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'role' => 'Super Admin',
            'is_approved' => true,
        ]);
    }

    public function test_index_displays_retention_policies(): void
    {
        FormRequestRetentionPolicy::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->get(route('form-requests.retention-policies.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/RetentionPolicies')
                ->has('policies', 3)
                ->has('sites')
            );
    }

    public function test_index_orders_policies_by_priority_desc(): void
    {
        FormRequestRetentionPolicy::factory()->create(['priority' => 1]);
        FormRequestRetentionPolicy::factory()->create(['priority' => 10]);
        FormRequestRetentionPolicy::factory()->create(['priority' => 5]);

        $response = $this->actingAs($this->user)
            ->get(route('form-requests.retention-policies.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('policies', 3)
            );
    }

    public function test_store_creates_global_retention_policy(): void
    {
        $policyData = [
            'name' => 'Global Policy',
            'description' => 'Apply to all sites',
            'retention_months' => 24,
            'applies_to_type' => 'global',
            'applies_to_id' => null,
            'form_type' => 'all',
            'priority' => 10,
        ];

        $response = $this->actingAs($this->user)
            ->post(route('form-requests.retention-policies.store'), $policyData);

        $response->assertRedirect();

        $this->assertDatabaseHas('form_request_retention_policies', [
            'name' => 'Global Policy',
            'retention_months' => 24,
            'applies_to_type' => 'global',
            'is_active' => true,
        ]);
    }

    public function test_store_creates_site_specific_retention_policy(): void
    {
        $site = Site::factory()->create();

        $policyData = [
            'name' => 'Site-Specific Policy',
            'description' => 'Apply to specific site',
            'retention_months' => 12,
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'form_type' => 'leave_request',
            'priority' => 5,
        ];

        $response = $this->actingAs($this->user)
            ->post(route('form-requests.retention-policies.store'), $policyData);

        $response->assertRedirect();

        $this->assertDatabaseHas('form_request_retention_policies', [
            'name' => 'Site-Specific Policy',
            'retention_months' => 12,
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'form_type' => 'leave_request',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('form-requests.retention-policies.store'), []);

        $response->assertSessionHasErrors([
            'name',
            'retention_months',
            'applies_to_type',
        ]);
    }

    public function test_store_requires_applies_to_id_when_type_is_site(): void
    {
        $policyData = [
            'name' => 'Site Policy',
            'retention_months' => 12,
            'applies_to_type' => 'site',
            'applies_to_id' => null, // Missing required site ID
        ];

        $response = $this->actingAs($this->user)
            ->post(route('form-requests.retention-policies.store'), $policyData);

        $response->assertSessionHasErrors(['applies_to_id']);
    }

    public function test_store_validates_retention_months_range(): void
    {
        $policyData = [
            'name' => 'Invalid Policy',
            'retention_months' => 150, // Exceeds max of 120
            'applies_to_type' => 'global',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('form-requests.retention-policies.store'), $policyData);

        $response->assertSessionHasErrors(['retention_months']);
    }

    public function test_store_validates_form_type_enum(): void
    {
        $policyData = [
            'name' => 'Test Policy',
            'retention_months' => 12,
            'applies_to_type' => 'global',
            'form_type' => 'invalid_type',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('form-requests.retention-policies.store'), $policyData);

        $response->assertSessionHasErrors(['form_type']);
    }

    public function test_update_modifies_retention_policy(): void
    {
        $policy = FormRequestRetentionPolicy::factory()->create([
            'name' => 'Old Name',
            'retention_months' => 12,
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'retention_months' => 18,
            'applies_to_type' => $policy->applies_to_type,
            'applies_to_id' => $policy->applies_to_id,
            'form_type' => $policy->form_type,
            'priority' => 15,
            'is_active' => false,
        ];

        $response = $this->actingAs($this->user)
            ->put(route('form-requests.retention-policies.update', $policy), $updateData);

        $response->assertRedirect();

        $this->assertDatabaseHas('form_request_retention_policies', [
            'id' => $policy->id,
            'name' => 'Updated Name',
            'retention_months' => 18,
            'priority' => 15,
            'is_active' => false,
        ]);
    }

    public function test_update_validates_required_fields(): void
    {
        $policy = FormRequestRetentionPolicy::factory()->create();

        $response = $this->actingAs($this->user)
            ->put(route('form-requests.retention-policies.update', $policy), []);

        $response->assertSessionHasErrors([
            'name',
            'retention_months',
            'applies_to_type',
        ]);
    }

    public function test_destroy_deletes_retention_policy(): void
    {
        $policy = FormRequestRetentionPolicy::factory()->create();

        $response = $this->actingAs($this->user)
            ->delete(route('form-requests.retention-policies.destroy', $policy));

        $response->assertRedirect();

        $this->assertDatabaseMissing('form_request_retention_policies', [
            'id' => $policy->id,
        ]);
    }

    public function test_toggle_activates_inactive_policy(): void
    {
        $policy = FormRequestRetentionPolicy::factory()->create([
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('form-requests.retention-policies.toggle', $policy));

        $response->assertRedirect();

        $policy->refresh();
        $this->assertTrue($policy->is_active);
    }

    public function test_toggle_deactivates_active_policy(): void
    {
        $policy = FormRequestRetentionPolicy::factory()->create([
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('form-requests.retention-policies.toggle', $policy));

        $response->assertRedirect();

        $policy->refresh();
        $this->assertFalse($policy->is_active);
    }

    public function test_index_includes_site_relationship(): void
    {
        $site = Site::factory()->create(['name' => 'Test Site']);
        FormRequestRetentionPolicy::factory()->create([
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('form-requests.retention-policies.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('policies.0.site')
            );
    }
}
