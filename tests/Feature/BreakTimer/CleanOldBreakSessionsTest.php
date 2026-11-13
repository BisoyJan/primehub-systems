<?php

namespace Tests\Feature\BreakTimer;

use App\Models\BreakEvent;
use App\Models\BreakPolicy;
use App\Models\BreakRetentionPolicy;
use App\Models\BreakSession;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanOldBreakSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected BreakPolicy $policy;

    protected BreakRetentionPolicy $retentionPolicy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'admin',
            'is_approved' => true,
        ]);

        $this->policy = BreakPolicy::factory()->create([
            'is_active' => true,
        ]);

        $this->retentionPolicy = BreakRetentionPolicy::factory()->create([
            'retention_months' => 3,
        ]);
    }

    #[Test]
    public function it_defaults_to_twelve_months_when_no_retention_policy_is_active(): void
    {
        $this->retentionPolicy->update(['is_active' => false]);

        $expired = $this->sessionAgedMonths(13);
        $kept = $this->sessionAgedMonths(11);

        $this->artisan('break-timer:clean-old-sessions --force')
            ->assertSuccessful();

        $this->assertDatabaseMissing('break_sessions', ['id' => $expired->id]);
        $this->assertDatabaseHas('break_sessions', ['id' => $kept->id]);
    }

    #[Test]
    public function site_policy_overrides_the_global_policy(): void
    {
        $site = Site::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $this->user->id,
            'site_id' => $site->id,
        ]);
        BreakRetentionPolicy::factory()->forSite($site->id)->create(['retention_months' => 6]);

        $siteSession = $this->sessionAgedMonths(4);
        $otherSession = $this->sessionAgedMonths(4, ['user_id' => User::factory()->create()->id]);

        $this->artisan('break-timer:clean-old-sessions --force')
            ->assertSuccessful();

        $this->assertDatabaseHas('break_sessions', ['id' => $siteSession->id]);
        $this->assertDatabaseMissing('break_sessions', ['id' => $otherSession->id]);
    }

    #[Test]
    public function break_type_policy_overrides_the_all_types_policy(): void
    {
        BreakRetentionPolicy::factory()->forBreakType('lunch')->create(['retention_months' => 6]);

        $lunch = $this->sessionAgedMonths(4, ['type' => 'lunch']);
        $break = $this->sessionAgedMonths(4, ['type' => '1st_break']);
        $combinedBreak = $this->sessionAgedMonths(4, ['type' => 'combined_break', 'combined_break_count' => 2]);

        $this->artisan('break-timer:clean-old-sessions --force')
            ->assertSuccessful();

        $this->assertDatabaseHas('break_sessions', ['id' => $lunch->id]);
        $this->assertDatabaseMissing('break_sessions', ['id' => $break->id]);
        $this->assertDatabaseMissing('break_sessions', ['id' => $combinedBreak->id]);
    }

    #[Test]
    public function it_never_deletes_active_or_paused_sessions(): void
    {
        $active = BreakSession::factory()->active()->create([
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->subMonths(4)->toDateString(),
        ]);
        $paused = BreakSession::factory()->paused()->create([
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->subMonths(4)->toDateString(),
        ]);

        $this->artisan('break-timer:clean-old-sessions --force')
            ->assertSuccessful();

        $this->assertDatabaseHas('break_sessions', ['id' => $active->id]);
        $this->assertDatabaseHas('break_sessions', ['id' => $paused->id]);
    }

    #[Test]
    public function dry_run_reports_without_deleting(): void
    {
        $session = $this->sessionAgedMonths(4);
        $event = BreakEvent::factory()->create(['break_session_id' => $session->id]);

        $this->artisan('break-timer:clean-old-sessions --force --dry-run')
            ->expectsOutput('Cleanup complete. Total sessions would be deleted: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('break_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('break_events', ['id' => $event->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function sessionAgedMonths(int $months, array $attributes = []): BreakSession
    {
        return BreakSession::factory()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->subMonths($months)->toDateString(),
            ...$attributes,
        ]);
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
