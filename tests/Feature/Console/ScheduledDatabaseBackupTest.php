<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduledDatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private function backupEvent(): Event
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'backup:run'));

        $this->assertNotNull($event, 'The backup:run command is not scheduled.');

        return $event;
    }

    #[Test]
    public function backup_run_is_scheduled_daily_at_eight_am(): void
    {
        $event = $this->backupEvent();

        $this->assertSame('0 8 * * *', $event->expression);
        $this->assertSame('Asia/Manila', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    #[Test]
    public function failed_scheduled_backup_notifies_super_admins_and_logs_the_error(): void
    {
        Log::spy();
        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        $event = $this->backupEvent();
        $disk = Storage::fake('local');
        $output = str_repeat('Backup diagnostic output. ', 100).'Database dump failed.';
        $disk->put('scheduled-backup-output.log', $output);
        $event->sendOutputTo($disk->path('scheduled-backup-output.log'));
        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $superAdmin->id,
            'type' => 'system',
            'title' => 'Scheduled Database Backup Failed',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $agent->id,
            'title' => 'Scheduled Database Backup Failed',
        ]);

        Log::shouldHaveReceived('error')
            ->with('Scheduled database backup failed', ['output' => substr($output, -2000)])
            ->once();
    }

    #[Test]
    public function successful_scheduled_backup_does_not_notify(): void
    {
        User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);

        $event = $this->backupEvent();
        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);

        $this->assertDatabaseMissing('notifications', [
            'title' => 'Scheduled Database Backup Failed',
        ]);
    }
}
