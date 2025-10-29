<?php

namespace Database\Seeders;

use App\Models\MonitorSpec;
use Illuminate\Database\Seeder;

class MonitorSpecSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 15 monitor specifications using the factory
        $monitors = MonitorSpec::factory()->count(15)->create();

        $this->command->info('Created ' . $monitors->count() . ' monitor specifications.');
    }
}
