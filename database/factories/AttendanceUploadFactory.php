<?php

namespace Database\Factories;

use App\Models\AttendanceUpload;
use App\Models\User;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AttendanceUpload>
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
        return [
            'uploaded_by' => User::factory(),
            'original_filename' => 'attendance_' . fake()->date() . '.txt',
            'stored_filename' => time() . '_' . fake()->uuid() . '.txt',
            'shift_date' => fake()->dateTimeBetween('-7 days', 'now'),
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
