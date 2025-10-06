<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;

class StockSeeder extends Seeder
{
    public function run(): void
    {
        // RAM Stocks
        RamSpec::all()->each(function ($ram) {
            $ram->stock()->create([
                'quantity' => fake()->numberBetween(5, 50),
            ]);
        });

        // Disk Stocks
        DiskSpec::all()->each(function ($disk) {
            $disk->stock()->create([
                'quantity' => fake()->numberBetween(10, 100),
            ]);
        });

        // Processor Stocks
        ProcessorSpec::all()->each(function ($cpu) {
            $cpu->stock()->create([
                'quantity' => fake()->numberBetween(3, 30),
            ]);
        });
    }
}
