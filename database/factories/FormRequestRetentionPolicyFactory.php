<?php

namespace Database\Factories;

use App\Models\FormRequestRetentionPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FormRequestRetentionPolicy>
 */
class FormRequestRetentionPolicyFactory extends Factory
{
    protected $model = FormRequestRetentionPolicy::class;

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
            'retention_months' => fake()->numberBetween(6, 36),
            'applies_to_type' => 'global',
            'applies_to_id' => null,
            'form_type' => fake()->randomElement(['all', 'leave_request', 'medication_request', 'it_concern']),
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

    /**
     * For leave requests
     */
    public function forLeaveRequests(): static
    {
        return $this->state(fn (array $attributes) => [
            'form_type' => 'leave_request',
        ]);
    }

    /**
     * For medication requests
     */
    public function forMedicationRequests(): static
    {
        return $this->state(fn (array $attributes) => [
            'form_type' => 'medication_request',
        ]);
    }
}
