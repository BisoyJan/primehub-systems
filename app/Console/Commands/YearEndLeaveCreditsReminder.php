<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LeaveCreditService;
use Illuminate\Console\Command;

class YearEndLeaveCreditsReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave:year-end-reminder
                            {--year= : The year to check (defaults to current year)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate report of users with unused leave credits expiring at year end';

    protected $leaveCreditService;

    public function __construct(LeaveCreditService $leaveCreditService)
    {
        parent::__construct();
        $this->leaveCreditService = $leaveCreditService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $year = $this->option('year') ?? now()->year;

        $this->info("Year-End Leave Credits Report for {$year}");
        $this->info("Credits will expire on December 31, {$year}");
        $this->newLine();

        $users = User::whereNotNull('hired_date')
            ->where('hired_date', '<=', now()->subMonths(6))
            ->get();

        $usersWithCredits = [];
        $totalExpiringCredits = 0;

        foreach ($users as $user) {
            $balance = $this->leaveCreditService->getBalance($user, $year);

            if ($balance > 0) {
                $usersWithCredits[] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'balance' => $balance,
                ];
                $totalExpiringCredits += $balance;
            }
        }

        if (empty($usersWithCredits)) {
            $this->info("✓ No users have unused leave credits for {$year}");
            return self::SUCCESS;
        }

        // Sort by balance descending
        usort($usersWithCredits, function($a, $b) {
            return $b['balance'] <=> $a['balance'];
        });

        // Display table
        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Expiring Credits'],
            array_map(function($user) {
                return [
                    $user['id'],
                    $user['name'],
                    $user['email'],
                    $user['role'],
                    number_format($user['balance'], 2),
                ];
            }, $usersWithCredits)
        );

        $this->newLine();
        $this->warn("⚠️  Total users with unused credits: " . count($usersWithCredits));
        $this->warn("⚠️  Total expiring credits: " . number_format($totalExpiringCredits, 2) . " days");
        $this->newLine();
        $this->comment("💡 Reminder: Credits do NOT carry over to the next year for leave applications.");
        $this->comment("💡 However, up to 4 credits can be carried over for cash conversion.");
        $this->comment("💡 Run 'php artisan leave:process-carryover' to process carryovers for cash conversion.");
        $this->comment("💡 Employees should use their remaining credits before December 31.");

        // Suggest running in November/December
        $currentMonth = now()->month;
        if ($currentMonth < 11) {
            $this->newLine();
            $this->info("💡 Tip: Run this report again in November or December for year-end planning.");
        }

        return self::SUCCESS;
    }
}
