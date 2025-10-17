<?php

namespace Database\Seeders;

use App\Models\DiskSpec;
use Illuminate\Database\Seeder;

class DiskSpecSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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
    }
}
