<?php

namespace Database\Factories;

use App\Models\ItConcern;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ItConcern>
 */
class ItConcernFactory extends Factory
{
    protected $model = ItConcern::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'site_id' => Site::factory(),
            'station_number' => $this->faker->numberBetween(1, 100),
            'category' => $this->faker->randomElement(['Hardware', 'Software', 'Network/Connectivity', 'Other']),
            'description' => $this->faker->paragraph(),
            'status' => 'pending',
            'priority' => $this->faker->randomElement(['low', 'medium', 'high', 'urgent']),
            'resolution_notes' => null,
            'resolved_at' => null,
            'resolved_by' => null,
        ];
    }

    /**
     * Indicate that the concern is assigned to someone.
     */
    public function assigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
        ]);
    }

    /**
     * Indicate that the concern is resolved.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'resolved',
            'resolved_by' => User::factory(),
            'resolved_at' => now(),
            'resolution_notes' => $this->faker->sentence(),
        ]);
    }
}
