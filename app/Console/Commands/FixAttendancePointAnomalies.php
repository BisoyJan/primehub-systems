<?php

namespace App\Console\Commands;

use App\Models\AttendancePoint;
use App\Services\AttendancePoint\GbroCalculationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixAttendancePointAnomalies extends Command
{
    protected $signature = 'points:fix-anomalies
                            {--dry-run : Show what would be fixed without making changes}
                            {--skip-sro : Skip SRO expiration fixing}
                            {--skip-gbro : Skip GBRO date clearing}
                            {--skip-gbro-eligible : Skip eligible_for_gbro flag correction}
                            {--skip-expires-at : Skip expires_at recalculation}';

    protected $description = 'Fix attendance point data anomalies: overdue SRO, stale GBRO dates, wrong eligible_for_gbro flag, and month-end expires_at overflow';

    public function __construct(protected GbroCalculationService $gbroService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
            $this->newLine();
        }

        $this->info('Attendance Point Anomaly Fixer');
        $this->info('==============================');
        $this->newLine();

        $totals = [
            'sro_expired' => 0,
            'gbro_dates_cleared' => 0,
            'gbro_eligible_fixed' => 0,
            'expires_at_fixed' => 0,
        ];

        // ── Fix 1: Expire overdue SRO points ──────────────────────────────────
        if (! $this->option('skip-sro')) {
            $this->info('Fix 1: Expiring overdue SRO points...');

            $sroQuery = AttendancePoint::with('user:id,first_name,last_name,middle_name')
                ->where('is_expired', false)
                ->where('is_excused', false)
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '<=', today());

            $count = (clone $sroQuery)->count();

            if ($count === 0) {
                $this->comment('  No overdue SRO points found.');
            } else {
                $this->comment("  Found {$count} overdue SRO points:");

                foreach ($sroQuery->cursor() as $point) {
                    $this->line("    - {$point->user->name}: {$point->point_type} ({$point->shift_date->format('Y-m-d')}) expires_at={$point->expires_at->format('Y-m-d')}");

                    if (! $dryRun) {
                        DB::transaction(function () use ($point) {
                            $point->markAsExpired('sro');
                        });
                    }

                    $totals['sro_expired']++;
                }
            }

            $this->newLine();
        }

        // ── Fix 2: Clear gbro_expires_at from non-eligible records ─────────────
        if (! $this->option('skip-gbro')) {
            $this->info('Fix 2: Clearing stale gbro_expires_at from non-GBRO-eligible records...');

            $count = AttendancePoint::where('eligible_for_gbro', false)
                ->whereNotNull('gbro_expires_at')
                ->count();

            if ($count === 0) {
                $this->comment('  No stale GBRO dates found.');
            } else {
                $this->comment("  Found {$count} records with stale gbro_expires_at.");

                if (! $dryRun) {
                    AttendancePoint::where('eligible_for_gbro', false)
                        ->whereNotNull('gbro_expires_at')
                        ->update(['gbro_expires_at' => null]);
                }

                $totals['gbro_dates_cleared'] = $count;
            }

            $this->newLine();
        }

        // ── Fix 3: Correct eligible_for_gbro flag based on business rules ─────────
        //   3a. whole_day_absence + is_advised=true  → eligible_for_gbro=true  (advised absence)
        //   3b. point_type != whole_day_absence       → eligible_for_gbro=true  (always eligible)
        //   3c. whole_day_absence + is_advised=false  → eligible_for_gbro=false (FTN/NCNS)
        // Must run BEFORE Fix 4 so expires_at is recalculated with the corrected flag.
        if (! $this->option('skip-gbro-eligible')) {
            $this->info('Fix 3: Correcting eligible_for_gbro flags...');

            $gbroAffectedUserIds = [];

            // 3a: Advised absences wrongly marked as not eligible
            $advisedWrong = AttendancePoint::where('point_type', 'whole_day_absence')
                ->where('is_advised', true)
                ->where('eligible_for_gbro', false)
                ->where('is_expired', false)
                ->get();

            if ($advisedWrong->isNotEmpty()) {
                $this->comment("  3a. Found {$advisedWrong->count()} advised absences with eligible_for_gbro=false:");
                foreach ($advisedWrong as $point) {
                    $this->line("    - ID {$point->id}: {$point->shift_date->format('Y-m-d')} (advised absence)");
                    if (! $dryRun) {
                        $point->update(['eligible_for_gbro' => true, 'expiration_type' => 'sro']);
                        $gbroAffectedUserIds[$point->user_id] = true;
                    }
                    $totals['gbro_eligible_fixed']++;
                }
            } else {
                $this->comment('  3a. No advised absences with wrong flag found.');
            }

            // 3b: Non-whole-day types wrongly marked as not eligible
            $nonWholeWrong = AttendancePoint::where('point_type', '!=', 'whole_day_absence')
                ->where('eligible_for_gbro', false)
                ->where('is_expired', false)
                ->get();

            if ($nonWholeWrong->isNotEmpty()) {
                $this->comment("  3b. Found {$nonWholeWrong->count()} non-whole-day points with eligible_for_gbro=false:");
                foreach ($nonWholeWrong as $point) {
                    $this->line("    - ID {$point->id}: {$point->shift_date->format('Y-m-d')} ({$point->point_type})");
                    if (! $dryRun) {
                        $point->update(['eligible_for_gbro' => true, 'expiration_type' => 'sro']);
                        $gbroAffectedUserIds[$point->user_id] = true;
                    }
                    $totals['gbro_eligible_fixed']++;
                }
            } else {
                $this->comment('  3b. No non-whole-day points with wrong flag found.');
            }

            // 3c: FTN/NCNS (whole_day_absence + is_advised=false) wrongly marked as eligible
            $ftnWrong = AttendancePoint::where('point_type', 'whole_day_absence')
                ->where('is_advised', false)
                ->where('eligible_for_gbro', true)
                ->where('is_expired', false)
                ->get();

            if ($ftnWrong->isNotEmpty()) {
                $this->comment("  3c. Found {$ftnWrong->count()} FTN/NCNS with eligible_for_gbro=true:");
                foreach ($ftnWrong as $point) {
                    $this->line("    - ID {$point->id}: {$point->shift_date->format('Y-m-d')} (FTN/NCNS)");
                    if (! $dryRun) {
                        $point->update(['eligible_for_gbro' => false, 'expiration_type' => 'none']);
                        $gbroAffectedUserIds[$point->user_id] = true;
                    }
                    $totals['gbro_eligible_fixed']++;
                }
            } else {
                $this->comment('  3c. No FTN/NCNS with wrong flag found.');
            }

            if (! $dryRun && ! empty($gbroAffectedUserIds)) {
                $this->comment('  Cascading GBRO recalculation for '.count($gbroAffectedUserIds).' affected users...');
                foreach (array_keys($gbroAffectedUserIds) as $uid) {
                    $this->gbroService->cascadeRecalculateGbro($uid);
                }
            }

            $this->newLine();
        }

        // ── Fix 4: Recalculate expires_at for month-end overflow ────────────────
        // Affects records where expires_at doesn't match the NoOverflow calculation.
        // This happens when Carbon's addMonths() overflows (e.g. Mar 31 + 6mo = Oct 1).
        if (! $this->option('skip-expires-at')) {
            $this->info('Fix 4: Correcting month-end expires_at overflow...');

            // NCNS/FTN: whole_day_absence + eligible_for_gbro=false → +1 year NoOverflow.
            // Source of truth is eligible_for_gbro, NOT is_advised. FTN records have
            // is_advised=true but are still 1-year violations (eligible_for_gbro=false).
            $ftnWrong = AttendancePoint::whereNotNull('expires_at')
                ->where('point_type', 'whole_day_absence')
                ->where('eligible_for_gbro', false)
                ->get()
                ->filter(fn ($p) => $p->expires_at->format('Y-m-d') !== Carbon::parse($p->shift_date)->addYearNoOverflow()->format('Y-m-d'));

            // All others (non-whole-day, or whole-day with eligible_for_gbro=true) → +6 months NoOverflow
            $nonFtnWrong = AttendancePoint::whereNotNull('expires_at')
                ->where(function ($q) {
                    $q->where('point_type', '!=', 'whole_day_absence')
                        ->orWhere('eligible_for_gbro', true);
                })
                ->get()
                ->filter(fn ($p) => $p->expires_at->format('Y-m-d') !== Carbon::parse($p->shift_date)->addMonthsNoOverflow(6)->format('Y-m-d'));

            $wrongCount = $ftnWrong->count() + $nonFtnWrong->count();

            if ($wrongCount === 0) {
                $this->comment('  No expires_at overflow issues found.');
            } else {
                $this->comment("  Found {$wrongCount} records with incorrect expires_at:");

                foreach ($ftnWrong as $point) {
                    $correct = Carbon::parse($point->shift_date)->addYearNoOverflow()->format('Y-m-d');
                    $this->line("    - ID {$point->id}: {$point->shift_date->format('Y-m-d')} (FTN) {$point->expires_at->format('Y-m-d')} → {$correct}");

                    if (! $dryRun) {
                        $point->update(['expires_at' => $correct]);
                    }

                    $totals['expires_at_fixed']++;
                }

                foreach ($nonFtnWrong as $point) {
                    $correct = Carbon::parse($point->shift_date)->addMonthsNoOverflow(6)->format('Y-m-d');
                    $this->line("    - ID {$point->id}: {$point->shift_date->format('Y-m-d')} ({$point->point_type}) {$point->expires_at->format('Y-m-d')} → {$correct}");

                    if (! $dryRun) {
                        $point->update(['expires_at' => $correct]);
                    }

                    $totals['expires_at_fixed']++;
                }
            }

            $this->newLine();
        }

        // ── Summary ────────────────────────────────────────────────────────────
        $this->info('Summary:');
        $this->table(
            ['Fix', 'Records Affected'],
            [
                ['SRO expired (overdue)', $totals['sro_expired']],
                ['GBRO dates cleared (non-eligible)', $totals['gbro_dates_cleared']],
                ['eligible_for_gbro corrected (advised absences)', $totals['gbro_eligible_fixed']],
                ['expires_at corrected (month-end overflow)', $totals['expires_at_fixed']],
            ]
        );

        if ($dryRun) {
            $this->warn('Dry run complete. Run without --dry-run to apply changes.');
        } else {
            $this->info('✅ Anomaly fix complete.');
        }

        return Command::SUCCESS;
    }
}
