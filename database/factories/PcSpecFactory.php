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
        $memTypes = ['DDR3', 'DDR4', 'DDR5'];

        $memoryType = $this->faker->randomElement($memTypes);

        return [
            'pc_number' => 'PC-'.date('Y').'-'.str_pad($this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'manufacturer' => $this->faker->randomElement($manufacturers),
            'model' => strtoupper($this->faker->bothify('????-####')),
            'memory_type' => $memoryType,
            'ram_gb' => $this->faker->randomElement([4, 8, 16, 32, 64]),
            'disk_gb' => $this->faker->randomElement([256, 512, 1024, 2048]),
            'available_ports' => $this->faker->optional(0.8)->randomElement(['HDMI, DisplayPort, USB-C', 'HDMI, VGA', 'DisplayPort, USB-C', 'HDMI, DisplayPort', 'HDMI, DisplayPort, VGA, USB-C']),
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
