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
                'first_name' => 'Super',
                'middle_name' => 'A',
                'last_name' => 'Admin',
                'email' => 'superadmin@example.com',
                'password' => Hash::make('password'),
                'role' => 'Super Admin',
                'email_verified_at' => now(),
                'is_approved' => true,
                'is_active' => true,
                'approved_at' => now(),
            ],
            [
                'first_name' => 'Admin',
                'middle_name' => 'U',
                'last_name' => 'User',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'),
                'role' => 'Admin',
                'email_verified_at' => now(),
                'is_approved' => true,
                'is_active' => true,
                'approved_at' => now(),
            ],
            [
                'first_name' => 'Agent',
                'middle_name' => 'U',
                'last_name' => 'User',
                'email' => 'agent@example.com',
                'password' => Hash::make('password'),
                'role' => 'Agent',
                'email_verified_at' => now(),
                'is_approved' => true,
                'is_active' => true,
                'approved_at' => now(),
            ],
            [
                'first_name' => 'IT',
                'middle_name' => 'U',
                'last_name' => 'User',
                'email' => 'it@example.com',
                'password' => Hash::make('password'),
                'role' => 'IT',
                'email_verified_at' => now(),
                'is_approved' => true,
                'is_active' => true,
                'approved_at' => now(),
            ],
            [
                'first_name' => 'Utility',
                'middle_name' => 'U',
                'last_name' => 'User',
                'email' => 'utility@example.com',
                'password' => Hash::make('password'),
                'role' => 'Utility',
                'email_verified_at' => now(),
                'is_approved' => true,
                'is_active' => true,
                'approved_at' => now(),
            ],
            [
                'first_name' => 'HR',
                'middle_name' => 'U',
                'last_name' => 'User',
                'email' => 'hr@example.com',
                'password' => Hash::make('password'),
                'role' => 'HR',
                'email_verified_at' => now(),
                'is_approved' => true,
                'is_active' => true,
                'approved_at' => now(),
            ],
            [
                'first_name' => 'Team',
                'middle_name' => 'U',
                'last_name' => 'Lead',
                'email' => 'teamlead@example.com',
                'password' => Hash::make('password'),
                'role' => 'Team Lead',
                'email_verified_at' => now(),
                'is_approved' => true,
                'is_active' => true,
                'approved_at' => now(),
            ],
            [
                'first_name' => 'Test',
                'middle_name' => 'E',
                'last_name' => 'Example',
                'email' => 'test@example.com',
                'password' => Hash::make('password'),
                'role' => 'Super Admin',
                'email_verified_at' => now(),
                'is_approved' => true,
                'is_active' => true,
                'approved_at' => now(),
            ],
        ];

        foreach ($testAccounts as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                $user
            );
        }

        // Create additional random users using the factory
        //User::factory()->count(10)->create();
    }
}
