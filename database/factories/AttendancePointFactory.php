<?php

namespace Database\Factories;

use App\Models\AttendancePoint;
use App\Models\User;
use App\Models\Attendance;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AttendancePoint>
 */
class AttendancePointFactory extends Factory
{
    protected $model = AttendancePoint::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shiftDate = $this->faker->dateTimeBetween('-30 days', 'now');
        $shiftDateCarbon = Carbon::instance($shiftDate);

        return [
            'user_id' => User::factory(),
            'attendance_id' => Attendance::factory(),
            'shift_date' => $shiftDateCarbon,
            'point_type' => $this->faker->randomElement(['tardy', 'undertime', 'half_day_absence', 'whole_day_absence']),
            'points' => 0.25,
            'status' => 'tardy',
            'is_advised' => false,
            'notes' => null,
            'is_excused' => false,
            'excused_by' => null,
            'excused_at' => null,
            'excuse_reason' => null,
            'expires_at' => $shiftDateCarbon->copy()->addMonths(6), // Default 6 months
            'expiration_type' => 'sro',
            'is_expired' => false,
            'expired_at' => null,
            'violation_details' => 'Test violation details',
            'tardy_minutes' => null,
            'undertime_minutes' => null,
            'eligible_for_gbro' => true,
            'gbro_applied_at' => null,
            'gbro_batch_id' => null,
        ];
    }

    /**
     * Indicate that the point is for tardy violation.
     */
    public function tardy(int $minutes = 10): static
    {
        return $this->state(function (array $attributes) use ($minutes) {
            return [
                'point_type' => 'tardy',
                'points' => 0.25,
                'status' => 'tardy',
                'violation_details' => "Tardy: Arrived {$minutes} minutes late.",
                'tardy_minutes' => $minutes,
                'eligible_for_gbro' => true,
            ];
        });
    }

    /**
     * Indicate that the point is for undertime violation.
     */
    public function undertime(int $minutes = 90): static
    {
        return $this->state(function (array $attributes) use ($minutes) {
            return [
                'point_type' => 'undertime',
                'points' => 0.25,
                'status' => 'undertime',
                'violation_details' => "Undertime: Left {$minutes} minutes early.",
                'undertime_minutes' => $minutes,
                'eligible_for_gbro' => true,
            ];
        });
    }

    /**
     * Indicate that the point is for half-day absence.
     */
    public function halfDayAbsence(int $minutes = 45): static
    {
        return $this->state(function (array $attributes) use ($minutes) {
            return [
                'point_type' => 'half_day_absence',
                'points' => 0.50,
                'status' => 'half_day_absence',
                'violation_details' => "Half-Day Absence: Arrived {$minutes} minutes late (exceeding grace period).",
                'tardy_minutes' => $minutes,
                'eligible_for_gbro' => true,
            ];
        });
    }

    /**
     * Indicate that the point is for NCNS (No Call, No Show).
     */
    public function ncns(): static
    {
        return $this->state(function (array $attributes) {
            $shiftDate = Carbon::parse($attributes['shift_date']);

            return [
                'point_type' => 'whole_day_absence',
                'points' => 1.00,
                'status' => 'ncns',
                'is_advised' => false,
                'violation_details' => 'No Call, No Show (NCNS): Employee did not report for work and did not provide prior notice.',
                'expires_at' => $shiftDate->copy()->addYear(), // 1 year for NCNS
                'expiration_type' => 'none',
                'eligible_for_gbro' => false, // NCNS not eligible for GBRO
            ];
        });
    }

    /**
     * Indicate that the point is for FTN (Failed to Notify).
     */
    public function ftn(): static
    {
        return $this->state(function (array $attributes) {
            $shiftDate = Carbon::parse($attributes['shift_date']);

            return [
                'point_type' => 'whole_day_absence',
                'points' => 1.00,
                'status' => 'ncns',
                'is_advised' => true,
                'violation_details' => 'Failed to Notify (FTN): Employee did not report for work despite being advised.',
                'expires_at' => $shiftDate->copy()->addYear(), // 1 year for FTN
                'expiration_type' => 'none',
                'eligible_for_gbro' => false, // FTN not eligible for GBRO
            ];
        });
    }

    /**
     * Indicate that the point has been excused.
     */
    public function excused(?User $excusedBy = null, string $reason = 'Approved by supervisor'): static
    {
        return $this->state(function (array $attributes) use ($excusedBy, $reason) {
            return [
                'is_excused' => true,
                'excused_by' => $excusedBy?->id ?? User::factory(),
                'excused_at' => now(),
                'excuse_reason' => $reason,
            ];
        });
    }

    /**
     * Indicate that the point has expired via SRO.
     */
    public function expiredSro(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_expired' => true,
                'expired_at' => now(),
                'expiration_type' => 'sro',
            ];
        });
    }

    /**
     * Indicate that the point has expired via GBRO.
     */
    public function expiredGbro(?string $batchId = null): static
    {
        return $this->state(function (array $attributes) use ($batchId) {
            return [
                'is_expired' => true,
                'expired_at' => now(),
                'expiration_type' => 'gbro',
                'gbro_applied_at' => now(),
                'gbro_batch_id' => $batchId ?? now()->format('YmdHis'),
            ];
        });
    }

    /**
     * Indicate that the point is expiring soon (within 30 days).
     */
    public function expiringSoon(int $daysUntilExpiration = 15): static
    {
        return $this->state(function (array $attributes) use ($daysUntilExpiration) {
            return [
                'expires_at' => now()->addDays($daysUntilExpiration),
            ];
        });
    }

    /**
     * Indicate that the point is past its expiration date but not marked expired.
     */
    public function pastExpiration(int $daysAgo = 5): static
    {
        return $this->state(function (array $attributes) use ($daysAgo) {
            return [
                'expires_at' => now()->subDays($daysAgo),
                'is_expired' => false,
            ];
        });
    }

    /**
     * Indicate that the point is eligible for GBRO.
     */
    public function eligibleForGbro(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'eligible_for_gbro' => true,
                'gbro_applied_at' => null,
                'is_expired' => false,
                'is_excused' => false,
            ];
        });
    }

    /**
     * Set a specific shift date for the point.
     */
    public function onDate(Carbon|string $date): static
    {
        return $this->state(function (array $attributes) use ($date) {
            $shiftDate = is_string($date) ? Carbon::parse($date) : $date;
            $pointType = $attributes['point_type'] ?? 'tardy';
            $isNcnsOrFtn = $pointType === 'whole_day_absence';

            return [
                'shift_date' => $shiftDate,
                'expires_at' => $isNcnsOrFtn
                    ? $shiftDate->copy()->addYear()
                    : $shiftDate->copy()->addMonths(6),
            ];
        });
    }

    /**
     * Set a specific user for the point.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Set a specific attendance record for the point.
     */
    public function forAttendance(Attendance $attendance): static
    {
        return $this->state(fn (array $attributes) => [
            'attendance_id' => $attendance->id,
            'user_id' => $attendance->user_id,
            'shift_date' => $attendance->shift_date,
        ]);
    }
}

