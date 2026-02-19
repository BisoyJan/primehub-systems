<?php

namespace Tests\Feature;

use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for leave credit cash conversion feature.
 */
class LeaveCreditCashConversionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $employee;

    protected LeaveCreditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'hired_date' => '2023-01-15',
        ]);

        $this->employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => '2024-01-15',
        ]);

        $this->service = app(LeaveCreditService::class);
    }

    #[Test]
    public function it_converts_regular_carryover_to_cash(): void
    {
        $carryover = LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 3.50,
            'credits_from_previous_year' => 5.00,
            'forfeited_credits' => 1.50,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ]);

        $result = $this->service->convertCarryoverToCash($carryover, $this->admin->id);

        $this->assertTrue($result['success']);
        $this->assertEquals(3.50, $result['credits_converted']);

        $carryover->refresh();
        $this->assertTrue($carryover->cash_converted);
        $this->assertNotNull($carryover->cash_converted_at);
        $this->assertEquals($this->admin->id, $carryover->processed_by);
    }

    #[Test]
    public function it_zeros_month0_credit_record_on_conversion(): void
    {
        $carryover = LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 4.00,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ]);

        // Create the month=0 credit record (simulating what ensureCarryoverCreditRecord does)
        $month0 = LeaveCredit::factory()->create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 0,
            'credits_earned' => 4.00,
            'credits_used' => 0,
            'credits_balance' => 4.00,
        ]);

        $result = $this->service->convertCarryoverToCash($carryover, $this->admin->id);

        $this->assertTrue($result['success']);

        $month0->refresh();
        $this->assertEquals(0, (float) $month0->credits_earned);
        $this->assertEquals(0, (float) $month0->credits_balance);
    }

    #[Test]
    public function it_handles_partially_used_carryover_on_conversion(): void
    {
        $carryover = LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 4.00,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ]);

        // Month=0 with some credits already used for leave
        $month0 = LeaveCredit::factory()->create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 0,
            'credits_earned' => 4.00,
            'credits_used' => 1.50,
            'credits_balance' => 2.50,
        ]);

        $result = $this->service->convertCarryoverToCash($carryover, $this->admin->id);

        $this->assertTrue($result['success']);
        // Only the remaining balance should be "converted" (not the already-used portion)
        $this->assertEquals(2.50, $result['credits_converted']);

        $month0->refresh();
        // credits_earned adjusted to match credits_used, balance zeroed
        $this->assertEquals(1.50, (float) $month0->credits_earned);
        $this->assertEquals(0, (float) $month0->credits_balance);
    }

    #[Test]
    public function it_rejects_first_regularization_carryover_conversion(): void
    {
        $carryover = LeaveCreditCarryover::factory()->firstRegularization()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 5.00,
            'from_year' => 2025,
            'to_year' => 2026,
        ]);

        $result = $this->service->convertCarryoverToCash($carryover, $this->admin->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('First regularization', $result['message']);

        $carryover->refresh();
        $this->assertFalse($carryover->cash_converted);
    }

    #[Test]
    public function it_rejects_already_converted_carryover(): void
    {
        $carryover = LeaveCreditCarryover::factory()->cashConverted()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 3.00,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
        ]);

        $result = $this->service->convertCarryoverToCash($carryover, $this->admin->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already been cash-converted', $result['message']);
    }

    #[Test]
    public function it_rejects_zero_credit_carryover(): void
    {
        $carryover = LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 0,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ]);

        $result = $this->service->convertCarryoverToCash($carryover, $this->admin->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No carryover credits', $result['message']);
    }

    #[Test]
    public function bulk_conversion_processes_only_regular_carryovers(): void
    {
        // Regular carryover - should be converted
        LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 3.00,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ]);

        // First regularization - should be skipped
        $otherEmployee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => '2024-06-15',
        ]);
        LeaveCreditCarryover::factory()->firstRegularization()->create([
            'user_id' => $otherEmployee->id,
            'carryover_credits' => 6.00,
            'from_year' => 2025,
            'to_year' => 2026,
        ]);

        $result = $this->service->processBulkCashConversion(2026, $this->admin->id);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertEquals(3.00, $result['total_converted']);
    }

    #[Test]
    public function ensure_carryover_credit_record_skips_cash_converted(): void
    {
        $carryover = LeaveCreditCarryover::factory()->cashConverted()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 4.00,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
        ]);

        // Build a leave request to trigger ensureCarryoverCreditRecord via deductCredits
        // The method is protected, so we test indirectly through the balance calculation
        $balance = $this->service->getBalance($this->employee, 2026);

        // Cash-converted carryover should NOT be included in balance
        $this->assertEquals(0, $balance);

        // No month=0 LeaveCredit should exist
        $month0 = LeaveCredit::forUser($this->employee->id)
            ->forYear(2026)
            ->where('month', 0)
            ->first();

        $this->assertNull($month0);
    }

    #[Test]
    public function converted_carryover_excluded_from_balance(): void
    {
        // Cash-converted regular carryover
        LeaveCreditCarryover::factory()->cashConverted()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 4.00,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
        ]);

        // Monthly credits for 2026
        LeaveCredit::factory()->create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
        ]);

        $balance = $this->service->getBalance($this->employee, 2026);

        // Should only have the monthly credit, not the converted carryover
        $this->assertEquals(1.25, $balance);
    }

    #[Test]
    public function bulk_cash_conversion_endpoint_works(): void
    {
        LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 2.50,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/form-requests/leave-requests/credits/cash-conversion/process', [
                'year' => 2026,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'summary' => [
                    'processed' => 1,
                ],
            ]);

        $this->assertDatabaseHas('leave_credit_carryovers', [
            'user_id' => $this->employee->id,
            'to_year' => 2026,
            'cash_converted' => true,
        ]);
    }

    #[Test]
    public function per_employee_cash_conversion_endpoint_works(): void
    {
        LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 3.00,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/form-requests/leave-requests/credits/{$this->employee->id}/cash-conversion", [
                'year' => 2026,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('leave_credit_carryovers', [
            'user_id' => $this->employee->id,
            'to_year' => 2026,
            'cash_converted' => true,
        ]);
    }

    #[Test]
    public function agent_cannot_access_cash_conversion_endpoint(): void
    {
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($agent)
            ->postJson('/form-requests/leave-requests/credits/cash-conversion/process', [
                'year' => 2026,
            ]);

        // Agent gets redirected (302) by permission middleware — no direct access
        $response->assertStatus(302);
    }

    #[Test]
    public function per_employee_endpoint_returns_404_for_missing_carryover(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/form-requests/leave-requests/credits/{$this->employee->id}/cash-conversion", [
                'year' => 2026,
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    #[Test]
    public function per_employee_endpoint_rejects_first_regularization(): void
    {
        LeaveCreditCarryover::factory()->firstRegularization()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 5.00,
            'from_year' => 2025,
            'to_year' => 2026,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/form-requests/leave-requests/credits/{$this->employee->id}/cash-conversion", [
                'year' => 2026,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    #[Test]
    public function per_employee_endpoint_returns_pending_warning_when_pending_leave_exists(): void
    {
        $carryover = LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 3.00,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 0,
            'credits_earned' => 3.00,
            'credits_used' => 0,
            'credits_balance' => 3.00,
        ]);

        // Create a pending VL request
        LeaveRequest::factory()->pending()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => '2026-02-15',
            'end_date' => '2026-02-16',
            'days_requested' => 2,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/form-requests/leave-requests/credits/{$this->employee->id}/cash-conversion", [
                'year' => 2026,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure(['pending_warning']);

        $this->assertNotNull($response->json('pending_warning'));
        $this->assertStringContainsString('pending leave request', $response->json('pending_warning'));
    }

    #[Test]
    public function per_employee_endpoint_returns_no_pending_warning_when_no_pending_leave(): void
    {
        $carryover = LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 3.00,
            'from_year' => 2025,
            'to_year' => 2026,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 0,
            'credits_earned' => 3.00,
            'credits_used' => 0,
            'credits_balance' => 3.00,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/form-requests/leave-requests/credits/{$this->employee->id}/cash-conversion", [
                'year' => 2026,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'pending_warning' => null,
            ]);
    }
}
