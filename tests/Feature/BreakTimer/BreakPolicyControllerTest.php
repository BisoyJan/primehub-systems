<?php

namespace Tests\Feature\BreakTimer;

use App\Models\BreakPolicy;
use App\Models\BreakSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BreakPolicyControllerTest extends TestCase
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

    // ─── Index ──────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_policies_index(): void
    {
        BreakPolicy::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('break-timer.policies.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Policies')
                ->has('policies', 3)
            );
    }

    #[Test]
    public function agent_cannot_view_policies_index(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_approved' => true]);

        $response = $this->actingAs($agent)
            ->get(route('break-timer.policies.index'));

        $response->assertForbidden();
    }

    // ─── Store ──────────────────────────────────────────────────────

    #[Test]
    public function admin_can_create_a_break_policy(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('break-timer.policies.store'), [
                'name' => 'Night Shift Policy',
                'max_breaks' => 3,
                'break_duration_minutes' => 15,
                'max_lunch' => 1,
                'lunch_duration_minutes' => 60,
                'grace_period_seconds' => 30,
                'is_active' => true,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('break_policies', [
            'name' => 'Night Shift Policy',
            'max_breaks' => 3,
            'break_duration_minutes' => 15,
        ]);
    }

    #[Test]
    public function store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('break-timer.policies.store'), []);

        $response->assertSessionHasErrors([
            'name',
            'max_breaks',
            'break_duration_minutes',
            'max_lunch',
            'lunch_duration_minutes',
            'grace_period_seconds',
        ]);
    }

    #[Test]
    public function store_validates_numeric_ranges(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('break-timer.policies.store'), [
                'name' => 'Bad Policy',
                'max_breaks' => 99,
                'break_duration_minutes' => 0,
                'max_lunch' => 99,
                'lunch_duration_minutes' => 999,
                'grace_period_seconds' => 9999,
            ]);

        $response->assertSessionHasErrors([
            'max_breaks',
            'break_duration_minutes',
            'max_lunch',
            'lunch_duration_minutes',
            'grace_period_seconds',
        ]);
    }

    // ─── Update ─────────────────────────────────────────────────────

    #[Test]
    public function admin_can_update_a_break_policy(): void
    {
        $policy = BreakPolicy::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($this->admin)
            ->put(route('break-timer.policies.update', $policy), [
                'name' => 'Updated Name',
                'max_breaks' => 2,
                'break_duration_minutes' => 20,
                'max_lunch' => 1,
                'lunch_duration_minutes' => 30,
                'grace_period_seconds' => 60,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('break_policies', [
            'id' => $policy->id,
            'name' => 'Updated Name',
            'break_duration_minutes' => 20,
        ]);
    }

    // ─── Destroy ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_delete_policy_with_no_sessions(): void
    {
        $policy = BreakPolicy::factory()->create();

        $response = $this->actingAs($this->admin)
            ->delete(route('break-timer.policies.destroy', $policy));

        $response->assertRedirect();
        $this->assertDatabaseMissing('break_policies', ['id' => $policy->id]);
    }

    #[Test]
    public function cannot_delete_policy_with_associated_sessions(): void
    {
        $policy = BreakPolicy::factory()->create();
        BreakSession::factory()->create(['break_policy_id' => $policy->id]);

        $response = $this->actingAs($this->admin)
            ->delete(route('break-timer.policies.destroy', $policy));

        $response->assertRedirect();
        $response->assertSessionHas('flash.type', 'error');
        $this->assertDatabaseHas('break_policies', ['id' => $policy->id]);
    }

    // ─── Toggle ─────────────────────────────────────────────────────

    #[Test]
    public function admin_can_toggle_policy_active_status(): void
    {
        $policy = BreakPolicy::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->admin)
            ->post(route('break-timer.policies.toggle', $policy));

        $response->assertRedirect();
        $this->assertDatabaseHas('break_policies', [
            'id' => $policy->id,
            'is_active' => false,
        ]);

        // Toggle back
        $this->actingAs($this->admin)
            ->post(route('break-timer.policies.toggle', $policy));

        $this->assertDatabaseHas('break_policies', [
            'id' => $policy->id,
            'is_active' => true,
        ]);
    }

    // ─── Unauthenticated Access ─────────────────────────────────────

    #[Test]
    public function unauthenticated_user_is_redirected_from_policies(): void
    {
        $response = $this->get(route('break-timer.policies.index'));
        $response->assertRedirect(route('login'));
    }

    // ─── Team Lead / Agent Cannot Manage ────────────────────────────

    #[Test]
    public function team_lead_cannot_create_policy(): void
    {
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);

        $response = $this->actingAs($tl)
            ->post(route('break-timer.policies.store'), [
                'name' => 'TL Policy',
                'max_breaks' => 2,
                'break_duration_minutes' => 15,
                'max_lunch' => 1,
                'lunch_duration_minutes' => 60,
                'grace_period_seconds' => 0,
            ]);

        // Middleware redirects unauthorized users (302), not 403
        $response->assertRedirect();
        $this->assertDatabaseMissing('break_policies', ['name' => 'TL Policy']);
    }

    #[Test]
    public function agent_cannot_delete_policy(): void
    {
        $policy = BreakPolicy::factory()->create();
        $agent = User::factory()->create(['role' => 'agent', 'is_approved' => true]);

        $response = $this->actingAs($agent)
            ->delete(route('break-timer.policies.destroy', $policy));

        $response->assertForbidden();
    }
}
