<?php

namespace Database\Factories;

use App\Models\SplCredit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SplCredit>
 */
class SplCreditFactory extends Factory
{
    protected $model = SplCredit::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'year' => now()->year,
            'total_credits' => SplCredit::YEARLY_CREDITS,
            'credits_used' => 0,
            'credits_balance' => SplCredit::YEARLY_CREDITS,
        ];
    }

    /**
     * State with some credits already used.
     */
    public function withUsedCredits(float $used): static
    {
        return $this->state(fn (array $attributes) => [
            'credits_used' => $used,
            'credits_balance' => $attributes['total_credits'] - $used,
        ]);
    }
}
