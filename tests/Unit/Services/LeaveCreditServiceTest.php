<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaveCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveCreditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LeaveCreditService();
    }

    #[Test]
    public function it_gets_monthly_rate_for_managers(): void
    {
        $superAdmin = User::factory()->create(['role' => 'Super Admin']);
        $admin = User::factory()->create(['role' => 'Admin']);
        $teamLead = User::factory()->create(['role' => 'Team Lead']);
        $hr = User::factory()->create(['role' => 'HR']);

        $this->assertEquals(1.5, $this->service->getMonthlyRate($superAdmin));
        $this->assertEquals(1.5, $this->service->getMonthlyRate($admin));
        $this->assertEquals(1.5, $this->service->getMonthlyRate($teamLead));
        $this->assertEquals(1.5, $this->service->getMonthlyRate($hr));
    }

    #[Test]
    public function it_gets_monthly_rate_for_employees(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $it = User::factory()->create(['role' => 'IT']);
        $utility = User::factory()->create(['role' => 'Utility']);

        $this->assertEquals(1.25, $this->service->getMonthlyRate($agent));
        $this->assertEquals(1.25, $this->service->getMonthlyRate($it));
        $this->assertEquals(1.25, $this->service->getMonthlyRate($utility));
    }

    #[Test]
    public function it_checks_eligibility_after_six_months(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subMonths(7),
        ]);

        $this->assertTrue($this->service->isEligible($user));
    }

    #[Test]
    public function it_checks_not_eligible_before_six_months(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subMonths(3),
        ]);

        $this->assertFalse($this->service->isEligible($user));
    }

    #[Test]
    public function it_returns_false_eligibility_without_hired_date(): void
    {
        $user = User::factory()->create(['hired_date' => null]);

        $this->assertFalse($this->service->isEligible($user));
    }

    #[Test]
    public function it_gets_eligibility_date(): void
    {
        $hiredDate = Carbon::parse('2025-01-01');
        $user = User::factory()->create(['hired_date' => $hiredDate]);

        $eligibilityDate = $this->service->getEligibilityDate($user);

        $this->assertInstanceOf(Carbon::class, $eligibilityDate);
        $this->assertEquals('2025-07-01', $eligibilityDate->format('Y-m-d'));
    }

    #[Test]
    public function it_returns_null_eligibility_date_without_hired_date(): void
    {
        $user = User::factory()->create(['hired_date' => null]);

        $eligibilityDate = $this->service->getEligibilityDate($user);

        $this->assertNull($eligibilityDate);
    }

    #[Test]
    public function it_gets_balance_for_user(): void
    {
        $user = User::factory()->create();

        // Create leave credits - getBalance uses earned - used, not credits_balance
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2025,
            'month' => 1, // Must be > 0 to be included in getTotalEarned
            'credits_earned' => 1.5,
            'credits_used' => 0,
            'credits_balance' => 1.5,
        ]);

        $balance = $this->service->getBalance($user, 2025);

        $this->assertEquals(1.5, $balance);
    }

    #[Test]
    public function it_gets_summary_for_user(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subYears(1),
        ]);

        $summary = $this->service->getSummary($user, 2025);

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('year', $summary);
        $this->assertArrayHasKey('is_eligible', $summary);
        $this->assertArrayHasKey('eligibility_date', $summary);
        $this->assertArrayHasKey('monthly_rate', $summary);
        $this->assertArrayHasKey('total_earned', $summary);
        $this->assertArrayHasKey('total_used', $summary);
        $this->assertArrayHasKey('balance', $summary);
        $this->assertArrayHasKey('credits_by_month', $summary);
    }

    #[Test]
    public function it_accrues_monthly_credits(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2025-01-01'),
        ]);

        // Accrue for February (after it ends)
        Carbon::setTestNow(Carbon::parse('2025-03-01'));
        $credit = $this->service->accrueMonthly($user, 2025, 2);

        $this->assertInstanceOf(LeaveCredit::class, $credit);
        $this->assertEquals(1.25, $credit->credits_earned);
        $this->assertEquals(2025, $credit->year);
        $this->assertEquals(2, $credit->month);
    }

    #[Test]
    public function it_does_not_accrue_before_month_ends(): void
    {
        // For regularized employees, credits accrue at end of month
        // User hired Jan 1, 2024 is regularized by July 1, 2024
        // In Feb 2025, they are post-regularization so they use end-of-month accrual
        $user = User::factory()->create([
            'hired_date' => Carbon::parse('2024-01-01'), // Regularized by July 2024
        ]);

        Carbon::setTestNow(Carbon::parse('2025-02-15')); // Mid-February
        $credit = $this->service->accrueMonthly($user, 2025, 2);

        // Should be null because Feb 28 (end of month) hasn't arrived yet
        $this->assertNull($credit);
    }

    #[Test]
    public function it_does_not_accrue_before_hire_date(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::parse('2025-06-01'),
        ]);

        Carbon::setTestNow(Carbon::parse('2025-06-01'));
        $credit = $this->service->accrueMonthly($user, 2025, 1); // January (before hire)

        $this->assertNull($credit);
    }

    #[Test]
    public function it_returns_existing_credit_if_already_accrued(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::parse('2025-01-01'),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2025,
            'month' => 2,
        ]);

        Carbon::setTestNow(Carbon::parse('2025-03-01'));
        $credit = $this->service->accrueMonthly($user, 2025, 2);

        // Should return existing, not create new
        $this->assertDatabaseCount('leave_credits', 1);
    }

    #[Test]
    public function it_validates_leave_request_eligibility(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subMonths(3), // Not eligible yet
        ]);

        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => Carbon::now()->addWeeks(3)->format('Y-m-d'),
            'end_date' => Carbon::now()->addWeeks(3)->addDays(2)->format('Y-m-d'),
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsStringIgnoringCase('not be eligible', $result['errors'][0]);
    }

    #[Test]
    public function it_validates_two_week_advance_notice(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subYears(1), // Eligible
        ]);

        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => Carbon::now()->addDays(7)->format('Y-m-d'), // Only 1 week advance
            'end_date' => Carbon::now()->addDays(9)->format('Y-m-d'),
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('2 weeks in advance', $result['errors'][0]);
    }

    #[Test]
    public function it_calculates_working_days_excluding_weekends(): void
    {
        // Monday to Friday = 5 working days
        $startDate = Carbon::parse('2025-12-01'); // Monday
        $endDate = Carbon::parse('2025-12-05'); // Friday

        $days = $this->service->calculateDays($startDate, $endDate);

        $this->assertEquals(5, $days);
    }

    #[Test]
    public function it_excludes_weekends_from_working_days(): void
    {
        // Friday to Monday = 2 working days (excluding Sat, Sun)
        $startDate = Carbon::parse('2025-12-05'); // Friday
        $endDate = Carbon::parse('2025-12-08'); // Monday

        $days = $this->service->calculateDays($startDate, $endDate);

        $this->assertEquals(2, $days);
    }
}
