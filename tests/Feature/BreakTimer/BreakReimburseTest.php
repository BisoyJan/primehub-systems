<?php

namespace Tests\Feature\BreakTimer;

use App\Models\BreakEvent;
use App\Models\BreakPolicy;
use App\Models\BreakSession;
use App\Models\User;
use App\Services\BreakTimerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BreakReimburseTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $agent;

    protected BreakPolicy $policy;

    protected BreakTimerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::today()->setTime(10, 0, 0));

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_approved' => true,
        ]);

        $this->agent = User::factory()->create([
            'role' => 'agent',
            'is_approved' => true,
        ]);

        $this->policy = BreakPolicy::factory()->create([
            'is_active' => true,
            'max_breaks' => 2,
            'break_duration_minutes' => 15,
        ]);

        $this->service = app(BreakTimerService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_extends_remaining_and_pauses_active_session(): void
    {
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
            'duration_seconds' => 900,
            'remaining_seconds' => 300,
            'overage_seconds' => 0,
            'total_paused_seconds' => 0,
            'started_at' => Carbon::now()->subSeconds(600),
        ]);

        $updated = $this->service->reimburseMinutes($session, 5, $this->admin->id, $this->admin->name, 'Pulled to call');

        // live elapsed = 600s → live remaining = 300; +5min reimbursed = 600s remaining
        $this->assertSame(600, $updated->remaining_seconds);
        $this->assertSame(900 + 300, $updated->duration_seconds);
        $this->assertSame(300, $updated->reimbursed_seconds);
        $this->assertSame('paused', $updated->status);
        $this->assertDatabaseHas('break_events', [
            'break_session_id' => $session->id,
            'action' => 'reimburse',
        ]);
    }

    #[Test]
    public function it_reduces_overage_then_spills_and_pauses(): void
    {
        $session = BreakSession::factory()->overage()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
            'duration_seconds' => 900,
            'remaining_seconds' => 0,
            'overage_seconds' => 120,
            'status' => 'overage',
        ]);

        $updated = $this->service->reimburseMinutes($session, 3, $this->admin->id, $this->admin->name, 'Forgot to pause');

        $this->assertSame(0, $updated->overage_seconds);
        $this->assertSame(180 - 120, $updated->remaining_seconds);
        $this->assertSame(180, $updated->reimbursed_seconds);
        $this->assertSame('paused', $updated->status);
        $this->assertNull($updated->ended_at);
    }

    #[Test]
    public function it_rejects_reimbursing_more_than_consumed(): void
    {
        // Active session: 900s duration, 120s consumed (started 120s ago), live remaining 780s.
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
            'duration_seconds' => 900,
            'remaining_seconds' => 780,
            'overage_seconds' => 0,
            'total_paused_seconds' => 0,
            'started_at' => Carbon::now()->subSeconds(120),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot reimburse more than the consumed time');
        // consumed = 120s (2 min). Asking 5 → should fail.
        $this->service->reimburseMinutes($session, 5, $this->admin->id, $this->admin->name, 'too much');
    }

    #[Test]
    public function it_rejects_reset_sessions(): void
    {
        $session = BreakSession::factory()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
            'status' => 'reset',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->reimburseMinutes($session, 5, $this->admin->id, $this->admin->name, 'reason');
    }

    #[Test]
    public function it_requires_permission_via_http(): void
    {
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
        ]);

        $response = $this->actingAs($this->agent)
            ->post("/break-timer/{$session->id}/reimburse", [
                'minutes' => 5,
                'reason' => 'test reason',
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function it_validates_minutes_range(): void
    {
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('break-timer.dashboard'))
            ->post("/break-timer/{$session->id}/reimburse", [
                'minutes' => 0,
                'reason' => 'test reason',
            ]);

        $response->assertSessionHasErrors('minutes');
    }

    #[Test]
    public function ending_an_active_session_after_reimburse_preserves_the_bumped_duration(): void
    {
        // Agent started 20 min ago on a 15-min break → 5 min overage right now.
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
            'duration_seconds' => 900,
            'remaining_seconds' => 0,
            'overage_seconds' => 0,
            'total_paused_seconds' => 0,
            'started_at' => Carbon::now()->subSeconds(1200),
        ]);

        // Admin reimburses 5 min → duration bumped to 20 min, session lands paused.
        $this->service->reimburseMinutes($session, 5, $this->admin->id, $this->admin->name, 'Pulled to call');

        // Agent immediately resumes and ends the session.
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $session->refresh();
        $this->service->resumeSession($session);
        $this->service->endSession($session->refresh());

        $session->refresh();

        // 1230s wall-clock since start, 30s paused for reimburse → 1200s consumed,
        // duration bumped to 1200s → zero overage. The reimbursement must stick.
        $this->assertSame('completed', $session->status);
        $this->assertSame(0, $session->overage_seconds);
    }

    #[Test]
    public function ending_a_reopened_overage_session_after_reimburse_does_not_revert_to_original_overage(): void
    {
        // Original overage scenario: started 20 min ago, ended 5 min ago with 5 min overage.
        $startedAt = Carbon::now()->subSeconds(1200);
        $endedAt = Carbon::now()->subSeconds(300);

        $session = BreakSession::factory()->overage()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
            'duration_seconds' => 900,
            'remaining_seconds' => 0,
            'overage_seconds' => 300,
            'total_paused_seconds' => 0,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'status' => 'overage',
        ]);

        // Admin reimburses 5 min — clears overage, reopens, lands paused.
        $this->service->reimburseMinutes($session, 5, $this->admin->id, $this->admin->name, 'Forgot to pause');

        // Agent resumes and ends right away (no extra consumption).
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $session->refresh();
        $this->service->resumeSession($session);
        $this->service->endSession($session->refresh());

        $session->refresh();

        // The bug previously regressed this to ~5 min of overage. After the fix,
        // the offline gap is treated as paused, so the reimbursement holds.
        $this->assertSame('completed', $session->status);
        $this->assertSame(0, $session->overage_seconds);
    }

    #[Test]
    public function rewind_to_an_end_event_removes_subsequent_reimburses_and_restores_overage(): void
    {
        // Reconstruct the scenario from the dashboard screenshot: agent ended
        // with overage, then admin reimbursed several times. Admin now wants to
        // undo everything back to the original end.
        $startedAt = Carbon::now()->subSeconds(3600);
        $session = BreakSession::factory()->overage()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
            'duration_seconds' => 900,
            'remaining_seconds' => 0,
            'overage_seconds' => 1800,
            'reimbursed_seconds' => 0,
            'started_at' => $startedAt,
            'ended_at' => Carbon::now()->subSeconds(1800),
            'status' => 'overage',
        ]);

        BreakEvent::create([
            'break_session_id' => $session->id,
            'action' => 'start',
            'remaining_seconds' => 900,
            'overage_seconds' => 0,
            'occurred_at' => $startedAt,
        ]);
        $endEvent = BreakEvent::create([
            'break_session_id' => $session->id,
            'action' => 'end',
            'remaining_seconds' => 0,
            'overage_seconds' => 1800,
            'occurred_at' => Carbon::now()->subSeconds(1800),
        ]);

        // Two reimburses on top of the ended session.
        Carbon::setTestNow(Carbon::now()->addSeconds(60));
        $this->service->reimburseMinutes($session->refresh(), 5, $this->admin->id, $this->admin->name, 'typo');
        Carbon::setTestNow(Carbon::now()->addSeconds(60));
        $this->service->reimburseMinutes($session->refresh(), 3, $this->admin->id, $this->admin->name, 'another typo');

        $session->refresh();
        $this->assertSame(480, $session->reimbursed_seconds);
        $this->assertSame(900 + 480, $session->duration_seconds);

        // Rewind from the original 'end' event — should undo every event at or after it.
        $this->service->rewindToEvent($session, $endEvent, $this->admin->id, $this->admin->name, 'cleaning up');

        $session->refresh();

        // Duration is back to the original; reimbursed bookkeeping is zeroed.
        $this->assertSame(900, $session->duration_seconds);
        $this->assertSame(0, $session->reimbursed_seconds);

        // We rewound TO the end event (deleting it), so the predecessor is 'start'
        // → session is back to active with the original remaining time.
        $this->assertSame('active', $session->status);
        $this->assertSame(900, $session->remaining_seconds);
        $this->assertSame(0, $session->overage_seconds);
        $this->assertNull($session->ended_at);
        $this->assertNull($session->ended_by);

        // The rewound events are gone; an audit 'restore' event was written.
        $this->assertDatabaseMissing('break_events', ['id' => $endEvent->id]);
        $this->assertDatabaseHas('break_events', [
            'break_session_id' => $session->id,
            'action' => 'restore',
        ]);
    }

    #[Test]
    public function rewind_to_a_single_reimburse_keeps_earlier_events_intact(): void
    {
        // Active session, two reimburses applied. Admin undoes only the second one.
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
            'duration_seconds' => 900,
            'remaining_seconds' => 300,
            'overage_seconds' => 0,
            'reimbursed_seconds' => 0,
            'total_paused_seconds' => 0,
            'started_at' => Carbon::now()->subSeconds(600),
        ]);

        BreakEvent::create([
            'break_session_id' => $session->id,
            'action' => 'start',
            'remaining_seconds' => 900,
            'overage_seconds' => 0,
            'occurred_at' => Carbon::now()->subSeconds(600),
        ]);

        // First reimburse stays.
        $this->service->reimburseMinutes($session->refresh(), 5, $this->admin->id, $this->admin->name, 'real');
        Carbon::setTestNow(Carbon::now()->addSeconds(60));
        // Second reimburse will be undone.
        $second = $this->service->reimburseMinutes($session->refresh(), 3, $this->admin->id, $this->admin->name, 'typo');

        $secondReimburseEvent = BreakEvent::query()
            ->where('break_session_id', $second->id)
            ->where('action', 'reimburse')
            ->latest('id')
            ->first();

        $this->service->rewindToEvent($session->refresh(), $secondReimburseEvent, $this->admin->id, $this->admin->name, 'undo last');

        $session->refresh();

        // First reimburse minutes remain accounted for; second one is reversed.
        $this->assertSame(300, $session->reimbursed_seconds);
        $this->assertSame(900 + 300, $session->duration_seconds);

        // First reimburse event still present.
        $this->assertDatabaseHas('break_events', [
            'break_session_id' => $session->id,
            'action' => 'reimburse',
            'reason' => "Reimbursed 5 min by {$this->admin->name} (#{$this->admin->id}): real",
        ]);
    }

    #[Test]
    public function rewind_rejects_non_rewindable_event_actions(): void
    {
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
        ]);

        $startEvent = BreakEvent::create([
            'break_session_id' => $session->id,
            'action' => 'start',
            'remaining_seconds' => 900,
            'overage_seconds' => 0,
            'occurred_at' => Carbon::now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->rewindToEvent($session, $startEvent, $this->admin->id, $this->admin->name, 'nope');
    }

    #[Test]
    public function rewind_http_route_requires_restore_permission(): void
    {
        $session = BreakSession::factory()->overage()->create([
            'user_id' => $this->agent->id,
            'break_policy_id' => $this->policy->id,
            'overage_seconds' => 60,
        ]);
        $event = BreakEvent::create([
            'break_session_id' => $session->id,
            'action' => 'end',
            'remaining_seconds' => 0,
            'overage_seconds' => 60,
            'occurred_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->agent)
            ->post("/break-timer/{$session->id}/timeline/{$event->id}/rewind", [
                'reason' => 'should be blocked',
            ]);

        $response->assertForbidden();
    }
}
