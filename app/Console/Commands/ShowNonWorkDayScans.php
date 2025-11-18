<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BiometricRecord;
use App\Models\User;
use Carbon\Carbon;

class ShowNonWorkDayScans extends Command
{
    protected $signature = 'attendance:show-non-work-scans {--from=} {--to=}';
    protected $description = 'Show biometric scans on days employees are not scheduled to work';

    public function handle()
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : Carbon::now()->subDays(7)->startOfDay();
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : Carbon::now()->endOfDay();

        $this->info("Checking biometric scans from {$from->toDateString()} to {$to->toDateString()}");
        $this->newLine();

        // Get all users with biometric records in the range
        $userIds = BiometricRecord::whereBetween('datetime', [$from, $to])
            ->distinct()
            ->pluck('user_id')
            ->filter();

        $nonWorkDayScans = [];

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            $schedule = $user->employeeSchedules()->where('is_active', true)->first();
            if (!$schedule) {
                continue;
            }

            // Get unique dates from biometric records
            $dates = BiometricRecord::where('user_id', $userId)
                ->whereBetween('datetime', [$from, $to])
                ->get()
                ->pluck('datetime')
                ->map(fn($dt) => $dt->format('Y-m-d'))
                ->unique();

            foreach ($dates as $date) {
                $dateCarbon = Carbon::parse($date);
                $dayName = $dateCarbon->format('l');

                // Check if employee works on this day
                if (!$schedule->worksOnDay($dayName)) {
                    $scanCount = BiometricRecord::where('user_id', $userId)
                        ->whereDate('datetime', $date)
                        ->count();

                    $nonWorkDayScans[] = [
                        'employee' => $user->name,
                        'date' => $date,
                        'day' => $dayName,
                        'scans' => $scanCount,
                    ];
                }
            }
        }

        if (empty($nonWorkDayScans)) {
            $this->info('✓ No biometric scans found on non-scheduled work days.');
            return 0;
        }

        $this->warn('⚠ Biometric scans detected on non-scheduled work days:');
        $this->newLine();

        $this->table(
            ['Employee', 'Date', 'Day', 'Scan Count'],
            array_map(fn($scan) => [
                $scan['employee'],
                $scan['date'],
                $scan['day'],
                $scan['scans'],
            ], $nonWorkDayScans)
        );

        $this->newLine();
        $this->info('Total: ' . count($nonWorkDayScans) . ' date(s) with non-work day scans');
        $this->comment('These scans were not processed into attendance records because employees are not scheduled to work on these days.');
        $this->comment('Review if these represent overtime, special work days, or data issues.');

        return 0;
    }
}
