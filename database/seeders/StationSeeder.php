<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Station;
use App\Models\Site;
use App\Models\PcSpec;
use Illuminate\Support\Str;

class StationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Example: Seed 10 stations for each site, with optional PC spec
        $sites = Site::all();
        $pcSpecs = PcSpec::all();
        $campaigns = \App\Models\Campaign::all();

        // Create a collection of available PC specs that haven't been assigned yet
        $availablePcSpecs = $pcSpecs->shuffle();
        $pcSpecIndex = 0;

        foreach ($sites as $site) {
            for ($i = 1; $i <= 10; $i++) {
                $stationNumber = strtoupper(($site->code ?? $site->name) . '-' . str_pad($i, 3, '0', STR_PAD_LEFT));

                // Assign unique PC spec if available, otherwise null
                $pcSpecId = null;
                if ($pcSpecIndex < $availablePcSpecs->count()) {
                    $pcSpecId = $availablePcSpecs[$pcSpecIndex]->id;
                    $pcSpecIndex++;
                }

                Station::create([
                    'site_id' => $site->id,
                    'station_number' => $stationNumber,
                    'pc_spec_id' => $pcSpecId, // Unique PC spec or null
                    'campaign_id' => $campaigns->random()->id, // Assign a random campaign
                    'status' => 'active', // Default status if required
                ]);
            }
        }
    }
}
