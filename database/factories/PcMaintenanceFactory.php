<?php

namespace Database\Factories;

use App\Models\PcMaintenance;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PcMaintenance>
 */
class PcMaintenanceFactory extends Factory
{
    protected $model = PcMaintenance::class;

    public function definition(): array
    {
        $station = Station::inRandomOrder()->first();
        $lastDate = $this->faker->dateTimeBetween('-6 months', 'now');
        $nextDate = (clone $lastDate)->modify('+3 months');
        return [
            'station_id' => $station ? $station->id : Station::factory(),
            'last_maintenance_date' => $lastDate->format('Y-m-d'),
            'next_due_date' => $nextDate->format('Y-m-d'),
            'maintenance_type' => $this->faker->randomElement(['cleaning', 'hardware check', 'software update']),
            'notes' => $this->faker->optional()->sentence(),
            'performed_by' => $this->faker->name(),
            'status' => $this->faker->randomElement(['completed', 'pending', 'overdue']),
        ];
    }
}
