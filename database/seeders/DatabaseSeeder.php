<?php

namespace Database\Seeders;

use App\Models\ProcessorSpec;
use App\Models\DiskSpec;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\RamSpec;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        RamSpec::factory()->count(15)->create();
        DiskSpec::factory()->count(15)->create();
        ProcessorSpec::factory()->count(15)->create();
        $this->call(
            [
                StockSeeder::class,
                MotherboardSpecSeeder::class,
            ]
        );
    }
}
