<?php

namespace Database\Factories;

use App\Models\Stock;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $stockableTypes = [
            RamSpec::class,
            DiskSpec::class,
            ProcessorSpec::class,
        ];

        $stockableType = fake()->randomElement($stockableTypes);

        return [
            'stockable_type' => $stockableType,
            'stockable_id' => $stockableType::factory(),
            'quantity' => fake()->numberBetween(0, 100),
            'reserved' => fake()->numberBetween(0, 20),
            'location' => fake()->randomElement(['Warehouse A', 'Warehouse B', 'Storage Room 1', 'Storage Room 2', null]),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate that the stock is for RAM.
     */
    public function forRam(): static
    {
        return $this->state(fn (array $attributes) => [
            'stockable_type' => RamSpec::class,
            'stockable_id' => RamSpec::factory(),
        ]);
    }

    /**
     * Indicate that the stock is for Disk.
     */
    public function forDisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'stockable_type' => DiskSpec::class,
            'stockable_id' => DiskSpec::factory(),
        ]);
    }

    /**
     * Indicate that the stock is for Processor.
     */
    public function forProcessor(): static
    {
        return $this->state(fn (array $attributes) => [
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => ProcessorSpec::factory(),
        ]);
    }

    /**
     * Indicate that the stock is out of stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 0,
            'reserved' => 0,
        ]);
    }

    /**
     * Indicate that the stock has items reserved.
     */
    public function withReservation(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => fake()->numberBetween(10, 50),
            'reserved' => fake()->numberBetween(1, 10),
        ]);
    }
}
