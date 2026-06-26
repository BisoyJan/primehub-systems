<?php

namespace App\Console\Commands;

use App\Models\CoachingExclusion;
use App\Models\CoachingSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ArchiveOrphanSessions extends Command
{
    protected $signature = 'coaching:archive-orphan-sessions';

    protected $description = 'Archive coaching sessions where the coachee has resigned or is coaching-excluded and the session was never acknowledged';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(1);

        $sessions = CoachingSession::submitted()
            ->where('ack_status', 'Pending')
            ->where('created_at', '<=', $cutoff)
            ->get();

        $archived = 0;

        foreach ($sessions as $session) {
            $coachee = $session->coachee;

            if (! $coachee) {
                continue;
            }

            $isInactive = ! $coachee->is_active || $coachee->resigned_at !== null;
            $isExcluded = CoachingExclusion::query()
                ->where('user_id', $coachee->id)
                ->active()
                ->exists();

            if (! $isInactive && ! $isExcluded) {
                continue;
            }

            CoachingSession::where('id', $session->id)
                ->where('ack_status', 'Pending')
                ->update([
                    'ack_status' => 'Archived',
                    'compliance_status' => 'Archived',
                    'ack_timestamp' => Carbon::now(),
                    'ack_comment' => 'Agent resigned — auto-archived',
                ]);

            $archived++;
        }

        $this->info("Archived {$archived} orphan coaching session(s).");

        return self::SUCCESS;
    }
}
