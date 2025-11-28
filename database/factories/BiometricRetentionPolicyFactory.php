<?php

namespace Database\Factories;

use App\Models\BiometricRetentionPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BiometricRetentionPolicy>
 */
class BiometricRetentionPolicyFactory extends Factory
{
    protected $model = BiometricRetentionPolicy::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true) . ' Policy',
            'description' => fake()->sentence(),
            'retention_months' => fake()->numberBetween(1, 24),
            'applies_to_type' => 'global',
            'applies_to_id' => null,
            'priority' => fake()->numberBetween(1, 100),
            'is_active' => true,
        ];
    }

    /**
     * Policy for a specific site
     */
    public function forSite(int $siteId): static
    {
        return $this->state(fn (array $attributes) => [
            'applies_to_type' => 'site',
            'applies_to_id' => $siteId,
        ]);
    }

    /**
     * Global policy
     */
    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'applies_to_type' => 'global',
            'applies_to_id' => null,
        ]);
    }

    /**
     * Inactive policy
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
