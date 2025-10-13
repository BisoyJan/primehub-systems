<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $sites = ['PH1-2F', 'PH1-3F', 'PH1-4F', 'PH2', 'PH3'];
        return [
            'name' => $this->faker->randomElement($sites),
        ];
    }
}
