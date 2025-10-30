<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
	/**
	 * Define the application's command schedule.
	 */
	protected function schedule(Schedule $schedule)
	{
		// Scheduled cleanup: remove ZIP files older than 24 hours from storage/app/temp
		$schedule->call(function () {
			$tempDir = storage_path('app/temp');
			if (!is_dir($tempDir)) return;
			$files = glob($tempDir . '/pc-qrcodes-*.zip');
			$now = time();
			foreach ($files as $file) {
				if (is_file($file) && ($now - filemtime($file)) > 86400) {
					@unlink($file);
				}
			}
		})->dailyAt('2:00');

		// Schedule dashboard stats calculation every five minutes
		$schedule->job(new \App\Jobs\CalculateDashboardStats)->everyFiveMinutes();
	}

	/**
	 * Register the commands for the application.
	 */
	protected function commands()
	{
		$this->load(__DIR__.'/Commands');
		// Register manual cleanup command inline
		\Illuminate\Console\Command::macro('qrcodes:cleanup-zips', function () {
			$tempDir = storage_path('app/temp');
			if (!is_dir($tempDir)) {
				$this->info('No temp directory found.');
				return;
			}
			$files = glob($tempDir . '/pc-qrcodes-*.zip');
			$now = time();
			$deleted = 0;
			foreach ($files as $file) {
				if (is_file($file) && ($now - filemtime($file)) > 86400) {
					@unlink($file);
					$deleted++;
				}
			}
			$this->info("Deleted {$deleted} old ZIP files.");
		});
	}
}
