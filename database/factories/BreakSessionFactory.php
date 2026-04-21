<?php

namespace Database\Factories;

use App\Models\BreakPolicy;
use App\Models\BreakSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BreakSession>
 */
class BreakSessionFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(['1st_break', '2nd_break', 'lunch']);
        $durationSeconds = $type === 'lunch' ? 3600 : 900;

        return [
            'session_id' => strtoupper(str_replace('_', '', $type)).'-'.Str::uuid(),
            'user_id' => User::factory(),
            'station' => fake()->optional()->word(),
            'break_policy_id' => BreakPolicy::factory(),
            'type' => $type,
            'status' => 'completed',
            'duration_seconds' => $durationSeconds,
            'started_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
            'ended_at' => now(),
            'remaining_seconds' => fake()->numberBetween(0, $durationSeconds),
            'overage_seconds' => 0,
            'overbreak_notified_at' => null,
            'total_paused_seconds' => 0,
            'shift_date' => now()->toDateString(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'ended_at' => null,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paused',
            'ended_at' => null,
            'last_pause_reason' => fake()->randomElement(['Coaching', 'Team Huddle', 'System Issue']),
        ]);
    }

    public function overage(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'overage',
            'remaining_seconds' => 0,
            'overage_seconds' => fake()->numberBetween(1, 600),
        ]);
    }

    public function lunch(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'lunch',
            'duration_seconds' => 3600,
        ]);
    }
}
