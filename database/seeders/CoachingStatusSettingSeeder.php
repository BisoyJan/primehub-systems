<?php

namespace Database\Seeders;

use App\Models\CoachingStatusSetting;
use Illuminate\Database\Seeder;

class CoachingStatusSettingSeeder extends Seeder
{
    /**
     * Seed the default coaching status thresholds.
     */
    public function run(): void
    {
        foreach (CoachingStatusSetting::DEFAULTS as $key => $config) {
            CoachingStatusSetting::firstOrCreate(
                ['key' => $key],
                [
                    'value' => $config['value'],
                    'label' => $config['label'],
                ]
            );
        }
    }
}
