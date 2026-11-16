<?php

namespace Tests\Feature\LeaveCredits;

use App\Http\Middleware\EnsureUserHasSchedule;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for the Leave Credits Management actions on the credits list.
 */
class LeaveCreditsManagementTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = '/form-requests/leave-requests/credits';

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
            'hired_date' => '2023-01-15',
        ]);
    }

    private function createAgent(string $hiredDate): User
    {
        return User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => $hiredDate,
        ]);
    }

    private function accrualDate(User $user, int $year, int $month): ?string
    {
        return LeaveCredit::forUser($user->id)
            ->forMonth($year, $month)
            ->first()
            ?->accrued_at
            ->format('Y-m-d');
    }

    #[Test]
    public function process_monthly_accruals_backfills_only_credits_that_have_fallen_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17'));

        $admin = $this->createAdmin();
        $dueOnThe13th = $this->createAgent('2026-04-13');
        $dueOnThe20th = $this->createAgent('2026-01-20');
        $monthEnd = $this->createAgent('2024-03-10');

        $response = $this->actingAs($admin)->postJson(self::BASE_URL.'/accruals/process');

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertSame(7, LeaveCredit::forUser($dueOnThe13th->id)->count());
        $this->assertSame('2026-11-13', $this->accrualDate($dueOnThe13th, 2026, 11));

        $this->assertSame('2026-10-20', $this->accrualDate($dueOnThe20th, 2026, 10));
        $this->assertNull($this->accrualDate($dueOnThe20th, 2026, 11));

        $this->assertSame('2026-10-31', $this->accrualDate($monthEnd, 2026, 10));
        $this->assertNull($this->accrualDate($monthEnd, 2026, 11));
    }

    #[Test]
    public function process_regularization_transfers_only_for_next_year_regularization(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-06-20'));

        $admin = $this->createAdmin();
        $regularizedNextYear = $this->createAgent('2026-11-14');
        $regularizedSameYear = $this->createAgent('2026-04-13');

        foreach ([$regularizedNextYear, $regularizedSameYear] as $user) {
            LeaveCredit::factory()->create([
                'user_id' => $user->id,
                'year' => 2026,
                'month' => 12,
                'credits_earned' => 1.25,
                'credits_used' => 0,
                'credits_balance' => 1.25,
            ]);
        }

        $response = $this->actingAs($admin)->postJson(self::BASE_URL.'/regularization/process', [
            'year' => 2027,
        ]);

        $response->assertOk()->assertJsonPath('summary.processed', 1);

        $this->assertDatabaseHas('leave_credit_carryovers', [
            'user_id' => $regularizedNextYear->id,
            'from_year' => 2026,
            'to_year' => 2027,
            'carryover_credits' => 1.25,
            'is_first_regularization' => true,
        ]);
        $this->assertSame(0, LeaveCreditCarryover::forUser($regularizedSameYear->id)->count());
    }

    #[Test]
    public function bulk_management_actions_require_the_edit_permission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17'));

        $agent = $this->createAgent('2026-04-13');
        $teamLead = User::factory()->create([
            'role' => 'Team Lead',
            'is_approved' => true,
            'hired_date' => '2023-01-15',
        ]);
        $carryover = LeaveCreditCarryover::factory()->create([
            'user_id' => $teamLead->id,
            'from_year' => 2025,
            'to_year' => 2026,
            'cash_converted' => false,
        ]);

        $requests = [
            '/accruals/process' => [],
            '/carryovers/process' => ['from_year' => 2025],
            '/regularization/process' => ['year' => 2026],
            '/cash-conversion/process' => ['year' => 2026],
        ];

        foreach ([$agent, $teamLead] as $actor) {
            foreach ($requests as $path => $payload) {
                $this->actingAs($actor)
                    ->postJson(self::BASE_URL.$path, $payload)
                    ->assertStatus(403);
            }
        }

        $this->assertSame(0, LeaveCredit::count());
        $this->assertSame(1, LeaveCreditCarryover::count());
        $this->assertFalse($carryover->fresh()->cash_converted);
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $months  Month => [used, balance]
     */
    private function seedCredits(User $user, int $year, array $months): void
    {
        foreach ($months as $month => [$used, $balance]) {
            LeaveCredit::factory()->create([
                'user_id' => $user->id,
                'year' => $year,
                'month' => $month,
                'credits_earned' => $used + $balance,
                'credits_used' => $used,
                'credits_balance' => $balance,
            ]);
        }
    }

    private function approvedLeave(User $user, string $type, string $filedOn, string $startsOn, float $days, float $deducted, int $creditsYear): LeaveRequest
    {
        return LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type' => $type,
            'status' => 'approved',
            'days_requested' => $days,
            'credits_deducted' => $deducted,
            'credits_year' => $creditsYear,
            'start_date' => $startsOn,
            'end_date' => $startsOn,
            'created_at' => $filedOn,
        ]);
    }

    private function creditsUsed(User $user, int $year): float
    {
        return (float) LeaveCredit::forUser($user->id)->forYear($year)->sum('credits_used');
    }

    #[Test]
    public function year_mismatch_fix_moves_exactly_the_credits_originally_deducted(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-10'));

        $admin = $this->createAdmin();
        $employee = $this->createAgent('2024-01-10');
        $this->seedCredits($employee, 2026, [9 => [0, 1.25], 10 => [0, 1.25], 11 => [0, 1.25], 12 => [0, 1.25]]);
        $this->seedCredits($employee, 2027, [1 => [1.25, 0], 2 => [1.25, 0], 3 => [0.5, 0.75]]);
        $leave = $this->approvedLeave($employee, 'VL', '2026-12-10', '2027-01-05', 5, 3, 2027);

        $this->actingAs($admin)->getJson(self::BASE_URL.'/mismatch/scan')
            ->assertOk()
            ->assertJson(['total_requests' => 1, 'fixable_requests' => 1, 'skipped_requests' => 0, 'total_credits' => 3]);

        $this->actingAs($admin)->postJson(self::BASE_URL.'/mismatch/fix')
            ->assertOk()
            ->assertJsonPath('summary.fixed', 1)
            ->assertJsonPath('summary.skipped', 0)
            ->assertJsonPath('summary.total_credits_moved', 3);

        $leave->refresh();
        $this->assertSame(2026, $leave->credits_year);
        $this->assertEquals(3, $leave->credits_deducted);
        $this->assertEquals(3, $this->creditsUsed($employee, 2026));
        $this->assertEquals(0, $this->creditsUsed($employee, 2027));

        $this->actingAs($admin)->getJson(self::BASE_URL.'/mismatch/scan')
            ->assertJson(['total_requests' => 0]);
    }

    #[Test]
    public function year_mismatch_ignores_leave_types_that_do_not_use_the_filing_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-10'));

        $admin = $this->createAdmin();
        $employee = $this->createAgent('2024-01-10');
        $this->seedCredits($employee, 2026, [11 => [0, 1.25], 12 => [1.0, 0.25]]);
        $this->seedCredits($employee, 2027, [1 => [1.25, 0], 2 => [1.25, 0], 3 => [0.5, 0.75]]);

        $leaves = [
            $this->approvedLeave($employee, 'LOA', '2026-12-10', '2027-01-05', 2, 2, 2027),
            $this->approvedLeave($employee, 'SPL', '2026-12-10', '2027-01-06', 1, 1, 2027),
            $this->approvedLeave($employee, 'SL', '2027-01-03', '2026-12-30', 1, 1, 2026),
        ];

        $this->actingAs($admin)->getJson(self::BASE_URL.'/mismatch/scan')
            ->assertOk()
            ->assertJson(['total_requests' => 0]);

        $this->actingAs($admin)->postJson(self::BASE_URL.'/mismatch/fix')
            ->assertOk()
            ->assertJsonPath('summary.fixed', 0);

        $this->assertEquals(1.0, $this->creditsUsed($employee, 2026));
        $this->assertEquals(3.0, $this->creditsUsed($employee, 2027));
        $this->assertSame([2027, 2027, 2026], array_map(fn (LeaveRequest $leave) => $leave->fresh()->credits_year, $leaves));
    }

    #[Test]
    public function year_mismatch_fix_skips_requests_the_correct_year_cannot_cover(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-05-10'));

        $admin = $this->createAdmin();
        $employee = $this->createAgent('2024-01-10');
        $this->seedCredits($employee, 2026, [12 => [0, 2.5]]);
        $this->seedCredits($employee, 2027, [1 => [1.25, 0], 2 => [1.25, 0], 3 => [1.25, 0], 4 => [0.25, 1.0]]);
        $covered = $this->approvedLeave($employee, 'VL', '2026-12-10', '2027-01-05', 2, 2, 2027);
        $uncovered = $this->approvedLeave($employee, 'VL', '2026-12-11', '2027-02-05', 2, 2, 2027);

        $scan = $this->actingAs($admin)->getJson(self::BASE_URL.'/mismatch/scan')
            ->assertOk()
            ->assertJson(['total_requests' => 2, 'fixable_requests' => 1, 'skipped_requests' => 1, 'total_credits' => 2]);

        $this->assertSame(
            [$covered->id => true, $uncovered->id => false],
            collect($scan->json('users.0.requests'))->pluck('can_fix', 'id')->all()
        );

        $this->actingAs($admin)->postJson(self::BASE_URL.'/mismatch/fix')
            ->assertOk()
            ->assertJsonPath('summary.fixed', 1)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('summary.total_credits_moved', 2)
            ->assertJsonPath('summary.skipped_details.0.leave_request_id', $uncovered->id);

        $this->assertSame(2026, $covered->fresh()->credits_year);
        $this->assertSame(2027, $uncovered->fresh()->credits_year);
        $this->assertEquals(2, $uncovered->fresh()->credits_deducted);
        $this->assertEquals(2, $this->creditsUsed($employee, 2026));
        $this->assertEquals(2, $this->creditsUsed($employee, 2027));
    }

    #[Test]
    public function credits_list_offers_bulk_tools_only_to_users_allowed_to_run_them(): void
    {
        $teamLead = User::factory()->create([
            'role' => 'Team Lead',
            'is_approved' => true,
            'hired_date' => '2023-01-15',
        ]);

        $this->actingAs($this->createAdmin())->get(self::BASE_URL)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canEdit', true)
                ->where('canFixYearMismatch', true));

        $this->actingAs($teamLead)->get(self::BASE_URL)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canEdit', false)
                ->where('canFixYearMismatch', false));
    }
}
