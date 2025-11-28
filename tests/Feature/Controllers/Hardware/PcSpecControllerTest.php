<?php

namespace Tests\Feature\Controllers\Hardware;

use App\Models\DiskSpec;
use App\Models\PcSpec;
use App\Models\ProcessorSpec;
use App\Models\RamSpec;
use App\Models\Station;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PcSpecControllerTest extends TestCase
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

    public function test_index_displays_pc_specs()
    {
        PcSpec::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->get(route('pcspecs.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Index')
                ->has('pcspecs.data', 3)
            );
    }

    public function test_create_displays_create_form()
    {
        $response = $this->actingAs($this->user)
            ->get(route('pcspecs.create'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Create')
                ->has('ramOptions')
                ->has('diskOptions')
                ->has('processorOptions')
            );
    }

    public function test_store_creates_pc_spec_and_decrements_stock()
    {
        $ramSpec = RamSpec::factory()->create(['capacity_gb' => 8]);
        Stock::factory()->create(['stockable_type' => RamSpec::class, 'stockable_id' => $ramSpec->id, 'quantity' => 10]);

        $diskSpec = DiskSpec::factory()->create();
        Stock::factory()->create(['stockable_type' => DiskSpec::class, 'stockable_id' => $diskSpec->id, 'quantity' => 10]);

        $processorSpec = ProcessorSpec::factory()->create();
        Stock::factory()->create(['stockable_type' => ProcessorSpec::class, 'stockable_id' => $processorSpec->id, 'quantity' => 10]);

        $data = [
            'pc_number' => 'PC-TEST-001',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex',
            'form_factor' => 'SFF',
            'memory_type' => 'DDR4',
            'ram_slots' => 4,
            'max_ram_capacity_gb' => 64,
            'max_ram_speed' => '3200',
            'm2_slots' => 1,
            'sata_ports' => 2,
            'ram_specs' => [$ramSpec->id => 2], // 2 sticks
            'disk_specs' => [$diskSpec->id => 1],
            'processor_spec_id' => $processorSpec->id,
            'quantity' => 1,
        ];

        $response = $this->actingAs($this->user)
            ->post(route('pcspecs.store'), $data);

        $response->assertRedirect(route('pcspecs.index'));
        $this->assertDatabaseHas('pc_specs', ['pc_number' => 'PC-TEST-001']);

        $pcSpec = PcSpec::where('pc_number', 'PC-TEST-001')->first();
        $this->assertTrue($pcSpec->ramSpecs->contains($ramSpec));
        $this->assertEquals(2, $pcSpec->ramSpecs->first()->pivot->quantity);

        // Check stock decrement
        $this->assertEquals(8, $ramSpec->stock->fresh()->quantity); // 10 - 2
        $this->assertEquals(9, $diskSpec->stock->fresh()->quantity); // 10 - 1
        $this->assertEquals(9, $processorSpec->stock->fresh()->quantity); // 10 - 1
    }

    public function test_edit_displays_edit_form()
    {
        $pcSpec = PcSpec::factory()->create();

        $response = $this->actingAs($this->user)
            ->get(route('pcspecs.edit', $pcSpec));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Edit')
                ->has('pcspec')
                ->where('pcspec.id', $pcSpec->id)
            );
    }

    public function test_update_updates_pc_spec_and_adjusts_stock()
    {
        $pcSpec = PcSpec::factory()->create();

        // Initial RAM
        $ramSpec1 = RamSpec::factory()->create(['capacity_gb' => 8]);
        Stock::factory()->create(['stockable_type' => RamSpec::class, 'stockable_id' => $ramSpec1->id, 'quantity' => 10]);
        $pcSpec->ramSpecs()->attach($ramSpec1, ['quantity' => 1]);
        // Manually decrement stock for initial setup
        $ramSpec1->stock->decrement('quantity', 1);

        // New RAM
        $ramSpec2 = RamSpec::factory()->create(['capacity_gb' => 16]);
        Stock::factory()->create(['stockable_type' => RamSpec::class, 'stockable_id' => $ramSpec2->id, 'quantity' => 10]);

        // Processor
        $processorSpec = ProcessorSpec::factory()->create();
        Stock::factory()->create(['stockable_type' => ProcessorSpec::class, 'stockable_id' => $processorSpec->id, 'quantity' => 10]);
        $pcSpec->processorSpecs()->attach($processorSpec);
        $processorSpec->stock->decrement('quantity', 1);

        $data = [
            'manufacturer' => 'Updated Manufacturer',
            'model' => 'Updated Model',
            'form_factor' => 'Tower',
            'memory_type' => 'DDR5',
            'ram_slots' => 4,
            'max_ram_capacity_gb' => 128,
            'max_ram_speed' => '4800',
            'm2_slots' => 2,
            'sata_ports' => 4,
            'ram_specs' => [$ramSpec2->id => 1], // Switch to new RAM
            'disk_specs' => [],
            'processor_spec_id' => $processorSpec->id, // Keep same processor
        ];

        $response = $this->actingAs($this->user)
            ->put(route('pcspecs.update', $pcSpec), $data);

        $response->assertRedirect(route('pcspecs.index'));

        // Check stock adjustment
        // Old RAM should be incremented (restored)
        $this->assertEquals(10, $ramSpec1->stock->fresh()->quantity); // 9 + 1
        // New RAM should be decremented
        $this->assertEquals(9, $ramSpec2->stock->fresh()->quantity); // 10 - 1
        // Processor should stay same (decremented)
        $this->assertEquals(9, $processorSpec->stock->fresh()->quantity);
    }

    public function test_destroy_deletes_pc_spec_and_restores_stock()
    {
        $pcSpec = PcSpec::factory()->create();

        $ramSpec = RamSpec::factory()->create();
        Stock::factory()->create(['stockable_type' => RamSpec::class, 'stockable_id' => $ramSpec->id, 'quantity' => 10]);
        $pcSpec->ramSpecs()->attach($ramSpec, ['quantity' => 2]);
        // Simulate stock consumed
        $ramSpec->stock->decrement('quantity', 2);

        $response = $this->actingAs($this->user)
            ->delete(route('pcspecs.destroy', $pcSpec));

        $response->assertRedirect(route('pcspecs.index'));
        $this->assertDatabaseMissing('pc_specs', ['id' => $pcSpec->id]);

        // Check stock restored
        $this->assertEquals(10, $ramSpec->stock->fresh()->quantity); // 8 + 2
    }

    public function test_destroy_prevents_deletion_if_used_in_station()
    {
        $pcSpec = PcSpec::factory()->create();
        Station::factory()->create(['pc_spec_id' => $pcSpec->id]);

        $response = $this->actingAs($this->user)
            ->delete(route('pcspecs.destroy', $pcSpec));

        $response->assertRedirect(); // Should redirect back
        $this->assertDatabaseHas('pc_specs', ['id' => $pcSpec->id]);
    }

    public function test_update_issue_updates_issue()
    {
        $pcSpec = PcSpec::factory()->create();

        $response = $this->actingAs($this->user)
            ->patch(route('pcspecs.updateIssue', $pcSpec), ['issue' => 'New Issue']);

        $response->assertRedirect();
        $this->assertDatabaseHas('pc_specs', [
            'id' => $pcSpec->id,
            'issue' => 'New Issue',
        ]);
    }
}
