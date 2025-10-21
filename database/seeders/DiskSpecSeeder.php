<?php

namespace Database\Seeders;

use App\Models\DiskSpec;
use Illuminate\Database\Seeder;

class DiskSpecSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 20 random disk specifications using the factory
        DiskSpec::factory()->count(20)->create();
    }
}
