<?php

namespace Database\Factories;

use App\Models\AttendanceUpload;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceUpload>
 */
class AttendanceUploadFactory extends Factory
{
    protected $model = AttendanceUpload::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-7 days', 'now');

        return [
            'uploaded_by' => User::factory(),
            'original_filename' => 'attendance_'.$date->format('Y-m-d').'.txt',
            'stored_filename' => fake()->uuid().'_'.fake()->uuid().'.txt',
            'date_from' => $date,
            'date_to' => $date,
            'biometric_site_id' => Site::factory(),
            'notes' => fake()->optional()->sentence(),
            'status' => 'pending',
            'total_records' => 0,
            'processed_records' => 0,
            'matched_employees' => 0,
            'unmatched_names' => 0,
            'unmatched_names_list' => [],
            'date_warnings' => [],
            'dates_found' => [],
            'error_message' => null,
        ];
    }

    /**
     * Indicate that the upload is processing.
     */
    public function processing(): static
    {
        return $this->state([
            'status' => 'processing',
        ]);
    }

    /**
     * Indicate that the upload is completed.
     */
    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'total_records' => fake()->numberBetween(50, 200),
            'processed_records' => fake()->numberBetween(40, 180),
            'matched_employees' => fake()->numberBetween(20, 50),
        ]);
    }

    /**
     * Indicate that the upload failed.
     */
    public function failed(): static
    {
        return $this->state([
            'status' => 'failed',
            'error_message' => fake()->sentence(),
        ]);
    }
}
