<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\User;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shiftDate = fake()->dateTimeBetween('-30 days', 'now');
        $scheduledTimeIn = fake()->time('H:i:s');
        $scheduledTimeOut = fake()->time('H:i:s');

        return [
            'user_id' => User::factory(),
            'employee_schedule_id' => EmployeeSchedule::factory(),
            'shift_date' => $shiftDate,
            'scheduled_time_in' => $scheduledTimeIn,
            'scheduled_time_out' => $scheduledTimeOut,
            'actual_time_in' => null,
            'actual_time_out' => null,
            'bio_in_site_id' => null,
            'bio_out_site_id' => null,
            'status' => 'ncns',
            'tardy_minutes' => null,
            'undertime_minutes' => null,
            'is_advised' => false,
            'admin_verified' => false,
            'is_cross_site_bio' => false,
            'verification_notes' => null,
            'notes' => null,
        ];
    }

    /**
     * Indicate that the attendance is on time.
     */
    public function onTime(): static
    {
        return $this->state(function (array $attributes) {
            $shiftDate = Carbon::parse($attributes['shift_date']);
            $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attributes['scheduled_time_in']);
            $actualTimeIn = $scheduledTimeIn->copy()->subMinutes(fake()->numberBetween(1, 10));

            return [
                'actual_time_in' => $actualTimeIn,
                'status' => 'on_time',
                'tardy_minutes' => null,
                'bio_in_site_id' => Site::factory(),
            ];
        });
    }

    /**
     * Indicate that the attendance is tardy.
     */
    public function tardy(): static
    {
        return $this->state(function (array $attributes) {
            $shiftDate = Carbon::parse($attributes['shift_date']);
            $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attributes['scheduled_time_in']);
            $tardyMinutes = fake()->numberBetween(1, 15);
            $actualTimeIn = $scheduledTimeIn->copy()->addMinutes($tardyMinutes);

            return [
                'actual_time_in' => $actualTimeIn,
                'status' => 'tardy',
                'tardy_minutes' => $tardyMinutes,
                'bio_in_site_id' => Site::factory(),
            ];
        });
    }

    /**
     * Indicate that the attendance is a half day absence.
     */
    public function halfDayAbsence(): static
    {
        return $this->state(function (array $attributes) {
            $shiftDate = Carbon::parse($attributes['shift_date']);
            $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attributes['scheduled_time_in']);
            $tardyMinutes = fake()->numberBetween(16, 120);
            $actualTimeIn = $scheduledTimeIn->copy()->addMinutes($tardyMinutes);

            return [
                'actual_time_in' => $actualTimeIn,
                'status' => 'half_day_absence',
                'tardy_minutes' => $tardyMinutes,
                'bio_in_site_id' => Site::factory(),
            ];
        });
    }

    /**
     * Indicate that the attendance is NCNS (No Call No Show).
     */
    public function ncns(): static
    {
        return $this->state([
            'actual_time_in' => null,
            'actual_time_out' => null,
            'status' => 'ncns',
        ]);
    }

    /**
     * Indicate that the absence is advised.
     */
    public function advisedAbsence(): static
    {
        return $this->state([
            'status' => 'advised_absence',
            'is_advised' => true,
            'admin_verified' => true,
            'verification_notes' => fake()->sentence(),
        ]);
    }

    /**
     * Indicate that the attendance has undertime (1-60 minutes early).
     */
    public function undertime(): static
    {
        return $this->state(function (array $attributes) {
            $shiftDate = Carbon::parse($attributes['shift_date']);
            $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attributes['scheduled_time_out']);
            $undertimeMinutes = fake()->numberBetween(1, 60); // 1-60 minutes early = undertime
            $actualTimeOut = $scheduledTimeOut->copy()->subMinutes($undertimeMinutes);

            return [
                'actual_time_out' => $actualTimeOut,
                'status' => 'undertime',
                'undertime_minutes' => $undertimeMinutes,
                'bio_out_site_id' => Site::factory(),
            ];
        });
    }

    /**
     * Indicate that bio in failed.
     */
    public function failedBioIn(): static
    {
        return $this->state(function (array $attributes) {
            $shiftDate = Carbon::parse($attributes['shift_date']);
            $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attributes['scheduled_time_out']);

            return [
                'actual_time_in' => null,
                'actual_time_out' => $scheduledTimeOut,
                'status' => 'failed_bio_in',
                'bio_out_site_id' => Site::factory(),
            ];
        });
    }

    /**
     * Indicate that bio out failed.
     */
    public function failedBioOut(): static
    {
        return $this->state(function (array $attributes) {
            $shiftDate = Carbon::parse($attributes['shift_date']);
            $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $attributes['scheduled_time_in']);

            return [
                'actual_time_in' => $scheduledTimeIn,
                'actual_time_out' => null,
                'status' => 'failed_bio_out',
                'bio_in_site_id' => Site::factory(),
            ];
        });
    }

    /**
     * Indicate that the attendance is cross-site.
     */
    public function crossSite(): static
    {
        return $this->state([
            'is_cross_site_bio' => true,
        ]);
    }

    /**
     * Indicate that the attendance is verified.
     */
    public function verified(): static
    {
        return $this->state([
            'admin_verified' => true,
            'verification_notes' => fake()->sentence(),
        ]);
    }
}
