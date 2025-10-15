<?php

namespace Database\Seeders;

use App\Models\ProcessorSpec;
use App\Models\DiskSpec;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\RamSpec;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Site;
use App\Models\Campaign;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // Seed unique ProcessorSpecs
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

        // Seed unique RamSpecs
        $rams = [
            ['manufacturer' => 'Corsair', 'model' => 'Vengeance LPX 8GB', 'capacity_gb' => 8, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Corsair', 'model' => 'Vengeance LPX 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Corsair', 'model' => 'Vengeance RGB 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3600, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'G.Skill', 'model' => 'Ripjaws V 8GB', 'capacity_gb' => 8, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'G.Skill', 'model' => 'Ripjaws V 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'G.Skill', 'model' => 'Trident Z 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3600, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Kingston', 'model' => 'Fury Beast 8GB', 'capacity_gb' => 8, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Kingston', 'model' => 'Fury Beast 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Crucial', 'model' => 'Ballistix 8GB', 'capacity_gb' => 8, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Crucial', 'model' => 'Ballistix 16GB', 'capacity_gb' => 16, 'type' => 'DDR4', 'speed' => 3200, 'form_factor' => 'DIMM', 'voltage' => 1.35],
            ['manufacturer' => 'Corsair', 'model' => 'Vengeance DDR5 16GB', 'capacity_gb' => 16, 'type' => 'DDR5', 'speed' => 5200, 'form_factor' => 'DIMM', 'voltage' => 1.25],
            ['manufacturer' => 'Corsair', 'model' => 'Vengeance DDR5 32GB', 'capacity_gb' => 32, 'type' => 'DDR5', 'speed' => 5600, 'form_factor' => 'DIMM', 'voltage' => 1.25],
            ['manufacturer' => 'G.Skill', 'model' => 'Trident Z5 16GB', 'capacity_gb' => 16, 'type' => 'DDR5', 'speed' => 6000, 'form_factor' => 'DIMM', 'voltage' => 1.25],
            ['manufacturer' => 'Kingston', 'model' => 'Fury Beast DDR3 8GB', 'capacity_gb' => 8, 'type' => 'DDR3', 'speed' => 1600, 'form_factor' => 'DIMM', 'voltage' => 1.5],
        ];

        foreach ($rams as $ram) {
            RamSpec::firstOrCreate(
                ['manufacturer' => $ram['manufacturer'], 'model' => $ram['model']],
                $ram
            );
        }

        // Seed unique DiskSpecs
        $disks = [
            ['manufacturer' => 'Samsung', 'model' => '970 EVO Plus 500GB', 'capacity_gb' => 512, 'interface' => 'PCIe 3.0 x4 NVMe', 'drive_type' => 'SSD', 'sequential_read_mb' => 3500, 'sequential_write_mb' => 3200],
            ['manufacturer' => 'Samsung', 'model' => '970 EVO Plus 1TB', 'capacity_gb' => 1024, 'interface' => 'PCIe 3.0 x4 NVMe', 'drive_type' => 'SSD', 'sequential_read_mb' => 3500, 'sequential_write_mb' => 3300],
            ['manufacturer' => 'Samsung', 'model' => '980 PRO 1TB', 'capacity_gb' => 1024, 'interface' => 'PCIe 4.0 x4 NVMe', 'drive_type' => 'SSD', 'sequential_read_mb' => 7000, 'sequential_write_mb' => 5000],
            ['manufacturer' => 'Samsung', 'model' => '980 PRO 2TB', 'capacity_gb' => 2048, 'interface' => 'PCIe 4.0 x4 NVMe', 'drive_type' => 'SSD', 'sequential_read_mb' => 7000, 'sequential_write_mb' => 5100],
            ['manufacturer' => 'Western Digital', 'model' => 'Blue 1TB', 'capacity_gb' => 1024, 'interface' => 'SATA III', 'drive_type' => 'HDD', 'sequential_read_mb' => 150, 'sequential_write_mb' => 150],
            ['manufacturer' => 'Western Digital', 'model' => 'Blue 2TB', 'capacity_gb' => 2048, 'interface' => 'SATA III', 'drive_type' => 'HDD', 'sequential_read_mb' => 180, 'sequential_write_mb' => 180],
            ['manufacturer' => 'Western Digital', 'model' => 'Black SN850 1TB', 'capacity_gb' => 1024, 'interface' => 'PCIe 4.0 x4 NVMe', 'drive_type' => 'SSD', 'sequential_read_mb' => 7000, 'sequential_write_mb' => 5300],
            ['manufacturer' => 'Seagate', 'model' => 'Barracuda 1TB', 'capacity_gb' => 1024, 'interface' => 'SATA III', 'drive_type' => 'HDD', 'sequential_read_mb' => 190, 'sequential_write_mb' => 190],
            ['manufacturer' => 'Seagate', 'model' => 'Barracuda 2TB', 'capacity_gb' => 2048, 'interface' => 'SATA III', 'drive_type' => 'HDD', 'sequential_read_mb' => 220, 'sequential_write_mb' => 220],
            ['manufacturer' => 'Seagate', 'model' => 'FireCuda 530 1TB', 'capacity_gb' => 1024, 'interface' => 'PCIe 4.0 x4 NVMe', 'drive_type' => 'SSD', 'sequential_read_mb' => 7300, 'sequential_write_mb' => 6000],
            ['manufacturer' => 'Crucial', 'model' => 'MX500 500GB', 'capacity_gb' => 512, 'interface' => 'SATA III', 'drive_type' => 'SSD', 'sequential_read_mb' => 560, 'sequential_write_mb' => 510],
            ['manufacturer' => 'Crucial', 'model' => 'MX500 1TB', 'capacity_gb' => 1024, 'interface' => 'SATA III', 'drive_type' => 'SSD', 'sequential_read_mb' => 560, 'sequential_write_mb' => 510],
            ['manufacturer' => 'Crucial', 'model' => 'P5 Plus 1TB', 'capacity_gb' => 1024, 'interface' => 'PCIe 4.0 x4 NVMe', 'drive_type' => 'SSD', 'sequential_read_mb' => 6600, 'sequential_write_mb' => 5000],
            ['manufacturer' => 'Toshiba', 'model' => 'X300 4TB', 'capacity_gb' => 4096, 'interface' => 'SATA III', 'drive_type' => 'HDD', 'sequential_read_mb' => 250, 'sequential_write_mb' => 250],
        ];

        foreach ($disks as $disk) {
            DiskSpec::firstOrCreate(
                ['manufacturer' => $disk['manufacturer'], 'model' => $disk['model']],
                $disk
            );
        }



        // Seed unique sites
        $siteNames = ['PH1-2F', 'PH1-3F', 'PH1-4F', 'PH2', 'PH3'];
        foreach ($siteNames as $name) {
            Site::firstOrCreate(['name' => $name]);
        }

        // Seed unique campaigns
        $campaignNames = ['Admin', 'All State', 'Helix', 'LG Copier', 'Medicare', 'PSO', 'Real State', 'Sales'];
        foreach ($campaignNames as $name) {
            Campaign::firstOrCreate(['name' => $name]);
        }

        $this->call(
            [
                StockSeeder::class,
                PcSpecSeeder::class,
                //StationSeeder::class,
            ]
        );
    }
}
