<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Station;
use App\Models\Site;
use App\Models\PcSpec;
use App\Models\Campaign;

class StationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sites = Site::all();
        $pcSpecs = PcSpec::all();
        $campaigns = Campaign::all();

        // If no sites, campaigns, or PC specs exist, create them first
        if ($sites->isEmpty() || $campaigns->isEmpty()) {
            return;
        }

        // Create a pool of available PC specs (some stations may not have PC specs)
        $availablePcSpecs = $pcSpecs->shuffle();
        $pcSpecIndex = 0;

        // Create 10 stations for each site
        foreach ($sites as $site) {
            for ($i = 1; $i <= 10; $i++) {
                $stationNumber = strtoupper(($site->code ?? $site->name) . '-' . str_pad($i, 3, '0', STR_PAD_LEFT));

                // Assign unique PC spec if available, 50% chance of having no PC spec
                $pcSpecId = null;
                if ($pcSpecIndex < $availablePcSpecs->count() && fake()->boolean(70)) {
                    $pcSpecId = $availablePcSpecs[$pcSpecIndex]->id;
                    $pcSpecIndex++;
                }

                Station::create([
                    'site_id' => $site->id,
                    'station_number' => $stationNumber,
                    'pc_spec_id' => $pcSpecId,
                    'campaign_id' => $campaigns->random()->id,
                    'status' => fake()->randomElement(['active', 'inactive', 'maintenance']),
                    'monitor_type' => fake()->randomElement(['Single', 'Dual']),
                ]);
            }
        }

        // Create additional random stations using the factory (optional)
        Station::factory()->count(20)->create();
    }
}
