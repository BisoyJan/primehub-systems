<?php

namespace Database\Factories;

use App\Models\LeaveCredit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LeaveCredit>
 */
class LeaveCreditFactory extends Factory
{
    protected $model = LeaveCredit::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $earned = $this->faker->randomFloat(2, 0, 5);
        $used = $this->faker->randomFloat(2, 0, $earned);
        $balance = $earned - $used;

        return [
            'user_id' => User::factory(),
            'credits_earned' => $earned,
            'credits_used' => $used,
            'credits_balance' => $balance,
            'year' => $this->faker->year(),
            'month' => $this->faker->numberBetween(1, 12),
            'accrued_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
        ];
    }

    /**
     * Indicate that the credit has no used credits.
     */
    public function unused(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'credits_used' => 0,
                'credits_balance' => $attributes['credits_earned'],
            ];
        });
    }

    /**
     * Indicate that the credit is fully used.
     */
    public function fullyUsed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'credits_used' => $attributes['credits_earned'],
                'credits_balance' => 0,
            ];
        });
    }
}
