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

    #[Test]
    public function it_blocks_vl_submission_when_credits_insufficient(): void
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

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsStringIgnoringCase('Insufficient leave credits', $result['errors'][0]);
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
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(), // Eligible
        ]);

        // Give user 3 credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => 1,
            'credits_earned' => 3.0,
            'credits_used' => 0,
            'credits_balance' => 3.0,
        ]);

        // Create a pending VL request consuming 2 days
        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'VL',
            'status' => 'pending',
            'days_requested' => 2,
            'start_date' => Carbon::now()->addWeeks(4)->startOfWeek(),
            'end_date' => Carbon::now()->addWeeks(4)->startOfWeek()->addDay(),
        ]);

        // Try to request 2 more days (but only 1 available after pending)
        $startDate = Carbon::now()->addWeeks(5)->startOfWeek();
        $endDate = $startDate->copy()->addDay();

        $result = $this->service->validateLeaveRequest($user, [
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 2,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsStringIgnoringCase('Insufficient leave credits', $result['errors'][0]);
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
    public function it_returns_partial_credits_when_vl_balance_insufficient(): void
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

        $this->assertTrue($result['should_deduct']);
        $this->assertTrue($result['partial_credit']);
        $this->assertFalse($result['convert_to_upto']);
        $this->assertEquals(2, $result['credits_to_deduct']);
        $this->assertEquals(3, $result['upto_days']);
        $this->assertStringContainsString('Partial VL credits', $result['reason']);
    }

    #[Test]
    public function it_returns_convert_to_upto_when_vl_balance_zero(): void
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

        $this->assertFalse($result['should_deduct']);
        $this->assertTrue($result['convert_to_upto']);
        $this->assertFalse($result['partial_credit']);
        $this->assertEquals(0, $result['credits_to_deduct']);
        $this->assertEquals(2, $result['upto_days']);
        $this->assertStringContainsString('No VL credits available', $result['reason']);
    }

    #[Test]
    public function it_returns_convert_to_upto_when_vl_user_not_eligible(): void
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

        $this->assertFalse($result['should_deduct']);
        $this->assertTrue($result['convert_to_upto']);
        $this->assertFalse($result['partial_credit']);
        $this->assertEquals(0, $result['credits_to_deduct']);
        $this->assertStringContainsString('Not eligible', $result['reason']);
    }

    // =====================================================================
    // Fractional Credit Flooring Unit Tests
    // =====================================================================

    #[Test]
    public function it_floors_fractional_vl_balance_to_whole_number(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Balance of 2.75 — floor(2.75) = 2
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
        $this->assertEquals(2, $result['credits_to_deduct']); // Floored from 2.75
        $this->assertEquals(3, $result['upto_days']); // 5 - 2 = 3
    }

    #[Test]
    public function it_converts_vl_to_upto_when_only_fractional_balance(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Balance of 0.75 — floor(0.75) = 0, should convert to UPTO
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
        $this->assertFalse($result['partial_credit']);
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
}
