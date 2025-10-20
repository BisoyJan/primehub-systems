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
        $memTypes      = ['DDR4', 'DDR5'];

        return [
            'pc_number'           => 'PC-' . date('Y') . '-' . str_pad($this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'manufacturer'        => $this->faker->randomElement($manufacturers),
            'model'               => strtoupper($this->faker->bothify('????-####')),
            'form_factor'         => $this->faker->randomElement($forms),
            'memory_type'         => $this->faker->randomElement($memTypes),
            'ram_slots'           => $this->faker->randomElement([2, 4]),
            'max_ram_capacity_gb' => $this->faker->randomElement([64, 128, 256]),
            'max_ram_speed'       => $this->faker->randomElement(['3200MHz', '3600MHz', '6000MHz']),
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
