<?php

namespace Tests\Feature\BreakTimer;

use App\Models\BreakEvent;
use App\Models\BreakPolicy;
use App\Models\BreakSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanOldBreakSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected BreakPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'admin',
            'is_approved' => true,
        ]);

        $this->policy = BreakPolicy::factory()->create([
            'is_active' => true,
            'retention_months' => 3,
        ]);
    }

    #[Test]
    public function it_skips_cleanup_when_no_active_policy(): void
    {
        $this->policy->update(['is_active' => false]);

        $this->artisan('break-timer:clean-old-sessions --force')
            ->expectsOutput('No active break policy found. Skipping cleanup.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_skips_cleanup_when_retention_months_is_null(): void
    {
        $this->policy->update(['retention_months' => null]);

        $this->artisan('break-timer:clean-old-sessions --force')
            ->expectsOutput('Active policy has no retention period configured. Skipping cleanup.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_deletes_sessions_older_than_retention_period(): void
    {
        // Old session (4 months ago — should be deleted with 3-month retention)
        $oldSession = BreakSession::factory()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->subMonths(4)->toDateString(),
        ]);
        BreakEvent::factory()->create(['break_session_id' => $oldSession->id]);

        // Recent session (1 month ago — should be kept)
        $recentSession = BreakSession::factory()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->subMonth()->toDateString(),
        ]);
        BreakEvent::factory()->create(['break_session_id' => $recentSession->id]);

        $this->artisan('break-timer:clean-old-sessions --force')
            ->assertSuccessful();

        $this->assertDatabaseMissing('break_sessions', ['id' => $oldSession->id]);
        $this->assertDatabaseHas('break_sessions', ['id' => $recentSession->id]);
    }

    #[Test]
    public function it_deletes_related_break_events(): void
    {
        $oldSession = BreakSession::factory()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->subMonths(4)->toDateString(),
        ]);
        $event = BreakEvent::factory()->create(['break_session_id' => $oldSession->id]);

        $this->artisan('break-timer:clean-old-sessions --force')
            ->assertSuccessful();

        $this->assertDatabaseMissing('break_events', ['id' => $event->id]);
    }

    #[Test]
    public function it_uses_manual_months_override(): void
    {
        // Session 2 months old
        $session = BreakSession::factory()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->subMonths(2)->toDateString(),
        ]);

        // Override to 1 month — should delete the 2-month-old session
        $this->artisan('break-timer:clean-old-sessions --force --months=1')
            ->assertSuccessful();

        $this->assertDatabaseMissing('break_sessions', ['id' => $session->id]);
    }

    #[Test]
    public function it_does_nothing_when_no_old_sessions_exist(): void
    {
        BreakSession::factory()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        $this->artisan('break-timer:clean-old-sessions --force')
            ->expectsOutput('No old break sessions found to delete.')
            ->assertSuccessful();
    }
}
