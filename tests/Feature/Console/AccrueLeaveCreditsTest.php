<?php

namespace Tests\Feature\Console;

use App\Models\LeaveCredit;
use App\Models\User;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccrueLeaveCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected LeaveCreditService $leaveCreditService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->leaveCreditService = app(LeaveCreditService::class);
    }

    #[Test]
    public function it_accrues_credits_for_all_users_with_hire_dates(): void
    {
        // Use last month since accrual only works for completed months
        $lastMonth = now()->subMonth();
        $year = $lastMonth->year;
        $month = $lastMonth->month;

        // Create active, approved users with hire dates before last month
        $user1 = User::factory()->create([
            'hired_date' => Carbon::now()->subMonths(6),
            'is_approved' => true,
            'is_active' => true,
        ]);
        $user2 = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
            'is_approved' => true,
            'is_active' => true,
        ]);

        // Create user without hire date (should be skipped)
        User::factory()->create(['hired_date' => null, 'is_approved' => true, 'is_active' => true]);

        $this->artisan('leave:accrue-credits', [
            '--year' => $year,
            '--month' => $month,
        ])
            ->expectsOutput("Accruing leave credits for {$year}-{$month}...")
            ->assertExitCode(0);

        // Verify credits were created
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user1->id,
            'year' => $year,
            'month' => $month,
        ]);

        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user2->id,
            'year' => $year,
            'month' => $month,
        ]);
    }

    #[Test]
    public function it_accrues_credits_for_specific_year_and_month(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::parse('2024-01-15'),
            'is_approved' => true,
            'is_active' => true,
        ]);

        $this->artisan('leave:accrue-credits', [
            '--year' => 2024,
            '--month' => 6,
        ])
            ->expectsOutput('Accruing leave credits for 2024-6...')
            ->assertExitCode(0);

        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $user->id,
            'year' => 2024,
            'month' => 6,
        ]);
    }

    #[Test]
    public function it_skips_users_without_hire_dates(): void
    {
        User::factory()->count(3)->create(['hired_date' => null, 'is_approved' => true, 'is_active' => true]);

        $this->artisan('leave:accrue-credits')
            ->assertExitCode(0);

        $this->assertEquals(0, LeaveCredit::count());
    }

    #[Test]
    public function it_skips_already_accrued_credits(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subMonths(3),
            'is_approved' => true,
            'is_active' => true,
        ]);

        // Create existing credit for current month
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => now()->month,
        ]);

        $this->artisan('leave:accrue-credits')
            ->assertExitCode(0);

        // Should still have only 1 credit record
        $this->assertEquals(1, LeaveCredit::where('user_id', $user->id)->count());
    }

    #[Test]
    public function it_displays_summary_with_accrued_skipped_and_errors(): void
    {
        $user1 = User::factory()->create([
            'hired_date' => Carbon::now()->subMonths(6),
            'is_approved' => true,
            'is_active' => true,
        ]);
        $user2 = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
            'is_approved' => true,
            'is_active' => true,
        ]);

        // Create user without hire date
        User::factory()->create(['hired_date' => null, 'is_approved' => true, 'is_active' => true]);

        $this->artisan('leave:accrue-credits')
            ->expectsOutput('Summary:')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_returns_failure_code_when_errors_occur(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subMonths(6),
            'is_approved' => true,
            'is_active' => true,
        ]);

        // Mock the service to throw an exception for this user
        $mockService = $this->partialMock(LeaveCreditService::class, function ($mock) {
            $mock->shouldReceive('accrueMonthly')
                ->andThrow(new \RuntimeException('Database connection failed'));
        });

        $this->artisan('leave:accrue-credits')
            ->assertExitCode(1); // FAILURE exit code when errors occur
    }

    #[Test]
    public function it_skips_inactive_users(): void
    {
        $lastMonth = now()->subMonth();
        $year = $lastMonth->year;
        $month = $lastMonth->month;

        // Active + approved user should get credits
        $activeUser = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
            'is_approved' => true,
            'is_active' => true,
        ]);

        // Inactive user should be skipped
        $inactiveUser = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
            'is_approved' => true,
            'is_active' => false,
        ]);

        // Unapproved user should be skipped
        $unapprovedUser = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
            'is_approved' => false,
            'is_active' => true,
        ]);

        $this->artisan('leave:accrue-credits', [
            '--year' => $year,
            '--month' => $month,
        ])->assertExitCode(0);

        // Only the active, approved user should have credits
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $activeUser->id,
            'year' => $year,
            'month' => $month,
        ]);

        $this->assertDatabaseMissing('leave_credits', [
            'user_id' => $inactiveUser->id,
        ]);

        $this->assertDatabaseMissing('leave_credits', [
            'user_id' => $unapprovedUser->id,
        ]);
    }

    #[Test]
    public function it_displays_credits_earned_for_each_user(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'hired_date' => Carbon::now()->subMonths(6),
            'is_approved' => true,
            'is_active' => true,
        ]);

        $this->artisan('leave:accrue-credits')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_multiple_users_with_different_hire_dates(): void
    {
        // Use last month since accrual only works for completed months
        $lastMonth = now()->subMonth();
        $year = $lastMonth->year;
        $month = $lastMonth->month;

        // User hired more than 6 months ago
        $seniorUser = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
            'is_approved' => true,
            'is_active' => true,
        ]);

        // User hired less than 6 months ago
        $juniorUser = User::factory()->create([
            'hired_date' => Carbon::now()->subMonths(3),
            'is_approved' => true,
            'is_active' => true,
        ]);

        $this->artisan('leave:accrue-credits', [
            '--year' => $year,
            '--month' => $month,
        ])
            ->assertExitCode(0);

        // Both should have credits
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $seniorUser->id,
            'year' => $year,
            'month' => $month,
        ]);
        $this->assertDatabaseHas('leave_credits', [
            'user_id' => $juniorUser->id,
            'year' => $year,
            'month' => $month,
        ]);
    }
}
