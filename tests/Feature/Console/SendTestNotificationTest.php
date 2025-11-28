<?php

namespace Tests\Feature\Console;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendTestNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected NotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationService = app(NotificationService::class);
    }

    #[Test]
    public function it_sends_test_notification_to_specific_user(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $this->artisan('notification:test', ['user_id' => $user->id])
            ->expectsOutput("Sending test notification to {$user->name}...")
            ->expectsOutput('✓ Test notification sent successfully!')
            ->assertExitCode(0);

        // Verify notification was created
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Test Notification',
        ]);
    }

    #[Test]
    public function it_sends_test_notification_to_all_users_with_all_flag(): void
    {
        User::factory()->count(3)->create(['is_approved' => true]);

        $this->artisan('notification:test', ['--all' => true])
            ->expectsOutput('Sending test notification to all approved users...')
            ->expectsOutput('✓ Test notification sent to all users successfully!')
            ->assertExitCode(0);

        // All approved users should have notifications
        $this->assertEquals(3, Notification::where('title', 'Test Notification')->count());
    }

    #[Test]
    public function it_rejects_unapproved_user(): void
    {
        $user = User::factory()->create(['is_approved' => false]);

        $this->artisan('notification:test', ['user_id' => $user->id])
            ->expectsOutput("User {$user->name} is not approved.")
            ->assertExitCode(1);

        // No notification should be created
        $this->assertEquals(0, Notification::where('user_id', $user->id)->count());
    }

    #[Test]
    public function it_rejects_non_existent_user(): void
    {
        $this->artisan('notification:test', ['user_id' => 99999])
            ->expectsOutput('User with ID 99999 not found.')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_displays_no_users_message_when_no_approved_users_exist(): void
    {
        User::factory()->count(2)->create(['is_approved' => false]);

        // In interactive mode (no user_id and no --all), it shows no approved users message
        $this->artisan('notification:test')
            ->expectsOutput('No approved users found.')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_shows_notification_viewing_instructions(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $this->artisan('notification:test', ['user_id' => $user->id])
            ->expectsOutput('The user can view this notification by:')
            ->expectsOutput('1. Clicking the bell icon in the header')
            ->expectsOutput('2. Visiting /notifications')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_includes_test_metadata_in_notification(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $this->artisan('notification:test', ['user_id' => $user->id])
            ->assertExitCode(0);

        $notification = Notification::where('user_id', $user->id)->first();
        $this->assertNotNull($notification);

        $data = $notification->data;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        $this->assertArrayHasKey('test', $data);
        $this->assertTrue($data['test']);
        $this->assertArrayHasKey('sent_at', $data);
    }

    #[Test]
    public function it_sends_notifications_to_all_approved_users_only(): void
    {
        User::factory()->count(3)->create(['is_approved' => true]);
        User::factory()->count(2)->create(['is_approved' => false]);

        $this->artisan('notification:test', ['--all' => true])
            ->assertExitCode(0);

        // Only 3 approved users should have notifications
        $this->assertEquals(3, Notification::where('title', 'Test Notification')->count());
    }

    #[Test]
    public function it_contains_correct_notification_message(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $this->artisan('notification:test', ['user_id' => $user->id])
            ->assertExitCode(0);

        $notification = Notification::where('user_id', $user->id)->first();
        $this->assertStringContainsString(
            'This is a test notification',
            $notification->message
        );
        $this->assertStringContainsString(
            'notification system is working correctly',
            $notification->message
        );
    }

    #[Test]
    public function it_returns_success_exit_code_for_valid_request(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $this->artisan('notification:test', ['user_id' => $user->id])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_returns_failure_exit_code_for_invalid_request(): void
    {
        $this->artisan('notification:test', ['user_id' => 99999])
            ->assertExitCode(1);
    }

    #[Test]
    public function it_handles_multiple_notifications_for_all_flag(): void
    {
        $users = User::factory()->count(5)->create(['is_approved' => true]);

        $this->artisan('notification:test', ['--all' => true])
            ->assertExitCode(0);

        foreach ($users as $user) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $user->id,
                'title' => 'Test Notification',
            ]);
        }
    }

    #[Test]
    public function it_displays_success_message_with_user_name(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Doe',
            'is_approved' => true,
        ]);

        $this->artisan('notification:test', ['user_id' => $user->id])
            ->expectsOutputToContain('John Doe')
            ->assertExitCode(0);
    }
}
