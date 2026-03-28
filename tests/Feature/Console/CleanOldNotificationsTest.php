<?php

namespace Tests\Feature\Console;

use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanOldNotificationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_read_notifications_older_than_retention_days(): void
    {
        $user = User::factory()->create();

        // Old read notification (100 days ago)
        $oldRead = Notification::factory()->read()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subDays(100),
        ]);

        // Recent read notification (30 days ago)
        $recentRead = Notification::factory()->read()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subDays(30),
        ]);

        $this->artisan('notifications:clean', ['--force' => true, '--days' => 90])
            ->expectsOutput('Starting cleanup of old notifications...')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseHas('notifications', ['id' => $recentRead->id]);
    }

    #[Test]
    public function it_deletes_unread_notifications_older_than_unread_retention_days(): void
    {
        $user = User::factory()->create();

        // Old unread notification (200 days ago)
        $oldUnread = Notification::factory()->create([
            'user_id' => $user->id,
            'read_at' => null,
            'created_at' => Carbon::now()->subDays(200),
        ]);

        // Recent unread notification (30 days ago)
        $recentUnread = Notification::factory()->create([
            'user_id' => $user->id,
            'read_at' => null,
            'created_at' => Carbon::now()->subDays(30),
        ]);

        $this->artisan('notifications:clean', ['--force' => true, '--unread-days' => 180])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('notifications', ['id' => $oldUnread->id]);
        $this->assertDatabaseHas('notifications', ['id' => $recentUnread->id]);
    }

    #[Test]
    public function it_does_not_delete_anything_in_dry_run_mode(): void
    {
        $user = User::factory()->create();

        $oldRead = Notification::factory()->read()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subDays(100),
        ]);

        $oldUnread = Notification::factory()->create([
            'user_id' => $user->id,
            'read_at' => null,
            'created_at' => Carbon::now()->subDays(200),
        ]);

        $this->artisan('notifications:clean', [
            '--force' => true,
            '--days' => 90,
            '--unread-days' => 180,
            '--dry-run' => true,
        ])->assertExitCode(0);

        // Both records should still exist
        $this->assertDatabaseHas('notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseHas('notifications', ['id' => $oldUnread->id]);
    }

    #[Test]
    public function it_reports_no_old_notifications_when_none_exist(): void
    {
        $user = User::factory()->create();

        // Only recent notifications
        Notification::factory()->create([
            'user_id' => $user->id,
            'read_at' => null,
            'created_at' => Carbon::now()->subDays(5),
        ]);

        $this->artisan('notifications:clean', ['--force' => true])
            ->expectsOutput('No old notifications found to delete.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_respects_custom_retention_days(): void
    {
        $user = User::factory()->create();

        // 40-day-old read notification
        $notification = Notification::factory()->read()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subDays(40),
        ]);

        // With default 90 days: should NOT be deleted
        $this->artisan('notifications:clean', ['--force' => true, '--days' => 90])
            ->assertExitCode(0);

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);

        // With 30 days: should be deleted
        $this->artisan('notifications:clean', ['--force' => true, '--days' => 30])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    #[Test]
    public function it_handles_both_read_and_unread_cleanup_together(): void
    {
        $user = User::factory()->create();

        $oldRead = Notification::factory()->read()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subDays(100),
        ]);

        $oldUnread = Notification::factory()->create([
            'user_id' => $user->id,
            'read_at' => null,
            'created_at' => Carbon::now()->subDays(200),
        ]);

        $recentRead = Notification::factory()->read()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subDays(10),
        ]);

        $recentUnread = Notification::factory()->create([
            'user_id' => $user->id,
            'read_at' => null,
            'created_at' => Carbon::now()->subDays(10),
        ]);

        $this->artisan('notifications:clean', [
            '--force' => true,
            '--days' => 90,
            '--unread-days' => 180,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $oldUnread->id]);
        $this->assertDatabaseHas('notifications', ['id' => $recentRead->id]);
        $this->assertDatabaseHas('notifications', ['id' => $recentUnread->id]);
    }
}
