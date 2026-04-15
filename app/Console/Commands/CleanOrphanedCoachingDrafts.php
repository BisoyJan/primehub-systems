<?php

namespace App\Console\Commands;

use App\Models\CoachingSession;
use App\Models\CoachingSessionAttachment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('coaching:clean-orphaned-drafts {--dry-run : List orphaned drafts without deleting}')]
#[Description('Remove orphaned coaching session drafts that have a corresponding submitted session')]
class CleanOrphanedCoachingDrafts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $orphanedDrafts = DB::select('
            SELECT d.id as draft_id, d.coachee_id, d.coach_id, d.session_date,
                   s.id as submitted_id, s.ack_status, s.compliance_status
            FROM coaching_sessions d
            INNER JOIN coaching_sessions s
                ON d.coachee_id = s.coachee_id
                AND d.coach_id = s.coach_id
                AND s.is_draft = 0
                AND YEARWEEK(d.session_date, 1) = YEARWEEK(s.session_date, 1)
            WHERE d.is_draft = 1
            ORDER BY d.created_at DESC
        ');

        if (empty($orphanedDrafts)) {
            $this->info('No orphaned drafts found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Draft ID', 'Coachee', 'Coach', 'Date', 'Submitted ID', 'Ack Status', 'Compliance'],
            collect($orphanedDrafts)->map(fn ($row) => [
                $row->draft_id, $row->coachee_id, $row->coach_id,
                $row->session_date, $row->submitted_id,
                $row->ack_status, $row->compliance_status,
            ])->all(),
        );

        $this->warn(count($orphanedDrafts).' orphaned draft(s) found.');

        if ($this->option('dry-run')) {
            $this->info('Dry run — no records deleted.');

            return self::SUCCESS;
        }

        $ids = collect($orphanedDrafts)->pluck('draft_id')->all();

        // Delete attachments first
        $attachments = CoachingSessionAttachment::whereIn('coaching_session_id', $ids)->get();
        foreach ($attachments as $attachment) {
            Storage::disk('local')->delete($attachment->file_path);
            $attachment->delete();
        }

        $deleted = CoachingSession::whereIn('id', $ids)->delete();

        $this->info("Deleted {$deleted} orphaned draft(s) and {$attachments->count()} attachment(s).");

        return self::SUCCESS;
    }
}
