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
        $manufacturers = ['Western Digital', 'Seagate', 'Samsung', 'Toshiba'];
        $interfaces    = ['SATA III', 'PCIe 3.0 x4 NVMe', 'PCIe 4.0 x4 NVMe'];
        $driveTypes    = ['HDD', 'SSD'];
        $capacityOptions = [256, 512, 1024, 2048, 4096, 8192]; // in GB

        $driveType = $this->faker->randomElement($driveTypes);
        $interface = $this->faker->randomElement($interfaces);

        // Set realistic sequential speeds based on type/interface
        if ($driveType === 'HDD') {
            $readSpeed  = $this->faker->numberBetween(100, 250);
            $writeSpeed = $this->faker->numberBetween(100, 250);
        } else {
            if (strpos($interface, 'PCIe 4.0') !== false) {
                $readSpeed  = $this->faker->numberBetween(5000, 7000);
                $writeSpeed = $this->faker->numberBetween(3000, 5000);
            } elseif (strpos($interface, 'PCIe 3.0') !== false) {
                $readSpeed  = $this->faker->numberBetween(3000, 3500);
                $writeSpeed = $this->faker->numberBetween(1500, 3000);
            } else {
                // SATA SSD
                $readSpeed  = $this->faker->numberBetween(500, 600);
                $writeSpeed = $this->faker->numberBetween(450, 550);
            }
        }

        return [
            'manufacturer'        => $this->faker->randomElement($manufacturers),
            'model_number'        => strtoupper($this->faker->bothify('??###??')),
            'capacity_gb'         => $this->faker->randomElement($capacityOptions),
            'interface'           => $interface,
            'drive_type'          => $driveType,
            'sequential_read_mb'  => $readSpeed,
            'sequential_write_mb' => $writeSpeed,
        ];
    }
}
