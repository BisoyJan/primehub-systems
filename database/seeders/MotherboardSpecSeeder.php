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
                // ✅ Only pick RAM that matches the motherboard memory_type
                $ramIds = RamSpec::where('type', $mb->memory_type)
                    ->inRandomOrder()
                    ->take(3)
                    ->pluck('id');

                // Disks don’t depend on compatibility
                $diskIds = DiskSpec::inRandomOrder()
                    ->take(2)
                    ->pluck('id');

                // ✅ Only pick processors that match the motherboard socket_type
                $cpuIds = ProcessorSpec::where('socket_type', $mb->socket_type)
                    ->inRandomOrder()
                    ->take(1)
                    ->pluck('id');

                // Sync relationships
                if ($ramIds->isNotEmpty()) {
                    $mb->ramSpecs()->sync($ramIds);
                }

                $mb->diskSpecs()->sync($diskIds);

                if ($cpuIds->isNotEmpty()) {
                    $mb->processorSpecs()->sync($cpuIds);
                }
            });
    }
}
