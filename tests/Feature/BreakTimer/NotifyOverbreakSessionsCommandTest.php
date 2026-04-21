<?php

namespace Tests\Feature\BreakTimer;

use App\Models\BreakPolicy;
use App\Models\BreakSession;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotifyOverbreakSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Notify admins once for each active session that is currently overbreak.
     */
    #[Test]
    public function it_notifies_admins_once_for_active_overbreak_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21 10:00:00'));

        $policy = BreakPolicy::factory()->create(['is_active' => true]);

        User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        User::factory()->create([
            'role' => 'Super Admin',
            'is_approved' => true,
        ]);

        $agent = User::factory()->create([
            'role' => 'agent',
            'is_approved' => true,
        ]);

        $session = BreakSession::factory()->active()->create([
            'user_id' => $agent->id,
            'break_policy_id' => $policy->id,
            'shift_date' => now()->toDateString(),
            'type' => '1st_break',
            'started_at' => now()->subMinutes(20),
            'duration_seconds' => 900,
            'remaining_seconds' => 900,
            'overbreak_notified_at' => null,
        ]);

        $this->artisan('break-timer:notify-overbreaks')
            ->expectsOutput('Notified admins for 1 overbreak session(s).')
            ->assertSuccessful();

        $session->refresh();

        $this->assertNotNull($session->overbreak_notified_at);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(2, Notification::query()->where('type', 'break_overage')->count());

        $this->artisan('break-timer:notify-overbreaks')
            ->expectsOutput('Notified admins for 0 overbreak session(s).')
            ->assertSuccessful();

        $this->assertSame(2, Notification::query()->where('type', 'break_overage')->count());
    }
}
