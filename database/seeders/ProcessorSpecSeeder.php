<?php

namespace Database\Seeders;

use App\Models\ProcessorSpec;
use Illuminate\Database\Seeder;

class ProcessorSpecSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 20 random processor specifications using the factory
        ProcessorSpec::factory()->count(20)->create();
    }
}
