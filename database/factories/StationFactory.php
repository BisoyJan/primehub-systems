<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Station>
 */
class StationFactory extends Factory
{
    protected $model = Station::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'station_number' => 'ST-' . fake()->unique()->numberBetween(1, 9999),
            'site_id' => Site::factory(),
            'campaign_id' => Campaign::factory(),
            'status' => fake()->randomElement(['active', 'inactive', 'maintenance']),
            'monitor_type' => fake()->randomElement(['dual', 'single']), // lowercase to match seeder
            'pc_spec_id' => PcSpec::factory(),
        ];
    }

    /**
     * Indicate that the station is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Indicate that the station is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the station is under maintenance.
     */
    public function maintenance(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'maintenance',
        ]);
    }
}
