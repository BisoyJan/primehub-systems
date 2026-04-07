<?php

namespace Database\Factories;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
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
        return [
            'stockable_type' => 'App\\Models\\GenericSpec',
            'stockable_id' => fake()->randomNumber(5),
            'quantity' => fake()->numberBetween(0, 100),
            'reserved' => fake()->numberBetween(0, 20),
            'location' => fake()->randomElement(['Warehouse A', 'Warehouse B', 'Storage Room 1', 'Storage Room 2', null]),
            'notes' => fake()->optional()->sentence(),
        ];
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
