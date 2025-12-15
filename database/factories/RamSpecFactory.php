<?php

namespace Database\Factories;

use App\Models\RamSpec;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RamSpec>
 */
class RamSpecFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */

    protected $model = RamSpec::class;

    public function definition(): array
    {
        // Common choices
        $manufacturer = $this->faker->randomElement(['Corsair', 'G.Skill', 'Kingston', 'Crucial', 'Samsung']);
        $model = strtoupper($this->faker->bothify('???-####'));
        $capacity_gb = $this->faker->randomElement([4, 8, 16, 32]);
        $types      = ['DDR3', 'DDR4', 'DDR5'];
        $type       = $this->faker->randomElement($types);
        $speedMap   = [
            'DDR3' => [1333, 1600, 1866],
            'DDR4' => [2133, 2666, 3000, 3200, 3600],
            'DDR5' => [4800, 5200, 5600, 6000],
        ];
        $speed = $this->faker->randomElement($speedMap[$type]);

        return [
            'manufacturer' =>  $manufacturer,
            'model'       =>  $model,
            'capacity_gb' => $capacity_gb,
            'type'       => $type,
            'speed'      =>  $speed,
        ];
    }
}
