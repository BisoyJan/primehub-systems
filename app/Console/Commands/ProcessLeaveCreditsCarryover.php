<?php

namespace App\Console\Commands;

use App\Models\LeaveCreditCarryover;
use App\Models\User;
use App\Services\LeaveCreditService;
use Illuminate\Console\Command;

class ProcessLeaveCreditsCarryover extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave:process-carryover
                            {--year= : The year to carry over from (defaults to previous year)}
                            {--user= : Process for a specific user ID only}
                            {--dry-run : Show what would be processed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process year-end leave credit carryovers (max 4 credits for cash conversion, not for leave)';

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
        $fromYear = $this->option('year') ?? (now()->year - 1);
        $toYear = $fromYear + 1;
        $userId = $this->option('user');
        $isDryRun = $this->option('dry-run');

        $this->info("Leave Credits Carryover Processing");
        $this->info("==================================");
        $this->info("From Year: {$fromYear}");
        $this->info("To Year: {$toYear}");
        $this->info("Max Carryover: " . LeaveCreditCarryover::MAX_CARRYOVER_CREDITS . " credits (for cash conversion only)");

        if ($isDryRun) {
            $this->warn("DRY RUN MODE - No changes will be made");
        }
        $this->newLine();

        if ($userId) {
            $this->processSingleUser($userId, $fromYear, $isDryRun);
        } else {
            $this->processAllUsers($fromYear, $isDryRun);
        }

        return self::SUCCESS;
    }

    /**
     * Process carryover for a single user.
     */
    protected function processSingleUser(int $userId, int $fromYear, bool $isDryRun): void
    {
        $user = User::find($userId);

        if (!$user) {
            $this->error("User with ID {$userId} not found.");
            return;
        }

        $this->info("Processing carryover for: {$user->name} (ID: {$user->id})");

        // Check if already processed
        $existing = LeaveCreditCarryover::forUser($userId)->fromYear($fromYear)->first();
        if ($existing) {
            $this->warn("Carryover already processed for this user.");
            $this->displayCarryoverInfo($existing);
            return;
        }

        // Get balance
        $balance = $this->leaveCreditService->getBalance($user, $fromYear);

        if ($balance <= 0) {
            $this->info("No unused credits to carry over (balance: {$balance})");
            return;
        }

        $carryover = min($balance, LeaveCreditCarryover::MAX_CARRYOVER_CREDITS);
        $forfeited = max(0, $balance - LeaveCreditCarryover::MAX_CARRYOVER_CREDITS);

        $this->table(
            ['Field', 'Value'],
            [
                ['Unused Credits from ' . $fromYear, number_format($balance, 2)],
                ['Carryover Credits (for cash)', number_format($carryover, 2)],
                ['Forfeited Credits', number_format($forfeited, 2)],
            ]
        );

        if (!$isDryRun) {
            $result = $this->leaveCreditService->processCarryover($user, $fromYear);
            if ($result) {
                $this->info("✓ Carryover processed successfully.");
            } else {
                $this->error("✗ Failed to process carryover.");
            }
        }
    }

    /**
     * Process carryover for all users.
     */
    protected function processAllUsers(int $fromYear, bool $isDryRun): void
    {
        $users = User::whereNotNull('hired_date')
            ->where('is_active', true) // Only active employees
            ->get();
        $toProcess = [];
        $alreadyProcessed = 0;
        $noCredits = 0;
        $skippedProbationary = [];

        $this->info("Analyzing {$users->count()} users...");
        $this->newLine();

        foreach ($users as $user) {
            // Check if already processed
            $existing = LeaveCreditCarryover::forUser($user->id)->fromYear($fromYear)->first();
            if ($existing) {
                $alreadyProcessed++;
                continue;
            }

            // Check if probationary employee who should wait for first regularization
            if ($this->leaveCreditService->shouldSkipYearEndCarryover($user, $fromYear)) {
                $balance = $this->leaveCreditService->getBalance($user, $fromYear);
                if ($balance > 0) {
                    $skippedProbationary[] = [
                        'user' => $user,
                        'balance' => $balance,
                    ];
                }
                continue;
            }

            // Get balance
            $balance = $this->leaveCreditService->getBalance($user, $fromYear);

            if ($balance <= 0) {
                $noCredits++;
                continue;
            }

            $carryover = min($balance, LeaveCreditCarryover::MAX_CARRYOVER_CREDITS);
            $forfeited = max(0, $balance - LeaveCreditCarryover::MAX_CARRYOVER_CREDITS);

            $toProcess[] = [
                'user' => $user,
                'balance' => $balance,
                'carryover' => $carryover,
                'forfeited' => $forfeited,
            ];
        }

        // Display summary
        $this->info("Summary:");
        $this->info("- Already processed: {$alreadyProcessed}");
        $this->info("- No credits to carry over: {$noCredits}");
        $this->info("- Skipped (probationary, awaiting first regularization): " . count($skippedProbationary));
        $this->info("- To be processed: " . count($toProcess));
        $this->newLine();

        // Show skipped probationary employees
        if (!empty($skippedProbationary)) {
            $this->warn("The following probationary employees are skipped (will get first regularization transfer instead):");
            $this->table(
                ['ID', 'Name', 'Hired', 'Regularizes', 'Credits'],
                array_map(function ($item) {
                    $hireDate = \Carbon\Carbon::parse($item['user']->hired_date);
                    $regDate = $hireDate->copy()->addMonths(6);
                    return [
                        $item['user']->id,
                        $item['user']->name,
                        $hireDate->format('M d, Y'),
                        $regDate->format('M d, Y'),
                        number_format($item['balance'], 2),
                    ];
                }, $skippedProbationary)
            );
            $this->newLine();
        }

        if (empty($toProcess)) {
            $this->info("No carryovers to process.");
            return;
        }

        // Display users to process
        $this->table(
            ['ID', 'Name', 'Role', 'Unused Credits', 'Carryover (Cash)', 'Forfeited'],
            array_map(function ($item) {
                return [
                    $item['user']->id,
                    $item['user']->name,
                    $item['user']->role,
                    number_format($item['balance'], 2),
                    number_format($item['carryover'], 2),
                    number_format($item['forfeited'], 2),
                ];
            }, $toProcess)
        );

        $this->newLine();
        $totalCarryover = array_sum(array_column($toProcess, 'carryover'));
        $totalForfeited = array_sum(array_column($toProcess, 'forfeited'));
        $this->info("Total Carryover Credits: " . number_format($totalCarryover, 2));
        $this->info("Total Forfeited Credits: " . number_format($totalForfeited, 2));
        $this->newLine();

        if ($isDryRun) {
            $this->warn("DRY RUN - No changes were made. Remove --dry-run to process.");
            return;
        }

        if (!$this->confirm("Do you want to process these carryovers?")) {
            $this->info("Operation cancelled.");
            return;
        }

        // Process all
        $processed = 0;
        $failed = 0;

        $this->withProgressBar($toProcess, function ($item) use (&$processed, &$failed, $fromYear) {
            $result = $this->leaveCreditService->processCarryover($item['user'], $fromYear);
            if ($result) {
                $processed++;
            } else {
                $failed++;
            }
        });

        $this->newLine(2);
        $this->info("✓ Processed: {$processed}");
        if ($failed > 0) {
            $this->error("✗ Failed: {$failed}");
        }
    }

    /**
     * Display carryover info.
     */
    protected function displayCarryoverInfo(LeaveCreditCarryover $carryover): void
    {
        $this->table(
            ['Field', 'Value'],
            [
                ['From Year', $carryover->from_year],
                ['To Year', $carryover->to_year],
                ['Credits from Previous Year', number_format($carryover->credits_from_previous_year, 2)],
                ['Carryover Credits (for cash)', number_format($carryover->carryover_credits, 2)],
                ['Forfeited Credits', number_format($carryover->forfeited_credits, 2)],
                ['Cash Converted', $carryover->cash_converted ? 'Yes' : 'No'],
                ['Cash Converted At', $carryover->cash_converted_at?->format('Y-m-d') ?? 'N/A'],
            ]
        );
    }
}
