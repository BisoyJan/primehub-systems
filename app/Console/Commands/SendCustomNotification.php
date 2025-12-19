<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendCustomNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notification:send
                            {user_id? : The user ID to send notification to}
                            {--title= : The notification title}
                            {--message= : The notification message}
                            {--type=system : The notification type}
                            {--all : Send to all approved users}
                            {--role= : Send to all users with a specific role}
                            {--users= : Comma-separated list of user IDs (e.g. 1,5,10)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a custom notification to a user, users by role, or all users';

    public function __construct(protected NotificationService $notificationService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = $this->option('title') ?? $this->ask('Enter notification title');
        $message = $this->option('message') ?? $this->ask('Enter notification message');
        $type = $this->option('type');

        if (empty($title) || empty($message)) {
            $this->error('Title and message are required.');
            return 1;
        }

        // Send to all users
        if ($this->option('all')) {
            $count = User::where('is_approved', true)->count();

            if ($count === 0) {
                $this->error('No approved users found.');
                return 1;
            }

            if (!$this->confirm("Send notification to all {$count} approved users?")) {
                $this->info('Cancelled.');
                return 0;
            }

            $this->notificationService->notifyAllUsers($title, $message);
            $this->info("✓ Notification sent to {$count} users successfully!");
            return 0;
        }

        // Send to users by role
        if ($role = $this->option('role')) {
            $count = User::where('role', $role)->where('is_approved', true)->count();

            if ($count === 0) {
                $this->error("No approved users with role '{$role}' found.");
                return 1;
            }

            if (!$this->confirm("Send notification to {$count} users with role '{$role}'?")) {
                $this->info('Cancelled.');
                return 0;
            }

            $this->notificationService->notifyUsersByRole($role, $type, $title, $message);
            $this->info("✓ Notification sent to {$count} {$role} users successfully!");
            return 0;
        }

        // Send to specific users by IDs
        if ($userIds = $this->option('users')) {
            $ids = array_map('trim', explode(',', $userIds));
            $users = User::whereIn('id', $ids)->where('is_approved', true)->get();

            if ($users->isEmpty()) {
                $this->error('No approved users found with the provided IDs.');
                return 1;
            }

            $notFoundIds = array_diff($ids, $users->pluck('id')->map(fn($id) => (string)$id)->toArray());
            if (!empty($notFoundIds)) {
                $this->warn('User IDs not found or not approved: ' . implode(', ', $notFoundIds));
            }

            $this->table(
                ['ID', 'Name', 'Email', 'Role'],
                $users->map(fn($user) => [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->role
                ])
            );

            if (!$this->confirm("Send notification to these {$users->count()} users?")) {
                $this->info('Cancelled.');
                return 0;
            }

            $this->notificationService->createForMultipleUsers(
                $users->pluck('id')->toArray(),
                $type,
                $title,
                $message
            );

            $this->info("✓ Notification sent to {$users->count()} users successfully!");
            return 0;
        }

        // Send to specific user
        $userId = $this->argument('user_id');

        if (!$userId) {
            $users = User::where('is_approved', true)->get(['id', 'first_name', 'last_name', 'email', 'role']);

            if ($users->isEmpty()) {
                $this->error('No approved users found.');
                return 1;
            }

            $this->table(
                ['ID', 'Name', 'Email', 'Role'],
                $users->map(fn($user) => [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->role
                ])
            );

            $userId = $this->ask('Enter the user ID to send notification to');
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

        $this->notificationService->create($user->id, $type, $title, $message);
        $this->info("✓ Notification sent to {$user->name} successfully!");

        return 0;
    }
}
