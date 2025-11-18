<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LeaveCreditService;
use Illuminate\Console\Command;

class BackfillLeaveCredits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave:backfill-credits
                            {--user= : Backfill for specific user ID only}
                            {--force : Force backfill even if credits already exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill leave credits for employees from their hire date to present';

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
        $this->info("Backfilling leave credits from hire date to present...");
        $this->newLine();

        // Get users to process
        $query = User::whereNotNull('hired_date');

        if ($userId = $this->option('user')) {
            $query->where('id', $userId);
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->warn("No users found with hire dates.");
            return self::SUCCESS;
        }

        $totalAccrued = 0;
        $processedUsers = 0;
        $skippedUsers = 0;

        $progressBar = $this->output->createProgressBar($users->count());
        $progressBar->start();

        foreach ($users as $user) {
            $accrued = $this->leaveCreditService->backfillCredits($user);

            if ($accrued > 0) {
                $totalAccrued += $accrued;
                $processedUsers++;
            } else {
                $skippedUsers++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Show summary
        $this->info("Backfill Complete!");
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Users', $users->count()],
                ['Users Processed', $processedUsers],
                ['Users Skipped', $skippedUsers],
                ['Total Months Accrued', $totalAccrued],
            ]
        );

        // Show some examples
        if ($processedUsers > 0) {
            $this->newLine();
            $this->info("Sample Results:");

            $sampleUsers = $users->take(3);
            foreach ($sampleUsers as $user) {
                $summary = $this->leaveCreditService->getSummary($user);
                $this->line("  • {$user->name}: {$summary['balance']} credits (hired {$user->hired_date->format('M d, Y')})");
            }
        }

        return self::SUCCESS;
    }
}
