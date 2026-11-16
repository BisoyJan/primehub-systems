<?php

namespace Tests\Feature\LeaveCredits;

use App\Http\Middleware\EnsureUserHasSchedule;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for the recalculate leave credits endpoint.
 */
class RecalculateCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            EnsureUserHasSchedule::class,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-17'));

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'hired_date' => '2023-01-15',
        ]);

        $this->employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => '2026-04-13',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function accrualDates(): array
    {
        return LeaveCredit::forUser($this->employee->id)
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(fn (LeaveCredit $credit) => $credit->accrued_at->format('Y-m-d'))
            ->all();
    }

    private function recalculateUrl(): string
    {
        return "/form-requests/leave-requests/credits/{$this->employee->id}/recalculate";
    }

    #[Test]
    public function it_rebuilds_credits_on_the_hire_day_and_discards_stale_records(): void
    {
        foreach ([5 => '2026-05-31', 6 => '2026-06-30'] as $month => $accruedAt) {
            LeaveCredit::factory()->create([
                'user_id' => $this->employee->id,
                'year' => 2026,
                'month' => $month,
                'credits_earned' => 1.25,
                'credits_used' => 0,
                'credits_balance' => 1.25,
                'accrued_at' => $accruedAt,
            ]);
        }
        LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'from_year' => 2025,
            'to_year' => 2026,
        ]);

        $response = $this->actingAs($this->admin)->post($this->recalculateUrl(), [
            'hired_date' => '2026-04-13',
            'reason' => 'Rebuild after accrual rule change',
        ]);

        $response->assertRedirect(route('leave-requests.credits.show', $this->employee->id));
        $response->assertSessionHas('type', 'success');

        $this->assertSame(
            ['2026-05-13', '2026-06-13', '2026-07-13', '2026-08-13', '2026-09-13', '2026-10-13', '2026-11-13'],
            $this->accrualDates()
        );
        $this->assertSame(0, LeaveCreditCarryover::forUser($this->employee->id)->count());
        $this->assertEquals(8.75, LeaveCredit::forUser($this->employee->id)->sum('credits_balance'));
    }

    #[Test]
    public function it_recalculates_from_a_corrected_hire_date(): void
    {
        $response = $this->actingAs($this->admin)->post($this->recalculateUrl(), [
            'hired_date' => '2026-06-20',
            'reason' => 'Hire date corrected',
        ]);

        $response->assertRedirect(route('leave-requests.credits.show', $this->employee->id));

        $this->assertSame('2026-06-20', $this->employee->fresh()->hired_date->format('Y-m-d'));
        $this->assertSame(
            ['2026-07-20', '2026-08-20', '2026-09-20', '2026-10-20'],
            $this->accrualDates()
        );
    }

    #[Test]
    public function it_rejects_invalid_input_without_touching_credits(): void
    {
        $credit = LeaveCredit::factory()->create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 5,
        ]);

        $this->actingAs($this->admin)
            ->post($this->recalculateUrl(), ['hired_date' => '2026-11-18', 'reason' => ''])
            ->assertSessionHasErrors(['hired_date', 'reason']);

        $this->assertSame('2026-04-13', $this->employee->fresh()->hired_date->format('Y-m-d'));
        $this->assertSame([$credit->id], LeaveCredit::forUser($this->employee->id)->pluck('id')->all());
    }

    #[Test]
    public function it_forbids_users_without_edit_permission(): void
    {
        $this->actingAs($this->employee)
            ->post($this->recalculateUrl(), ['hired_date' => '2026-06-20', 'reason' => 'Self-service'])
            ->assertStatus(403);

        $this->assertSame('2026-04-13', $this->employee->fresh()->hired_date->format('Y-m-d'));
    }
}
