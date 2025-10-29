<?php

namespace Database\Factories;

use App\Models\PcSpec;
use Illuminate\Database\Eloquent\Factories\Factory;

class PcSpecFactory extends Factory
{
    protected $model = PcSpec::class;

    public function definition(): array
{
    $manufacturers = ['ASUS', 'Gigabyte', 'MSI', 'ASRock'];
    $forms         = ['ATX', 'Micro-ATX', 'Mini-ITX'];
    $memTypes      = ['DDR3', 'DDR4', 'DDR5'];
    $speedMap      = [
        'DDR3' => ['1333MHz', '1600MHz', '1866MHz'],
        'DDR4' => ['2133MHz', '2666MHz', '3000MHz', '3200MHz', '3600MHz'],
        'DDR5' => ['4800MHz', '5200MHz', '5600MHz', '6000MHz'],
    ];

    $memoryType = $this->faker->randomElement($memTypes);
    $maxRamSpeed = $this->faker->randomElement($speedMap[$memoryType]);

    return [
        'pc_number'           => 'PC-' . date('Y') . '-' . str_pad($this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
        'manufacturer'        => $this->faker->randomElement($manufacturers),
        'model'               => strtoupper($this->faker->bothify('????-####')),
        'form_factor'         => $this->faker->randomElement($forms),
        'memory_type'         => $memoryType,
        'ram_slots'           => $this->faker->randomElement([2, 4]),
        'max_ram_capacity_gb' => $this->faker->randomElement([64, 128, 256]),
        'max_ram_speed'       => $maxRamSpeed,
        'm2_slots'            => $this->faker->numberBetween(1, 3),
        'sata_ports'          => $this->faker->numberBetween(2, 6),
    ];
}

    /**
     * Indicate that the PC spec should not have a PC number.
     */
    public function withoutPcNumber(): static
    {
        return $this->state(fn (array $attributes) => [
            'pc_number' => null,
        ]);
    }
}
