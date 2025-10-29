<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PcSpec;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;

class PcSpecSeeder extends Seeder
{
    public function run(): void
    {
        PcSpec::factory()
            ->count(40)
            ->create()
            ->each(function (PcSpec $pc) {
                // Only pick RAM that matches the motherboard memory_type
                $ramIds = RamSpec::where('type', $pc->memory_type)
                    ->inRandomOrder()
                    ->take(3)
                    ->pluck('id');

                // Disks don’t depend on compatibility
                $diskIds = DiskSpec::inRandomOrder()
                    ->take(2)
                    ->pluck('id');

                // Pick any processor (no socket_type filter)
                $cpuIds = ProcessorSpec::inRandomOrder()
                    ->take(1)
                    ->pluck('id');

                // Sync relationships
                if ($ramIds->isNotEmpty()) {
                    $pc->ramSpecs()->sync($ramIds);
                }

                $pc->diskSpecs()->sync($diskIds);

                if ($cpuIds->isNotEmpty()) {
                    $pc->processorSpecs()->sync($cpuIds);
                }
            });
    }
}
