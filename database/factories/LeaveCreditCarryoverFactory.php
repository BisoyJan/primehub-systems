<?php

namespace Database\Factories;

use App\Models\LeaveCreditCarryover;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LeaveCreditCarryover>
 */
class LeaveCreditCarryoverFactory extends Factory
{
    protected $model = LeaveCreditCarryover::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fromYear = $this->faker->numberBetween(2024, 2025);
        $creditsFromPrevYear = $this->faker->randomFloat(2, 0, 10);
        $carryoverCredits = min($creditsFromPrevYear, LeaveCreditCarryover::MAX_CARRYOVER_CREDITS);
        $forfeited = max(0, $creditsFromPrevYear - $carryoverCredits);

        return [
            'user_id' => User::factory(),
            'credits_from_previous_year' => $creditsFromPrevYear,
            'carryover_credits' => $carryoverCredits,
            'forfeited_credits' => $forfeited,
            'from_year' => $fromYear,
            'to_year' => $fromYear + 1,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ];
    }

    /**
     * Indicate a first regularization carryover.
     */
    public function firstRegularization(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_first_regularization' => true,
        ]);
    }

    /**
     * Indicate the carryover was cash converted.
     */
    public function cashConverted(): static
    {
        return $this->state(fn (array $attributes) => [
            'cash_converted' => true,
            'cash_converted_at' => now(),
        ]);
    }
}
