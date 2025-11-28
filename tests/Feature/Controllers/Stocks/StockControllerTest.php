<?php

namespace Tests\Feature\Controllers\Stocks;

use App\Models\DiskSpec;
use App\Models\MonitorSpec;
use App\Models\ProcessorSpec;
use App\Models\RamSpec;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);
    }

    public function test_index_displays_stocks(): void
    {
        $ramSpec = RamSpec::factory()->create();
        Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('stocks.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/Stocks/Index')
                ->has('stocks.data')
            );
    }

    public function test_index_filters_by_type(): void
    {
        $ramSpec = RamSpec::factory()->create();
        $diskSpec = DiskSpec::factory()->create();

        Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
        ]);

        Stock::factory()->create([
            'stockable_type' => DiskSpec::class,
            'stockable_id' => $diskSpec->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('stocks.index', ['type' => 'ram']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/Stocks/Index')
                ->where('filterType', 'ram')
            );
    }

    public function test_index_searches_by_manufacturer_and_model(): void
    {
        $ramSpec = RamSpec::factory()->create([
            'manufacturer' => 'Corsair',
            'model' => 'Vengeance RGB',
        ]);

        Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('stocks.index', ['search' => 'Corsair']));

        $response->assertStatus(200);
    }

    public function test_store_creates_new_stock(): void
    {
        $ramSpec = RamSpec::factory()->create();

        $stockData = [
            'type' => 'ram',
            'stockable_id' => $ramSpec->id,
            'quantity' => 50,
            'reserved' => 5,
            'location' => 'Warehouse A',
            'notes' => 'Initial stock',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('stocks.store'), $stockData);

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseHas('stocks', [
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 50,
            'reserved' => 5,
            'location' => 'Warehouse A',
        ]);
    }

    public function test_store_updates_existing_stock_if_exists(): void
    {
        $ramSpec = RamSpec::factory()->create();
        $existingStock = Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 30,
        ]);

        $stockData = [
            'type' => 'ram',
            'stockable_id' => $ramSpec->id,
            'quantity' => 100,
            'reserved' => 10,
            'location' => 'Warehouse B',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('stocks.store'), $stockData);

        $response->assertRedirect();

        $this->assertDatabaseHas('stocks', [
            'id' => $existingStock->id,
            'quantity' => 100,
            'reserved' => 10,
            'location' => 'Warehouse B',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('stocks.store'), []);

        $response->assertSessionHasErrors(['type', 'stockable_id']);
    }

    public function test_store_validates_type_enum(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('stocks.store'), [
                'type' => 'invalid_type',
                'stockable_id' => 1,
            ]);

        $response->assertSessionHasErrors(['type']);
    }

    public function test_show_returns_stock_as_json(): void
    {
        $ramSpec = RamSpec::factory()->create();
        $stock = Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('stocks.show', $stock));

        $response->assertStatus(200)
            ->assertJson([
                'id' => $stock->id,
                'stockable_type' => RamSpec::class,
                'stockable_id' => $ramSpec->id,
            ]);
    }

    public function test_update_modifies_stock(): void
    {
        $diskSpec = DiskSpec::factory()->create();
        $stock = Stock::factory()->create([
            'stockable_type' => DiskSpec::class,
            'stockable_id' => $diskSpec->id,
            'quantity' => 50,
            'reserved' => 5,
            'location' => 'Old Location',
        ]);

        $updateData = [
            'quantity' => 75,
            'reserved' => 10,
            'location' => 'New Location',
            'notes' => 'Updated notes',
        ];

        $response = $this->actingAs($this->user)
            ->put(route('stocks.update', $stock), $updateData);

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'quantity' => 75,
            'reserved' => 10,
            'location' => 'New Location',
            'notes' => 'Updated notes',
        ]);
    }

    public function test_update_applies_delta_quantity(): void
    {
        $processorSpec = ProcessorSpec::factory()->create();
        $stock = Stock::factory()->create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => $processorSpec->id,
            'quantity' => 50,
            'reserved' => 0,
        ]);

        $updateData = [
            'delta_quantity' => 20,
        ];

        $response = $this->actingAs($this->user)
            ->put(route('stocks.update', $stock), $updateData);

        $response->assertRedirect();

        $stock->refresh();
        $this->assertEquals(70, $stock->quantity);
    }

    public function test_update_applies_negative_delta_but_not_below_zero(): void
    {
        $monitorSpec = MonitorSpec::factory()->create();
        $stock = Stock::factory()->create([
            'stockable_type' => MonitorSpec::class,
            'stockable_id' => $monitorSpec->id,
            'quantity' => 10,
        ]);

        $updateData = [
            'delta_quantity' => -20, // Would go negative
        ];

        $response = $this->actingAs($this->user)
            ->put(route('stocks.update', $stock), $updateData);

        $response->assertRedirect();

        $stock->refresh();
        $this->assertEquals(0, $stock->quantity);
    }

    public function test_destroy_deletes_stock(): void
    {
        $ramSpec = RamSpec::factory()->create();
        $stock = Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('stocks.destroy', $stock));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_adjust_increases_quantity_atomically(): void
    {
        $diskSpec = DiskSpec::factory()->create();
        $stock = Stock::factory()->create([
            'stockable_type' => DiskSpec::class,
            'stockable_id' => $diskSpec->id,
            'quantity' => 40,
        ]);

        $adjustData = [
            'type' => 'disk',
            'stockable_id' => $diskSpec->id,
            'delta' => 15,
        ];

        $response = $this->actingAs($this->user)
            ->post(route('stocks.adjust'), $adjustData);

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $stock->refresh();
        $this->assertEquals(55, $stock->quantity);
    }

    public function test_adjust_decreases_quantity_atomically(): void
    {
        $processorSpec = ProcessorSpec::factory()->create();
        $stock = Stock::factory()->create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => $processorSpec->id,
            'quantity' => 60,
        ]);

        $adjustData = [
            'type' => 'processor',
            'stockable_id' => $processorSpec->id,
            'delta' => -25,
        ];

        $response = $this->actingAs($this->user)
            ->post(route('stocks.adjust'), $adjustData);

        $response->assertRedirect();

        $stock->refresh();
        $this->assertEquals(35, $stock->quantity);
    }

    public function test_adjust_creates_stock_if_not_exists(): void
    {
        // Use a monitor without the auto-created stock
        $monitorSpec = MonitorSpec::factory()->create();
        
        // Delete the auto-created stock from factory's afterCreating callback
        Stock::where('stockable_type', MonitorSpec::class)
            ->where('stockable_id', $monitorSpec->id)
            ->delete();

        $adjustData = [
            'type' => 'monitor',
            'stockable_id' => $monitorSpec->id,
            'delta' => 20,
        ];

        $response = $this->actingAs($this->user)
            ->post(route('stocks.adjust'), $adjustData);

        $response->assertRedirect();

        $this->assertDatabaseHas('stocks', [
            'stockable_type' => MonitorSpec::class,
            'stockable_id' => $monitorSpec->id,
            'quantity' => 20,
        ]);
    }

    public function test_adjust_does_not_allow_negative_quantity(): void
    {
        $ramSpec = RamSpec::factory()->create();
        $stock = Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 5,
        ]);

        $adjustData = [
            'type' => 'ram',
            'stockable_id' => $ramSpec->id,
            'delta' => -10,
        ];

        $response = $this->actingAs($this->user)
            ->post(route('stocks.adjust'), $adjustData);

        $response->assertRedirect();

        $stock->refresh();
        $this->assertEquals(0, $stock->quantity);
    }
}
