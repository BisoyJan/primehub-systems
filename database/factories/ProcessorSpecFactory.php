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
        $brands = ['Intel', 'AMD'];

        $intelSeries = [
            'Core i3-10100',
            'Core i3-12100',
            'Core i5-10400',
            'Core i5-12400',
            'Core i7-10700K',
            'Core i7-12700K',
            'Core i9-10900K',
            'Core i9-12900K',
            'Pentium Gold G6400',
            'Pentium Gold G7400',
            'Celeron G5905'
        ];

        $amdSeries = [
            'Ryzen 3 3100',
            'Ryzen 3 5300G',
            'Ryzen 5 3600',
            'Ryzen 5 5600',
            'Ryzen 5 5600G',
            'Ryzen 7 3700X',
            'Ryzen 7 5800X',
            'Ryzen 9 3900X',
            'Ryzen 9 5900X',
            'Ryzen 9 5950X',
            'Athlon 3000G',
            'Athlon 320GE'
        ];

        $sockets = [
            'LGA1151',
            'LGA1200',
            'LGA1700',
            'AM3+',
            'AM4',
            'AM5',
            'TR4',
            'sTRX4'
        ];

        $coreCount = $this->faker->numberBetween(2, 16);
        $threadCount = $coreCount * $this->faker->numberBetween(1, 2);
        $brand = $this->faker->randomElement($brands);
        $series = $brand === 'Intel'
            ? $this->faker->randomElement($intelSeries)
            : $this->faker->randomElement($amdSeries);

        return [
            'brand'               => $brand,
            'series'              => $series,
            'socket_type'         => $this->faker->randomElement($sockets),
            'core_count'          => $coreCount,
            'thread_count'        => $threadCount,
            'base_clock_ghz'  => round($this->faker->randomFloat(2, 2.0, 3.8), 2),
            'boost_clock_ghz' => round($this->faker->randomFloat(2, 3.5, 5.2), 2),
            'integrated_graphics' => $brand === 'Intel'
                ? $this->faker->randomElement(['Intel UHD 610', 'Intel UHD 730', 'Intel UHD 770'])
                : $this->faker->randomElement(['Radeon Vega 3', 'Radeon Vega 7', 'Radeon Vega 8']),
            'tdp_watts'           => $this->faker->randomElement([35, 65, 95, 105, 125]),
        ];
    }
}
