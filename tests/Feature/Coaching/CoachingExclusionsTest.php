<?php

namespace Tests\Feature\Coaching;

use App\Models\CoachingExclusion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CoachingExclusionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $this->agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_excludes_a_user_from_coaching(): void
    {
        $response = $this->actingAs($this->admin)->post('/coaching/exclusions', [
            'user_id' => $this->agent->id,
            'reason' => CoachingExclusion::REASON_NEW_HIRE,
            'notes' => 'Started this week',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('coaching_exclusions', [
            'user_id' => $this->agent->id,
            'reason' => CoachingExclusion::REASON_NEW_HIRE,
            'excluded_by' => $this->admin->id,
            'revoked_at' => null,
        ]);
        $this->assertTrue($this->agent->fresh()->isCoachingExcluded());
    }

    #[Test]
    public function user_scope_filters_excluded_users(): void
    {
        CoachingExclusion::create([
            'user_id' => $this->agent->id,
            'reason' => CoachingExclusion::REASON_LONG_LEAVE,
            'excluded_by' => $this->admin->id,
            'excluded_at' => now(),
        ]);

        $included = User::where('role', 'Agent')->notCoachingExcluded()->pluck('id');
        $this->assertNotContains($this->agent->id, $included);

        $excluded = User::where('role', 'Agent')->coachingExcluded()->pluck('id');
        $this->assertContains($this->agent->id, $excluded);
    }

    #[Test]
    public function expired_exclusion_auto_restores_eligibility(): void
    {
        CoachingExclusion::create([
            'user_id' => $this->agent->id,
            'reason' => CoachingExclusion::REASON_OTHER,
            'excluded_by' => $this->admin->id,
            'excluded_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($this->agent->fresh()->isCoachingExcluded());
    }

    #[Test]
    public function revoking_restores_eligibility(): void
    {
        $exclusion = CoachingExclusion::create([
            'user_id' => $this->agent->id,
            'reason' => CoachingExclusion::REASON_OTHER,
            'excluded_by' => $this->admin->id,
            'excluded_at' => now(),
        ]);

        $this->assertTrue($this->agent->fresh()->isCoachingExcluded());

        $response = $this->actingAs($this->admin)->delete(
            "/coaching/exclusions/users/{$this->agent->id}",
            ['revoke_notes' => 'Back to normal cadence']
        );

        $response->assertRedirect();
        $this->assertNotNull($exclusion->fresh()->revoked_at);
        $this->assertFalse($this->agent->fresh()->isCoachingExcluded());
    }

    #[Test]
    public function bulk_exclusion_excludes_multiple_users(): void
    {
        $second = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post('/coaching/exclusions/bulk', [
            'user_ids' => [$this->agent->id, $second->id],
            'reason' => CoachingExclusion::REASON_ON_PIP,
        ]);

        $response->assertRedirect();
        $this->assertTrue($this->agent->fresh()->isCoachingExcluded());
        $this->assertTrue($second->fresh()->isCoachingExcluded());
    }

    #[Test]
    public function non_admin_cannot_access_exclusions_index(): void
    {
        $other = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($other)->get('/coaching/exclusions');
        // Permission middleware redirects unauthenticated/unauthorized users
        $this->assertContains($response->status(), [302, 403]);
    }

    #[Test]
    public function it_lists_users_on_index_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/coaching/exclusions');
        $response->assertOk();
    }
}
