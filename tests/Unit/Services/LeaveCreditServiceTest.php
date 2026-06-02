<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\LeaveCredit;
use App\Models\LeaveCreditManualAdjustment;
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
        $this->service = new LeaveCreditService;
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
    public function it_allows_short_notice_vl_requests(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subYears(1), // Eligible
        ]);

        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => Carbon::now()->addDays(7)->format('Y-m-d'), // Only 1 week advance
            'end_date' => Carbon::now()->addDays(9)->format('Y-m-d'),
        ]);

        // Short notice is now informational only — admin overrides during approval
        $this->assertTrue($result['valid']);
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

    #[Test]
    public function it_allows_vl_submission_when_credits_insufficient_with_warning(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(), // Eligible
        ]);

        // Give user only 1.25 credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        // Request 5 working days of VL (Mon-Fri, 3+ weeks ahead)
        $startDate = Carbon::now()->addWeeks(3)->startOfWeek(); // Monday
        $endDate = $startDate->copy()->addDays(4); // Friday

        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 5,
        ]);

        // VL submission is now allowed with insufficient credits
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertTrue($result['insufficient_vl_credits']);
    }

    #[Test]
    public function it_allows_vl_submission_when_credits_sufficient(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(), // Eligible
        ]);

        // Give user 5 credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 5.0,
            'credits_used' => 0,
            'credits_balance' => 5.0,
        ]);

        // Request 2 working days of VL (3+ weeks ahead)
        $startDate = Carbon::now()->addWeeks(3)->startOfWeek(); // Monday
        $endDate = $startDate->copy()->addDay(); // Tuesday

        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 2,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    #[Test]
    public function it_accounts_for_pending_credits_in_vl_validation(): void
    {
        // Fix test date to avoid month-boundary edge cases with future accrual
        Carbon::setTestNow(Carbon::parse('2026-02-20'));

        $user = User::factory()->create([
            'role' => 'Agent', // 1.25 monthly rate
            'hired_date' => Carbon::parse('2025-01-01'), // Eligible
        ]);

        // Give user 1.25 credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        // Create a pending VL request consuming 1 day
        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'status' => 'pending',
            'days_requested' => 1,
            'start_date' => Carbon::parse('2026-04-06'),
            'end_date' => Carbon::parse('2026-04-06'),
        ]);

        // Request 2 days for March 16-17
        // Projected: 1.25 + 1.25 (Feb accrual) = 2.50, minus 1 pending = 1.50 < 2 → insufficient but allowed
        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-17',
            'days_requested' => 2,
        ]);

        // VL submission is now allowed with insufficient credits
        $this->assertTrue($result['valid']);
        $this->assertTrue($result['insufficient_vl_credits']);
    }

    #[Test]
    public function it_does_not_block_bl_submission_regardless_of_credits(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(), // Eligible
        ]);

        // Give user 0 credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 0,
            'credits_used' => 0,
            'credits_balance' => 0,
        ]);

        // Request BL with 0 credits — should pass (BL doesn't consume credits)
        $startDate = Carbon::now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDays(2);

        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'BL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 3,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    #[Test]
    public function it_does_not_block_sl_submission_regardless_of_credits(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(), // Eligible
        ]);

        // Give user 0 credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 0,
            'credits_used' => 0,
            'credits_balance' => 0,
        ]);

        // Request SL with 0 credits — should pass (SL handles this at approval time)
        $startDate = Carbon::now()->addDays(1);
        if ($startDate->isWeekend()) {
            $startDate = $startDate->next(Carbon::MONDAY);
        }
        $endDate = $startDate->copy();

        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'SL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 1,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    #[Test]
    public function it_excludes_bl_from_pending_credits_calculation(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Create pending BL request
        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'BL',
            'status' => 'pending',
            'days_requested' => 3,
            'start_date' => Carbon::now()->addWeeks(3)->startOfWeek(),
            'end_date' => Carbon::now()->addWeeks(3)->startOfWeek()->addDays(2),
        ]);

        // Create pending VL request
        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'status' => 'pending',
            'days_requested' => 2,
            'start_date' => Carbon::now()->addWeeks(4)->startOfWeek(),
            'end_date' => Carbon::now()->addWeeks(4)->startOfWeek()->addDay(),
        ]);

        $pendingCredits = $this->service->getPendingCredits($user, now()->year);

        // Should only count VL (2 days), NOT BL (3 days)
        $this->assertEquals(2, $pendingCredits);
    }

    // =====================================================================
    // Phase 5: checkVlCreditDeduction Unit Tests
    // =====================================================================

    #[Test]
    public function it_returns_full_credits_when_vl_balance_sufficient(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 10,
            'credits_used' => 0,
            'credits_balance' => 10,
        ]);

        $startDate = Carbon::now()->addWeeks(3)->startOfWeek();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'days_requested' => 3,
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDays(2),
        ]);

        $result = $this->service->checkVlCreditDeduction($user, $leaveRequest);

        $this->assertTrue($result['should_deduct']);
        $this->assertNull($result['reason']);
        $this->assertFalse($result['convert_to_upto']);
        $this->assertFalse($result['partial_credit']);
        $this->assertEquals(3, $result['credits_to_deduct']);
        $this->assertEquals(0, $result['upto_days']);
    }

    #[Test]
    public function it_uses_actual_balance_for_future_month_vl_requests(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Give only 2 credits for a past month
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => now()->month > 1 ? now()->month - 1 : 1,
            'credits_earned' => 2,
            'credits_used' => 0,
            'credits_balance' => 2,
        ]);

        // Request 3 days for 3 months in the future — only actual accrued
        // credits are used (no projected future accruals)
        $startDate = Carbon::now()->addMonths(3)->startOfWeek();
        if ($startDate->isWeekend()) {
            $startDate = $startDate->next(Carbon::MONDAY);
        }
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'days_requested' => 3,
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDays(2),
        ]);

        $result = $this->service->checkVlCreditDeduction($user, $leaveRequest);

        // With actual balance only (2 credits), should be partial: 2 credited + 1 UPTO
        $this->assertTrue($result['should_deduct']);
        $this->assertTrue($result['partial_credit']);
        $this->assertEquals(2, $result['credits_to_deduct']);
        $this->assertEquals(1, $result['upto_days']);
    }

    #[Test]
    public function it_pre_creates_future_credit_records_for_vl_approval(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Only current month credit exists
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => now()->month > 1 ? now()->month - 1 : 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        $targetDate = Carbon::now()->addMonths(3);

        $initialCount = LeaveCredit::where('user_id', $user->id)
            ->where('year', now()->year)
            ->count();

        $created = $this->service->ensureFutureCreditsExist($user, $targetDate);

        $this->assertGreaterThan(0, $created);

        $afterCount = LeaveCredit::where('user_id', $user->id)
            ->where('year', now()->year)
            ->count();

        $this->assertEquals($initialCount + $created, $afterCount);

        // Running again should not create duplicates
        $secondRun = $this->service->ensureFutureCreditsExist($user, $targetDate);
        $this->assertEquals(0, $secondRun);
    }

    #[Test]
    public function it_returns_partial_credit_when_vl_balance_insufficient(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 2,
            'credits_used' => 0,
            'credits_balance' => 2,
        ]);

        $startDate = Carbon::now()->addWeeks(3)->startOfWeek();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'days_requested' => 5,
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDays(4),
        ]);

        $result = $this->service->checkVlCreditDeduction($user, $leaveRequest);

        // Partial credit: 2 days VL credited, 3 days UPTO
        $this->assertTrue($result['should_deduct']);
        $this->assertTrue($result['partial_credit']);
        $this->assertFalse($result['convert_to_upto']);
        $this->assertEquals(2, $result['credits_to_deduct']);
        $this->assertEquals(3, $result['upto_days']);
    }

    #[Test]
    public function it_converts_to_upto_when_vl_balance_zero(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 0,
            'credits_used' => 0,
            'credits_balance' => 0,
        ]);

        $startDate = Carbon::now()->addWeeks(3)->startOfWeek();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'days_requested' => 2,
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDay(),
        ]);

        $result = $this->service->checkVlCreditDeduction($user, $leaveRequest);

        // All UPTO: no credits to deduct
        $this->assertFalse($result['should_deduct']);
        $this->assertTrue($result['convert_to_upto']);
        $this->assertFalse($result['partial_credit']);
        $this->assertEquals(0, $result['credits_to_deduct']);
        $this->assertEquals(2, $result['upto_days']);
    }

    #[Test]
    public function it_converts_to_upto_when_vl_user_not_eligible(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subMonths(3), // Less than 6 months
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 5,
            'credits_used' => 0,
            'credits_balance' => 5,
        ]);

        $startDate = Carbon::now()->addWeeks(3)->startOfWeek();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'days_requested' => 2,
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDay(),
        ]);

        $result = $this->service->checkVlCreditDeduction($user, $leaveRequest);

        // Not eligible → all UPTO
        $this->assertFalse($result['should_deduct']);
        $this->assertTrue($result['convert_to_upto']);
        $this->assertEquals(0, $result['credits_to_deduct']);
        $this->assertEquals(2, $result['upto_days']);
        $this->assertStringContainsString('Not eligible', $result['reason']);
    }

    // =====================================================================
    // Fractional Credit Flooring Unit Tests
    // =====================================================================

    #[Test]
    public function it_returns_partial_credit_when_fractional_vl_balance_insufficient(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Balance of 2.75 — floor(2.75) = 2, requesting 5 -> partial: 2 VL + 3 UPTO
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 2.75,
            'credits_used' => 0,
            'credits_balance' => 2.75,
        ]);

        $startDate = Carbon::now()->addWeeks(3)->startOfWeek();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'days_requested' => 5,
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDays(4),
        ]);

        $result = $this->service->checkVlCreditDeduction($user, $leaveRequest);

        $this->assertTrue($result['should_deduct']);
        $this->assertTrue($result['partial_credit']);
        $this->assertEquals(2, $result['credits_to_deduct']);
        $this->assertEquals(3, $result['upto_days']);
    }

    #[Test]
    public function it_converts_vl_to_upto_when_only_fractional_balance(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Balance of 0.75 — floor(0.75) = 0, all UPTO
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 0.75,
            'credits_used' => 0,
            'credits_balance' => 0.75,
        ]);

        $startDate = Carbon::now()->addWeeks(3)->startOfWeek();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'days_requested' => 2,
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDay(),
        ]);

        $result = $this->service->checkVlCreditDeduction($user, $leaveRequest);

        $this->assertFalse($result['should_deduct']);
        $this->assertTrue($result['convert_to_upto']);
        $this->assertEquals(0, $result['credits_to_deduct']);
        $this->assertEquals(2, $result['upto_days']);
    }

    #[Test]
    public function it_floors_fractional_sl_balance_to_whole_number(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Balance of 1.25 — floor(1.25) = 1
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        $startDate = Carbon::now()->addWeeks(3)->startOfWeek();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SL',
            'days_requested' => 3,
            'medical_cert_submitted' => true,
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDays(2),
        ]);

        $result = $this->service->checkSlCreditDeduction($user, $leaveRequest);

        $this->assertTrue($result['should_deduct']);
        $this->assertTrue($result['partial_credit']);
        $this->assertEquals(1, $result['credits_to_deduct']); // Floored from 1.25
        $this->assertEquals(2, $result['upto_days']); // 3 - 1 = 2
    }

    #[Test]
    public function it_converts_sl_to_upto_when_only_fractional_balance(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Balance of 0.50 — floor(0.50) = 0, should convert to UPTO
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 0.50,
            'credits_used' => 0,
            'credits_balance' => 0.50,
        ]);

        $startDate = Carbon::now()->addWeeks(3)->startOfWeek();
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SL',
            'days_requested' => 2,
            'medical_cert_submitted' => true,
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDay(),
        ]);

        $result = $this->service->checkSlCreditDeduction($user, $leaveRequest);

        $this->assertFalse($result['should_deduct']);
        $this->assertTrue($result['convert_to_upto']);
        $this->assertFalse($result['partial_credit']);
        $this->assertEquals(0, $result['credits_to_deduct']);
        $this->assertEquals(2, $result['upto_days']);
    }

    // =====================================================================
    // First-Approved-Gets-Priority Tests (no pending credit reservation)
    // =====================================================================

    #[Test]
    public function it_does_not_subtract_pending_vl_credits_from_sl_credit_check(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Balance of 1.25 — floor(1.25) = 1 SL credit available
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        // Pending VL request for 1 day — should NOT reduce SL balance
        $startDate = Carbon::now()->addWeeks(2)->startOfWeek();
        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'days_requested' => 1,
            'status' => 'pending',
            'start_date' => $startDate,
            'end_date' => $startDate,
        ]);

        // SL request for 3 days — balance 1.25, floor=1, partial (1 SL + 2 UPTO)
        $slStart = Carbon::now()->addWeeks(3)->startOfWeek();
        $slRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SL',
            'days_requested' => 3,
            'medical_cert_submitted' => true,
            'start_date' => $slStart,
            'end_date' => $slStart->copy()->addDays(2),
        ]);

        $result = $this->service->checkSlCreditDeduction($user, $slRequest);

        // First-approved-gets-priority: pending VL doesn't reserve credits
        $this->assertTrue($result['should_deduct']);
        $this->assertFalse($result['convert_to_upto']);
        $this->assertEquals(1, $result['credits_to_deduct']);
    }

    #[Test]
    public function it_does_not_subtract_pending_sl_credits_from_vl_credit_check(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Balance of 3 — enough for 2 VL days
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 3,
            'credits_used' => 0,
            'credits_balance' => 3,
        ]);

        // Pending SL request for 2 days — should NOT reduce VL balance
        $startDate = Carbon::now()->addWeeks(2)->startOfWeek();
        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SL',
            'days_requested' => 2,
            'status' => 'pending',
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDay(),
        ]);

        // VL request for 2 days — full balance of 3 available (floor=3 >= 2)
        $vlStart = Carbon::now()->addWeeks(3)->startOfWeek();
        $vlRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'days_requested' => 2,
            'start_date' => $vlStart,
            'end_date' => $vlStart->copy()->addDay(),
        ]);

        $result = $this->service->checkVlCreditDeduction($user, $vlRequest);

        // First-approved-gets-priority: pending SL doesn't reserve credits
        $this->assertTrue($result['should_deduct']);
        $this->assertFalse($result['partial_credit']);
        $this->assertEquals(2, $result['credits_to_deduct']);
        $this->assertEquals(0, $result['upto_days']);
    }

    #[Test]
    public function it_excludes_current_request_from_pending_credits_calculation(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Balance of 2
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 2,
            'credits_used' => 0,
            'credits_balance' => 2,
        ]);

        // The VL request itself IS pending (2 days) — should NOT count against itself
        $startDate = Carbon::now()->addWeeks(3)->startOfWeek();
        $vlRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'days_requested' => 2,
            'status' => 'pending',
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDay(),
        ]);

        $result = $this->service->checkVlCreditDeduction($user, $vlRequest);

        $this->assertTrue($result['should_deduct']);
        $this->assertEquals(2, $result['credits_to_deduct']);
    }

    // =====================================================================
    // Future Credit Accrual in VL Validation Tests
    // =====================================================================

    #[Test]
    public function it_allows_vl_when_future_accrual_makes_credits_sufficient(): void
    {
        // Simulate: today is Feb 20, 2026
        Carbon::setTestNow(Carbon::parse('2026-02-20'));

        $user = User::factory()->create([
            'role' => 'Agent', // 1.25 monthly rate
            'hired_date' => Carbon::parse('2025-01-01'), // Eligible (>6 months)
        ]);

        // Give user 4.25 current credits (not enough for 5 days on its own)
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 4.25,
            'credits_used' => 0,
            'credits_balance' => 4.25,
        ]);

        // Request 5 working days of VL for March 16-20 (future month)
        // Feb end-of-month accrual (+1.25) will happen before Mar 16
        // Projected: 4.25 + 1.25 = 5.50 >= 5 days → should pass
        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-20',
            'days_requested' => 5,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    #[Test]
    public function it_allows_vl_when_future_accrual_still_insufficient(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-20'));

        $user = User::factory()->create([
            'role' => 'Agent', // 1.25 monthly rate
            'hired_date' => Carbon::parse('2025-01-01'),
        ]);

        // Give user 2.0 credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 2.0,
            'credits_used' => 0,
            'credits_balance' => 2.0,
        ]);

        // Request 5 days for March 16-20
        // Projected: 2.0 + 1.25 = 3.25 < 5 → insufficient but allowed
        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-20',
            'days_requested' => 5,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['insufficient_vl_credits']);
    }

    #[Test]
    public function it_does_not_add_future_accrual_for_current_month_leave(): void
    {
        // Set to Feb 1 so that Feb 23 is 3+ weeks ahead (passes 2-week notice)
        Carbon::setTestNow(Carbon::parse('2026-02-01'));

        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2025-01-01'),
        ]);

        // Give user 1.25 credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        // Request 2 days in current month (Feb 23-24)
        // No future accrual for same month → 1.25 < 2 → insufficient but allowed
        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => '2026-02-23',
            'end_date' => '2026-02-24',
            'days_requested' => 2,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['insufficient_vl_credits']);
    }

    #[Test]
    public function it_includes_multiple_months_of_future_accrual(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15'));

        $user = User::factory()->create([
            'role' => 'Super Admin', // 1.5 monthly rate
            'hired_date' => Carbon::parse('2024-01-01'),
        ]);

        // Give user 2.0 credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 2.0,
            'credits_used' => 0,
            'credits_balance' => 2.0,
        ]);

        // Request 5 days for March 16-20 (2 months ahead: Jan + Feb accrual)
        // Projected: 2.0 + (2 * 1.5) = 5.0 >= 5 → should pass
        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-20',
            'days_requested' => 5,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    #[Test]
    public function it_accounts_for_pending_credits_with_future_accrual(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-20'));

        $user = User::factory()->create([
            'role' => 'Agent', // 1.25 monthly rate
            'hired_date' => Carbon::parse('2025-01-01'),
        ]);

        // Give user 4.25 credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 4.25,
            'credits_used' => 0,
            'credits_balance' => 4.25,
        ]);

        // Create a pending VL consuming 2 days
        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'status' => 'pending',
            'days_requested' => 2,
            'start_date' => Carbon::parse('2026-04-06'),
            'end_date' => Carbon::parse('2026-04-07'),
        ]);

        // Request 5 days for March 16-20
        // Projected: 4.25 + 1.25 = 5.50, minus 2 pending = 3.50 < 5 → insufficient but allowed
        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-20',
            'days_requested' => 5,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['insufficient_vl_credits']);
    }

    // =====================================================================
    // Manual Adjustments Persistence (leave_credit_manual_adjustments table)
    // =====================================================================

    #[Test]
    public function update_monthly_credit_persists_adjustment_to_dedicated_table(): void
    {
        $admin = User::factory()->create(['role' => 'Super Admin']);
        $user = User::factory()->create(['role' => 'Agent', 'hired_date' => Carbon::parse('2025-01-01')]);

        $credit = LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        $this->service->updateMonthlyCredit($credit, 2.00, 'Correction for overtime', $admin->id);

        $this->assertDatabaseHas('leave_credit_manual_adjustments', [
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'adjusted_earned' => 2.00,
            'adjusted_by' => $admin->id,
        ]);
    }

    #[Test]
    public function update_monthly_credit_upserts_when_same_month_edited_twice(): void
    {
        $admin = User::factory()->create(['role' => 'Super Admin']);
        $user = User::factory()->create(['role' => 'Agent', 'hired_date' => Carbon::parse('2025-01-01')]);

        $credit = LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        // First edit
        $this->service->updateMonthlyCredit($credit, 1.50, 'First correction', $admin->id);
        // Second edit — should update, not insert a second row
        $credit->refresh();
        $this->service->updateMonthlyCredit($credit, 2.00, 'Second correction', $admin->id);

        $this->assertDatabaseCount('leave_credit_manual_adjustments', 1);
        $this->assertDatabaseHas('leave_credit_manual_adjustments', [
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'adjusted_earned' => 2.00,
        ]);
    }

    #[Test]
    public function recalculate_reapplies_manual_adjustments_from_dedicated_table_not_activity_log(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29'));

        // User hired 2024-01-01 → regularized Jul 2024 → Jan 2026 IS backfilled
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2024-01-01'),
        ]);

        // Seed a manual adjustment record directly — simulating a past admin edit
        // whose activity log entry has already been purged after 60 days
        LeaveCreditManualAdjustment::create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'adjusted_earned' => 2.50,
            'reason' => 'Retroactive correction',
            'adjusted_by' => null,
            'adjusted_at' => now()->subDays(90), // 90 days ago — activity log already purged
        ]);

        // Run recalculate (deleteExisting=true wipes leave_credits but NOT the adjustments table)
        $result = $this->service->recalculateCreditsForUser($user, deleteExisting: true);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['details']['manual_adjustments_reapplied']);

        // Verify the Jan 2026 credit was set to the overridden earned value
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 2.50,
        ]);
    }

    #[Test]
    public function recalculate_does_not_fail_when_manual_adjustments_table_is_empty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29'));

        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2026-01-01'),
        ]);

        // No entries in leave_credit_manual_adjustments
        $result = $this->service->recalculateCreditsForUser($user, deleteExisting: true);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['details']['manual_adjustments_reapplied']);
    }

    // =====================================================================
    // reapplyLeaveDeductions — month-matching deduction order (via reflection)
    // =====================================================================

    /**
     * Helper: call the private reapplyLeaveDeductions method via reflection.
     */
    private function invokeReapplyLeaveDeductions(User $user): int
    {
        $method = new \ReflectionMethod($this->service, 'reapplyLeaveDeductions');
        $method->setAccessible(true);

        return $method->invoke($this->service, $user);
    }

    #[Test]
    public function reapply_deductions_uses_previous_month_credit_for_early_month_leave(): void
    {
        // SL on Feb 9 → Feb credit not yet accrued → should deduct from January (L-1)
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2024-01-01'),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id, 'year' => 2026, 'month' => 1,
            'credits_earned' => 1.25, 'credits_used' => 0, 'credits_balance' => 1.25,
        ]);
        LeaveCredit::factory()->create([
            'user_id' => $user->id, 'year' => 2026, 'month' => 2,
            'credits_earned' => 1.25, 'credits_used' => 0, 'credits_balance' => 1.25,
        ]);

        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SL', 'status' => 'approved',
            'days_requested' => 1, 'credits_year' => 2026, 'credits_deducted' => 1.00,
            'start_date' => '2026-02-09', 'end_date' => '2026-02-09',
        ]);

        $applied = $this->invokeReapplyLeaveDeductions($user);

        $this->assertEquals(1, $applied);
        // Jan (L-1) absorbs the Feb leave
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id, 'year' => 2026, 'month' => 1,
            'credits_used' => 1.00, 'credits_balance' => 0.25,
        ]);
        // Feb untouched
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id, 'year' => 2026, 'month' => 2,
            'credits_used' => 0.00, 'credits_balance' => 1.25,
        ]);
    }

    #[Test]
    public function reapply_deductions_falls_back_to_own_month_when_no_prior_month_exists(): void
    {
        // Leave in Jan (L=1) — no carryover, no L-1; falls to Jan itself
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2024-01-01'),
        ]);

        // Only Jan exists — no month=0, no prior monthly credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id, 'year' => 2026, 'month' => 1,
            'credits_earned' => 1.25, 'credits_used' => 0, 'credits_balance' => 1.25,
        ]);

        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL', 'status' => 'approved',
            'days_requested' => 1, 'credits_year' => 2026, 'credits_deducted' => 1.00,
            'start_date' => '2026-01-15', 'end_date' => '2026-01-15',
        ]);

        $applied = $this->invokeReapplyLeaveDeductions($user);

        $this->assertEquals(1, $applied);
        // Jan leave with no carryover and no L-1 → falls back to Jan itself
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id, 'year' => 2026, 'month' => 1,
            'credits_used' => 1.00, 'credits_balance' => 0.25,
        ]);
    }

    #[Test]
    public function reapply_deductions_does_not_use_carryover_for_april_or_later_leaves(): void
    {
        // Carryover (month=0) expires Mar 31. An Apr leave must NOT touch it.
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2024-01-01'),
        ]);

        // Carryover (month=0) — still in the DB but should be ignored for Apr+
        LeaveCredit::factory()->create([
            'user_id' => $user->id, 'year' => 2026, 'month' => 0,
            'credits_earned' => 2.00, 'credits_used' => 0, 'credits_balance' => 2.00,
        ]);
        // Mar accrual — L-1 bucket for an Apr leave
        LeaveCredit::factory()->create([
            'user_id' => $user->id, 'year' => 2026, 'month' => 3,
            'credits_earned' => 1.25, 'credits_used' => 0, 'credits_balance' => 1.25,
        ]);
        // Apr accrual
        LeaveCredit::factory()->create([
            'user_id' => $user->id, 'year' => 2026, 'month' => 4,
            'credits_earned' => 1.25, 'credits_used' => 0, 'credits_balance' => 1.25,
        ]);

        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SL', 'status' => 'approved',
            'days_requested' => 1, 'credits_year' => 2026, 'credits_deducted' => 1.00,
            'start_date' => '2026-04-22', 'end_date' => '2026-04-22',
        ]);

        $this->invokeReapplyLeaveDeductions($user);

        // Carryover must remain untouched — Apr leave cannot use expired carryover
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id, 'year' => 2026, 'month' => 0,
            'credits_used' => 0.00,
        ]);
        // Mar (L-1) absorbs the deduction
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id, 'year' => 2026, 'month' => 3,
            'credits_used' => 1.00, 'credits_balance' => 0.25,
        ]);
        // Apr untouched
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id, 'year' => 2026, 'month' => 4,
            'credits_used' => 0.00,
        ]);
    }

    #[Test]
    public function reapply_deductions_uses_carryover_for_january_leave(): void
    {
        // Carryover (month=0) has not yet expired for a Jan leave.
        // Priority: carryover → then L-1 (none for Jan) → then Jan itself.
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2024-01-01'),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id, 'year' => 2026, 'month' => 0,
            'credits_earned' => 2.00, 'credits_used' => 0, 'credits_balance' => 2.00,
        ]);
        LeaveCredit::factory()->create([
            'user_id' => $user->id, 'year' => 2026, 'month' => 1,
            'credits_earned' => 1.25, 'credits_used' => 0, 'credits_balance' => 1.25,
        ]);

        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL', 'status' => 'approved',
            'days_requested' => 1, 'credits_year' => 2026, 'credits_deducted' => 1.00,
            'start_date' => '2026-01-20', 'end_date' => '2026-01-20',
        ]);

        $this->invokeReapplyLeaveDeductions($user);

        // Carryover consumed first for Jan leave
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id, 'year' => 2026, 'month' => 0,
            'credits_used' => 1.00, 'credits_balance' => 1.00,
        ]);
        // Jan untouched (carryover was sufficient)
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id, 'year' => 2026, 'month' => 1,
            'credits_used' => 0.00,
        ]);
    }

    #[Test]
    public function recalculate_distributes_three_leaves_into_correct_months(): void
    {
        // Full integration: SL Feb 9 → Jan, VL Mar 13 → Feb, SL Apr 22 → Mar
        // User hired 2024-01-01 → regularized Jul 2024 → Jan–Apr 2026 backfilled (1.25 each)
        Carbon::setTestNow(Carbon::parse('2026-05-29'));

        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2024-01-01'),
        ]);

        foreach ([
            ['2026-02-09', 'SL'],  // deducts Jan (L-1=1)
            ['2026-03-13', 'VL'],  // deducts Feb (L-1=2)
            ['2026-04-22', 'SL'],  // deducts Mar (L-1=3)
        ] as [$date, $type]) {
            LeaveRequest::factory()->create([
                'user_id' => $user->id,
                'leave_type' => $type,
                'status' => 'approved',
                'days_requested' => 1,
                'credits_year' => 2026,
                'credits_deducted' => 1.00,
                'start_date' => $date,
                'end_date' => $date,
            ]);
        }

        $result = $this->service->recalculateCreditsForUser($user, deleteExisting: true);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['details']['leave_deductions_reapplied']);

        $expected = [
            1 => ['used' => 1.00, 'balance' => 0.25], // absorbed Feb 9 SL
            2 => ['used' => 1.00, 'balance' => 0.25], // absorbed Mar 13 VL
            3 => ['used' => 1.00, 'balance' => 0.25], // absorbed Apr 22 SL
            4 => ['used' => 0.00, 'balance' => 1.25], // untouched
        ];

        foreach ($expected as $month => ['used' => $used, 'balance' => $balance]) {
            $this->assertDatabaseHas('leave_credits', [
                'user_id' => $user->id, 'year' => 2026, 'month' => $month,
                'credits_used' => $used, 'credits_balance' => $balance,
            ]);
        }
    }

    // ============================================
    // LeaveCreditManualAdjustment tests
    // ============================================

    #[Test]
    public function update_monthly_credit_persists_manual_adjustment_record(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01'));

        $admin = User::factory()->create(['role' => 'Admin']);
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2025-01-01'),
        ]);

        $credit = LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 3,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        $this->service->updateMonthlyCredit($credit, 2.50, 'Correction for March', $admin->id);

        $this->assertDatabaseHas('leave_credit_manual_adjustments', [
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 3,
            'adjusted_earned' => 2.50,
            'adjusted_by' => $admin->id,
        ]);

        $this->assertDatabaseCount('leave_credit_manual_adjustments', 1);
    }

    #[Test]
    public function update_monthly_credit_upserts_on_second_adjustment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01'));

        $admin = User::factory()->create(['role' => 'Admin']);
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2025-01-01'),
        ]);

        $credit = LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 3,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        $this->service->updateMonthlyCredit($credit, 2.00, 'First correction', $admin->id);
        $credit->refresh();
        $this->service->updateMonthlyCredit($credit, 1.75, 'Second correction', $admin->id);

        // Should still be exactly one record (upserted, not duplicated)
        $this->assertDatabaseCount('leave_credit_manual_adjustments', 1);
        $this->assertDatabaseHas('leave_credit_manual_adjustments', [
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 3,
            'adjusted_earned' => 1.75,
        ]);
    }

    #[Test]
    public function update_monthly_credit_stores_reason_text(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01'));

        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2025-01-01'),
        ]);

        $credit = LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 4,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        $this->service->updateMonthlyCredit($credit, 1.50, 'System correction for April accrual', null);

        $adjustment = LeaveCreditManualAdjustment::where('user_id', $user->id)
            ->where('year', 2026)
            ->where('month', 4)
            ->first();

        $this->assertNotNull($adjustment);
        $this->assertEquals('System correction for April accrual', $adjustment->reason);
        $this->assertNull($adjustment->adjusted_by);
    }

    #[Test]
    public function recalculate_credits_reapplies_manual_adjustments_after_reset(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29'));

        $admin = User::factory()->create(['role' => 'Admin']);
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2026-01-01'),
        ]);

        // Accrue January–April credits normally
        foreach ([1, 2, 3, 4] as $month) {
            LeaveCredit::factory()->create([
                'user_id' => $user->id,
                'year' => 2026,
                'month' => $month,
                'credits_earned' => 1.25,
                'credits_used' => 0,
                'credits_balance' => 1.25,
            ]);
        }

        // Admin manually adjusts March to 2.50
        $marchCredit = LeaveCredit::where('user_id', $user->id)
            ->where('year', 2026)
            ->where('month', 3)
            ->first();
        $this->service->updateMonthlyCredit($marchCredit, 2.50, 'Special allowance for March', $admin->id);

        // Recalculate from scratch (deleteExisting = true)
        $result = $this->service->recalculateCreditsForUser($user, deleteExisting: true);

        $this->assertTrue($result['success']);

        // March should still have adjusted_earned = 2.50 after recalculation
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 3,
            'credits_earned' => 2.50,
        ]);
    }

    #[Test]
    public function manual_adjustment_record_survives_credit_reset(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29'));

        $admin = User::factory()->create(['role' => 'Admin']);
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::parse('2026-01-01'),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 2,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        $credit = LeaveCredit::where('user_id', $user->id)->where('month', 2)->first();
        $this->service->updateMonthlyCredit($credit, 1.50, 'February override', $admin->id);

        // Delete all leave_credits (simulating a reset)
        LeaveCredit::where('user_id', $user->id)->delete();

        // The adjustment record should still exist
        $this->assertDatabaseHas('leave_credit_manual_adjustments', [
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 2,
            'adjusted_earned' => 1.50,
        ]);
    }
}
