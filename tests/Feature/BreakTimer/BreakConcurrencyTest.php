<?php

namespace Tests\Feature\BreakTimer;

use App\Models\BreakPolicy;
use App\Models\BreakSession;
use App\Models\User;
use App\Services\BreakTimerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 4.6 — Break session concurrent-start race condition tests.
 *
 * Verifies that the DB-level unique constraint (break_sessions_active_guard_unique)
 * is the last line of defence: when a duplicate-key error (MySQL 1062) bubbles up
 * from startSession(), the controller catches it and returns a user-friendly error
 * flash instead of a 500.
 */
class BreakConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected BreakPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * When startSession() throws a duplicate-key QueryException (error 1062),
     * the controller must return a redirect with an error flash — never a 500.
     *
     * This simulates two concurrent requests both passing the lockForUpdate check
     * before either has committed, where the second one hits the DB constraint.
     */
    #[Test]
    public function duplicate_key_on_start_returns_error_flash_not_server_error(): void
    {
        // Simulate the DB-level 1062 by mocking startSession() to throw it.
        // We still need validateAndGetDuration to work, so we only mock startSession.
        $mock = $this->mock(BreakTimerService::class);

        $mock->shouldReceive('getActivePolicy')->andReturn($this->policy);
        $mock->shouldReceive('getShiftDate')->andReturn(Carbon::today()->toDateString());
        $mock->shouldReceive('validateAndGetDuration')->andReturn([
            'type' => '1st_break',
            'duration_seconds' => 900,
            'combined_break_count' => null,
        ]);
        $pdoException = new \PDOException("Duplicate entry 'user_id_date' for key 'break_sessions_active_guard_unique'");
        $pdoException->errorInfo = ['23000', 1062, "Duplicate entry 'user_id_date' for key 'break_sessions_active_guard_unique'"];
        $mock->shouldReceive('startSession')->andThrow(
            new QueryException(
                'mysql',
                'INSERT INTO `break_sessions` ...',
                [],
                $pdoException
            )
        );

        $response = $this->actingAs($this->user)
            ->post(route('break-timer.start'), [
                'type' => '1st_break',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('type', 'error');
        $response->assertSessionHas('message', 'You already have an active break/lunch session.');
    }

    /**
     * The DB-level constraint actually fires when inserting a second active session
     * for the same user on the same shift_date.
     *
     * This tests that the constraint is in place and the Eloquent insert fails
     * with a QueryException, which confirms the guard column is working at the
     * DB schema level.
     */
    #[Test]
    public function db_level_guard_prevents_duplicate_active_session(): void
    {
        // First active session inserted normally
        BreakSession::factory()->active()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'type' => '1st_break',
            'shift_date' => Carbon::today()->toDateString(),
        ]);

        // A second insert for the same user+date should violate the unique guard
        $this->expectException(QueryException::class);

        BreakSession::factory()->active()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'type' => '2nd_break',
            'shift_date' => Carbon::today()->toDateString(),
        ]);
    }

    /**
     * The guard column is NULL when a session is ended (status='ended'),
     * so completed sessions do NOT block new active sessions.
     */
    #[Test]
    public function ended_sessions_do_not_block_new_active_session(): void
    {
        // Create a completed session for the same user + date
        BreakSession::factory()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'type' => '1st_break',
            'status' => 'completed',
            'shift_date' => Carbon::today()->toDateString(),
        ]);

        // Creating an active session for the same user + date should succeed
        $session = BreakSession::factory()->active()->create([
            'user_id' => $this->user->id,
            'break_policy_id' => $this->policy->id,
            'type' => '2nd_break',
            'shift_date' => Carbon::today()->toDateString(),
        ]);

        $this->assertNotNull($session->id,
            'Active session should be created without error when only completed sessions exist for the same user/date.');
    }
}
