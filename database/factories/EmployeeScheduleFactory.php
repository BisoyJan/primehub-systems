<?php

namespace Database\Factories;

use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Models\Campaign;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmployeeSchedule>
 */
class EmployeeScheduleFactory extends Factory
{
    protected $model = EmployeeSchedule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'campaign_id' => Campaign::factory(),
            'site_id' => Site::factory(),
            'shift_type' => fake()->randomElement(['morning_shift', 'afternoon_shift', 'night_shift', 'graveyard_shift', 'utility_24h']),
            'scheduled_time_in' => '09:00:00',
            'scheduled_time_out' => '18:00:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'grace_period_minutes' => 15,
            'is_active' => true,
            'effective_date' => now()->subMonth(),
            'end_date' => null,
        ];
    }

    /**
     * Morning shift (06:00-15:00).
     */
    public function morningShift(): static
    {
        return $this->state([
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '06:00:00',
            'scheduled_time_out' => '15:00:00',
        ]);
    }

    /**
     * Afternoon shift (15:00-00:00).
     */
    public function afternoonShift(): static
    {
        return $this->state([
            'shift_type' => 'afternoon_shift',
            'scheduled_time_in' => '15:00:00',
            'scheduled_time_out' => '00:00:00',
        ]);
    }

    /**
     * Night shift (22:00-07:00).
     */
    public function nightShift(): static
    {
        return $this->state([
            'shift_type' => 'night_shift',
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00',
        ]);
    }

    /**
     * Graveyard shift (00:00-09:00).
     */
    public function graveyardShift(): static
    {
        return $this->state([
            'shift_type' => 'graveyard_shift',
            'scheduled_time_in' => '00:00:00',
            'scheduled_time_out' => '09:00:00',
        ]);
    }

    /**
     * Inactive schedule.
     */
    public function inactive(): static
    {
        return $this->state([
            'is_active' => false,
            'end_date' => now()->subDay(),
        ]);
    }

    /**
     * Weekend schedule.
     */
    public function weekendSchedule(): static
    {
        return $this->state([
            'work_days' => ['saturday', 'sunday'],
        ]);
    }

    /**
     * Full week schedule.
     */
    public function fullWeek(): static
    {
        return $this->state([
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        ]);
    }
}
