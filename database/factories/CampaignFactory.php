<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        $campaigns = [
            'Admin',
            'All State',
            'Helix',
            'LG Copier',
            'Medicare',
            'PSO',
            'Real State',
            'Sales',
            'Customer Service',
            'Technical Support',
            'Marketing',
            'Finance',
        ];

        return [
            // Use unique() to ensure no duplicate names during factory generation
            'name' => $this->faker->unique()->randomElement($campaigns),
        ];
    }
}
