<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create test accounts for each role
        $users = [
            [
                'name' => 'Super Admin User',
                'email' => 'superadmin@example.com',
                'password' => Hash::make('password'),
                'role' => 'Super Admin',
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'),
                'role' => 'Admin',
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Agent User',
                'email' => 'agent@example.com',
                'password' => Hash::make('password'),
                'role' => 'Agent',
                'email_verified_at' => now(),
            ],
            [
                'name' => 'HR User',
                'email' => 'hr@example.com',
                'password' => Hash::make('password'),
                'role' => 'HR',
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => Hash::make('password'),
                'role' => 'Agent',
                'email_verified_at' => now(),
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                $user
            );
        }
    }
}
