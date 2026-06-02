<?php

namespace Tests\Feature\BreakTimer;

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
}
