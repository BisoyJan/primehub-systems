<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;
use App\Models\MonitorSpec;

class StockSeeder extends Seeder
{
    public function run(): void
    {
        // Create stock entries for all RAM specs
        RamSpec::all()->each(function ($ram) {
            $ram->stock()->create([
                'quantity' => fake()->numberBetween(5, 50),
                'reserved' => fake()->numberBetween(0, 10),
                'location' => fake()->randomElement(['Warehouse A', 'Warehouse B', 'Storage Room 1', null]),
                'notes' => fake()->optional(0.3)->sentence(),
            ]);
        });

        // Create stock entries for all disk specs
        DiskSpec::all()->each(function ($disk) {
            $disk->stock()->create([
                'quantity' => fake()->numberBetween(10, 100),
                'reserved' => fake()->numberBetween(0, 15),
                'location' => fake()->randomElement(['Warehouse A', 'Warehouse B', 'Storage Room 1', null]),
                'notes' => fake()->optional(0.3)->sentence(),
            ]);
        });

        // Create stock entries for all processor specs
        ProcessorSpec::all()->each(function ($cpu) {
            $cpu->stock()->create([
                'quantity' => fake()->numberBetween(3, 30),
                'reserved' => fake()->numberBetween(0, 5),
                'location' => fake()->randomElement(['Warehouse A', 'Warehouse B', 'Storage Room 1', null]),
                'notes' => fake()->optional(0.3)->sentence(),
            ]);
        });

        // Create stock entries for all monitor specs
        MonitorSpec::all()->each(function ($monitor) {
            $monitor->stock()->create([
                'quantity' => fake()->numberBetween(5, 40),
                'reserved' => fake()->numberBetween(0, 8),
                'location' => fake()->randomElement(['Warehouse A', 'Warehouse B', 'Storage Room 1', null]),
                'notes' => fake()->optional(0.3)->sentence(),
            ]);
        });
    }
}
