<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MotherboardSpec;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;

class MotherboardSpecSeeder extends Seeder
{
    public function run(): void
    {
        MotherboardSpec::factory()
            ->count(15)
            ->create()
            ->each(function (MotherboardSpec $mb) {
                $ramIds  = RamSpec::inRandomOrder()->take(3)->pluck('id');
                $diskIds = DiskSpec::inRandomOrder()->take(2)->pluck('id');
                $cpuIds  = ProcessorSpec::inRandomOrder()->take(1)->pluck('id');

                $mb->ramSpecs()->sync($ramIds);
                $mb->diskSpecs()->sync($diskIds);
                $mb->processorSpecs()->sync($cpuIds);
            });
    }
}
