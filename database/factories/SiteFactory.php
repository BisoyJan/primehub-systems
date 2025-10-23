<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $sites = ['PH1', 'PH2', 'PH3', 'PH1-2F', 'PH1-3F', 'PH1-4F', 'PH4', 'PH5', 'PH6', 'PH7'];

        return [
            // Use unique() to ensure no duplicate names during factory generation
            'name' => $this->faker->unique()->randomElement($sites),
        ];
    }
}
