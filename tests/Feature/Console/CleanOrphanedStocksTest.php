<?php

namespace Tests\Feature\Console;

use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanOrphanedStocksTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_orphaned_stock_records(): void
    {
        // Create an orphaned stock (spec doesn't exist)
        $orphanedStock = Stock::create([
            'stockable_type' => 'App\\Models\\ProcessorSpec',
            'stockable_id' => 99999,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        $this->artisan('stock:clean-orphaned')
            ->expectsOutput('Cleaning orphaned stock records...')
            ->expectsOutputToContain('Deleting orphaned stock')
            ->assertExitCode(0);

        // Orphaned stock should be deleted
        $this->assertDatabaseMissing('stocks', ['id' => $orphanedStock->id]);
    }

    #[Test]
    public function it_handles_multiple_orphaned_stocks(): void
    {
        // Create multiple orphaned stocks
        Stock::create([
            'stockable_type' => 'App\\Models\\ProcessorSpec',
            'stockable_id' => 99999,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        Stock::create([
            'stockable_type' => 'App\\Models\\ProcessorSpec',
            'stockable_id' => 88888,
            'quantity' => 5,
            'reserved' => 0,
        ]);

        Stock::create([
            'stockable_type' => 'App\\Models\\ProcessorSpec',
            'stockable_id' => 77777,
            'quantity' => 3,
            'reserved' => 0,
        ]);

        $this->artisan('stock:clean-orphaned')
            ->expectsOutput('Successfully deleted 3 orphaned stock record(s).')
            ->assertExitCode(0);

        $this->assertEquals(0, Stock::count());
    }

    #[Test]
    public function it_displays_message_when_no_orphaned_stocks_found(): void
    {
        // No stocks at all
        $this->artisan('stock:clean-orphaned')
            ->expectsOutput('No orphaned stock records found.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_orphaned_stock_details(): void
    {
        $orphaned = Stock::create([
            'stockable_type' => 'App\\Models\\ProcessorSpec',
            'stockable_id' => 99999,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        $this->artisan('stock:clean-orphaned')
            ->expectsOutputToContain('Deleting orphaned stock')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('stocks', ['id' => $orphaned->id]);
    }

    #[Test]
    public function it_handles_empty_stock_table(): void
    {
        $this->artisan('stock:clean-orphaned')
            ->expectsOutput('No orphaned stock records found.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_returns_success_exit_code(): void
    {
        $this->artisan('stock:clean-orphaned')
            ->assertExitCode(0);
    }
}
