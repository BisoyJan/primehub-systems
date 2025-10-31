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
     *
     * All seeders now utilize factories for generating realistic test data.
     */
    public function run(): void
    {
        // Seed unique sites (hardcoded as these are business-specific)
        $siteNames = ['PH1', 'PH2', 'PH3'];
        foreach ($siteNames as $name) {
            Site::firstOrCreate(['name' => $name]);
        }


        // Call all seeders - each now uses factories for generating data
        $this->call([
            AccountSeeder::class,       // Creates 4 test accounts + 10 random users via factory
            ProcessorSpecSeeder::class, // Creates 20 processors via factory
            RamSpecSeeder::class,       // Creates 20 RAM specs via factory
            DiskSpecSeeder::class,      // Creates 20 disk specs via factory
            StockSeeder::class,         // Creates stock entries for all specs with random quantities
            PcSpecSeeder::class,        // Creates 15 PC specs via factory with relationships
            MonitorSpecSeeder::class,   // Creates 15 monitor specs via factory with relationships
            CampaignSeeder::class,      // Creates unique campaigns (hardcoded)
            StationSeeder::class,       // Creates 30 site-based stations + 20 random via factory
            PcMaintenanceSeeder::class, // Creates 10 sample PC maintenance records
        ]);
    }
}
