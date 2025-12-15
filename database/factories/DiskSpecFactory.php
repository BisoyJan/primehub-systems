<?php

namespace Database\Factories;

use App\Models\DiskSpec;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DiskSpec>
 */
class DiskSpecFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */

    protected $model = DiskSpec::class;

    public function definition(): array
    {
        $manufacturers = ['Western Digital', 'Seagate', 'Samsung', 'Toshiba', 'Kingston', 'Crucial'];
        $capacityOptions = [256, 512, 1024, 2048, 4096, 8192]; // in GB

        return [
            'manufacturer' => $this->faker->randomElement($manufacturers),
            'model'        => strtoupper($this->faker->bothify('??###??')),
            'capacity_gb'  => $this->faker->randomElement($capacityOptions),
        ];
    }
}
