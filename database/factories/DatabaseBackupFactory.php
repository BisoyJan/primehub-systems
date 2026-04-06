<?php

namespace Database\Factories;

use App\Models\DatabaseBackup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseBackup>
 */
class DatabaseBackupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filename' => 'backup-'.fake()->dateTimeThisYear()->format('Y-m-d-His').'.sql.gz',
            'disk' => 'local',
            'path' => 'backups/backup-'.fake()->uuid().'.sql.gz',
            'size' => fake()->numberBetween(1024, 104857600),
            'status' => 'completed',
            'created_by' => User::factory(),
            'completed_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'size' => 0,
            'completed_at' => null,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
            'size' => 0,
            'completed_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => fake()->sentence(),
            'size' => 0,
            'completed_at' => null,
        ]);
    }
}
