<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Http\Middleware\EnsureUserHasSchedule;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\Site;
use App\Models\SplCredit;
use App\Models\User;
use App\Services\SplCreditService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SoloParentLeaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            EnsureUserHasSchedule::class,
        ]);
    }

    protected function createSoloParentAgent(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'Agent',
            'is_approved' => true,
            'is_solo_parent' => true,
            'hired_date' => now()->subYear(),
        ], $overrides));

        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return $user;
    }

    // ==================== SPL Credit Model Tests ====================

    #[Test]
    public function spl_credits_are_created_with_default_7_days(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        $record = SplCredit::ensureCreditsExist($user->id);

        $this->assertEquals(7.00, (float) $record->total_credits);
        $this->assertEquals(0.00, (float) $record->credits_used);
        $this->assertEquals(7.00, (float) $record->credits_balance);
        $this->assertEquals(now()->year, $record->year);
    }

    #[Test]
    public function ensure_credits_exist_is_idempotent(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);

        $record1 = SplCredit::ensureCreditsExist($user->id);
        $record2 = SplCredit::ensureCreditsExist($user->id);

        $this->assertEquals($record1->id, $record2->id);
        $this->assertDatabaseCount('spl_credits', 1);
    }

    #[Test]
    public function get_balance_returns_full_credits_when_no_record_exists(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        $balance = SplCredit::getBalance($user->id);

        $this->assertEquals(7.00, $balance);
    }

    #[Test]
    public function get_balance_returns_actual_balance(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        SplCredit::factory()->create([
            'user_id' => $user->id,
            'credits_used' => 3.0,
            'credits_balance' => 4.0,
        ]);

        $this->assertEquals(4.0, SplCredit::getBalance($user->id));
    }

    // ==================== SPL Credit Service Tests ====================

    #[Test]
    public function spl_credit_service_returns_correct_summary(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        SplCredit::factory()->create([
            'user_id' => $user->id,
            'credits_used' => 2.5,
            'credits_balance' => 4.5,
        ]);

        $service = app(SplCreditService::class);
        $summary = $service->getSummary($user);

        $this->assertEquals(7.0, $summary['total']);
        $this->assertEquals(2.5, $summary['used']);
        $this->assertEquals(4.5, $summary['balance']);
        $this->assertEquals(now()->year, $summary['year']);
    }

    #[Test]
    public function check_spl_credit_deduction_with_sufficient_credits(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        SplCredit::factory()->create(['user_id' => $user->id]);

        $service = app(SplCreditService::class);
        $result = $service->checkSplCreditDeduction($user, 3.0);

        $this->assertTrue($result['should_deduct']);
        $this->assertEquals(3.0, $result['credits_to_deduct']);
        $this->assertEquals(7.0, $result['available']);
        $this->assertFalse($result['insufficient']);
    }

    #[Test]
    public function check_spl_credit_deduction_with_insufficient_credits(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        SplCredit::factory()->withUsedCredits(5.0)->create(['user_id' => $user->id]);

        $service = app(SplCreditService::class);
        $result = $service->checkSplCreditDeduction($user, 3.0);

        $this->assertTrue($result['should_deduct']);
        $this->assertEquals(2.0, $result['credits_to_deduct']);
        $this->assertEquals(2.0, $result['available']);
        $this->assertTrue($result['insufficient']);
    }

    #[Test]
    public function check_spl_credit_deduction_with_zero_balance(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        SplCredit::factory()->withUsedCredits(7.0)->create(['user_id' => $user->id]);

        $service = app(SplCreditService::class);
        $result = $service->checkSplCreditDeduction($user, 1.0);

        $this->assertFalse($result['should_deduct']);
        $this->assertEquals(0, $result['credits_to_deduct']);
        $this->assertTrue($result['insufficient']);
    }

    #[Test]
    public function deduct_credits_updates_balance_correctly(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        SplCredit::factory()->create(['user_id' => $user->id]);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SPL',
            'start_date' => now()->addDays(7)->format('Y-m-d'),
            'end_date' => now()->addDays(9)->format('Y-m-d'),
            'days_requested' => 3,
        ]);

        $service = app(SplCreditService::class);
        $result = $service->deductCredits($leaveRequest, 2.5);

        $this->assertTrue($result);

        $record = SplCredit::forUser($user->id)->forYear(now()->year)->first();
        $this->assertEquals(2.5, (float) $record->credits_used);
        $this->assertEquals(4.5, (float) $record->credits_balance);

        $leaveRequest->refresh();
        $this->assertEquals(2.5, (float) $leaveRequest->credits_deducted);
    }

    #[Test]
    public function restore_credits_on_cancellation(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        SplCredit::factory()->withUsedCredits(3.0)->create(['user_id' => $user->id]);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SPL',
            'credits_deducted' => 3.0,
            'credits_year' => now()->year,
        ]);

        $service = app(SplCreditService::class);
        $result = $service->restoreCredits($leaveRequest);

        $this->assertTrue($result);

        $record = SplCredit::forUser($user->id)->forYear(now()->year)->first();
        $this->assertEquals(0.0, (float) $record->credits_used);
        $this->assertEquals(7.0, (float) $record->credits_balance);
    }

    #[Test]
    public function restore_credits_does_not_exceed_total(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        SplCredit::factory()->create([
            'user_id' => $user->id,
            'credits_used' => 1.0,
            'credits_balance' => 6.0,
        ]);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SPL',
            'credits_deducted' => 3.0,
            'credits_year' => now()->year,
        ]);

        $service = app(SplCreditService::class);
        $service->restoreCredits($leaveRequest);

        $record = SplCredit::forUser($user->id)->forYear(now()->year)->first();
        $this->assertEquals(7.0, (float) $record->credits_balance);
        $this->assertEquals(0.0, (float) $record->credits_used);
    }

    // ==================== Validation Tests ====================

    #[Test]
    public function validate_spl_request_rejects_non_solo_parent(): void
    {
        $user = User::factory()->create(['is_solo_parent' => false]);

        $service = app(SplCreditService::class);
        $errors = $service->validateSplRequest($user);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Solo Parent status', $errors[0]);
    }

    #[Test]
    public function validate_spl_request_passes_for_solo_parent(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);

        $service = app(SplCreditService::class);
        $errors = $service->validateSplRequest($user);

        $this->assertEmpty($errors);
    }

    // ==================== Controller Integration Tests ====================

    #[Test]
    public function solo_parent_can_see_spl_credits_on_create_form(): void
    {
        $user = $this->createSoloParentAgent();

        $response = $this->actingAs($user)->get(route('leave-requests.create'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Create')
                ->has('splCreditsSummary')
                ->where('isSoloParent', true)
            );
    }

    #[Test]
    public function non_solo_parent_does_not_see_spl_credits(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'is_solo_parent' => false,
            'hired_date' => now()->subYear(),
        ]);
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('leave-requests.create'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Create')
                ->where('isSoloParent', false)
                ->where('splCreditsSummary', null)
            );
    }

    #[Test]
    public function solo_parent_can_store_spl_request(): void
    {
        $user = $this->createSoloParentAgent();

        // Find a Monday that's at least 3 days in the future
        $startDate = now()->addDays(3);
        while ($startDate->isWeekend()) {
            $startDate->addDay();
        }
        $endDate = $startDate->copy();

        $response = $this->actingAs($user)->post(route('leave-requests.store'), [
            'leave_type' => 'SPL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'reason' => 'Solo Parent Leave for child care needs',
            'campaign_department' => 'Tech',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $user->id,
            'leave_type' => 'SPL',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function non_solo_parent_cannot_store_spl_request(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'is_solo_parent' => false,
            'hired_date' => now()->subYear(),
        ]);
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $startDate = now()->addDays(3);
        while ($startDate->isWeekend()) {
            $startDate->addDay();
        }

        $response = $this->actingAs($user)->post(route('leave-requests.store'), [
            'leave_type' => 'SPL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $startDate->format('Y-m-d'),
            'reason' => 'Solo Parent Leave attempted',
            'campaign_department' => 'Tech',
        ]);

        $response->assertSessionHasErrors('validation');
        $this->assertDatabaseMissing('leave_requests', [
            'user_id' => $user->id,
            'leave_type' => 'SPL',
        ]);
    }

    #[Test]
    public function admin_can_approve_spl_request_with_auto_fifo_credits(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $hr = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
        ]);
        $user = $this->createSoloParentAgent(['email' => 'employee@example.com']);

        // A single weekday in the future
        $leaveDate = now()->addDays(7);
        while ($leaveDate->isWeekend()) {
            $leaveDate->addDay();
        }

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'SPL',
            'start_date' => $leaveDate->format('Y-m-d'),
            'end_date' => $leaveDate->format('Y-m-d'),
            'days_requested' => 1,
        ]);

        // Pre-store day record from submission (like the store method does)
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $leaveDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => false,
        ]);

        // Admin approves first
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approved SPL request.',
        ]);

        // HR approves second — triggers full approval and auto-FIFO
        $response = $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approved SPL request.',
        ]);

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertNotNull($leaveRequest->admin_approved_at);
        $this->assertNotNull($leaveRequest->hr_approved_at);
        $this->assertEquals('approved', $leaveRequest->status);

        // Verify auto-FIFO created day record with spl_credited status
        $dayRecord = $leaveRequest->days()->where('date', $leaveDate->format('Y-m-d'))->first();
        $this->assertNotNull($dayRecord);
        $this->assertEquals('spl_credited', $dayRecord->day_status);
    }

    #[Test]
    public function spl_approval_with_partial_credits_auto_denies_uncovered_days(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $hr = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
        ]);
        $user = $this->createSoloParentAgent(['email' => 'employee2@example.com']);

        // Use up 6 credits, leaving only 1
        SplCredit::factory()->withUsedCredits(6.0)->create(['user_id' => $user->id]);

        // Find 2 consecutive weekdays
        $startDate = now()->addDays(7);
        while ($startDate->isWeekend()) {
            $startDate->addDay();
        }
        $endDate = $startDate->copy()->addDay();
        while ($endDate->isWeekend()) {
            $endDate->addDay();
        }

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'SPL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 2,
        ]);

        // Pre-store day records from submission
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $startDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => false,
        ]);
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $endDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => false,
        ]);

        // Admin approves first
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approved partial SPL.',
        ]);

        // HR approves second — triggers full approval and auto-FIFO with auto-denial
        $response = $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approved partial SPL.',
        ]);

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertTrue((bool) $leaveRequest->has_partial_denial);
        $this->assertEquals(1, $leaveRequest->approved_days);

        // First day should be spl_credited (FIFO)
        $day1 = $leaveRequest->days()->where('date', $startDate->format('Y-m-d'))->first();
        $this->assertNotNull($day1);
        $this->assertEquals('spl_credited', $day1->day_status);

        // Second day should NOT have a day record — it was auto-denied
        $day2 = $leaveRequest->days()->where('date', $endDate->format('Y-m-d'))->first();
        $this->assertNull($day2);

        // Second day should have a denied date record
        $deniedDate = $leaveRequest->deniedDates()->where('denied_date', $endDate->format('Y-m-d'))->first();
        $this->assertNotNull($deniedDate);
        $this->assertEquals('Auto-denied: Insufficient SPL credits', $deniedDate->denial_reason);

        // Only 1 credit should have been deducted
        $this->assertEquals(1.0, (float) $leaveRequest->credits_deducted);
    }

    #[Test]
    public function spl_approval_with_zero_credits_auto_denies_all_days(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $hr = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
        ]);
        $user = $this->createSoloParentAgent(['email' => 'employee3@example.com']);

        // Use up all 7 credits
        SplCredit::factory()->withUsedCredits(7.0)->create(['user_id' => $user->id]);

        $leaveDate = now()->addDays(7);
        while ($leaveDate->isWeekend()) {
            $leaveDate->addDay();
        }

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'SPL',
            'start_date' => $leaveDate->format('Y-m-d'),
            'end_date' => $leaveDate->format('Y-m-d'),
            'days_requested' => 1,
        ]);

        // Pre-store day record from submission
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $leaveDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => false,
        ]);

        // Admin approves first
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approved zero-credit SPL.',
        ]);

        // HR approves second — triggers full approval
        $response = $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approved zero-credit SPL.',
        ]);

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertTrue((bool) $leaveRequest->has_partial_denial);
        $this->assertEquals(0, $leaveRequest->approved_days);

        // Day should NOT have a day record — it was auto-denied
        $dayRecord = $leaveRequest->days()->where('date', $leaveDate->format('Y-m-d'))->first();
        $this->assertNull($dayRecord);

        // Day should have a denied date record
        $deniedDate = $leaveRequest->deniedDates()->where('denied_date', $leaveDate->format('Y-m-d'))->first();
        $this->assertNotNull($deniedDate);
        $this->assertEquals('Auto-denied: Insufficient SPL credits', $deniedDate->denial_reason);

        // No credits should have been deducted
        $this->assertEquals(0.0, (float) $leaveRequest->credits_deducted);
    }

    #[Test]
    public function spl_half_day_credits_are_calculated_correctly(): void
    {
        $user = User::factory()->create(['is_solo_parent' => true]);
        SplCredit::factory()->create(['user_id' => $user->id]);

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => 'SPL',
            'start_date' => now()->addDays(7)->format('Y-m-d'),
            'end_date' => now()->addDays(9)->format('Y-m-d'),
            'days_requested' => 3,
        ]);

        // Deduct 1.5 credits (one whole day + one half day)
        $service = app(SplCreditService::class);
        $service->deductCredits($leaveRequest, 1.5);

        $record = SplCredit::forUser($user->id)->forYear(now()->year)->first();
        $this->assertEquals(1.5, (float) $record->credits_used);
        $this->assertEquals(5.5, (float) $record->credits_balance);
    }

    // ==================== Account Controller Tests ====================

    #[Test]
    public function admin_can_update_solo_parent_status(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'is_solo_parent' => false,
            'hired_date' => now()->subYear(),
            'email' => 'testuser@primehubmail.com',
        ]);

        $response = $this->actingAs($admin)->put(route('accounts.update', $user), [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role' => $user->role,
            'hired_date' => $user->hired_date->format('Y-m-d'),
            'is_solo_parent' => 1,
        ]);

        $response->assertRedirect(route('accounts.index'));

        $user->refresh();
        $this->assertTrue($user->is_solo_parent);
    }

    #[Test]
    public function solo_parent_status_shows_on_account_edit(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'is_solo_parent' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('accounts.edit', $user));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Account/Edit')
                ->where('user.is_solo_parent', true)
            );
    }

    // ==================== LeaveRequest Model Tests ====================

    #[Test]
    public function leave_request_requires_spl_credits_returns_true_for_spl(): void
    {
        $leaveRequest = LeaveRequest::factory()->create(['leave_type' => 'SPL']);
        $this->assertTrue($leaveRequest->requiresSplCredits());
    }

    #[Test]
    public function leave_request_requires_spl_credits_returns_false_for_vl(): void
    {
        $leaveRequest = LeaveRequest::factory()->create(['leave_type' => 'VL']);
        $this->assertFalse($leaveRequest->requiresSplCredits());
    }

    // ==================== Leave Credits Page Tests ====================

    #[Test]
    public function solo_parent_sees_spl_credits_on_credits_page(): void
    {
        $user = $this->createSoloParentAgent();
        SplCredit::factory()->withUsedCredits(2.0)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('leave-requests.credits.show', $user));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Credits/Show')
                ->has('splCreditsSummary')
                ->where('splCreditsSummary.total', fn ($val) => (float) $val === 7.0)
                ->where('splCreditsSummary.used', fn ($val) => (float) $val === 2.0)
                ->where('splCreditsSummary.balance', fn ($val) => (float) $val === 5.0)
            );
    }

    #[Test]
    public function non_solo_parent_does_not_see_spl_credits_on_credits_page(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'is_solo_parent' => false,
            'hired_date' => now()->subYear(),
        ]);
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('leave-requests.credits.show', $user));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Credits/Show')
                ->where('splCreditsSummary', null)
            );
    }

    // ==================== SPL Edit Page Tests ====================

    #[Test]
    public function edit_page_loads_spl_day_settings_for_spl_request(): void
    {
        $user = $this->createSoloParentAgent();

        $startDate = now()->addDays(3);
        while ($startDate->isWeekend()) {
            $startDate->addDay();
        }
        $endDate = $startDate->copy()->addDay();
        while ($endDate->isWeekend()) {
            $endDate->addDay();
        }

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'SPL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 2,
        ]);

        // Create day records like the store method does
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $startDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => true,
        ]);
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $endDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => false,
        ]);

        $response = $this->actingAs($user)->get(route('leave-requests.edit', $leaveRequest));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Edit')
                ->has('splDaySettings', 2)
                ->where('splDaySettings.0.date', $startDate->format('Y-m-d'))
                ->where('splDaySettings.0.is_half_day', true)
                ->where('splDaySettings.1.date', $endDate->format('Y-m-d'))
                ->where('splDaySettings.1.is_half_day', false)
            );
    }

    #[Test]
    public function update_spl_request_updates_half_day_settings(): void
    {
        $user = $this->createSoloParentAgent();

        $startDate = now()->addDays(3);
        while ($startDate->isWeekend()) {
            $startDate->addDay();
        }

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'SPL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $startDate->format('Y-m-d'),
            'days_requested' => 1,
        ]);

        // Existing day record with whole day
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $startDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => false,
        ]);

        // Update to half day
        $response = $this->actingAs($user)->put(route('leave-requests.update', $leaveRequest), [
            'leave_type' => 'SPL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $startDate->format('Y-m-d'),
            'reason' => 'Updated reason',
            'campaign_department' => 'Tech',
            'spl_day_settings' => [
                ['date' => $startDate->format('Y-m-d'), 'is_half_day' => true],
            ],
        ]);

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals(0.5, (float) $leaveRequest->days_requested);

        $dayRecord = $leaveRequest->days()->first();
        $this->assertTrue((bool) $dayRecord->is_half_day);
    }

    #[Test]
    public function spl_approval_skips_weekends_in_credit_assignment(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $hr = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
        ]);
        $user = $this->createSoloParentAgent(['email' => 'weekend-test@example.com']);

        // Find a Thursday so the range Thu-Tue spans a weekend (5 calendar days, 3 weekdays)
        $startDate = now()->addDays(7);
        while ($startDate->dayOfWeek !== Carbon::THURSDAY) {
            $startDate->addDay();
        }
        $endDate = $startDate->copy()->addDays(5); // Tuesday (Thu, Fri, [Sat, Sun], Mon, Tue = 4 weekdays)

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'SPL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 4,
        ]);

        // Pre-store day records for weekdays only (like the store method does)
        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            if ($current->isWeekday()) {
                LeaveRequestDay::create([
                    'leave_request_id' => $leaveRequest->id,
                    'date' => $current->format('Y-m-d'),
                    'day_status' => 'pending',
                    'is_half_day' => false,
                ]);
            }
            $current->addDay();
        }

        // Admin approves
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approved.',
        ]);

        // HR approves — triggers full approval and auto-FIFO
        $response = $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approved.',
        ]);

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);

        // Should have exactly 4 day records (weekdays only), no weekend records
        $dayRecords = $leaveRequest->days()->orderBy('date')->get();
        $this->assertCount(4, $dayRecords);

        foreach ($dayRecords as $day) {
            $dayOfWeek = Carbon::parse($day->date)->dayOfWeek;
            $this->assertNotEquals(Carbon::SATURDAY, $dayOfWeek, "Saturday {$day->date} should not have a day record");
            $this->assertNotEquals(Carbon::SUNDAY, $dayOfWeek, "Sunday {$day->date} should not have a day record");
            $this->assertEquals('spl_credited', $day->day_status);
        }

        // Verify credits used = 4 (not 6 which would include weekends)
        $splCredit = SplCredit::where('user_id', $user->id)->where('year', $startDate->year)->first();
        $this->assertEquals(4.0, (float) $splCredit->credits_used);
        $this->assertEquals(3.0, (float) $splCredit->credits_balance);
    }

    #[Test]
    public function admin_can_override_half_day_during_spl_approval(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $hr = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
        ]);
        $user = $this->createSoloParentAgent(['email' => 'halfday-override@example.com']);

        // Find 2 consecutive weekdays
        $startDate = now()->addDays(7);
        while ($startDate->isWeekend()) {
            $startDate->addDay();
        }
        $endDate = $startDate->copy()->addDay();
        while ($endDate->isWeekend()) {
            $endDate->addDay();
        }

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'SPL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 2,
        ]);

        // Pre-store day records (both full-day from submission)
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $startDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => false,
        ]);
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $endDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => false,
        ]);

        // Admin approves and overrides first day to half-day
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approved with half-day override.',
            'spl_half_day_overrides' => [
                $startDate->format('Y-m-d') => true,
            ],
        ]);

        // HR approves — triggers full approval and auto-FIFO
        $response = $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approved SPL request.',
        ]);

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);

        // First day should be half-day (0.5 credit), second day full (1.0 credit) = 1.5 total
        $dayRecords = $leaveRequest->days()->orderBy('date')->get();
        $this->assertCount(2, $dayRecords);

        $this->assertTrue((bool) $dayRecords[0]->is_half_day);
        $this->assertFalse((bool) $dayRecords[1]->is_half_day);

        $splCredit = SplCredit::where('user_id', $user->id)->where('year', $startDate->year)->first();
        $this->assertEquals(1.5, (float) $splCredit->credits_used);
        $this->assertEquals(5.5, (float) $splCredit->credits_balance);
    }

    #[Test]
    public function spl_approval_auto_downgrades_to_half_day_when_partial_credits_remain(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $hr = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
        ]);
        $user = $this->createSoloParentAgent(['email' => 'autodowngrade@example.com']);

        // Use up 5.5 credits, leaving only 1.5
        SplCredit::factory()->withUsedCredits(5.5)->create(['user_id' => $user->id]);

        // Find 2 consecutive weekdays
        $startDate = now()->addDays(7);
        while ($startDate->isWeekend()) {
            $startDate->addDay();
        }
        $endDate = $startDate->copy()->addDay();
        while ($endDate->isWeekend()) {
            $endDate->addDay();
        }

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'leave_type' => 'SPL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_requested' => 2,
        ]);

        // Pre-store day records (both full-day from submission)
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $startDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => false,
        ]);
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $endDate->format('Y-m-d'),
            'day_status' => 'pending',
            'is_half_day' => false,
        ]);

        // Admin approves first
        $this->actingAs($admin)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'Admin approved auto-downgrade test.',
        ]);

        // HR approves second — triggers full approval with auto-downgrade
        $response = $this->actingAs($hr)->post(route('leave-requests.approve', $leaveRequest), [
            'review_notes' => 'HR approved auto-downgrade test.',
        ]);

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);

        // First day should be full (1.0 credit), second day auto-downgraded to half (0.5 credit)
        // Both days should be covered — no denied dates
        $dayRecords = $leaveRequest->days()->orderBy('date')->get();
        $this->assertCount(2, $dayRecords);

        // Day 1: full day
        $this->assertFalse((bool) $dayRecords[0]->is_half_day);
        $this->assertEquals('spl_credited', $dayRecords[0]->day_status);

        // Day 2: auto-downgraded to half day
        $this->assertTrue((bool) $dayRecords[1]->is_half_day);
        $this->assertEquals('spl_credited', $dayRecords[1]->day_status);

        // No denied dates — both days covered
        $this->assertEquals(0, $leaveRequest->deniedDates()->count());

        // Total credits deducted: 1.0 + 0.5 = 1.5
        $this->assertEquals(1.5, (float) $leaveRequest->credits_deducted);

        $splCredit = SplCredit::where('user_id', $user->id)->where('year', $startDate->year)->first();
        $this->assertEquals(7.0, (float) $splCredit->credits_used); // 5.5 + 1.5
        $this->assertEquals(0.0, (float) $splCredit->credits_balance);
    }
}
