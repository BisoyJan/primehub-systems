<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AttendanceProcessor;
use App\Services\AttendanceFileParser;
use App\Models\BiometricRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReprocessAttendanceFromBiometric extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reprocess-attendance-from-biometric {--from=} {--to=} {--dry}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : Carbon::now()->subDays(7)->startOfDay();
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : Carbon::now()->endOfDay();
        $dry = (bool) $this->option('dry');

    $this->info("Reprocessing attendance from {$from->toDateString()} to {$to->toDateString()}" . ($dry ? ' (dry-run)' : ''));

        // Find users who have biometric records in the range
        $userIds = BiometricRecord::whereBetween('datetime', [$from, $to])->distinct()->pluck('user_id')->filter()->values();

        if ($userIds->isEmpty()) {
            $this->info('No biometric records found in the given range.');
            return 0;
        }

    $processor = app(AttendanceProcessor::class);
    // Use a parser instance from the container instead of accessing protected properties
    $parser = app(AttendanceFileParser::class);

        foreach ($userIds as $userId) {
            /** @var User|null $user */
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            $this->line("Processing user: {$user->id} - {$user->name}");

            // Load biometric records for this user in the date range
            $bios = BiometricRecord::where('user_id', $user->id)
                ->whereBetween('datetime', [$from, $to])
                ->orderBy('datetime')
                ->get();

            if ($bios->isEmpty()) {
                continue;
            }

            // Build the parser-like records collection expected by the processor
            $records = collect();
            foreach ($bios as $b) {
                $records->push([
                    'datetime' => Carbon::parse($b->datetime),
                    'name' => $b->employee_name,
                    'mode' => null,
                ]);
            }

            // Normalized name from the user's actual name to ensure matching
            $normalizedName = $parser->normalizeName($user->name);

            if ($dry) {
                $this->line(" - would process {$records->count()} biometric records for {$user->name}");
                continue;
            }

            // Call processEmployeeRecords to re-evaluate shifts and save attendance
            try {
                $result = $processor->reprocessEmployeeRecords($normalizedName, $records, $from);
                $this->line(" - processed: " . ($result['records_processed'] ?? 0));
            } catch (\Exception $e) {
                $this->error(" - failed processing user {$user->id}: {$e->getMessage()}");
            }
        }

        $this->info('Reprocessing completed.');

        return 0;
    }

    /**
     * Command options are declared on the signature.
     */
}
