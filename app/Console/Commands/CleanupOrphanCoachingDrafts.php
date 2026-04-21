<?php

namespace App\Console\Commands;

use App\Models\CoachingSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanCoachingDrafts extends Command
{
    protected $signature = 'coaching:cleanup-orphan-drafts
                            {--dry-run : List orphan drafts without deleting}';

    protected $description = 'Delete coaching draft sessions that already have a submitted counterpart for the same coach + coachee + session_date.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $drafts = CoachingSession::with('attachments')
            ->where('is_draft', true)
            ->get();

        $orphans = $drafts->filter(function (CoachingSession $draft) {
            return CoachingSession::where('is_draft', false)
                ->where('coach_id', $draft->coach_id)
                ->where('coachee_id', $draft->coachee_id)
                ->whereDate('session_date', $draft->session_date)
                ->exists();
        })->values();

        $this->info('Total drafts: '.$drafts->count());
        $this->info('Orphan drafts (have submitted counterpart): '.$orphans->count());

        if ($orphans->isEmpty()) {
            $this->info('Nothing to clean up.');

            return self::SUCCESS;
        }

        $this->table(
            ['draft_id', 'coach_id', 'coachee_id', 'session_date', 'created_at', 'attachments'],
            $orphans->map(fn ($d) => [
                $d->id,
                $d->coach_id,
                $d->coachee_id,
                optional($d->session_date)->toDateString(),
                optional($d->created_at)->toDateTimeString(),
                $d->attachments->count(),
            ])->all()
        );

        if ($dryRun) {
            $this->warn('Dry run — no rows deleted.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Delete these '.$orphans->count().' orphan drafts and their attachments?', false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $deletedRows = 0;
        $deletedFiles = 0;

        foreach ($orphans as $draft) {
            try {
                DB::transaction(function () use ($draft, &$deletedFiles) {
                    foreach ($draft->attachments as $attachment) {
                        if ($attachment->file_path && Storage::disk('local')->exists($attachment->file_path)) {
                            Storage::disk('local')->delete($attachment->file_path);
                            $deletedFiles++;
                        }
                        $attachment->delete();
                    }
                    $draft->delete();
                });
                $deletedRows++;
            } catch (\Throwable $e) {
                Log::error('CleanupOrphanCoachingDrafts failed for draft '.$draft->id.': '.$e->getMessage());
                $this->error('Failed to delete draft '.$draft->id.': '.$e->getMessage());
            }
        }

        $this->info("Deleted drafts: {$deletedRows}");
        $this->info("Removed attachment files: {$deletedFiles}");
        $this->info('Remaining drafts: '.CoachingSession::where('is_draft', true)->count());

        return self::SUCCESS;
    }
}
