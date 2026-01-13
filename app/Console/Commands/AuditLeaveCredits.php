<?php

namespace App\Console\Commands;

use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\User;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AuditLeaveCredits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave-credits:audit
                            {--year= : The year to audit (defaults to current year)}
                            {--user= : Audit only a specific user ID}
                            {--fix : Attempt to fix detected issues}
                            {--detailed : Show detailed output for each check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit leave credit data integrity and detect/fix inconsistencies';

    protected array $issues = [];
    protected int $fixedCount = 0;

    public function __construct(protected LeaveCreditService $leaveCreditService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $year = (int) ($this->option('year') ?? now()->year);
        $userId = $this->option('user');
        $shouldFix = $this->option('fix');
        $detailed = $this->option('detailed');

        $this->info("╔════════════════════════════════════════════════════════════╗");
        $this->info("║           LEAVE CREDITS AUDIT REPORT                       ║");
        $this->info("╠════════════════════════════════════════════════════════════╣");
        $this->info("║  Year: {$year}                                                 ║");
        $this->info("║  Date: " . now()->format('Y-m-d H:i:s') . "                          ║");
        $this->info("╚════════════════════════════════════════════════════════════╝");
        $this->newLine();

        // Get users to audit
        $query = User::whereNotNull('hired_date');
        if ($userId) {
            $query->where('id', $userId);
        }
        $users = $query->get();

        $this->info("Auditing {$users->count()} users...");
        $this->newLine();

        // Run all audit checks
        $this->checkPendingWithCarryover($users, $year, $detailed);
        $this->checkCarryoverCaps($users, $year, $detailed);
        $this->checkMissingCredits($users, $year, $detailed);
        $this->checkDuplicateCarryovers($users, $detailed);
        $this->checkInvalidFirstRegularization($users, $detailed);
        $this->checkNegativeBalances($users, $year, $detailed);
        $this->checkInactiveUsersWithCredits($year, $detailed);

        // Display summary
        $this->displaySummary();

        // Fix issues if requested
        if ($shouldFix && !empty($this->issues)) {
            $this->newLine();
            if ($this->confirm('Do you want to attempt to fix the detected issues?')) {
                $this->fixIssues();
            }
        }

        return empty($this->issues) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Check for users who have both pending first regularization AND carryover (invalid state).
     */
    protected function checkPendingWithCarryover($users, int $year, bool $detailed): void
    {
        $this->info("🔍 Check 1: Pending Transfer + Carryover Conflict");
        $this->info("   (Users shouldn't have both pending first reg AND carryover)");

        $previousYear = $year - 1;
        $issueCount = 0;

        foreach ($users as $user) {
            $pending = $this->leaveCreditService->getPendingRegularizationCredits($user);

            if ($pending['is_pending']) {
                $carryover = LeaveCreditCarryover::forUser($user->id)
                    ->fromYear($previousYear)
                    ->toYear($year)
                    ->where('is_first_regularization', false)
                    ->first();

                if ($carryover) {
                    $this->issues[] = [
                        'type' => 'pending_with_carryover',
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'details' => "Has pending transfer ({$pending['credits']} credits) AND carryover ({$carryover->carryover_credits} credits)",
                        'carryover_id' => $carryover->id,
                    ];
                    $issueCount++;

                    if ($detailed) {
                        $this->warn("   ✗ {$user->name}: Pending {$pending['credits']} + Carryover {$carryover->carryover_credits}");
                    }
                }
            }
        }

        $this->displayCheckResult($issueCount);
    }

    /**
     * Check for carryovers exceeding MAX 4 for same-year regularizations.
     */
    protected function checkCarryoverCaps($users, int $year, bool $detailed): void
    {
        $this->info("🔍 Check 2: Carryover Cap Violations");
        $this->info("   (Same-year regularization should have MAX 4 credits)");

        $previousYear = $year - 1;
        $issueCount = 0;

        foreach ($users as $user) {
            $hireDate = Carbon::parse($user->hired_date);
            $regDate = $hireDate->copy()->addMonths(6);

            // Same year hire and regularization
            if ($hireDate->year === $regDate->year && $hireDate->year < $year) {
                $carryover = LeaveCreditCarryover::forUser($user->id)
                    ->fromYear($previousYear)
                    ->toYear($year)
                    ->first();

                if ($carryover && !$carryover->is_first_regularization && $carryover->carryover_credits > LeaveCreditCarryover::MAX_CARRYOVER_CREDITS) {
                    $this->issues[] = [
                        'type' => 'carryover_cap_violation',
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'details' => "Carryover {$carryover->carryover_credits} exceeds MAX " . LeaveCreditCarryover::MAX_CARRYOVER_CREDITS,
                        'carryover_id' => $carryover->id,
                        'current_value' => $carryover->carryover_credits,
                    ];
                    $issueCount++;

                    if ($detailed) {
                        $this->warn("   ✗ {$user->name}: {$carryover->carryover_credits} > MAX 4");
                    }
                }
            }
        }

        $this->displayCheckResult($issueCount);
    }

    /**
     * Check for missing credits for months since hire date.
     */
    protected function checkMissingCredits($users, int $year, bool $detailed): void
    {
        $this->info("🔍 Check 3: Missing Monthly Credits");
        $this->info("   (Users should have credits for each eligible month)");

        $issueCount = 0;
        $now = now();

        foreach ($users as $user) {
            $hireDate = Carbon::parse($user->hired_date);

            // Only check users hired before or during this year
            if ($hireDate->year > $year) {
                continue;
            }

            // Determine start month (first accrual month after hire)
            $startMonth = $hireDate->copy()->addMonth();
            if ($startMonth->year < $year) {
                $startMonth = Carbon::create($year, 1, 1);
            }

            // Don't check future months
            $endMonth = $now->year === $year ? $now : Carbon::create($year, 12, 31);

            $missingMonths = [];
            $currentMonth = $startMonth->copy();

            while ($currentMonth->lte($endMonth) && $currentMonth->year === $year) {
                // Check if credit exists for this month
                $credit = LeaveCredit::forUser($user->id)
                    ->where('year', $year)
                    ->where('month', $currentMonth->month)
                    ->first();

                if (!$credit) {
                    $missingMonths[] = $currentMonth->format('M');
                }

                $currentMonth->addMonth();
            }

            if (!empty($missingMonths)) {
                $this->issues[] = [
                    'type' => 'missing_credits',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'details' => 'Missing credits for: ' . implode(', ', $missingMonths),
                    'missing_months' => $missingMonths,
                    'year' => $year,
                ];
                $issueCount++;

                if ($detailed) {
                    $this->warn("   ✗ {$user->name}: Missing " . implode(', ', $missingMonths));
                }
            }
        }

        $this->displayCheckResult($issueCount);
    }

    /**
     * Check for duplicate carryover records.
     */
    protected function checkDuplicateCarryovers($users, bool $detailed): void
    {
        $this->info("🔍 Check 4: Duplicate Carryover Records");
        $this->info("   (Users should have only one carryover per year transition)");

        $issueCount = 0;

        foreach ($users as $user) {
            $duplicates = LeaveCreditCarryover::forUser($user->id)
                ->selectRaw('from_year, to_year, COUNT(*) as count')
                ->groupBy('from_year', 'to_year')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $dup) {
                $this->issues[] = [
                    'type' => 'duplicate_carryover',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'details' => "Multiple carryovers ({$dup->count}) for {$dup->from_year}→{$dup->to_year}",
                    'from_year' => $dup->from_year,
                    'to_year' => $dup->to_year,
                ];
                $issueCount++;

                if ($detailed) {
                    $this->warn("   ✗ {$user->name}: {$dup->count} carryovers for {$dup->from_year}→{$dup->to_year}");
                }
            }
        }

        $this->displayCheckResult($issueCount);
    }

    /**
     * Check for invalid first regularization carryovers.
     * (Users hired & regularized same year shouldn't have first_regularization=true)
     */
    protected function checkInvalidFirstRegularization($users, bool $detailed): void
    {
        $this->info("🔍 Check 5: Invalid First Regularization Flag");
        $this->info("   (Same-year hires shouldn't have first_regularization=true)");

        $issueCount = 0;

        foreach ($users as $user) {
            $hireDate = Carbon::parse($user->hired_date);
            $regDate = $hireDate->copy()->addMonths(6);

            // Same year hire and regularization
            if ($hireDate->year === $regDate->year) {
                $invalidCarryover = LeaveCreditCarryover::forUser($user->id)
                    ->where('is_first_regularization', true)
                    ->first();

                if ($invalidCarryover) {
                    $this->issues[] = [
                        'type' => 'invalid_first_reg',
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'details' => "Has first_regularization=true but hired & reg same year ({$hireDate->year})",
                        'carryover_id' => $invalidCarryover->id,
                    ];
                    $issueCount++;

                    if ($detailed) {
                        $this->warn("   ✗ {$user->name}: First reg flag but same-year hire/reg");
                    }
                }
            }
        }

        $this->displayCheckResult($issueCount);
    }

    /**
     * Check for negative balances.
     */
    protected function checkNegativeBalances($users, int $year, bool $detailed): void
    {
        $this->info("🔍 Check 6: Negative Credit Balances");
        $this->info("   (Credit balances should never be negative)");

        $issueCount = 0;

        foreach ($users as $user) {
            $negativeCredits = LeaveCredit::forUser($user->id)
                ->forYear($year)
                ->where('credits_balance', '<', 0)
                ->get();

            foreach ($negativeCredits as $credit) {
                $this->issues[] = [
                    'type' => 'negative_balance',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'details' => "Negative balance {$credit->credits_balance} for {$year}-{$credit->month}",
                    'credit_id' => $credit->id,
                ];
                $issueCount++;

                if ($detailed) {
                    $this->warn("   ✗ {$user->name}: {$credit->credits_balance} for month {$credit->month}");
                }
            }
        }

        $this->displayCheckResult($issueCount);
    }

    /**
     * Check for inactive users (terminated) who still have credit balances.
     * This is informational - terminated users may have unused credits.
     */
    protected function checkInactiveUsersWithCredits(int $year, bool $detailed): void
    {
        $this->info("🔍 Check 7: Inactive Users with Credit Balances");
        $this->info("   (Terminated employees with remaining unused credits)");

        $issueCount = 0;

        // Get inactive users with credits
        $inactiveUsers = User::whereNotNull('hired_date')
            ->where('is_active', false)
            ->get();

        foreach ($inactiveUsers as $user) {
            $balance = $this->leaveCreditService->getBalance($user, $year);

            if ($balance > 0) {
                $this->issues[] = [
                    'type' => 'inactive_user_credits',
                    'severity' => 'info', // Informational, not an error
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'details' => "Inactive user has {$balance} unused credits for {$year}",
                    'balance' => $balance,
                ];
                $issueCount++;

                if ($detailed) {
                    $this->warn("   ℹ {$user->name} (ID: {$user->id}): {$balance} credits remaining");
                }
            }
        }

        if ($issueCount === 0) {
            $this->info("   ✓ No inactive users with credits found");
        } else {
            $this->info("   ℹ {$issueCount} inactive user(s) with credits (informational)");
        }
        $this->newLine();
    }

    /**
     * Display check result.
     */
    protected function displayCheckResult(int $issueCount): void
    {
        if ($issueCount === 0) {
            $this->info("   ✓ No issues found");
        } else {
            $this->error("   ✗ {$issueCount} issue(s) found");
        }
        $this->newLine();
    }

    /**
     * Display audit summary.
     */
    protected function displaySummary(): void
    {
        $this->newLine();
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->info("                        AUDIT SUMMARY                          ");
        $this->info("═══════════════════════════════════════════════════════════════");

        if (empty($this->issues)) {
            $this->info("✅ All checks passed! No issues detected.");
            return;
        }

        // Group issues by type
        $grouped = collect($this->issues)->groupBy('type');

        $this->error("❌ Total issues found: " . count($this->issues));
        $this->newLine();

        $tableData = [];
        foreach ($grouped as $type => $issues) {
            $tableData[] = [$this->getIssueTypeName($type), count($issues)];
        }

        $this->table(['Issue Type', 'Count'], $tableData);

        // Show detailed issues
        $this->newLine();
        $this->info("Detailed Issues:");
        $this->newLine();

        $issueTable = [];
        foreach ($this->issues as $issue) {
            $issueTable[] = [
                $issue['user_id'],
                substr($issue['user_name'], 0, 25),
                $this->getIssueTypeName($issue['type']),
                substr($issue['details'], 0, 50),
            ];
        }

        $this->table(['User ID', 'Name', 'Issue Type', 'Details'], $issueTable);
    }

    /**
     * Get human-readable issue type name.
     */
    protected function getIssueTypeName(string $type): string
    {
        return match ($type) {
            'pending_with_carryover' => 'Pending + Carryover',
            'carryover_cap_violation' => 'Carryover Cap Exceeded',
            'missing_credits' => 'Missing Credits',
            'duplicate_carryover' => 'Duplicate Carryover',
            'invalid_first_reg' => 'Invalid First Reg Flag',
            'negative_balance' => 'Negative Balance',
            default => $type,
        };
    }

    /**
     * Attempt to fix detected issues.
     */
    protected function fixIssues(): void
    {
        $this->newLine();
        $this->info("Attempting to fix issues...");
        $this->newLine();

        foreach ($this->issues as $issue) {
            $fixed = match ($issue['type']) {
                'pending_with_carryover' => $this->fixPendingWithCarryover($issue),
                'carryover_cap_violation' => $this->fixCarryoverCap($issue),
                'invalid_first_reg' => $this->fixInvalidFirstReg($issue),
                default => false,
            };

            if ($fixed) {
                $this->fixedCount++;
            }
        }

        $this->newLine();
        $this->info("Fixed {$this->fixedCount} issue(s).");

        $unfixed = count($this->issues) - $this->fixedCount;
        if ($unfixed > 0) {
            $this->warn("{$unfixed} issue(s) require manual intervention:");
            $this->comment("- Missing credits: Run 'php artisan leave:accrue-credits --backfill'");
            $this->comment("- Duplicate carryovers: Manually review and delete duplicates");
            $this->comment("- Negative balances: Review leave usage and adjust credits");
        }
    }

    /**
     * Fix pending + carryover conflict by removing the incorrect carryover.
     */
    protected function fixPendingWithCarryover(array $issue): bool
    {
        $carryover = LeaveCreditCarryover::find($issue['carryover_id']);
        if ($carryover) {
            $carryover->delete();
            $this->info("✓ Deleted incorrect carryover for {$issue['user_name']}");
            return true;
        }
        return false;
    }

    /**
     * Fix carryover cap violation by capping at MAX.
     */
    protected function fixCarryoverCap(array $issue): bool
    {
        $carryover = LeaveCreditCarryover::find($issue['carryover_id']);
        if ($carryover) {
            $oldValue = $carryover->carryover_credits;
            $carryover->update([
                'carryover_credits' => LeaveCreditCarryover::MAX_CARRYOVER_CREDITS,
                'forfeited_credits' => $oldValue - LeaveCreditCarryover::MAX_CARRYOVER_CREDITS,
            ]);
            $this->info("✓ Capped carryover for {$issue['user_name']} ({$oldValue} → " . LeaveCreditCarryover::MAX_CARRYOVER_CREDITS . ")");
            return true;
        }
        return false;
    }

    /**
     * Fix invalid first regularization flag.
     */
    protected function fixInvalidFirstReg(array $issue): bool
    {
        $carryover = LeaveCreditCarryover::find($issue['carryover_id']);
        if ($carryover) {
            $carryover->update([
                'is_first_regularization' => false,
                'regularization_date' => null,
            ]);
            $this->info("✓ Fixed first_reg flag for {$issue['user_name']}");
            return true;
        }
        return false;
    }
}
