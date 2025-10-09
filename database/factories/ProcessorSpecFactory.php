<?php

namespace Database\Factories;

use App\Models\ProcessorSpec;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProcessorSpec>
 */
class ProcessorSpecFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = ProcessorSpec::class;

    public function definition(): array
    {
        $manufacturers = ['Intel', 'AMD'];

        $intelmodel = [
            'Core i3-10100'   => 'LGA1200',
            'Core i3-12100'   => 'LGA1700',
            'Core i5-10400'   => 'LGA1200',
            'Core i5-12400'   => 'LGA1700',
            'Core i7-10700K'  => 'LGA1200',
            'Core i7-12700K'  => 'LGA1700',
            'Core i9-10900K'  => 'LGA1200',
            'Core i9-12900K'  => 'LGA1700',
            'Pentium Gold G6400' => 'LGA1200',
            'Pentium Gold G7400' => 'LGA1700',
            'Celeron G5905'   => 'LGA1200',
        ];

        $amdmodel = [
            'Ryzen 3 3100'    => 'AM4',
            'Ryzen 3 5300G'   => 'AM4',
            'Ryzen 5 3600'    => 'AM4',
            'Ryzen 5 5600'    => 'AM4',
            'Ryzen 5 5600G'   => 'AM4',
            'Ryzen 7 3700X'   => 'AM4',
            'Ryzen 7 5800X'   => 'AM4',
            'Ryzen 9 3900X'   => 'AM4',
            'Ryzen 9 5900X'   => 'AM4',
            'Ryzen 9 5950X'   => 'AM4',
            'Athlon 3000G'    => 'AM4',
            'Athlon 320GE'    => 'AM4',
            // If you want AM5/TR4/sTRX4 CPUs, add them here
        ];

        $manufacturer = $this->faker->randomElement($manufacturers);

        if ($manufacturer === 'Intel') {
            $model = $this->faker->randomElement(array_keys($intelmodel));
            $socket = $intelmodel[$model];
        } else {
            $model = $this->faker->randomElement(array_keys($amdmodel));
            $socket = $amdmodel[$model];
        }

        $coreCount   = $this->faker->numberBetween(2, 16);
        $threadCount = $coreCount * $this->faker->numberBetween(1, 2);

        return [
            'manufacturer'               => $manufacturer,
            'model'              => $model,
            'socket_type'         => $socket, // ✅ always correct now
            'core_count'          => $coreCount,
            'thread_count'        => $threadCount,
            'base_clock_ghz'      => round($this->faker->randomFloat(2, 2.0, 3.8), 2),
            'boost_clock_ghz'     => round($this->faker->randomFloat(2, 3.5, 5.2), 2),
            'integrated_graphics' => $manufacturer === 'Intel'
                ? $this->faker->randomElement(['Intel UHD 610', 'Intel UHD 730', 'Intel UHD 770'])
                : $this->faker->randomElement(['Radeon Vega 3', 'Radeon Vega 7', 'Radeon Vega 8']),
            'tdp_watts'           => $this->faker->randomElement([35, 65, 95, 105, 125]),
        ];
    }
}
