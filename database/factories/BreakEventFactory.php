<?php

namespace Database\Factories;

use App\Models\BreakEvent;
use App\Models\BreakSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BreakEvent>
 */
class BreakEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'break_session_id' => BreakSession::factory(),
            'action' => fake()->randomElement(['start', 'pause', 'resume', 'end']),
            'remaining_seconds' => fake()->numberBetween(0, 900),
            'overage_seconds' => 0,
            'reason' => null,
            'occurred_at' => now(),
        ];
    }
}
