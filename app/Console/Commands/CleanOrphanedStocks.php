<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Stock;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;

class CleanOrphanedStocks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:clean-orphaned';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up orphaned stock records that have no associated spec';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Cleaning orphaned stock records...');

        $deletedCount = 0;

        // Get all stocks
        $stocks = Stock::all();

        foreach ($stocks as $stock) {
            // Check if the related model exists
            if (!$stock->stockable) {
                $this->warn("Deleting orphaned stock: ID {$stock->id} (Type: {$stock->stockable_type}, ID: {$stock->stockable_id})");
                $stock->delete();
                $deletedCount++;
            }
        }

        if ($deletedCount > 0) {
            $this->info("Successfully deleted {$deletedCount} orphaned stock record(s).");
        } else {
            $this->info('No orphaned stock records found.');
        }

        return Command::SUCCESS;
    }
}
