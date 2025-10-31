<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PcMaintenance;

class PcMaintenanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 10 sample PC maintenance records
        PcMaintenance::factory()->count(10)->create();
    }
}
