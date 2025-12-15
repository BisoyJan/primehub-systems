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
    $memTypes      = ['DDR3', 'DDR4', 'DDR5'];

    $memoryType = $this->faker->randomElement($memTypes);

    return [
        'pc_number'           => 'PC-' . date('Y') . '-' . str_pad($this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
        'manufacturer'        => $this->faker->randomElement($manufacturers),
        'model'               => strtoupper($this->faker->bothify('????-####')),
        'memory_type'         => $memoryType,
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
