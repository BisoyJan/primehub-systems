<?php

namespace Tests\Feature\Console;

use App\Models\DiskSpec;
use App\Models\MonitorSpec;
use App\Models\ProcessorSpec;
use App\Models\RamSpec;
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
        // Create a stock with a valid spec
        $ramSpec = RamSpec::factory()->create();
        $validStock = Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
        ]);

        // Create an orphaned stock (spec doesn't exist)
        $orphanedStock = Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => 99999, // Non-existent ID
        ]);

        $this->artisan('stock:clean-orphaned')
            ->expectsOutput('Cleaning orphaned stock records...')
            ->expectsOutputToContain('Deleting orphaned stock')
            ->assertExitCode(0);

        // Valid stock should remain
        $this->assertDatabaseHas('stocks', ['id' => $validStock->id]);

        // Orphaned stock should be deleted
        $this->assertDatabaseMissing('stocks', ['id' => $orphanedStock->id]);
    }

    #[Test]
    public function it_handles_multiple_orphaned_stocks(): void
    {
        // Create multiple orphaned stocks
        $orphaned1 = Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => 99999,
        ]);

        $orphaned2 = Stock::factory()->create([
            'stockable_type' => DiskSpec::class,
            'stockable_id' => 88888,
        ]);

        $orphaned3 = Stock::factory()->create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => 77777,
        ]);

        $this->artisan('stock:clean-orphaned')
            ->expectsOutput('Successfully deleted 3 orphaned stock record(s).')
            ->assertExitCode(0);

        $this->assertEquals(0, Stock::count());
    }

    #[Test]
    public function it_displays_message_when_no_orphaned_stocks_found(): void
    {
        // Create valid stocks only
        $ramSpec = RamSpec::factory()->create();
        Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
        ]);

        $diskSpec = DiskSpec::factory()->create();
        Stock::factory()->create([
            'stockable_type' => DiskSpec::class,
            'stockable_id' => $diskSpec->id,
        ]);

        $this->artisan('stock:clean-orphaned')
            ->expectsOutput('No orphaned stock records found.')
            ->assertExitCode(0);

        // All stocks should remain
        $this->assertEquals(2, Stock::count());
    }

    #[Test]
    public function it_handles_different_stockable_types(): void
    {
        // Create orphaned stocks for different types
        $ramOrphaned = Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => 99999,
        ]);

        $diskOrphaned = Stock::factory()->create([
            'stockable_type' => DiskSpec::class,
            'stockable_id' => 88888,
        ]);

        $processorOrphaned = Stock::factory()->create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => 77777,
        ]);

        $monitorOrphaned = Stock::factory()->create([
            'stockable_type' => MonitorSpec::class,
            'stockable_id' => 66666,
        ]);

        $this->artisan('stock:clean-orphaned')
            ->assertExitCode(0);

        $this->assertEquals(0, Stock::count());
    }

    #[Test]
    public function it_displays_orphaned_stock_details(): void
    {
        // Create orphaned stock directly - stockable_id 99999 doesn't exist
        $orphaned = Stock::create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => 99999,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        // Verify the orphaned stock was detected and deleted
        $this->artisan('stock:clean-orphaned')
            ->expectsOutputToContain("Deleting orphaned stock")
            ->assertExitCode(0);

        // Verify the stock was deleted
        $this->assertDatabaseMissing('stocks', ['id' => $orphaned->id]);
    }

    #[Test]
    public function it_preserves_valid_stocks_with_existing_specs(): void
    {
        // Create valid stocks for all types
        $ramSpec = RamSpec::factory()->create();
        $ramStock = Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
        ]);

        $diskSpec = DiskSpec::factory()->create();
        $diskStock = Stock::factory()->create([
            'stockable_type' => DiskSpec::class,
            'stockable_id' => $diskSpec->id,
        ]);

        $processorSpec = ProcessorSpec::factory()->create();
        $processorStock = Stock::factory()->create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => $processorSpec->id,
        ]);

        $monitorSpec = MonitorSpec::factory()->create();
        $monitorStock = Stock::factory()->create([
            'stockable_type' => MonitorSpec::class,
            'stockable_id' => $monitorSpec->id,
        ]);

        $this->artisan('stock:clean-orphaned')
            ->expectsOutput('No orphaned stock records found.')
            ->assertExitCode(0);

        // At least these 4 valid stocks should remain
        $this->assertGreaterThanOrEqual(4, Stock::count());
        $this->assertDatabaseHas('stocks', ['id' => $ramStock->id]);
        $this->assertDatabaseHas('stocks', ['id' => $diskStock->id]);
        $this->assertDatabaseHas('stocks', ['id' => $processorStock->id]);
        $this->assertDatabaseHas('stocks', ['id' => $monitorStock->id]);
    }

    #[Test]
    public function it_handles_mixed_valid_and_orphaned_stocks(): void
    {
        // Create valid stock
        $ramSpec = RamSpec::factory()->create();
        $validStock = Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
        ]);

        // Create orphaned stocks
        $orphaned1 = Stock::factory()->create([
            'stockable_type' => DiskSpec::class,
            'stockable_id' => 88888,
        ]);

        $orphaned2 = Stock::factory()->create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => 77777,
        ]);

        $this->artisan('stock:clean-orphaned')
            ->expectsOutput('Successfully deleted 2 orphaned stock record(s).')
            ->assertExitCode(0);

        // Valid stock should remain
        $this->assertDatabaseHas('stocks', ['id' => $validStock->id]);

        // Orphaned stocks should be deleted
        $this->assertDatabaseMissing('stocks', ['id' => $orphaned1->id]);
        $this->assertDatabaseMissing('stocks', ['id' => $orphaned2->id]);

        $this->assertEquals(1, Stock::count());
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
