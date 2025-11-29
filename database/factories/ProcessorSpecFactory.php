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
        ];

        $manufacturer = $manufacturers[array_rand($manufacturers)];

        if ($manufacturer === 'Intel') {
            $model = array_rand($intelmodel);
            $socket = $intelmodel[$model];
        } else {
            $model = array_rand($amdmodel);
            $socket = $amdmodel[$model];
        }

        $coreCount   = rand(2, 16);
        $threadCount = $coreCount * rand(1, 2);

        $intelGraphics = ['Intel UHD 610', 'Intel UHD 730', 'Intel UHD 770'];
        $amdGraphics = ['Radeon Vega 3', 'Radeon Vega 7', 'Radeon Vega 8'];
        $tdpOptions = [35, 65, 95, 105, 125];

        return [
            'manufacturer'        => $manufacturer,
            'model'               => $model,
            'socket_type'         => $socket,
            'core_count'          => $coreCount,
            'thread_count'        => $threadCount,
            'base_clock_ghz'      => round(mt_rand(200, 380) / 100, 2),
            'boost_clock_ghz'     => round(mt_rand(350, 520) / 100, 2),
            'integrated_graphics' => $manufacturer === 'Intel'
                ? $intelGraphics[array_rand($intelGraphics)]
                : $amdGraphics[array_rand($amdGraphics)],
            'tdp_watts'           => $tdpOptions[array_rand($tdpOptions)],
        ];
    }
}
