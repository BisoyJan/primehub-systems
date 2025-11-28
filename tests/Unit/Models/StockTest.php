<?php

namespace Tests\Unit\Models;

use App\Models\DiskSpec;
use App\Models\MonitorSpec;
use App\Models\ProcessorSpec;
use App\Models\RamSpec;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_guarded_id_attribute(): void
    {
        $ramSpec = RamSpec::factory()->create();

        $stock = Stock::create([
            'id' => 999,
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 10,
            'reserved' => 2,
        ]);

        // ID should be auto-incremented, not 999
        $this->assertNotEquals(999, $stock->id);
    }

    #[Test]
    public function it_casts_quantity_to_integer(): void
    {
        $ramSpec = RamSpec::factory()->create();

        $stock = Stock::create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => '10',
        ]);

        $this->assertIsInt($stock->quantity);
        $this->assertEquals(10, $stock->quantity);
    }

    #[Test]
    public function it_casts_reserved_to_integer(): void
    {
        $ramSpec = RamSpec::factory()->create();

        $stock = Stock::create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 10,
            'reserved' => '2',
        ]);

        $this->assertIsInt($stock->reserved);
        $this->assertEquals(2, $stock->reserved);
    }

    #[Test]
    public function it_has_polymorphic_relationship_with_ram_spec(): void
    {
        $ramSpec = RamSpec::factory()->create();

        $stock = Stock::create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 10,
        ]);

        $this->assertInstanceOf(RamSpec::class, $stock->stockable);
        $this->assertEquals($ramSpec->id, $stock->stockable->id);
    }

    #[Test]
    public function it_has_polymorphic_relationship_with_disk_spec(): void
    {
        $diskSpec = DiskSpec::factory()->create();

        $stock = Stock::create([
            'stockable_type' => DiskSpec::class,
            'stockable_id' => $diskSpec->id,
            'quantity' => 5,
        ]);

        $this->assertInstanceOf(DiskSpec::class, $stock->stockable);
        $this->assertEquals($diskSpec->id, $stock->stockable->id);
    }

    #[Test]
    public function it_has_polymorphic_relationship_with_processor_spec(): void
    {
        $processorSpec = ProcessorSpec::factory()->create();

        $stock = Stock::create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => $processorSpec->id,
            'quantity' => 8,
        ]);

        $this->assertInstanceOf(ProcessorSpec::class, $stock->stockable);
        $this->assertEquals($processorSpec->id, $stock->stockable->id);
    }

    #[Test]
    public function it_has_polymorphic_relationship_with_monitor_spec(): void
    {
        $monitorSpec = MonitorSpec::factory()->create();

        $stock = Stock::create([
            'stockable_type' => MonitorSpec::class,
            'stockable_id' => $monitorSpec->id,
            'quantity' => 12,
        ]);

        $this->assertInstanceOf(MonitorSpec::class, $stock->stockable);
        $this->assertEquals($monitorSpec->id, $stock->stockable->id);
    }

    #[Test]
    public function it_creates_stock_with_zero_reserved(): void
    {
        $ramSpec = RamSpec::factory()->create();

        $stock = Stock::create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 10,
        ]);

        $this->assertEquals(0, $stock->reserved);
    }

    #[Test]
    public function it_updates_stock_quantity(): void
    {
        $ramSpec = RamSpec::factory()->create();

        $stock = Stock::create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 10,
        ]);

        $stock->update(['quantity' => 15]);

        $this->assertEquals(15, $stock->fresh()->quantity);
    }

    #[Test]
    public function it_updates_reserved_quantity(): void
    {
        $ramSpec = RamSpec::factory()->create();

        $stock = Stock::create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 10,
            'reserved' => 2,
        ]);

        $stock->update(['reserved' => 5]);

        $this->assertEquals(5, $stock->fresh()->reserved);
    }

    #[Test]
    public function it_returns_null_for_orphaned_stockable(): void
    {
        $stock = Stock::create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => 99999, // Non-existent
            'quantity' => 10,
        ]);

        $this->assertNull($stock->stockable);
    }

    #[Test]
    public function it_stores_timestamps(): void
    {
        $ramSpec = RamSpec::factory()->create();

        $stock = Stock::create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 10,
        ]);

        $this->assertNotNull($stock->created_at);
        $this->assertNotNull($stock->updated_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $stock->created_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $stock->updated_at);
    }

    #[Test]
    public function it_uses_stocks_table_name(): void
    {
        $stock = new Stock();

        $this->assertEquals('stocks', $stock->getTable());
    }
}
