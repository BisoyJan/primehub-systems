<?php

namespace Database\Seeders;
use App\Models\Campaign;
use Illuminate\Database\Seeder;

class CampaignSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed unique campaigns (hardcoded as these are business-specific)
        $campaignNames = ['Admin','Utility', 'All State', 'Helix', 'LG Copier', 'Medicare', 'PSO', 'Real Estate', 'Sales'];
        foreach ($campaignNames as $name) {
            Campaign::firstOrCreate(['name' => $name]);
        }
    }
}
