<?php

namespace Database\Factories;

use App\Models\MonitorSpec;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonitorSpecFactory extends Factory
{
    protected $model = MonitorSpec::class;

    public function definition(): array
    {
        $brands = ['Dell', 'LG', 'Samsung', 'ASUS', 'BenQ', 'Acer', 'ViewSonic', 'HP', 'MSI'];
        $panelTypes = ['IPS', 'VA', 'TN', 'OLED'];
        $resolutions = ['1920x1080', '2560x1440', '3840x2160', '1920x1200', '2560x1080'];
        $screenSizes = [21.5, 24.0, 27.0, 32.0, 34.0];
        
        return [
            'brand' => fake()->randomElement($brands),
            'model' => fake()->bothify('??###-##'),
            'screen_size' => fake()->randomElement($screenSizes),
            'resolution' => fake()->randomElement($resolutions),
            'panel_type' => fake()->randomElement($panelTypes),
            'ports' => fake()->randomElement([
                ['HDMI', 'DisplayPort'],
                ['HDMI', 'VGA'],
                ['HDMI', 'DisplayPort', 'USB-C'],
                ['DisplayPort', 'USB-C'],
            ]),
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }
}
