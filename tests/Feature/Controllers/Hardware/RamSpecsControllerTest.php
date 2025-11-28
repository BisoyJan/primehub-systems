<?php

namespace Tests\Feature\Controllers\Hardware;

use App\Models\PcSpec;
use App\Models\RamSpec;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RamSpecsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);
    }

    public function test_index_displays_ram_specs()
    {
        RamSpec::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->get(route('ramspecs.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/RamSpecs/Index')
                ->has('ramspecs.data', 3)
            );
    }

    public function test_create_displays_create_form()
    {
        $response = $this->actingAs($this->user)
            ->get(route('ramspecs.create'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/RamSpecs/Create')
            );
    }

    public function test_store_creates_ram_spec_and_stock()
    {
        $data = [
            'manufacturer' => 'Corsair',
            'model' => 'Vengeance LPX',
            'capacity_gb' => 16,
            'type' => 'DDR4',
            'speed' => 3200,
            'form_factor' => 'DIMM',
            'voltage' => 1.35,
            'stock_quantity' => 10,
        ];

        $response = $this->actingAs($this->user)
            ->post(route('ramspecs.store'), $data);

        $response->assertRedirect(route('ramspecs.index'));
        $this->assertDatabaseHas('ram_specs', [
            'manufacturer' => 'Corsair',
            'model' => 'Vengeance LPX',
        ]);

        $ramSpec = RamSpec::where('model', 'Vengeance LPX')->first();
        $this->assertDatabaseHas('stocks', [
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 10,
        ]);
    }

    public function test_edit_displays_edit_form()
    {
        $ramSpec = RamSpec::factory()->create();

        $response = $this->actingAs($this->user)
            ->get(route('ramspecs.edit', $ramSpec));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/RamSpecs/Edit')
                ->has('ramspec')
                ->where('ramspec.id', $ramSpec->id)
            );
    }

    public function test_update_updates_ram_spec()
    {
        $ramSpec = RamSpec::factory()->create();

        $data = [
            'manufacturer' => 'Updated Manufacturer',
            'model' => 'Updated Model',
            'capacity_gb' => 32,
            'type' => 'DDR5',
            'speed' => 4800,
            'form_factor' => 'DIMM',
            'voltage' => 1.1,
        ];

        $response = $this->actingAs($this->user)
            ->put(route('ramspecs.update', $ramSpec), $data);

        $response->assertRedirect(route('ramspecs.index'));
        $this->assertDatabaseHas('ram_specs', [
            'id' => $ramSpec->id,
            'manufacturer' => 'Updated Manufacturer',
        ]);
    }

    public function test_destroy_deletes_ram_spec_and_stock_when_safe()
    {
        $ramSpec = RamSpec::factory()->create();
        Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 0, // Safe to delete
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('ramspecs.destroy', $ramSpec));

        $response->assertRedirect(route('ramspecs.index'));
        $this->assertDatabaseMissing('ram_specs', ['id' => $ramSpec->id]);
        $this->assertDatabaseMissing('stocks', [
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
        ]);
    }

    public function test_destroy_prevents_deletion_if_stock_exists()
    {
        $ramSpec = RamSpec::factory()->create();
        Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 5, // Not safe to delete
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('ramspecs.destroy', $ramSpec));

        $response->assertRedirect(route('ramspecs.index'));
        // Should still exist
        $this->assertDatabaseHas('ram_specs', ['id' => $ramSpec->id]);
    }

    public function test_destroy_prevents_deletion_if_used_in_pc_specs()
    {
        $ramSpec = RamSpec::factory()->create();
        Stock::factory()->create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 0,
        ]);

        $pcSpec = PcSpec::factory()->create();
        $ramSpec->pcSpecs()->attach($pcSpec);

        $response = $this->actingAs($this->user)
            ->delete(route('ramspecs.destroy', $ramSpec));

        $response->assertRedirect(route('ramspecs.index'));
        // Should still exist
        $this->assertDatabaseHas('ram_specs', ['id' => $ramSpec->id]);
    }
}
