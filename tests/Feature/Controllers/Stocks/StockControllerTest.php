<?php

namespace Tests\Feature\Controllers\Stocks;

use App\Models\ProcessorSpec;
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
        $processorSpec = ProcessorSpec::factory()->create();
        Stock::create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => $processorSpec->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('stocks.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/Stocks/Index')
                ->has('stocks.data')
            );
    }

    public function test_index_searches_stocks(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('stocks.index', ['search' => 'test']));

        $response->assertStatus(200);
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
        $processorSpec = ProcessorSpec::factory()->create();
        $stock = Stock::create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => $processorSpec->id,
            'quantity' => 10,
            'reserved' => 2,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('stocks.show', $stock));

        $response->assertStatus(200)
            ->assertJson([
                'id' => $stock->id,
                'stockable_type' => ProcessorSpec::class,
                'stockable_id' => $processorSpec->id,
            ]);
    }

    public function test_update_modifies_stock(): void
    {
        $processorSpec = ProcessorSpec::factory()->create();
        $stock = Stock::create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => $processorSpec->id,
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
        $stock = Stock::create([
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
        $processorSpec = ProcessorSpec::factory()->create();
        $stock = Stock::create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => $processorSpec->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        $updateData = [
            'delta_quantity' => -20,
        ];

        $response = $this->actingAs($this->user)
            ->put(route('stocks.update', $stock), $updateData);

        $response->assertRedirect();

        $stock->refresh();
        $this->assertEquals(0, $stock->quantity);
    }

    public function test_destroy_deletes_stock(): void
    {
        $processorSpec = ProcessorSpec::factory()->create();
        $stock = Stock::create([
            'stockable_type' => ProcessorSpec::class,
            'stockable_id' => $processorSpec->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('stocks.destroy', $stock));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }
}
