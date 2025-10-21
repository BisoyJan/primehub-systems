<?php

namespace Database\Factories;

use App\Models\PcTransfer;
use App\Models\Station;
use App\Models\PcSpec;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PcTransfer>
 */
class PcTransferFactory extends Factory
{
    protected $model = PcTransfer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_station_id' => Station::factory(),
            'to_station_id' => Station::factory(),
            'pc_spec_id' => PcSpec::factory(),
            'user_id' => User::factory(),
            'transfer_type' => fake()->randomElement(['transfer', 'swap', 'deployment', 'return']),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate that this is a transfer operation.
     */
    public function transfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'transfer_type' => 'transfer',
        ]);
    }

    /**
     * Indicate that this is a swap operation.
     */
    public function swap(): static
    {
        return $this->state(fn (array $attributes) => [
            'transfer_type' => 'swap',
        ]);
    }

    /**
     * Indicate that this is a deployment.
     */
    public function deployment(): static
    {
        return $this->state(fn (array $attributes) => [
            'transfer_type' => 'deployment',
            'from_station_id' => null,
        ]);
    }

    /**
     * Indicate that this is a return operation.
     */
    public function return(): static
    {
        return $this->state(fn (array $attributes) => [
            'transfer_type' => 'return',
            'to_station_id' => null,
        ]);
    }
}
