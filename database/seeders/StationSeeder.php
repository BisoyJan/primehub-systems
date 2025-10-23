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
     * Creates realistic station data for testing with various scenarios:
     * - Stations with and without PCs
     * - Stations with single and dual monitors
     * - Different statuses (Admin, Occupied, Vacant, No PC)
     * - Variety of station numbering patterns
     */
    public function run(): void
    {
        $sites = Site::all();
        $pcSpecs = PcSpec::all();
        $campaigns = Campaign::all();
        $monitors = MonitorSpec::all();

        // Ensure required data exists
        if ($sites->isEmpty() || $campaigns->isEmpty()) {
            $this->command->warn('⚠️  Sites and Campaigns must exist before seeding stations.');
            $this->command->info('💡 Run: php artisan db:seed --class=SiteSeeder');
            $this->command->info('💡 Run: php artisan db:seed --class=CampaignSeeder');
            return;
        }

        if ($monitors->isEmpty()) {
            $this->command->warn('⚠️  No monitors found. Run MonitorSpecSeeder first for complete data.');
        }

        $this->command->info('🚀 Starting Station seeding...');

        // Track available PC specs (each can only be assigned once)
        $availablePcSpecs = $pcSpecs->shuffle()->values();
        $pcSpecIndex = 0;
        $totalCreated = 0;

        // Status distribution for realistic scenarios
        $statuses = ['Admin', 'Occupied', 'Vacant', 'No PC'];
        $statusWeights = [5, 60, 25, 10]; // Percentage weights

        // Station numbering patterns
        $numberingPatterns = [
            'number_only' => ['PC-001', 'PC-002', 'PC-003'], // Sequential numbers
            'letter_only' => ['ST-1A', 'ST-1B', 'ST-1C'], // Sequential letters
            'both' => ['WS-1A', 'WS-2B', 'WS-3C'], // Both increment
        ];

        // Create stations for each site with realistic patterns
        foreach ($sites as $siteIndex => $site) {
            $siteCode = $site->code ?? strtoupper(substr($site->name, 0, 3));
            $stationsPerSite = fake()->numberBetween(8, 15);

            $this->command->info("  📍 Creating {$stationsPerSite} stations for site: {$site->name}");

            for ($i = 1; $i <= $stationsPerSite; $i++) {
                // Vary numbering patterns by site
                $pattern = $siteIndex % 3;
                if ($pattern === 0) {
                    // Number only: PC-001, PC-002
                    $stationNumber = $siteCode . '-' . str_pad($i, 3, '0', STR_PAD_LEFT);
                } elseif ($pattern === 1) {
                    // Letter suffix: ST-01A, ST-01B
                    $letterSuffix = chr(65 + (($i - 1) % 26));
                    $stationNumber = $siteCode . '-' . str_pad($i, 2, '0', STR_PAD_LEFT) . $letterSuffix;
                } else {
                    // Mixed: WS-1A, WS-2B
                    $letterSuffix = chr(65 + (($i - 1) % 26));
                    $stationNumber = $siteCode . '-' . $i . $letterSuffix;
                }

                // Determine status (weighted random)
                $statusRoll = fake()->numberBetween(1, 100);
                $cumulative = 0;
                $status = 'Occupied';
                foreach ($statuses as $idx => $possibleStatus) {
                    $cumulative += $statusWeights[$idx];
                    if ($statusRoll <= $cumulative) {
                        $status = $possibleStatus;
                        break;
                    }
                }

                // PC assignment logic based on status
                $pcSpecId = null;
                if ($status !== 'No PC' && $status !== 'Vacant') {
                    // Admin and Occupied stations should have PCs (if available)
                    if ($pcSpecIndex < $availablePcSpecs->count()) {
                        $pcSpecId = $availablePcSpecs[$pcSpecIndex]->id;
                        $pcSpecIndex++;
                    }
                } elseif ($status === 'Vacant' && fake()->boolean(30)) {
                    // 30% of vacant stations have PCs (recently vacated)
                    if ($pcSpecIndex < $availablePcSpecs->count()) {
                        $pcSpecId = $availablePcSpecs[$pcSpecIndex]->id;
                        $pcSpecIndex++;
                    }
                }

                // Monitor type: More dual monitors for Admin/Occupied stations
                if ($status === 'Admin' || ($status === 'Occupied' && fake()->boolean(40))) {
                    $monitorType = 'dual';
                } else {
                    $monitorType = fake()->boolean(20) ? 'dual' : 'single';
                }

                // Create station
                $station = Station::create([
                    'site_id' => $site->id,
                    'station_number' => $stationNumber,
                    'pc_spec_id' => $pcSpecId,
                    'campaign_id' => $campaigns->random()->id,
                    'status' => $status,
                    'monitor_type' => $monitorType,
                ]);

                // Attach monitors based on monitor_type and availability
                if ($monitors->isNotEmpty()) {
                    $hasMonitors = fake()->boolean(85); // 85% have monitors

                    if ($hasMonitors) {
                        if ($monitorType === 'single') {
                            // Single monitor
                            $station->monitors()->attach($monitors->random()->id, ['quantity' => 1]);
                        } else {
                            // Dual monitors: 60% same model, 40% different models
                            if (fake()->boolean(60)) {
                                // Same monitor model
                                $station->monitors()->attach($monitors->random()->id, ['quantity' => 2]);
                            } else {
                                // Different monitor models
                                $monitor1 = $monitors->random();
                                $availableMonitors = $monitors->where('id', '!=', $monitor1->id);

                                if ($availableMonitors->isNotEmpty()) {
                                    $monitor2 = $availableMonitors->random();
                                    $station->monitors()->attach([
                                        $monitor1->id => ['quantity' => 1],
                                        $monitor2->id => ['quantity' => 1],
                                    ]);
                                } else {
                                    // Fallback: use same monitor twice
                                    $station->monitors()->attach($monitor1->id, ['quantity' => 2]);
                                }
                            }
                        }
                    }
                }

                $totalCreated++;
            }
        }

        // Create some additional random stations for variety
        $additionalCount = 15;
        $this->command->info("  🎲 Creating {$additionalCount} additional random stations...");

        for ($i = 1; $i <= $additionalCount; $i++) {
            $randomSite = $sites->random();
            $randomCode = strtoupper(fake()->lexify('???'));
            $stationNumber = $randomCode . '-' . fake()->numberBetween(1, 999);

            // Random status
            $statusRoll = fake()->numberBetween(1, 100);
            $cumulative = 0;
            $status = 'Occupied';
            foreach ($statuses as $idx => $possibleStatus) {
                $cumulative += $statusWeights[$idx];
                if ($statusRoll <= $cumulative) {
                    $status = $possibleStatus;
                    break;
                }
            }

            // PC assignment
            $pcSpecId = null;
            if ($status !== 'No PC' && $pcSpecIndex < $availablePcSpecs->count() && fake()->boolean(60)) {
                $pcSpecId = $availablePcSpecs[$pcSpecIndex]->id;
                $pcSpecIndex++;
            }

            // Monitor type
            $monitorType = fake()->randomElement(['single', 'dual']);

            $station = Station::create([
                'site_id' => $randomSite->id,
                'station_number' => $stationNumber,
                'pc_spec_id' => $pcSpecId,
                'campaign_id' => $campaigns->random()->id,
                'status' => $status,
                'monitor_type' => $monitorType,
            ]);

            // Attach monitors
            if ($monitors->isNotEmpty() && fake()->boolean(80)) {
                if ($monitorType === 'single') {
                    $station->monitors()->attach($monitors->random()->id, ['quantity' => 1]);
                } else {
                    if (fake()->boolean(60)) {
                        $station->monitors()->attach($monitors->random()->id, ['quantity' => 2]);
                    } else {
                        $monitor1 = $monitors->random();
                        $monitor2 = $monitors->where('id', '!=', $monitor1->id)->random();
                        $station->monitors()->attach([
                            $monitor1->id => ['quantity' => 1],
                            $monitor2->id => ['quantity' => 1],
                        ]);
                    }
                }
            }

            $totalCreated++;
        }

        // Summary
        $this->command->newLine();
        $this->command->info("✅ Successfully created {$totalCreated} stations!");
        $this->command->info("   - {$pcSpecIndex} stations with PCs assigned");
        $this->command->info("   - " . ($totalCreated - $pcSpecIndex) . " stations without PCs (vacant/reserved)");

        // Status breakdown
        $statusCounts = Station::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $this->command->newLine();
        $this->command->info("📊 Status Distribution:");
        foreach ($statusCounts as $status => $count) {
            $this->command->info("   - {$status}: {$count}");
        }

        // Monitor breakdown
        $singleMonitors = Station::where('monitor_type', 'single')->count();
        $dualMonitors = Station::where('monitor_type', 'dual')->count();

        $this->command->newLine();
        $this->command->info("🖥️  Monitor Distribution:");
        $this->command->info("   - Single monitors: {$singleMonitors}");
        $this->command->info("   - Dual monitors: {$dualMonitors}");
    }
}
