<?php

namespace Database\Seeders;

use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Models\Campaign;
use App\Models\Site;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create sites
        $ph1 = Site::firstOrCreate(['name' => 'PH1']);
        $ph2 = Site::firstOrCreate(['name' => 'PH2']);

        // Get campaigns from CampaignSeeder
        $admin = Campaign::where('name', 'Admin')->first();
        $helix = Campaign::where('name', 'Helix')->first();
        $realEstate = Campaign::where('name', 'Real Estate')->first();
        $sales = Campaign::where('name', 'Sales')->first();
        $pso = Campaign::where('name', 'PSO')->first();

        // Night Shift employees (3)
        $this->createEmployeeSchedule('John', 'Doe', 'john.doe@example.com', $sales, $ph1, '22:00:00', '07:00:00', '2025-07-01');
        $this->createEmployeeSchedule('Jane', 'Smith', 'jane.smith@example.com', $helix, $ph1, '22:00:00', '07:00:00', '2024-10-15');
        $this->createEmployeeSchedule('Mike', 'Johnson', 'mike.johnson@example.com', $realEstate, $ph2, '22:00:00', '07:00:00', '2024-12-01');

        // Midnight Shift employee (1) - 12 AM to 9 AM
        $this->createEmployeeSchedule('Lisa', 'Garcia', 'lisa.garcia@example.com', $pso, $ph2, '00:00:00', '09:00:00', '2025-01-10');

        // Morning Shift employee (1)
        $this->createEmployeeSchedule('Sarah', 'Williams', 'sarah.williams@example.com', $admin, $ph1, '08:00:00', '17:00:00', '2024-09-20');

        // Afternoon Shift employee (1)
        $this->createEmployeeSchedule('David', 'Brown', 'david.brown@example.com', $realEstate, $ph2, '14:00:00', '23:00:00', '2024-11-15');
    }

    /**
     * Helper method to create employee and schedule
     */
    private function createEmployeeSchedule(
        string $firstName,
        string $lastName,
        string $email,
        Campaign $campaign,
        Site $site,
        string $timeIn,
        string $timeOut,
        string $hiredDate
    ): void {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'middle_name' => null,
                'last_name' => $lastName,
                'password' => Hash::make('password'),
                'role' => 'Agent',
                'hired_date' => $hiredDate,
                'email_verified_at' => now(),
            ]
        );

        // Determine shift type based on time
        // Graveyard: 12 AM - 4:59 AM (00:00:00 - 04:59:59)
        // Morning: 5 AM - 11:59 AM (05:00:00 - 11:59:59)
        // Afternoon: 12 PM - 5:59 PM (12:00:00 - 17:59:59)
        // Night: 6 PM - 11:59 PM (18:00:00 - 23:59:59)
        $shiftType = 'night_shift';
        if ($timeIn >= '00:00:00' && $timeIn < '05:00:00') {
            $shiftType = 'graveyard_shift';
        } elseif ($timeIn >= '05:00:00' && $timeIn < '12:00:00') {
            $shiftType = 'morning_shift';
        } elseif ($timeIn >= '12:00:00' && $timeIn < '18:00:00') {
            $shiftType = 'afternoon_shift';
        } elseif ($timeIn >= '18:00:00') {
            $shiftType = 'night_shift';
        }

        EmployeeSchedule::firstOrCreate(
            [
                'user_id' => $user->id,
                'shift_type' => $shiftType,
            ],
            [
                'campaign_id' => $campaign->id,
                'site_id' => $site->id,
                'scheduled_time_in' => $timeIn,
                'scheduled_time_out' => $timeOut,
                'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'grace_period_minutes' => 15,
                'is_active' => true,
                'effective_date' => '2025-01-01',
            ]
        );
    }
}
