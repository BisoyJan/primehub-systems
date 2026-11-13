<?php

namespace Tests\Feature\BreakTimer;

use App\Models\BreakPolicy;
use App\Models\BreakRetentionPolicy;
use App\Models\BreakSession;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BreakRetentionPolicyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_approved' => true,
        ]);
    }

    #[Test]
    public function policies_page_includes_retention_data_for_admin(): void
    {
        $site = Site::factory()->create();
        BreakRetentionPolicy::factory()->create(['priority' => 1]);
        BreakRetentionPolicy::factory()->forSite($site->id)->create(['priority' => 50]);
        BreakSession::factory()->lunch()->create();

        $this->actingAs($this->admin)
            ->get(route('break-timer.policies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Policies')
                ->has('retentionPolicies', 2)
                ->where('retentionPolicies.0.priority', 50)
                ->where('retentionPolicies.0.site.id', $site->id)
                ->has('sites', 1)
                ->where('retentionStats.lunch.total', 1)
                ->has('retentionStats.lunch.byAge', 5)
                ->where('retentionStats.lunch.byAge.0.count', 1)
            );
    }

    #[Test]
    public function policies_page_only_sends_the_data_each_permission_allows(): void
    {
        config(['permissions.role_permissions.hr' => ['break_timer.retention']]);
        $retentionOnly = User::factory()->create(['role' => 'hr', 'is_approved' => true]);

        BreakRetentionPolicy::factory()->create();
        BreakPolicy::factory()->create();

        $this->actingAs($retentionOnly)
            ->get(route('break-timer.policies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('policies', 0)
                ->has('retentionPolicies', 1)
            );

        config(['permissions.role_permissions.hr' => ['break_timer.manage_policy']]);

        $this->actingAs($retentionOnly)
            ->get(route('break-timer.policies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('policies', 1)
                ->has('retentionPolicies', 0)
                ->where('retentionStats', null)
            );

        $this->actingAs($retentionOnly)
            ->post(route('break-timer.retention-policies.store'), [
                'name' => 'Blocked',
                'retention_months' => 6,
                'applies_to_type' => 'global',
            ]);

        $this->assertDatabaseMissing('break_retention_policies', ['name' => 'Blocked']);
    }

    #[Test]
    public function store_creates_a_global_policy_with_defaults(): void
    {
        $this->actingAs($this->admin)
            ->post(route('break-timer.retention-policies.store'), [
                'name' => 'Global Policy',
                'retention_months' => 24,
                'applies_to_type' => 'global',
            ])
            ->assertRedirect()
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseHas('break_retention_policies', [
            'name' => 'Global Policy',
            'retention_months' => 24,
            'applies_to_type' => 'global',
            'applies_to_id' => null,
            'break_type' => 'all',
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function store_creates_a_site_and_break_type_specific_policy(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('break-timer.retention-policies.store'), [
                'name' => 'Site Lunch Policy',
                'description' => 'Keep lunch sessions longer',
                'retention_months' => 18,
                'applies_to_type' => 'site',
                'applies_to_id' => $site->id,
                'break_type' => 'lunch',
                'priority' => 5,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('break_retention_policies', [
            'name' => 'Site Lunch Policy',
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'break_type' => 'lunch',
            'priority' => 5,
        ]);
    }

    #[Test]
    public function store_rejects_invalid_input(): void
    {
        $this->actingAs($this->admin)
            ->post(route('break-timer.retention-policies.store'), [])
            ->assertSessionHasErrors(['name', 'retention_months', 'applies_to_type']);

        $this->actingAs($this->admin)
            ->post(route('break-timer.retention-policies.store'), [
                'name' => 'Invalid',
                'retention_months' => 150,
                'applies_to_type' => 'site',
                'applies_to_id' => null,
                'break_type' => '1st_break',
            ])
            ->assertSessionHasErrors(['retention_months', 'applies_to_id', 'break_type']);

        $this->assertDatabaseCount('break_retention_policies', 0);
    }

    #[Test]
    public function update_modifies_a_policy_and_clears_the_site_when_made_global(): void
    {
        $site = Site::factory()->create();
        $policy = BreakRetentionPolicy::factory()->forSite($site->id)->create([
            'name' => 'Old Name',
            'retention_months' => 12,
        ]);

        $this->actingAs($this->admin)
            ->put(route('break-timer.retention-policies.update', $policy), [
                'name' => 'Updated Name',
                'retention_months' => 18,
                'applies_to_type' => 'global',
                'applies_to_id' => $site->id,
                'break_type' => 'combined',
                'priority' => 15,
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('break_retention_policies', [
            'id' => $policy->id,
            'name' => 'Updated Name',
            'retention_months' => 18,
            'applies_to_type' => 'global',
            'applies_to_id' => null,
            'break_type' => 'combined',
            'priority' => 15,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function destroy_deletes_a_policy(): void
    {
        $policy = BreakRetentionPolicy::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('break-timer.retention-policies.destroy', $policy))
            ->assertRedirect();

        $this->assertDatabaseMissing('break_retention_policies', ['id' => $policy->id]);
    }

    #[Test]
    public function toggle_flips_the_active_status(): void
    {
        $policy = BreakRetentionPolicy::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('break-timer.retention-policies.toggle', $policy))
            ->assertRedirect();

        $this->assertFalse($policy->refresh()->is_active);

        $this->actingAs($this->admin)
            ->post(route('break-timer.retention-policies.toggle', $policy));

        $this->assertTrue($policy->refresh()->is_active);
    }

    #[Test]
    public function preview_counts_the_sessions_a_policy_would_delete(): void
    {
        $policy = BreakRetentionPolicy::factory()->create([
            'retention_months' => 3,
            'break_type' => 'all',
        ]);

        $oldDate = now()->subMonths(4)->toDateString();
        BreakSession::factory()->lunch()->create(['shift_date' => $oldDate]);
        BreakSession::factory()->create(['type' => '1st_break', 'shift_date' => $oldDate]);
        BreakSession::factory()->active()->create(['type' => '2nd_break', 'shift_date' => $oldDate]);
        BreakSession::factory()->lunch()->create(['shift_date' => now()->subMonth()->toDateString()]);

        $this->actingAs($this->admin)
            ->getJson(route('break-timer.retention-policies.preview', $policy))
            ->assertOk()
            ->assertJson([
                'cutoff_date' => now()->subMonths(3)->toDateString(),
                'total_affected' => 2,
                'preview' => [
                    ['break_type' => 'break', 'count' => 1, 'oldest_date' => $oldDate],
                    ['break_type' => 'lunch', 'count' => 1],
                    ['break_type' => 'combined', 'count' => 0, 'oldest_date' => null],
                ],
            ]);
    }

    #[Test]
    public function preview_of_a_site_policy_only_counts_that_sites_sessions(): void
    {
        $site = Site::factory()->create();
        $siteUser = User::factory()->create();
        EmployeeSchedule::factory()->create(['user_id' => $siteUser->id, 'site_id' => $site->id]);

        $policy = BreakRetentionPolicy::factory()->forSite($site->id)->forBreakType('lunch')->create([
            'retention_months' => 3,
        ]);

        $oldDate = now()->subMonths(4)->toDateString();
        BreakSession::factory()->lunch()->create(['user_id' => $siteUser->id, 'shift_date' => $oldDate]);
        BreakSession::factory()->lunch()->create(['shift_date' => $oldDate]);

        $this->actingAs($this->admin)
            ->getJson(route('break-timer.retention-policies.preview', $policy))
            ->assertOk()
            ->assertJsonCount(1, 'preview')
            ->assertJson(['total_affected' => 1]);
    }

    #[Test]
    public function agent_cannot_manage_retention_policies(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_approved' => true]);
        $policy = BreakRetentionPolicy::factory()->create();

        $this->actingAs($agent)
            ->delete(route('break-timer.retention-policies.destroy', $policy))
            ->assertForbidden();

        $this->actingAs($agent)
            ->getJson(route('break-timer.retention-policies.preview', $policy))
            ->assertForbidden();

        $this->assertDatabaseHas('break_retention_policies', ['id' => $policy->id]);
    }
}
