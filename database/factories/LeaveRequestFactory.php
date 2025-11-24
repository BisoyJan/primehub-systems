<?php

namespace Database\Factories;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('now', '+30 days');
        $endDate = (clone $startDate)->modify('+' . $this->faker->numberBetween(1, 5) . ' days');
        $days = $startDate->diff($endDate)->days + 1;

        return [
            'user_id' => User::factory(),
            'leave_type' => $this->faker->randomElement(['VL', 'SL', 'BL', 'SPL', 'LOA']),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_requested' => $days,
            'reason' => $this->faker->sentence(),
            'campaign_department' => $this->faker->randomElement(['Sales', 'Support', 'Tech', 'HR']),
            'medical_cert_submitted' => false,
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'credits_deducted' => null,
            'credits_year' => null,
            'attendance_points_at_request' => 0,
            'auto_rejected' => false,
            'auto_rejection_reason' => null,
        ];
    }

    /**
     * Indicate that the leave request is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the leave request is approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Indicate that the leave request is denied.
     */
    public function denied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'denied',
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'review_notes' => 'Denied due to insufficient credits',
        ]);
    }

    /**
     * Indicate that the leave request is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
