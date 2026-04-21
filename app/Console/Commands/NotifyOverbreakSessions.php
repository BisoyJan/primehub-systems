<?php

namespace App\Console\Commands;

use App\Services\BreakTimerService;
use Illuminate\Console\Command;

class NotifyOverbreakSessions extends Command
{
    protected $signature = 'break-timer:notify-overbreaks';

    protected $description = 'Notify admins when active break sessions exceed their allowed duration';

    public function __construct(protected BreakTimerService $breakTimerService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $notifiedSessions = $this->breakTimerService->notifyAdminsAboutActiveOverbreaks();

        $this->info("Notified admins for {$notifiedSessions} overbreak session(s).");

        return self::SUCCESS;
    }
}
