<?php

namespace Database\Factories;

use App\Models\MotherboardSpec;
use Illuminate\Database\Eloquent\Factories\Factory;

class MotherboardSpecFactory extends Factory
{
    protected $model = MotherboardSpec::class;

    public function definition(): array
    {
        $brands       = ['ASUS', 'Gigabyte', 'MSI', 'ASRock'];
        $chipsets     = ['B760', 'Z790', 'X670', 'B550', 'Z690'];
        $forms        = ['ATX', 'Micro-ATX', 'Mini-ITX'];
        $memTypes     = ['DDR4', 'DDR5'];
        $pcieOptions  = [
            'PCIe 4.0 x16; PCIe 4.0 x4',
            'PCIe 5.0 x16; PCIe 4.0 x4',
            'PCIe 3.0 x16; PCIe 3.0 x1'
        ];
        $usbOptions   = [
            'USB3.2 Gen2; USB-C',
            'USB3.1 Gen1; USB2.0',
            'USB4; USB-C'
        ];
        $ethSpeeds    = ['1 GbE', '2.5 GbE', '10 GbE'];
        $sockets      = ['LGA1200', 'LGA1700', 'AM4', 'AM5', 'TR4'];

        return [
            'brand'               => $this->faker->randomElement($brands),
            'model'               => strtoupper($this->faker->bothify('????-####')),
            'chipset'             => $this->faker->randomElement($chipsets),
            'form_factor'         => $this->faker->randomElement($forms),
            'socket_type'         => $this->faker->randomElement($sockets),
            'memory_type'         => $this->faker->randomElement($memTypes),
            'ram_slots'           => $this->faker->randomElement([2, 4]),
            'max_ram_capacity_gb' => $this->faker->randomElement([64, 128, 256]),
            'max_ram_speed'       => $this->faker->randomElement(['3200MHz', '3600MHz', '6000MHz']),
            'pcie_slots'          => $this->faker->randomElement($pcieOptions),
            'm2_slots'            => $this->faker->numberBetween(1, 3),
            'sata_ports'          => $this->faker->numberBetween(2, 6),
            'usb_ports'           => $this->faker->randomElement($usbOptions),
            'ethernet_speed'      => $this->faker->randomElement($ethSpeeds),
            'wifi'                => $this->faker->boolean(50),
        ];
    }
}
