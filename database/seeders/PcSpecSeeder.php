<?php

namespace Database\Seeders;

use App\Models\PcSpec;
use App\Models\ProcessorSpec;
use Illuminate\Database\Seeder;

class PcSpecSeeder extends Seeder
{
    public function run(): void
    {
        PcSpec::factory()
            ->count(40)
            ->create()
            ->each(function (PcSpec $pc) {
                // Pick any processor (no socket_type filter)
                $cpuIds = ProcessorSpec::inRandomOrder()
                    ->take(1)
                    ->pluck('id');

                if ($cpuIds->isNotEmpty()) {
                    $pc->processorSpecs()->sync($cpuIds);
                }
            });
    }
}
