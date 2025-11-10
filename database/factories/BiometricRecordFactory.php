<?php

namespace Database\Factories;

use App\Models\BiometricRecord;
use App\Models\User;
use App\Models\Site;
use App\Models\AttendanceUpload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BiometricRecord>
 */
class BiometricRecordFactory extends Factory
{
    protected $model = BiometricRecord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $datetime = fake()->dateTimeBetween('-30 days', 'now');
        $carbonDate = Carbon::instance($datetime);

        return [
            'user_id' => User::factory(),
            'attendance_upload_id' => AttendanceUpload::factory(),
            'site_id' => Site::factory(),
            'employee_name' => fake()->lastName() . ' ' . strtoupper(fake()->randomLetter()),
            'datetime' => $carbonDate,
            'record_date' => $carbonDate->format('Y-m-d'),
            'record_time' => $carbonDate->format('H:i:s'),
        ];
    }

    /**
     * Set a specific datetime.
     */
    public function atTime(Carbon $datetime): static
    {
        return $this->state([
            'datetime' => $datetime,
            'record_date' => $datetime->format('Y-m-d'),
            'record_time' => $datetime->format('H:i:s'),
        ]);
    }
}
