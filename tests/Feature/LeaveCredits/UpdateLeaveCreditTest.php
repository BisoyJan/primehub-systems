<?php

declare(strict_types=1);

namespace Tests\Feature\LeaveCredits;

use App\Http\Middleware\EnsureUserHasSchedule;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateLeaveCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureUserHasSchedule::class,
        ]);
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);
    }

    private function createHrUser(): User
    {
        return User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);
    }

    private function createAgent(): User
    {
        return User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);
    }

    private function createEmployeeWithCarryover(int $year = 2025): array
    {
        $employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);

        $carryover = LeaveCreditCarryover::factory()->create([
            'user_id' => $employee->id,
            'credits_from_previous_year' => 6.00,
            'carryover_credits' => 4.00,
            'forfeited_credits' => 2.00,
            'from_year' => $year - 1,
            'to_year' => $year,
        ]);

        $month0Credit = LeaveCredit::factory()->create([
            'user_id' => $employee->id,
            'year' => $year,
            'month' => 0,
            'credits_earned' => 4.00,
            'credits_used' => 2.00,
            'credits_balance' => 2.00,
            'accrued_at' => "{$year}-01-01",
        ]);

        return [$employee, $carryover, $month0Credit];
    }

    // ── Carryover Update Tests ──────────────────────────────────────────

    #[Test]
    public function admin_can_update_carryover_credits(): void
    {
        $admin = $this->createAdmin();
        [$employee, $carryover] = $this->createEmployeeWithCarryover();

        $response = $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/carryover", [
                'carryover_credits' => 3.00,
                'year' => 2025,
                'reason' => 'Correction after audit',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('message');
        $response->assertSessionHas('type', 'success');

        $this->assertDatabaseHas('leave_credit_carryovers', [
            'id' => $carryover->id,
            'carryover_credits' => 3.00,
        ]);
    }

    #[Test]
    public function hr_can_update_carryover_credits(): void
    {
        $hr = $this->createHrUser();
        [$employee, $carryover] = $this->createEmployeeWithCarryover();

        $response = $this->actingAs($hr)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/carryover", [
                'carryover_credits' => 3.50,
                'year' => 2025,
                'reason' => 'HR adjustment',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('message');
        $response->assertSessionHas('type', 'success');

        $this->assertDatabaseHas('leave_credit_carryovers', [
            'id' => $carryover->id,
            'carryover_credits' => 3.50,
        ]);
    }

    #[Test]
    public function agent_cannot_update_carryover_credits(): void
    {
        $agent = $this->createAgent();
        [$employee, $carryover] = $this->createEmployeeWithCarryover();

        $response = $this->actingAs($agent)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/carryover", [
                'carryover_credits' => 3.00,
                'year' => 2025,
                'reason' => 'Should not work',
            ]);

        $response->assertStatus(403);

        // Carryover should remain unchanged
        $this->assertDatabaseHas('leave_credit_carryovers', [
            'id' => $carryover->id,
            'carryover_credits' => 4.00,
        ]);
    }

    #[Test]
    public function carryover_update_validates_required_fields(): void
    {
        $admin = $this->createAdmin();
        [$employee] = $this->createEmployeeWithCarryover();

        $response = $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/carryover", []);

        $response->assertSessionHasErrors(['carryover_credits', 'year', 'reason']);
    }

    #[Test]
    public function carryover_update_validates_numeric_constraints(): void
    {
        $admin = $this->createAdmin();
        [$employee] = $this->createEmployeeWithCarryover();

        $response = $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/carryover", [
                'carryover_credits' => -1,
                'year' => 2025,
                'reason' => 'Testing',
            ]);

        $response->assertSessionHasErrors(['carryover_credits']);
    }

    #[Test]
    public function carryover_update_validates_max_credits(): void
    {
        $admin = $this->createAdmin();
        [$employee] = $this->createEmployeeWithCarryover();

        $response = $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/carryover", [
                'carryover_credits' => 31,
                'year' => 2025,
                'reason' => 'Testing',
            ]);

        $response->assertSessionHasErrors(['carryover_credits']);
    }

    #[Test]
    public function carryover_update_returns_error_when_no_carryover_record(): void
    {
        $admin = $this->createAdmin();
        $employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);

        $response = $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/carryover", [
                'carryover_credits' => 3.00,
                'year' => 2025,
                'reason' => 'No carryover exists',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('type', 'error');
    }

    #[Test]
    public function carryover_update_handles_increase(): void
    {
        $admin = $this->createAdmin();
        [$employee, $carryover, $month0Credit] = $this->createEmployeeWithCarryover();

        $response = $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/carryover", [
                'carryover_credits' => 5.00,
                'year' => 2025,
                'reason' => 'Increase carryover',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('message');
        $response->assertSessionHas('type', 'success');

        // Carryover updated
        $this->assertDatabaseHas('leave_credit_carryovers', [
            'id' => $carryover->id,
            'carryover_credits' => 5.00,
        ]);

        // Month 0 credit updated (earned increased, used stays same, balance increases)
        $month0Credit->refresh();
        $this->assertEquals(5.00, (float) $month0Credit->credits_earned);
        $this->assertEquals(2.00, (float) $month0Credit->credits_used);
        $this->assertEquals(3.00, (float) $month0Credit->credits_balance);
    }

    // ── Monthly Credit Update Tests ─────────────────────────────────────

    #[Test]
    public function admin_can_update_monthly_credit(): void
    {
        $admin = $this->createAdmin();
        $employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);

        $credit = LeaveCredit::factory()->create([
            'user_id' => $employee->id,
            'year' => 2025,
            'month' => 3,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => '2025-03-01',
        ]);

        $response = $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/credits/{$credit->id}", [
                'credits_earned' => 1.50,
                'reason' => 'Rate correction',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('message');
        $response->assertSessionHas('type', 'success');

        $credit->refresh();
        $this->assertEquals(1.50, (float) $credit->credits_earned);
        $this->assertEquals(0, (float) $credit->credits_used);
        $this->assertEquals(1.50, (float) $credit->credits_balance);
    }

    #[Test]
    public function agent_cannot_update_monthly_credit(): void
    {
        $agent = $this->createAgent();
        $employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);

        $credit = LeaveCredit::factory()->create([
            'user_id' => $employee->id,
            'year' => 2025,
            'month' => 3,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => '2025-03-01',
        ]);

        $response = $this->actingAs($agent)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/credits/{$credit->id}", [
                'credits_earned' => 1.50,
                'reason' => 'Should not work',
            ]);

        $response->assertStatus(403);

        $credit->refresh();
        $this->assertEquals(1.25, (float) $credit->credits_earned);
    }

    #[Test]
    public function monthly_credit_update_validates_required_fields(): void
    {
        $admin = $this->createAdmin();
        $employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);

        $credit = LeaveCredit::factory()->create([
            'user_id' => $employee->id,
            'year' => 2025,
            'month' => 3,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => '2025-03-01',
        ]);

        $response = $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/credits/{$credit->id}", []);

        $response->assertSessionHasErrors(['credits_earned', 'reason']);
    }

    #[Test]
    public function monthly_credit_update_rejects_wrong_user(): void
    {
        $admin = $this->createAdmin();
        $employee1 = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);
        $employee2 = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);

        $credit = LeaveCredit::factory()->create([
            'user_id' => $employee1->id,
            'year' => 2025,
            'month' => 3,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => '2025-03-01',
        ]);

        // Try to update employee1's credit via employee2's URL
        $response = $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee2->id}/credits/{$credit->id}", [
                'credits_earned' => 1.50,
                'reason' => 'Wrong user test',
            ]);

        $response->assertStatus(403);

        // Credit should remain unchanged
        $credit->refresh();
        $this->assertEquals(1.25, (float) $credit->credits_earned);
    }

    // ── Props Tests ─────────────────────────────────────────────────────

    #[Test]
    public function credits_index_returns_can_edit_prop_for_admin(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)
            ->get('/form-requests/leave-requests/credits');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Index')
            ->where('canEdit', true)
        );
    }

    #[Test]
    public function credits_show_returns_can_edit_prop_for_admin(): void
    {
        $admin = $this->createAdmin();
        $employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);

        $response = $this->actingAs($admin)
            ->get("/form-requests/leave-requests/credits/{$employee->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Show')
            ->where('canEdit', true)
        );
    }

    #[Test]
    public function credits_show_returns_can_edit_false_for_own_credits_page(): void
    {
        $agent = $this->createAgent();

        $response = $this->actingAs($agent)
            ->get("/form-requests/leave-requests/credits/{$agent->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Show')
            ->where('canEdit', false)
        );
    }

    #[Test]
    public function credits_show_includes_pending_leave_info(): void
    {
        $admin = $this->createAdmin();
        $employee = $this->createAgent();
        $year = now()->year;

        \App\Models\LeaveRequest::factory()->create([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'status' => 'pending',
            'days_requested' => 2,
            'start_date' => \Carbon\Carbon::create($year, 6, 10),
            'end_date' => \Carbon\Carbon::create($year, 6, 11),
        ]);

        $response = $this->actingAs($admin)
            ->get("/form-requests/leave-requests/credits/{$employee->id}?year={$year}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Show')
            ->has('pendingLeaveInfo')
            ->where('pendingLeaveInfo.pending_count', 1)
            ->where('pendingLeaveInfo.pending_credits', 2)
            ->has('pendingLeaveInfo.future_accrual')
        );
    }

    #[Test]
    public function credits_show_pending_leave_info_includes_future_accrual(): void
    {
        $admin = $this->createAdmin();
        $employee = $this->createAgent();
        $year = now()->year;

        // Create a leave credit record so monthly rate can be calculated
        \App\Models\LeaveCredit::factory()->create([
            'user_id' => $employee->id,
            'year' => $year,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => \Carbon\Carbon::create($year, 1, 15),
        ]);

        // Pending leave far in the future so future_accrual > 0
        \App\Models\LeaveRequest::factory()->create([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'status' => 'pending',
            'days_requested' => 1,
            'start_date' => \Carbon\Carbon::create($year, 12, 15),
            'end_date' => \Carbon\Carbon::create($year, 12, 15),
        ]);

        $response = $this->actingAs($admin)
            ->get("/form-requests/leave-requests/credits/{$employee->id}?year={$year}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Show')
            ->has('pendingLeaveInfo.future_accrual')
            ->where('pendingLeaveInfo.pending_count', 1)
        );
    }

    #[Test]
    public function credits_show_no_pending_has_zero_future_accrual(): void
    {
        $admin = $this->createAdmin();
        $employee = $this->createAgent();
        $year = now()->year;

        $response = $this->actingAs($admin)
            ->get("/form-requests/leave-requests/credits/{$employee->id}?year={$year}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Show')
            ->where('pendingLeaveInfo.pending_count', 0)
            ->where('pendingLeaveInfo.future_accrual', 0)
        );
    }

    #[Test]
    public function credits_index_includes_pending_count_per_employee(): void
    {
        $admin = $this->createAdmin();
        $employee = $this->createAgent();
        $year = now()->year;

        // Create credits so employee appears in list
        \App\Models\LeaveCredit::factory()->create([
            'user_id' => $employee->id,
            'year' => $year,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => \Carbon\Carbon::create($year, 1, 15),
        ]);

        \App\Models\LeaveRequest::factory()->create([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'status' => 'pending',
            'days_requested' => 1,
            'start_date' => \Carbon\Carbon::create($year, 6, 10),
            'end_date' => \Carbon\Carbon::create($year, 6, 10),
        ]);

        $response = $this->actingAs($admin)
            ->get("/form-requests/leave-requests/credits?year={$year}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Index')
            ->has('creditsData.data.0.pending_count')
            ->has('creditsData.data.0.pending_credits')
        );
    }

    // ── Credit Edit History Tests ───────────────────────────────────────

    #[Test]
    public function credits_show_includes_edit_history_for_admin(): void
    {
        $admin = $this->createAdmin();
        [$employee, $carryover] = $this->createEmployeeWithCarryover();

        // Make a carryover edit to generate activity log entry
        $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/carryover", [
                'carryover_credits' => 3.00,
                'year' => 2025,
                'reason' => 'Audit correction',
            ]);

        $response = $this->actingAs($admin)
            ->get("/form-requests/leave-requests/credits/{$employee->id}?year=2025");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Show')
            ->has('creditEditHistory')
            ->has('creditEditHistory.0', fn ($entry) => $entry
                ->where('event', 'carryover_manually_adjusted')
                ->where('reason', 'Audit correction')
                ->has('editor_name')
                ->has('old_value')
                ->has('new_value')
                ->has('created_at')
                ->etc()
            )
        );
    }

    #[Test]
    public function credits_show_edit_history_empty_for_agent_viewing_own(): void
    {
        $agent = $this->createAgent();

        $response = $this->actingAs($agent)
            ->get("/form-requests/leave-requests/credits/{$agent->id}?year=2025");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Show')
            ->where('creditEditHistory', [])
        );
    }

    #[Test]
    public function credits_show_edit_history_includes_monthly_adjustments(): void
    {
        $admin = $this->createAdmin();
        [$employee, $carryover, $month0Credit] = $this->createEmployeeWithCarryover();

        // Create a monthly credit to edit
        $monthlyCredit = LeaveCredit::factory()->create([
            'user_id' => $employee->id,
            'year' => 2025,
            'month' => 3,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => '2025-03-15',
        ]);

        // Edit the monthly credit
        $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/credits/{$monthlyCredit->id}", [
                'credits_earned' => 2.00,
                'reason' => 'Bonus credit',
            ]);

        $response = $this->actingAs($admin)
            ->get("/form-requests/leave-requests/credits/{$employee->id}?year=2025");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Show')
            ->has('creditEditHistory.0', fn ($entry) => $entry
                ->where('event', 'credit_manually_adjusted')
                ->where('reason', 'Bonus credit')
                ->where('month', 3)
                ->etc()
            )
        );
    }

    #[Test]
    public function credits_show_edit_history_records_editor_name(): void
    {
        $admin = $this->createAdmin();
        [$employee, $carryover] = $this->createEmployeeWithCarryover();

        // Edit carryover
        $this->actingAs($admin)
            ->put("/form-requests/leave-requests/credits/{$employee->id}/carryover", [
                'carryover_credits' => 5.00,
                'year' => 2025,
                'reason' => 'Year-end adjustment',
            ]);

        $response = $this->actingAs($admin)
            ->get("/form-requests/leave-requests/credits/{$employee->id}?year=2025");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Credits/Show')
            ->has('creditEditHistory.0', fn ($entry) => $entry
                ->where('editor_name', "{$admin->first_name} {$admin->last_name}")
                ->etc()
            )
        );
    }
}
