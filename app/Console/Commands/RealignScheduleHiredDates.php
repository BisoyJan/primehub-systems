<?php

namespace App\Console\Commands;

use App\Models\EmployeeSchedule;
use App\Models\User;
use Illuminate\Console\Command;

class RealignScheduleHiredDates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'employee-schedules:realign-hired-dates
                            {--dry-run : Preview changes without applying them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Realign each employee schedule's effective_date to its owner's hired_date";

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $rows = [];
        $fixed = 0;

        User::whereNotNull('hired_date')
            ->whereHas('employeeSchedules')
            ->with('employeeSchedules')
            ->chunkById(200, function ($users) use (&$rows, &$fixed, $dryRun) {
                foreach ($users as $user) {
                    $hiredDate = $user->hired_date->format('Y-m-d');

                    $mismatched = $user->employeeSchedules
                        ->filter(fn (EmployeeSchedule $schedule) => $schedule->effective_date?->format('Y-m-d') !== $hiredDate);

                    foreach ($mismatched as $schedule) {
                        $rows[] = [
                            $schedule->id,
                            $user->name,
                            $schedule->effective_date?->format('Y-m-d') ?? '-',
                            $hiredDate,
                        ];
                        $fixed++;
                    }

                    if (! $dryRun && $mismatched->isNotEmpty()) {
                        EmployeeSchedule::whereIn('id', $mismatched->pluck('id'))
                            ->update(['effective_date' => $hiredDate]);
                    }
                }
            });

        if ($fixed === 0) {
            $this->info('All employee schedules already match their hired date.');

            return self::SUCCESS;
        }

        $this->table(['Schedule ID', 'Employee', 'Current', 'Hired Date'], $rows);
        $this->info(($dryRun ? 'Would realign ' : 'Realigned ')."{$fixed} schedule(s).");

        return self::SUCCESS;
    }
}
