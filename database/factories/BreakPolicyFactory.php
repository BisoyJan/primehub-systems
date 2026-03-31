<?php

namespace Database\Factories;

use App\Models\BreakPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BreakPolicy>
 */
class BreakPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Default Policy',
            'max_breaks' => 2,
            'break_duration_minutes' => 15,
            'max_lunch' => 1,
            'lunch_duration_minutes' => 60,
            'grace_period_minutes' => 0,
            'allowed_pause_reasons' => ['Coaching', 'Team Huddle', 'System Issue', 'Supervisor Request', 'Other'],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
