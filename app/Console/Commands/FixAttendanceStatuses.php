<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FixAttendanceStatuses extends Command
{
    protected $signature = 'attendance:fix-statuses {--date=} {--from=} {--to=}';
    protected $description = 'Fix attendance statuses based on actual time in/out records';

    public function handle()
    {
        if ($this->option('from') && $this->option('to')) {
            $from = $this->option('from');
            $to = $this->option('to');
            $this->info("Fixing attendance statuses from {$from} to {$to}...");

            $attendances = Attendance::whereBetween('shift_date', [$from, $to])
                ->with('employeeSchedule', 'user')
                ->get();
        } else {
            $date = $this->option('date') ?: Carbon::today()->format('Y-m-d');
            $this->info("Fixing attendance statuses for {$date}...");

            $attendances = Attendance::where('shift_date', $date)
                ->with('employeeSchedule', 'user')
                ->get();
        }

        $updated = 0;
        foreach ($attendances as $att) {
            if (!$att->employeeSchedule) {
                continue;
            }

            $oldStatus = $att->status;
            $oldSecondaryStatus = $att->secondary_status;
            $hasTimeIn = $att->actual_time_in !== null;
            $hasTimeOut = $att->actual_time_out !== null;

            // Recalculate status based on what we have
            if (!$hasTimeIn && !$hasTimeOut) {
                $att->status = 'ncns';
                $att->secondary_status = null;
            } elseif (!$hasTimeIn && $hasTimeOut) {
                $att->status = 'failed_bio_in';
                $att->secondary_status = null;
            } elseif ($hasTimeIn && !$hasTimeOut) {
                // Calculate tardiness
                $shiftDate = is_string($att->shift_date) ? $att->shift_date : $att->shift_date->format('Y-m-d');
                $scheduledIn = Carbon::parse($shiftDate . ' ' . $att->employeeSchedule->scheduled_time_in);
                $actualIn = Carbon::parse($att->actual_time_in);
                $tardyMins = (int) $scheduledIn->diffInMinutes($actualIn, false);

                if ($tardyMins <= 0 || $actualIn->lessThanOrEqualTo($scheduledIn)) {
                    $att->status = 'failed_bio_out'; // On time but missing out
                    $att->secondary_status = null;
                    $att->tardy_minutes = null;
                } elseif ($tardyMins >= 1 && $tardyMins <= 15) {
                    $att->status = 'tardy'; // Keep tardy as primary
                    $att->secondary_status = 'failed_bio_out'; // Add missing out as secondary
                    $att->tardy_minutes = $tardyMins;
                } else {
                    $att->status = 'half_day_absence'; // Keep half day as primary
                    $att->secondary_status = 'failed_bio_out'; // Add missing out as secondary
                    $att->tardy_minutes = $tardyMins;
                }
            } elseif ($hasTimeIn && $hasTimeOut) {
                // Both exist, recalculate full status
                $shiftDate = is_string($att->shift_date) ? $att->shift_date : Carbon::parse($att->shift_date)->format('Y-m-d');
                $scheduledIn = Carbon::parse($shiftDate . ' ' . $att->employeeSchedule->scheduled_time_in);
                $actualIn = Carbon::parse($att->actual_time_in);
                $tardyMins = (int) $scheduledIn->diffInMinutes($actualIn, false);

                if ($tardyMins <= 0 || $actualIn->lessThanOrEqualTo($scheduledIn)) {
                    $att->status = 'on_time';
                    $att->tardy_minutes = null;
                } elseif ($tardyMins >= 1 && $tardyMins <= 15) {
                    $att->status = 'tardy';
                    $att->tardy_minutes = $tardyMins;
                } else {
                    $att->status = 'half_day_absence';
                    $att->tardy_minutes = $tardyMins;
                }

                $att->secondary_status = null;
            }

            if ($oldStatus !== $att->status || $oldSecondaryStatus !== $att->secondary_status) {
                $att->save();
                $updated++;
                $secondaryMsg = $att->secondary_status ? " + {$att->secondary_status}" : "";
                $this->line("Updated {$att->user->name}: {$oldStatus} → {$att->status}{$secondaryMsg}");
            }
        }

        $this->info("Total updated: {$updated}");
        return 0;
    }
}
