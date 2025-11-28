<?php

namespace Database\Factories;

use App\Models\MedicationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MedicationRequestFactory extends Factory
{
    protected $model = MedicationRequest::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->name(),
            'medication_type' => $this->faker->randomElement([
                'Declogen',
                'Biogesic',
                'Mefenamic Acid',
                'Kremil-S',
                'Cetirizine',
                'Saridon',
                'Diatabs'
            ]),
            'reason' => $this->faker->sentence(),
            'onset_of_symptoms' => $this->faker->randomElement([
                'Just today',
                'More than 1 day',
                'More than 1 week'
            ]),
            'agrees_to_policy' => true,
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'admin_notes' => null,
        ];
    }
}
