<?php

namespace Database\Seeders;

use App\Models\ProcessorSpec;
use Illuminate\Database\Seeder;

class ProcessorSpecSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $processors = [
            ['manufacturer' => 'Intel', 'model' => 'Core i3-10100', 'socket_type' => 'LGA1200', 'core_count' => 4, 'thread_count' => 8, 'base_clock_ghz' => 3.6, 'boost_clock_ghz' => 4.3, 'integrated_graphics' => 'Intel UHD 630', 'tdp_watts' => 65],
            ['manufacturer' => 'Intel', 'model' => 'Core i3-12100', 'socket_type' => 'LGA1700', 'core_count' => 4, 'thread_count' => 8, 'base_clock_ghz' => 3.3, 'boost_clock_ghz' => 4.3, 'integrated_graphics' => 'Intel UHD 730', 'tdp_watts' => 65],
            ['manufacturer' => 'Intel', 'model' => 'Core i5-10400', 'socket_type' => 'LGA1200', 'core_count' => 6, 'thread_count' => 12, 'base_clock_ghz' => 2.9, 'boost_clock_ghz' => 4.3, 'integrated_graphics' => 'Intel UHD 630', 'tdp_watts' => 65],
            ['manufacturer' => 'Intel', 'model' => 'Core i5-12400', 'socket_type' => 'LGA1700', 'core_count' => 6, 'thread_count' => 12, 'base_clock_ghz' => 2.5, 'boost_clock_ghz' => 4.4, 'integrated_graphics' => 'Intel UHD 730', 'tdp_watts' => 65],
            ['manufacturer' => 'Intel', 'model' => 'Core i7-10700K', 'socket_type' => 'LGA1200', 'core_count' => 8, 'thread_count' => 16, 'base_clock_ghz' => 3.8, 'boost_clock_ghz' => 5.1, 'integrated_graphics' => 'Intel UHD 630', 'tdp_watts' => 125],
            ['manufacturer' => 'Intel', 'model' => 'Core i7-12700K', 'socket_type' => 'LGA1700', 'core_count' => 12, 'thread_count' => 20, 'base_clock_ghz' => 3.6, 'boost_clock_ghz' => 5.0, 'integrated_graphics' => 'Intel UHD 770', 'tdp_watts' => 125],
            ['manufacturer' => 'Intel', 'model' => 'Core i9-10900K', 'socket_type' => 'LGA1200', 'core_count' => 10, 'thread_count' => 20, 'base_clock_ghz' => 3.7, 'boost_clock_ghz' => 5.3, 'integrated_graphics' => 'Intel UHD 630', 'tdp_watts' => 125],
            ['manufacturer' => 'Intel', 'model' => 'Core i9-12900K', 'socket_type' => 'LGA1700', 'core_count' => 16, 'thread_count' => 24, 'base_clock_ghz' => 3.2, 'boost_clock_ghz' => 5.2, 'integrated_graphics' => 'Intel UHD 770', 'tdp_watts' => 125],
            ['manufacturer' => 'AMD', 'model' => 'Ryzen 3 3100', 'socket_type' => 'AM4', 'core_count' => 4, 'thread_count' => 8, 'base_clock_ghz' => 3.6, 'boost_clock_ghz' => 3.9, 'integrated_graphics' => null, 'tdp_watts' => 65],
            ['manufacturer' => 'AMD', 'model' => 'Ryzen 5 3600', 'socket_type' => 'AM4', 'core_count' => 6, 'thread_count' => 12, 'base_clock_ghz' => 3.6, 'boost_clock_ghz' => 4.2, 'integrated_graphics' => null, 'tdp_watts' => 65],
            ['manufacturer' => 'AMD', 'model' => 'Ryzen 5 5600G', 'socket_type' => 'AM4', 'core_count' => 6, 'thread_count' => 12, 'base_clock_ghz' => 3.9, 'boost_clock_ghz' => 4.4, 'integrated_graphics' => 'Radeon Vega 7', 'tdp_watts' => 65],
            ['manufacturer' => 'AMD', 'model' => 'Ryzen 7 5800X', 'socket_type' => 'AM4', 'core_count' => 8, 'thread_count' => 16, 'base_clock_ghz' => 3.8, 'boost_clock_ghz' => 4.7, 'integrated_graphics' => null, 'tdp_watts' => 105],
            ['manufacturer' => 'AMD', 'model' => 'Ryzen 9 5900X', 'socket_type' => 'AM4', 'core_count' => 12, 'thread_count' => 24, 'base_clock_ghz' => 3.7, 'boost_clock_ghz' => 4.8, 'integrated_graphics' => null, 'tdp_watts' => 105],
            ['manufacturer' => 'AMD', 'model' => 'Ryzen 9 5950X', 'socket_type' => 'AM4', 'core_count' => 16, 'thread_count' => 32, 'base_clock_ghz' => 3.4, 'boost_clock_ghz' => 4.9, 'integrated_graphics' => null, 'tdp_watts' => 105],
        ];

        foreach ($processors as $processor) {
            ProcessorSpec::firstOrCreate(
                ['manufacturer' => $processor['manufacturer'], 'model' => $processor['model']],
                $processor
            );
        }
    }
}
