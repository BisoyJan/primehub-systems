<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendTestNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notification:test {user_id?} {--all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test notification to a user or all users';

    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('all')) {
            $this->info('Sending test notification to all approved users...');
            $this->notificationService->notifyAllUsers(
                'Test Notification',
                'This is a test notification sent to all users. The notification system is working correctly!',
                ['test' => true, 'sent_at' => now()->toDateTimeString()]
            );
            $this->info('✓ Test notification sent to all users successfully!');
            return 0;
        }

        $userId = $this->argument('user_id');

        if (!$userId) {
            // Interactive mode - show list of users
            $users = User::where('is_approved', true)->get(['id', 'first_name', 'last_name', 'email']);

            if ($users->isEmpty()) {
                $this->error('No approved users found.');
                return 1;
            }

            $this->table(
                ['ID', 'Name', 'Email'],
                $users->map(fn($user) => [
                    $user->id,
                    $user->name,
                    $user->email
                ])
            );

            $userId = $this->ask('Enter the user ID to send a test notification to');
        }

        $user = User::find($userId);

        if (!$user) {
            $this->error("User with ID {$userId} not found.");
            return 1;
        }

        if (!$user->is_approved) {
            $this->error("User {$user->name} is not approved.");
            return 1;
        }

        $this->info("Sending test notification to {$user->name}...");

        $this->notificationService->notifySystemMessage(
            $user->id,
            'Test Notification',
            'This is a test notification. The notification system is working correctly!',
            ['test' => true, 'sent_at' => now()->toDateTimeString()]
        );

        $this->info('✓ Test notification sent successfully!');
        $this->line('');
        $this->line('The user can view this notification by:');
        $this->line('1. Clicking the bell icon in the header');
        $this->line('2. Visiting /notifications');

        return 0;
    }
}
