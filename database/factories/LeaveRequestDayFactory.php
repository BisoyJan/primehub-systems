<?php

namespace Database\Factories;

use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LeaveRequestDay>
 */
class LeaveRequestDayFactory extends Factory
{
    protected $model = LeaveRequestDay::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leave_request_id' => LeaveRequest::factory(),
            'date' => $this->faker->date(),
            'day_status' => LeaveRequestDay::STATUS_PENDING,
            'notes' => null,
            'assigned_by' => null,
            'assigned_at' => null,
        ];
    }

    /**
     * Day is SL Credited (paid).
     */
    public function slCredited(?User $assigner = null): static
    {
        return $this->state(fn (array $attributes) => [
            'day_status' => LeaveRequestDay::STATUS_SL_CREDITED,
            'assigned_by' => $assigner?->id ?? User::factory(),
            'assigned_at' => now(),
        ]);
    }

    /**
     * Day is NCNS (unpaid, gets attendance point).
     */
    public function ncns(?User $assigner = null): static
    {
        return $this->state(fn (array $attributes) => [
            'day_status' => LeaveRequestDay::STATUS_NCNS,
            'notes' => 'Failed to notify team lead/manager',
            'assigned_by' => $assigner?->id ?? User::factory(),
            'assigned_at' => now(),
        ]);
    }

    /**
     * Day is Advised Absence / UPTO (unpaid, SL context).
     */
    public function advisedAbsence(?User $assigner = null): static
    {
        return $this->state(fn (array $attributes) => [
            'day_status' => LeaveRequestDay::STATUS_ADVISED_ABSENCE,
            'notes' => 'Agent informed but no credits remaining (UPTO)',
            'assigned_by' => $assigner?->id ?? User::factory(),
            'assigned_at' => now(),
        ]);
    }

    /**
     * Day is UPTO — Unpaid Time Off (VL context, no violation).
     */
    public function upto(?User $assigner = null): static
    {
        return $this->state(fn (array $attributes) => [
            'day_status' => LeaveRequestDay::STATUS_UPTO,
            'notes' => 'UPTO — Unpaid Time Off (no violation)',
            'assigned_by' => $assigner?->id ?? User::factory(),
            'assigned_at' => now(),
        ]);
    }
}
