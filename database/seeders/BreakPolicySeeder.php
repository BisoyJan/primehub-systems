<?php

namespace Database\Seeders;

use App\Models\BreakPolicy;
use Illuminate\Database\Seeder;

class BreakPolicySeeder extends Seeder
{
    public function run(): void
    {
        BreakPolicy::firstOrCreate(
            ['name' => 'Default Policy'],
            [
                'max_breaks' => 2,
                'break_duration_minutes' => 15,
                'max_lunch' => 1,
                'lunch_duration_minutes' => 60,
                'grace_period_minutes' => 0,
                'allowed_pause_reasons' => ['Coaching', 'Bathroom', 'Manager Request', 'Other'],
                'is_active' => true,
            ],
        );
    }
}
