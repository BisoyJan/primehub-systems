<?php

namespace Database\Seeders;

use App\Models\RamSpec;
use Illuminate\Database\Seeder;

class RamSpecSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 20 random RAM specifications using the factory
        RamSpec::factory()->count(20)->create();
    }
}
