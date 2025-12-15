<?php

namespace Database\Factories;

use App\Models\ProcessorSpec;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProcessorSpec>
 */
class ProcessorSpecFactory extends Factory
{
    protected $model = ProcessorSpec::class;

    public function definition(): array
    {
        $manufacturers = ['Intel', 'AMD'];

        $intelModels = [
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
            'Celeron G5905',
        ];

        $amdModels = [
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
            'Athlon 320GE',
        ];

        $manufacturer = $manufacturers[array_rand($manufacturers)];
        $model = $manufacturer === 'Intel'
            ? $intelModels[array_rand($intelModels)]
            : $amdModels[array_rand($amdModels)];

        $coreCount   = rand(2, 16);
        $threadCount = $coreCount * rand(1, 2);

        return [
            'manufacturer'        => $manufacturer,
            'model'               => $model,
            'core_count'          => $coreCount,
            'thread_count'        => $threadCount,
            'base_clock_ghz'      => round(mt_rand(200, 380) / 100, 2),
            'boost_clock_ghz'     => round(mt_rand(350, 520) / 100, 2),
        ];
    }
}
