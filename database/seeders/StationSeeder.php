<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Station;
use App\Models\Site;
use App\Models\PcSpec;
use App\Models\Campaign;
use App\Models\MonitorSpec;

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
    $monitors = MonitorSpec::all();

        // If no sites, campaigns, or PC specs exist, create them first
        if ($sites->isEmpty() || $campaigns->isEmpty() || $monitors->isEmpty()) {
            return;
        }

        // Create a pool of available PC specs (some stations may not have PC specs)
        $availablePcSpecs = $pcSpecs->shuffle();
        $pcSpecIndex = 0;

        // Create 10 stations for each site
        foreach ($sites as $site) {
            for ($i = 1; $i <= 10; $i++) {
                $stationNumber = strtoupper(($site->code ?? $site->name) . '-' . str_pad($i, 3, '0', STR_PAD_LEFT));

                // Assign unique PC spec if available, 70% chance of having a PC spec
                $pcSpecId = null;
                if ($pcSpecIndex < $availablePcSpecs->count() && fake()->boolean(70)) {
                    $pcSpecId = $availablePcSpecs[$pcSpecIndex]->id;
                    $pcSpecIndex++;
                }

                $monitorType = fake()->randomElement(['single', 'dual']);
                $status = $pcSpecId === null ? 'No Pc' : fake()->randomElement(['Occupied', 'Vacant', 'Admin']);
                $station = Station::create([
                    'site_id' => $site->id,
                    'station_number' => $stationNumber,
                    'pc_spec_id' => $pcSpecId,
                    'campaign_id' => $campaigns->random()->id,
                    'status' => $status,
                    'monitor_type' => $monitorType,
                ]);

                // Attach monitors based on monitor_type
                if ($monitorType === 'single') {
                    $monitor = $monitors->random();
                    $station->monitors()->sync([
                        $monitor->id => ['quantity' => 1]
                    ]);
                } else { // Dual
                    // Can be same or different monitors
                    $selectedMonitors = $monitors->random(2);
                    $syncData = [];
                    foreach ($selectedMonitors as $monitor) {
                        $syncData[$monitor->id] = ['quantity' => 1];
                    }
                    $station->monitors()->sync($syncData);
                }
            }
        }
    }
}
