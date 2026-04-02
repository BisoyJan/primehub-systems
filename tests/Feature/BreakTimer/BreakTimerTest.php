<?php

namespace Tests\Feature\BreakTimer;

use App\Models\BreakEvent;
use App\Models\BreakPolicy;
use App\Models\BreakSession;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\BreakTimerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BreakTimerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected BreakPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time to 10:00 AM so getShiftDate() returns today's calendar date
        Carbon::setTestNow(Carbon::today()->setTime(10, 0, 0));

        $this->user = User::factory()->create([
            'role' => 'admin',
            'is_approved' => true,
        ]);

        $this->policy = BreakPolicy::factory()->create([
            'is_active' => true,
            'max_breaks' => 2,
            'break_duration_minutes' => 15,
            'max_lunch' => 1,
            'lunch_duration_minutes' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_displays_break_timer_index_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('break-timer.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreakTimer/Index')
                ->has('policy')
                ->has('todaySessions')
                ->has('breaksUsed')
                ->has('lunchUsed')
            );
    }

    #[Test]
    public function it_can_start_a_break(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('break-timer.start'), [
                'type' => '1st_break',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('break_sessions', [
            'user_id' => $this->user->id,
            'type' => '1st_break',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_auto_determines_break_number(): void
    {
        // Create a completed 1st break
        BreakSession::factory()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'type' => '1st_break',
            'status' => 'completed',
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.start'), [
                'type' => '2nd_break',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('break_sessions', [
            'user_id' => $this->user->id,
            'type' => '2nd_break',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_prevents_starting_when_max_breaks_reached(): void
    {
        BreakSession::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'type' => '1st_break',
            'status' => 'completed',
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.start'), [
                'type' => '1st_break',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash.type', 'error');
    }

    #[Test]
    public function it_can_start_lunch(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('break-timer.start'), [
                'type' => 'lunch',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('break_sessions', [
            'user_id' => $this->user->id,
            'type' => 'lunch',
            'status' => 'active',
            'duration_seconds' => 3600,
        ]);
    }

    #[Test]
    public function it_prevents_duplicate_lunch(): void
    {
        BreakSession::factory()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'type' => 'lunch',
            'status' => 'completed',
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.start'), [
                'type' => 'lunch',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash.type', 'error');
    }

    #[Test]
    public function it_prevents_starting_while_active_session_exists(): void
    {
        BreakSession::factory()->active()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'type' => '1st_break',
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.start'), [
                'type' => '1st_break',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash.type', 'error');
    }

    #[Test]
    public function it_can_pause_active_session(): void
    {
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.pause', $session), [
                'reason' => 'Coaching',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash.type', 'success');

        $session->refresh();
        $this->assertEquals('paused', $session->status);
        $this->assertEquals('Coaching', $session->last_pause_reason);
    }

    #[Test]
    public function it_cannot_pause_another_users_session(): void
    {
        $otherUser = User::factory()->create(['role' => 'agent', 'is_approved' => true]);
        $session = BreakSession::factory()->active()->create([
            'user_id' => $otherUser->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.pause', $session), [
                'reason' => 'Coaching',
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function it_can_resume_paused_session(): void
    {
        $session = BreakSession::factory()->paused()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'shift_date' => now()->toDateString(),
        ]);

        BreakEvent::factory()->create([
            'break_session_id' => $session->id,
            'action' => 'pause',
            'occurred_at' => now()->subMinutes(2),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.resume', $session));

        $response->assertRedirect();
        $response->assertSessionHas('flash.type', 'success');

        $session->refresh();
        $this->assertEquals('active', $session->status);
    }

    #[Test]
    public function it_can_end_active_session(): void
    {
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'started_at' => now()->subMinutes(10),
            'duration_seconds' => 900,
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.end', $session));

        $response->assertRedirect();
        $response->assertSessionHas('flash.type', 'success');

        $session->refresh();
        $this->assertContains($session->status, ['completed', 'overage']);
        $this->assertNotNull($session->ended_at);
    }

    #[Test]
    public function it_detects_overage_on_end(): void
    {
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'started_at' => now()->subMinutes(20),
            'duration_seconds' => 900, // 15 min
            'shift_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.end', $session));

        $response->assertRedirect();

        $session->refresh();
        $this->assertEquals('overage', $session->status);
        $this->assertGreaterThan(0, $session->overage_seconds);
    }

    #[Test]
    public function it_can_reset_shift(): void
    {
        BreakSession::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'status' => 'completed',
            'shift_date' => now()->toDateString(),
        ]);

        $this->assertEquals(2, BreakSession::where('user_id', $this->user->id)->count());

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.reset'), [
                'approval' => 'Approved by supervisor: John Doe',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash.type', 'success');

        $this->assertEquals(0, BreakSession::where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function it_returns_status_json(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('break-timer.status'));

        $response->assertOk()
            ->assertJson(['active' => false]);
    }

    #[Test]
    public function it_returns_active_status_with_session(): void
    {
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'started_at' => now()->subMinutes(5),
            'duration_seconds' => 900,
            'shift_date' => now()->toDateString(),
        ]);

        BreakEvent::factory()->create([
            'break_session_id' => $session->id,
            'action' => 'start',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('break-timer.status'));

        $response->assertOk()
            ->assertJson([
                'active' => true,
            ])
            ->assertJsonStructure([
                'active',
                'session',
                'remaining_seconds',
                'overage_seconds',
            ]);
    }

    #[Test]
    public function it_can_start_combined_break(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('break-timer.start'), [
                'type' => 'combined',
                'combined_break_count' => 1,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('break_sessions', [
            'user_id' => $this->user->id,
            'type' => 'combined',
            'status' => 'active',
            'duration_seconds' => 4500, // 15 + 60 = 75 min
        ]);
    }

    #[Test]
    public function it_creates_notification_on_overage(): void
    {
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'started_at' => now()->subMinutes(20),
            'duration_seconds' => 900,
            'shift_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->user)
            ->post(route('break-timer.end', $session));

        $session->refresh();
        $this->assertEquals('overage', $session->status);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'break_overage',
        ]);
    }

    #[Test]
    public function unauthenticated_user_cannot_access_break_timer(): void
    {
        $response = $this->get(route('break-timer.index'));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function night_shift_user_gets_yesterday_shift_date_after_midnight(): void
    {
        EmployeeSchedule::factory()->nightShift()->create([
            'user_id' => $this->user->id,
        ]);

        // 1 AM — within the night shift that started yesterday (22:00→07:00)
        Carbon::setTestNow(Carbon::today()->setTime(1, 0, 0));

        $service = app(BreakTimerService::class);
        $shiftDate = $service->getShiftDateForUser($this->user->id);

        $this->assertEquals(Carbon::yesterday()->toDateString(), $shiftDate);
    }

    #[Test]
    public function night_shift_user_gets_today_shift_date_after_shift_ends(): void
    {
        EmployeeSchedule::factory()->nightShift()->create([
            'user_id' => $this->user->id,
        ]);

        // 10 AM — after the night shift ended (07:00)
        Carbon::setTestNow(Carbon::today()->setTime(10, 0, 0));

        $service = app(BreakTimerService::class);
        $shiftDate = $service->getShiftDateForUser($this->user->id);

        $this->assertEquals(Carbon::today()->toDateString(), $shiftDate);
    }

    #[Test]
    public function morning_shift_user_gets_today_shift_date(): void
    {
        EmployeeSchedule::factory()->morningShift()->create([
            'user_id' => $this->user->id,
        ]);

        // 8 AM — during morning shift (06:00→15:00)
        Carbon::setTestNow(Carbon::today()->setTime(8, 0, 0));

        $service = app(BreakTimerService::class);
        $shiftDate = $service->getShiftDateForUser($this->user->id);

        $this->assertEquals(Carbon::today()->toDateString(), $shiftDate);
    }

    #[Test]
    public function graveyard_shift_user_gets_today_shift_date(): void
    {
        EmployeeSchedule::factory()->graveyardShift()->create([
            'user_id' => $this->user->id,
        ]);

        // 3 AM — during graveyard shift (00:00→09:00), same-day shift
        Carbon::setTestNow(Carbon::today()->setTime(3, 0, 0));

        $service = app(BreakTimerService::class);
        $shiftDate = $service->getShiftDateForUser($this->user->id);

        $this->assertEquals(Carbon::today()->toDateString(), $shiftDate);
    }

    #[Test]
    public function user_without_schedule_falls_back_to_policy_shift_date(): void
    {
        // No EmployeeSchedule created for user — should fall back to policy reset time

        // 3 AM — before default policy reset (06:00), so should return yesterday
        Carbon::setTestNow(Carbon::today()->setTime(3, 0, 0));

        $service = app(BreakTimerService::class);
        $shiftDate = $service->getShiftDateForUser($this->user->id);

        $this->assertEquals(Carbon::yesterday()->toDateString(), $shiftDate);
    }

    #[Test]
    public function night_shift_break_start_assigns_correct_shift_date(): void
    {
        EmployeeSchedule::factory()->nightShift()->create([
            'user_id' => $this->user->id,
        ]);

        // 12:30 AM — agent started shift yesterday at 22:00, now taking a break
        Carbon::setTestNow(Carbon::parse('2026-04-02 00:30:00'));

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.start'), [
                'type' => 'break',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('break_sessions', [
            'user_id' => $this->user->id,
            'shift_date' => '2026-04-01',
            'status' => 'active',
        ]);
    }
}
