<?php

namespace App\Console\Commands;

use App\Models\AttendancePoint;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixGbroExpirationDates extends Command
{
    protected $signature = 'points:fix-gbro-dates';
    protected $description = 'Fix GBRO expiration dates for points that were updated with wrong reference';

    public function handle()
    {
        $this->info('Fixing GBRO expiration dates...');

        // For points that had GBRO applied, update the remaining points to use the scheduled GBRO date
        // Find all users who have points with gbro_applied_at set
        $usersWithGbroApplied = AttendancePoint::whereNotNull('gbro_applied_at')
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        foreach ($usersWithGbroApplied as $userId) {
            // Get the scheduled GBRO date from the expired points (their gbro_expires_at before expiration)
            $expiredPoint = AttendancePoint::where('user_id', $userId)
                ->whereNotNull('gbro_applied_at')
                ->whereNotNull('gbro_expires_at')
                ->orderBy('gbro_applied_at', 'desc')
                ->first();

            if (!$expiredPoint || !$expiredPoint->gbro_expires_at) {
                continue;
            }

            // The new GBRO prediction should be: scheduled_gbro_date + 60 days
            $scheduledGbroDate = Carbon::parse($expiredPoint->gbro_expires_at);
            $newGbroPrediction = $scheduledGbroDate->copy()->addDays(60);

            // Update remaining active GBRO-eligible points
            $updated = AttendancePoint::where('user_id', $userId)
                ->where('is_expired', false)
                ->where('is_excused', false)
                ->where('eligible_for_gbro', true)
                ->whereNull('gbro_applied_at')
                ->update(['gbro_expires_at' => $newGbroPrediction->format('Y-m-d')]);

            if ($updated > 0) {
                $userName = $expiredPoint->user->name ?? "User {$userId}";
                $this->line("  {$userName}: Updated {$updated} points to gbro_expires_at = {$newGbroPrediction->format('Y-m-d')} (based on scheduled GBRO {$scheduledGbroDate->format('Y-m-d')})");
            }
        }

        $this->info('✅ GBRO dates fixed!');

        return Command::SUCCESS;
    }
}
