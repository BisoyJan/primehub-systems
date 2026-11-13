<?php

namespace Database\Factories;

use App\Models\BreakRetentionPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BreakRetentionPolicy>
 */
class BreakRetentionPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' Policy',
            'description' => fake()->sentence(),
            'retention_months' => fake()->numberBetween(6, 36),
            'applies_to_type' => 'global',
            'applies_to_id' => null,
            'break_type' => 'all',
            'priority' => 100,
            'is_active' => true,
        ];
    }

    public function forSite(int $siteId): static
    {
        return $this->state(fn (array $attributes) => [
            'applies_to_type' => 'site',
            'applies_to_id' => $siteId,
        ]);
    }

    public function forBreakType(string $breakType): static
    {
        return $this->state(fn (array $attributes) => [
            'break_type' => $breakType,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
