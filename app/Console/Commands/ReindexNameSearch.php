<?php

namespace App\Console\Commands;

use App\Models\BiometricRecord;
use App\Models\User;
use App\Support\SearchNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('search:reindex-names')]
#[Description('Backfill name_search_index for diacritic-insensitive name search.')]
class ReindexNameSearch extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->reindexUsers();
        $this->reindexBiometricRecords();

        return self::SUCCESS;
    }

    private function reindexUsers(): void
    {
        $this->info('Reindexing users...');

        User::query()->chunkById(500, function ($users) {
            foreach ($users as $user) {
                $values = array_map(fn (string $column) => $user->{$column}, $user->getNameSearchColumns());
                $index = SearchNormalizer::normalize(implode(' ', $values));

                DB::table('users')->where('id', $user->id)->update(['name_search_index' => $index]);
            }
        });
    }

    private function reindexBiometricRecords(): void
    {
        $this->info('Reindexing biometric_records...');

        BiometricRecord::query()->chunkById(500, function ($records) {
            foreach ($records as $record) {
                $values = array_map(fn (string $column) => $record->{$column}, $record->getNameSearchColumns());
                $index = SearchNormalizer::normalize(implode(' ', $values));

                DB::table('biometric_records')->where('id', $record->id)->update(['name_search_index' => $index]);
            }
        });
    }
}
