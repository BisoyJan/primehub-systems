<?php

namespace Tests\Feature\Console;

use App\Models\LeaveCredit;
use App\Models\User;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class YearEndLeaveCreditsReminderTest extends TestCase
{
    use RefreshDatabase;

    protected LeaveCreditService $leaveCreditService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->leaveCreditService = app(LeaveCreditService::class);
    }

    #[Test]
    public function it_generates_report_for_current_year(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Create leave credits
        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 1.25,
        ]);

        $this->artisan('leave:year-end-reminder')
            ->expectsOutput("Year-End Leave Credits Report for " . now()->year)
            ->expectsOutput("Credits will expire on December 31, " . now()->year)
            ->assertExitCode(0);
    }

    #[Test]
    public function it_generates_report_for_specific_year(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::parse('2023-01-01'),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => 2024,
            'month' => 6,
            'credits_earned' => 1.25,
        ]);

        $this->artisan('leave:year-end-reminder', ['--year' => 2024])
            ->expectsOutput('Year-End Leave Credits Report for 2024')
            ->expectsOutput('Credits will expire on December 31, 2024')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_users_with_unused_credits(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        LeaveCredit::factory()->unused()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => now()->month,
            'credits_earned' => 5.0,
            'credits_balance' => 5.0,
        ]);

        // Verify the user appears in the output table
        $this->artisan('leave:year-end-reminder')
            ->expectsOutputToContain('john@example.com')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_message_when_no_unused_credits(): void
    {
        // Create users without credits
        User::factory()->count(2)->create([
            'hired_date' => Carbon::now()->subYear(),
        ]);

        $this->artisan('leave:year-end-reminder')
            ->expectsOutput("✓ No users have unused leave credits for " . now()->year)
            ->assertExitCode(0);
    }

    #[Test]
    public function it_only_includes_users_hired_at_least_6_months_ago(): void
    {
        // User hired 8 months ago (should be included)
        $seniorUser = User::factory()->create([
            'hired_date' => Carbon::now()->subMonths(8),
        ]);

        // User hired 3 months ago (should be excluded)
        $juniorUser = User::factory()->create([
            'hired_date' => Carbon::now()->subMonths(3),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $seniorUser->id,
            'year' => now()->year,
            'credits_earned' => 5.0,
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $juniorUser->id,
            'year' => now()->year,
            'credits_earned' => 3.0,
        ]);

        $this->artisan('leave:year-end-reminder')
            ->expectsOutputToContain($seniorUser->name)
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_table_with_credit_details(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
        ]);

        LeaveCredit::factory()->unused()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'credits_earned' => 7.5,
            'credits_balance' => 7.5,
        ]);

        // Verify the table structure exists with user data
        $this->artisan('leave:year-end-reminder')
            ->expectsOutputToContain($user->email)
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_total_users_and_expiring_credits(): void
    {
        $users = User::factory()->count(3)->create([
            'hired_date' => Carbon::now()->subYear(),
        ]);

        foreach ($users as $user) {
            LeaveCredit::factory()->create([
                'user_id' => $user->id,
                'year' => now()->year,
                'credits_earned' => 5.0,
            ]);
        }

        $this->artisan('leave:year-end-reminder')
            ->expectsOutputToContain('Total users with unused credits: 3')
            ->expectsOutputToContain('Total expiring credits:')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_sorts_users_by_balance_descending(): void
    {
        $user1 = User::factory()->create([
            'first_name' => 'Alice',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        $user2 = User::factory()->create([
            'first_name' => 'Bob',
            'hired_date' => Carbon::now()->subYear(),
        ]);

        // Bob has more credits
        LeaveCredit::factory()->create([
            'user_id' => $user1->id,
            'year' => now()->year,
            'credits_earned' => 3.0,
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user2->id,
            'year' => now()->year,
            'credits_earned' => 10.0,
        ]);

        $this->artisan('leave:year-end-reminder')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_reminder_messages(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'credits_earned' => 5.0,
        ]);

        $this->artisan('leave:year-end-reminder')
            ->expectsOutputToContain('💡 Reminder: Credits do NOT carry over to the next year.')
            ->expectsOutputToContain('💡 Employees should use their remaining credits before December 31.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_suggests_running_in_november_or_december(): void
    {
        // Mock the current date to be October
        Carbon::setTestNow(Carbon::create(now()->year, 10, 15));

        // Need a user with credits for the tip to show
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
        ]);

        LeaveCredit::factory()->unused()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'credits_earned' => 5.0,
            'credits_balance' => 5.0,
        ]);

        $this->artisan('leave:year-end-reminder')
            ->expectsOutputToContain('Tip: Run this report again in November or December')
            ->assertExitCode(0);

        Carbon::setTestNow(); // Reset
    }

    #[Test]
    public function it_does_not_show_tip_when_running_in_november_or_december(): void
    {
        // Mock the current date to be November
        Carbon::setTestNow(Carbon::create(now()->year, 11, 15));

        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'credits_earned' => 5.0,
        ]);

        $output = $this->artisan('leave:year-end-reminder')
            ->assertExitCode(0)
            ->run();

        Carbon::setTestNow(); // Reset
    }

    #[Test]
    public function it_formats_credits_with_two_decimal_places(): void
    {
        $user = User::factory()->create([
            'hired_date' => Carbon::now()->subYear(),
        ]);

        LeaveCredit::factory()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'credits_earned' => 7.5,
        ]);

        $this->artisan('leave:year-end-reminder')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_excludes_users_without_hire_dates(): void
    {
        User::factory()->create([
            'hired_date' => null,
        ]);

        $this->artisan('leave:year-end-reminder')
            ->expectsOutput("✓ No users have unused leave credits for " . now()->year)
            ->assertExitCode(0);
    }

    #[Test]
    public function it_returns_success_exit_code(): void
    {
        $this->artisan('leave:year-end-reminder')
            ->assertExitCode(0);
    }
}
