<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LeaveCreditService;
use Illuminate\Console\Command;

class AccrueLeaveCredits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave:accrue-credits
                            {--year= : The year to accrue credits for (defaults to current year)}
                            {--month= : The month to accrue credits for (defaults to current month)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Accrue monthly leave credits for all users';

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
        $month = $this->option('month') ?? now()->month;

        $this->info("Accruing leave credits for {$year}-{$month}...");

        $users = User::whereNotNull('hired_date')->get();
        $accrued = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($users as $user) {
            try {
                $credit = $this->leaveCreditService->accrueMonthly($user, $year, $month);

                if ($credit) {
                    $this->info("✓ {$user->name}: {$credit->credits_earned} credits");
                    $accrued++;
                } else {
                    $this->warn("⊘ {$user->name}: Skipped (no hire date or already accrued)");
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->error("✗ {$user->name}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Accrued: {$accrued}");
        $this->info("  Skipped: {$skipped}");
        if ($errors > 0) {
            $this->error("  Errors: {$errors}");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
