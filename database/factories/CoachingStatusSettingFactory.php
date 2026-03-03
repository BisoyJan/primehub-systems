<?php

namespace Database\Factories;

use App\Models\CoachingStatusSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CoachingStatusSetting>
 */
class CoachingStatusSettingFactory extends Factory
{
    protected $model = CoachingStatusSetting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(3),
            'value' => fake()->numberBetween(5, 60),
            'label' => fake()->sentence(4),
        ];
    }
}
