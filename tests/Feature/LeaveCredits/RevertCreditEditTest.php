<?php

namespace Tests\Feature\LeaveCredits;

use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\User;
use App\Services\LeaveCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Tests for the revert credit edit feature.
 */
class RevertCreditEditTest extends TestCase
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
    public function it_reverts_a_carryover_credit_edit(): void
    {
        // Create carryover with initial value
        $carryover = LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 3.00,
            'credits_from_previous_year' => 5.00,
            'forfeited_credits' => 2.00,
            'from_year' => 2025,
            'to_year' => 2026,
        ]);

        // Create the month=0 record for carryover
        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 0,
            'credits_earned' => 3.00,
            'credits_used' => 0,
            'credits_balance' => 3.00,
            'accrued_at' => '2026-01-01',
        ]);

        // Edit carryover via service (3.00 → 1.50), this logs activity
        $this->service->updateCarryoverCredits($carryover, 1.50, 'Initial adjustment', $this->admin->id);

        // Get the activity that was logged
        $activity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'carryover_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($activity);

        // Revert the edit
        $response = $this->actingAs($this->admin)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$activity->id}", [
                'reason' => 'Wrong data was entered',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('type', 'success');

        // The carryover should be restored to the old value (3.00)
        $carryover->refresh();
        $this->assertEquals(3.00, (float) $carryover->carryover_credits);

        // A new activity log should have been created for the revert
        $revertActivity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'carryover_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotEquals($activity->id, $revertActivity->id);
        $this->assertStringContains('Reverted edit from', $revertActivity->properties['reason']);
        $this->assertTrue($revertActivity->properties['is_revert']);
        $this->assertEquals($activity->id, $revertActivity->properties['reverted_activity_id']);
    }

    #[Test]
    public function it_reverts_a_monthly_credit_edit(): void
    {
        // Create monthly credit
        $credit = LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => '2026-01-15',
        ]);

        // Edit monthly credit (1.25 → 0.50)
        $this->service->updateMonthlyCredit($credit, 0.50, 'Correction', $this->admin->id);

        // Get the activity
        $activity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'credit_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($activity);

        // Revert the edit
        $response = $this->actingAs($this->admin)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$activity->id}");

        $response->assertRedirect();
        $response->assertSessionHas('type', 'success');

        // Credit should be restored to 1.25
        $credit->refresh();
        $this->assertEquals(1.25, (float) $credit->credits_earned);
        $this->assertEquals(1.25, (float) $credit->credits_balance);

        // Verify the revert activity has is_revert flag
        $revertActivity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'credit_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        $this->assertTrue($revertActivity->properties['is_revert']);
        $this->assertEquals($activity->id, $revertActivity->properties['reverted_activity_id']);
    }

    #[Test]
    public function agent_cannot_revert_credit_edit(): void
    {
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        // Create a dummy activity log entry
        $activity = Activity::create([
            'log_name' => 'leave-credits',
            'event' => 'carryover_manually_adjusted',
            'description' => 'Test',
            'properties' => [
                'user_id' => $this->employee->id,
                'year' => 2026,
                'old_carryover' => 3.00,
                'new_carryover' => 1.50,
            ],
            'causer_type' => User::class,
            'causer_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($agent)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$activity->id}");

        // Agent should be rejected by permission middleware (403 or redirect)
        $this->assertTrue(in_array($response->getStatusCode(), [302, 403]));
    }

    #[Test]
    public function it_rejects_reverting_non_latest_entry(): void
    {
        // Create monthly credits
        $credit = LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 1,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => '2026-01-15',
        ]);

        // First edit (1.25 → 0.50)
        $this->service->updateMonthlyCredit($credit, 0.50, 'First change', $this->admin->id);

        $firstActivity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'credit_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        // Second edit (0.50 → 2.00)
        $credit->refresh();
        $this->service->updateMonthlyCredit($credit, 2.00, 'Second change', $this->admin->id);

        // Try to revert the FIRST (non-latest) activity — should fail
        $response = $this->actingAs($this->admin)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$firstActivity->id}");

        $response->assertRedirect();
        $response->assertSessionHas('type', 'error');
        $response->assertSessionHas('message', 'Only the most recent credit edit can be reverted.');
    }

    #[Test]
    public function it_rejects_reverting_activity_for_wrong_user(): void
    {
        $otherEmployee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => '2024-06-01',
        ]);

        // Create activity for otherEmployee
        $activity = Activity::create([
            'log_name' => 'leave-credits',
            'event' => 'carryover_manually_adjusted',
            'description' => 'Test',
            'properties' => [
                'user_id' => $otherEmployee->id,
                'year' => 2026,
                'old_carryover' => 3.00,
                'new_carryover' => 1.50,
            ],
            'causer_type' => User::class,
            'causer_id' => $this->admin->id,
        ]);

        // Try to revert using the wrong user ID in URL
        $response = $this->actingAs($this->admin)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$activity->id}");

        $response->assertRedirect();
        $response->assertSessionHas('type', 'error');
        $response->assertSessionHas('message', 'This edit does not belong to the specified user.');
    }

    #[Test]
    public function it_reverts_with_optional_reason(): void
    {
        $credit = LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 2,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => '2026-02-15',
        ]);

        $this->service->updateMonthlyCredit($credit, 0.75, 'Test edit', $this->admin->id);

        $activity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'credit_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        // Revert WITHOUT a reason
        $response = $this->actingAs($this->admin)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$activity->id}");

        $response->assertRedirect();
        $response->assertSessionHas('type', 'success');

        // The activity reason should still have the auto-generated prefix
        $revertActivity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'credit_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        $this->assertStringContains('Reverted edit from', $revertActivity->properties['reason']);
    }

    #[Test]
    public function it_rejects_reverting_non_credit_edit_activity(): void
    {
        // Create activity with a non-edit event
        $activity = Activity::create([
            'log_name' => 'leave-credits',
            'event' => 'credit_accrued',
            'description' => 'Test accrual',
            'properties' => [
                'user_id' => $this->employee->id,
                'year' => 2026,
            ],
            'causer_type' => User::class,
            'causer_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$activity->id}");

        $response->assertRedirect();
        $response->assertSessionHas('type', 'error');
        $response->assertSessionHas('message', 'This activity log entry is not a credit edit.');
    }

    /**
     * Custom assertion helper: check if string contains a substring.
     */
    protected function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }

    #[Test]
    public function it_cascade_reverts_a_carryover_revert_entry(): void
    {
        // Create carryover with initial value
        $carryover = LeaveCreditCarryover::factory()->create([
            'user_id' => $this->employee->id,
            'carryover_credits' => 3.00,
            'credits_from_previous_year' => 5.00,
            'forfeited_credits' => 2.00,
            'from_year' => 2025,
            'to_year' => 2026,
        ]);

        LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 0,
            'credits_earned' => 3.00,
            'credits_used' => 0,
            'credits_balance' => 3.00,
            'accrued_at' => '2026-01-01',
        ]);

        // Step 1: Edit carryover (3.00 → 1.50)
        $this->service->updateCarryoverCredits($carryover, 1.50, 'Initial adjustment', $this->admin->id);

        $originalActivity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'carryover_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        // Step 2: Revert that edit (1.50 → 3.00), creates a revert entry
        $this->actingAs($this->admin)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$originalActivity->id}", [
                'reason' => 'Wrong value',
            ]);

        $revertActivity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'carryover_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        $this->assertTrue($revertActivity->properties['is_revert']);
        $carryover->refresh();
        $this->assertEquals(3.00, (float) $carryover->carryover_credits);

        // Step 3: Cascade revert — undo the revert (should delete revert entry, restore value to 1.50)
        $response = $this->actingAs($this->admin)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$revertActivity->id}");

        $response->assertRedirect();
        $response->assertSessionHas('type', 'success');

        // The revert entry should be deleted
        $this->assertNull(Activity::find($revertActivity->id));

        // Value should be restored to what the revert changed from (1.50)
        $carryover->refresh();
        $this->assertEquals(1.50, (float) $carryover->carryover_credits);

        // The original entry should now be the latest
        $latestActivity = Activity::where('log_name', 'leave-credits')
            ->whereIn('event', ['carryover_manually_adjusted', 'credit_manually_adjusted'])
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        $this->assertEquals($originalActivity->id, $latestActivity->id);
    }

    #[Test]
    public function it_cascade_reverts_a_monthly_credit_revert_entry(): void
    {
        $credit = LeaveCredit::create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'month' => 3,
            'credits_earned' => 1.25,
            'credits_used' => 0,
            'credits_balance' => 1.25,
            'accrued_at' => '2026-03-15',
        ]);

        // Step 1: Edit monthly credit (1.25 → 0.50)
        $this->service->updateMonthlyCredit($credit, 0.50, 'Correction', $this->admin->id);

        $originalActivity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'credit_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        // Step 2: Revert that edit (0.50 → 1.25)
        $this->actingAs($this->admin)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$originalActivity->id}");

        $revertActivity = Activity::where('log_name', 'leave-credits')
            ->where('event', 'credit_manually_adjusted')
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        $this->assertTrue($revertActivity->properties['is_revert']);
        $credit->refresh();
        $this->assertEquals(1.25, (float) $credit->credits_earned);

        // Step 3: Cascade revert — undo the revert (deletes revert entry, restores value to 0.50)
        $response = $this->actingAs($this->admin)
            ->post("/form-requests/leave-requests/credits/{$this->employee->id}/revert/{$revertActivity->id}");

        $response->assertRedirect();
        $response->assertSessionHas('type', 'success');

        // The revert entry should be deleted
        $this->assertNull(Activity::find($revertActivity->id));

        // Value should be restored to 0.50 (what revert changed from)
        $credit->refresh();
        $this->assertEquals(0.50, (float) $credit->credits_earned);

        // The original entry should now be the latest
        $latestActivity = Activity::where('log_name', 'leave-credits')
            ->whereIn('event', ['carryover_manually_adjusted', 'credit_manually_adjusted'])
            ->where('properties->user_id', $this->employee->id)
            ->orderByDesc('id')
            ->first();

        $this->assertEquals($originalActivity->id, $latestActivity->id);
    }
}
