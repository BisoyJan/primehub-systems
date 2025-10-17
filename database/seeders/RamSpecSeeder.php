<?php

namespace Database\Seeders;

use App\Models\RamSpec;
use Illuminate\Database\Seeder;

class RamSpecSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rams = [
            ['manufacturer' => 'Corsair', 'model' => 'Vengeance LPX 8GB', 'capacity_gb' => 8, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Corsair', 'model' => 'Vengeance LPX 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Corsair', 'model' => 'Vengeance RGB 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3600, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'G.Skill', 'model' => 'Ripjaws V 8GB', 'capacity_gb' => 8, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'G.Skill', 'model' => 'Ripjaws V 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'G.Skill', 'model' => 'Trident Z 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3600, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Kingston', 'model' => 'Fury Beast 8GB', 'capacity_gb' => 8, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Kingston', 'model' => 'Fury Beast 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Crucial', 'model' => 'Ballistix 8GB', 'capacity_gb' => 8, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Crucial', 'model' => 'Ballistix 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Corsair', 'model' => 'Vengeance DDR5 16GB', 'capacity_gb' => 16, 'type' => 'DDR5', 'speed' => 5200, 'form_factor' => 'DIMM', 'voltage' => 1.25],
            ['manufacturer' => 'Corsair', 'model' => 'Vengeance DDR5 32GB', 'capacity_gb' => 32, 'type' => 'DDR5', 'speed' => 5600, 'form_factor' => 'DIMM', 'voltage' => 1.25],
            ['manufacturer' => 'G.Skill', 'model' => 'Trident Z5 16GB', 'capacity_gb' => 16, 'type' => 'DDR5', 'speed' => 6000, 'form_factor' => 'DIMM', 'voltage' => 1.25],
            ['manufacturer' => 'Kingston', 'model' => 'Fury Beast DDR3 8GB', 'capacity_gb' => 8, 'type' => 'DDR3', 'speed' => 1600, 'form_factor' => 'DIMM', 'voltage' => 1.5],
        ];

        foreach ($rams as $ram) {
            RamSpec::firstOrCreate(
                ['manufacturer' => $ram['manufacturer'], 'model' => $ram['model']],
                $ram
            );
        }
    }
}
