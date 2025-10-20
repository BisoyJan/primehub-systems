<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Site;
use App\Models\Campaign;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed unique sites
        //$siteNames = ['PH1-2F', 'PH1-3F', 'PH1-4F', 'PH2', 'PH3'];
        $siteNames = ['PH1', 'PH2', 'PH3'];
        foreach ($siteNames as $name) {
            Site::firstOrCreate(['name' => $name]);
        }

        // Seed unique campaigns
        $campaignNames = ['Admin', 'All State', 'Helix', 'LG Copier', 'Medicare', 'PSO', 'Real State', 'Sales'];
        foreach ($campaignNames as $name) {
            Campaign::firstOrCreate(['name' => $name]);
        }

        // Call all seeders
        $this->call([
            AccountSeeder::class,
            ProcessorSpecSeeder::class,
            RamSpecSeeder::class,
            DiskSpecSeeder::class,
            StockSeeder::class,
            PcSpecSeeder::class,
            StationSeeder::class,
        ]);
    }
}
