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
        // Create specific test accounts for each role
        $testAccounts = [
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
                'name' => 'IT User',
                'email' => 'it@example.com',
                'password' => Hash::make('password'),
                'role' => 'IT',
                'email_verified_at' => now(),
            ],
        ];

        foreach ($testAccounts as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                $user
            );
        }

        // Create additional random users using the factory
        User::factory()->count(10)->create();
    }
}
