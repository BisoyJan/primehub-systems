<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LeaveCreditService;
use Illuminate\Console\Command;

class ProcessRegularizationCarryover extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave:process-regularization
                            {--user= : Process only a specific user ID}
                            {--year= : The regularization year to process (defaults to current year)}
                            {--dry-run : Show what would be processed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process first-time regularization credit transfers for employees hired in previous year but regularized this year';

    public function __construct(protected LeaveCreditService $leaveCreditService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $year = $this->option('year') ?? now()->year;
        $userId = $this->option('user');
        $isDryRun = $this->option('dry-run');

        $this->info("Processing first-time regularization credit transfers for year {$year}...");
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }
        $this->newLine();

        // Get users to process
        if ($userId) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("User with ID {$userId} not found.");
                return 1;
            }
            $users = collect([$user]);
        } else {
            $users = $this->leaveCreditService->getUsersNeedingFirstRegularization($year);
        }

        if ($users->isEmpty()) {
            $this->info('No users need first-time regularization credit transfer.');
            return 0;
        }

        $this->info("Found {$users->count()} user(s) eligible for first-time regularization transfer:");
        $this->newLine();

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        $tableData = [];

        foreach ($users as $user) {
            $regularizationInfo = $this->leaveCreditService->getRegularizationInfo($user, $year);
            $pendingCredits = $regularizationInfo['pending_credits'];

            if (!$this->leaveCreditService->needsFirstRegularizationTransfer($user, $year)) {
                $this->warn("⊘ {$user->name}: Skipped (not eligible or already processed)");
                $skipped++;
                continue;
            }

            $tableData[] = [
                $user->id,
                $user->name,
                $regularizationInfo['hired_date'],
                $regularizationInfo['regularization_date'],
                $pendingCredits['credits'],
                $pendingCredits['months_accrued'],
            ];

            if (!$isDryRun) {
                try {
                    $carryover = $this->leaveCreditService->processFirstRegularizationTransfer($user);

                    if ($carryover) {
                        $this->info("✓ {$user->name}: {$carryover->carryover_credits} credits transferred from {$carryover->from_year} to {$carryover->to_year}");
                        $processed++;
                    } else {
                        $this->warn("⊘ {$user->name}: No credits to transfer");
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $this->error("✗ {$user->name}: {$e->getMessage()}");
                    $errors++;
                }
            }
        }

        if ($isDryRun && !empty($tableData)) {
            $this->newLine();
            $this->table(
                ['ID', 'Name', 'Hired', 'Regularization', 'Credits', 'Months'],
                $tableData
            );
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Found: {$users->count()}");
        if (!$isDryRun) {
            $this->info("  Processed: {$processed}");
            $this->info("  Skipped: {$skipped}");
            if ($errors > 0) {
                $this->error("  Errors: {$errors}");
            }
        }

        return $errors > 0 ? 1 : 0;
    }
}
