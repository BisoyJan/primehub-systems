<?php

namespace Tests\Feature;

use App\Models\PcSpec;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for PC Spec CRUD operations.
 */
class PcSpecCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected RamSpec $ram;
    protected DiskSpec $disk;
    protected ProcessorSpec $processor;

    protected function setUp(): void
    {
        parent::setUp();

        // IT role has pcspecs permissions
        $this->admin = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);

        // Create hardware components with stock
        $this->ram = RamSpec::factory()->create([
            'type' => 'DDR4',
            'capacity_gb' => 16,
        ]);
        $this->ram->stock()->create(['quantity' => 10]);

        $this->disk = DiskSpec::factory()->create([
            'capacity_gb' => 512,
            'interface' => 'NVMe',
        ]);
        $this->disk->stock()->create(['quantity' => 10]);

        $this->processor = ProcessorSpec::factory()->create();
        $this->processor->stock()->create(['quantity' => 10]);
    }

    #[Test]
    public function it_displays_pc_specs_index_page()
    {
        $pcSpecs = PcSpec::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('pcspecs.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Index')
                ->has('pcspecs.data', 5)
            );
    }

    #[Test]
    public function it_displays_create_pc_spec_form()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('pcspecs.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Create')
                ->has('ramOptions')
                ->has('diskOptions')
                ->has('processorOptions')
            );
    }

    #[Test]
    public function it_creates_single_pc_spec_with_components()
    {
        $data = [
            'manufacturer' => 'ASUS',
            'model' => 'PRIME B450M-A',
            'memory_type' => 'DDR4',
            'm2_slots' => 2,
            'sata_ports' => 4,
            'ram_specs' => [$this->ram->id => 2], // 2 RAM sticks
            'disk_specs' => [$this->disk->id => 1],
            'processor_spec_id' => $this->processor->id,
            'quantity' => 1,
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('pcspecs.store'), $data);

        $response->assertRedirect(route('pcspecs.index'));

        $this->assertDatabaseHas('pc_specs', [
            'manufacturer' => 'ASUS',
            'model' => 'PRIME B450M-A',
            'memory_type' => 'DDR4',
        ]);

        $pcSpec = PcSpec::first();
        $this->assertCount(1, $pcSpec->ramSpecs()->get());
        $this->assertEquals(2, $pcSpec->ramSpecs->first()->pivot->quantity);
        $this->assertCount(1, $pcSpec->diskSpecs()->get());
        $this->assertCount(1, $pcSpec->processorSpecs()->get());
    }

    #[Test]
    public function it_creates_multiple_pc_specs_with_quantity()
    {
        $data = [
            'manufacturer' => 'Gigabyte',
            'model' => 'B550M DS3H',
            'memory_type' => 'DDR4',
            'm2_slots' => 2,
            'sata_ports' => 4,
            'ram_specs' => [$this->ram->id => 2],
            'disk_specs' => [$this->disk->id => 1],
            'processor_spec_id' => $this->processor->id,
            'quantity' => 3, // Create 3 identical PCs
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('pcspecs.store'), $data);

        $response->assertRedirect(route('pcspecs.index'));

        $this->assertEquals(3, PcSpec::count());
        $this->assertEquals(4, $this->ram->stock->fresh()->quantity); // 10 - (3 × 2) = 4
        $this->assertEquals(7, $this->disk->stock->fresh()->quantity); // 10 - 3 = 7
    }

    #[Test]
    public function it_validates_required_fields_on_create()
    {
        $response = $this->actingAs($this->admin)
            ->post(route('pcspecs.store'), []);

        $response->assertSessionHasErrors([
            'manufacturer',
            'model',
            'memory_type',
        ]);
    }

    #[Test]
    public function it_displays_edit_pc_spec_form()
    {
        $pcSpec = PcSpec::factory()->create();
        $pcSpec->ramSpecs()->attach($this->ram->id, ['quantity' => 2]);
        $pcSpec->diskSpecs()->attach($this->disk->id);
        $pcSpec->processorSpecs()->attach($this->processor->id);

        $response = $this->actingAs($this->admin)
            ->get(route('pcspecs.edit', $pcSpec));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Edit')
                ->where('pcspec.id', $pcSpec->id)
                ->has('ramOptions')
                ->has('diskOptions')
                ->has('processorOptions')
            );
    }

    #[Test]
    public function it_updates_pc_spec_basic_info()
    {
        $pcSpec = PcSpec::factory()->create([
            'manufacturer' => 'ASUS',
            'model' => 'OLD-MODEL',
        ]);
        $pcSpec->processorSpecs()->attach($this->processor->id);

        $response = $this->actingAs($this->admin)
            ->put(route('pcspecs.update', $pcSpec), [
                'manufacturer' => 'ASUS',
                'model' => 'NEW-MODEL',
                'memory_type' => $pcSpec->memory_type,
                'm2_slots' => $pcSpec->m2_slots,
                'sata_ports' => $pcSpec->sata_ports,
                'ram_specs' => [],
                'disk_specs' => [],
                'processor_spec_id' => $this->processor->id,
            ]);

        $response->assertRedirect(route('pcspecs.index'));

        $this->assertDatabaseHas('pc_specs', [
            'id' => $pcSpec->id,
            'model' => 'NEW-MODEL',
        ]);
    }

    #[Test]
    public function it_updates_pc_spec_components()
    {
        $pcSpec = PcSpec::factory()->create(['memory_type' => 'DDR4']);
        $oldRam = RamSpec::factory()->create(['type' => 'DDR4']);
        $oldRam->stock()->create(['quantity' => 10]);

        $pcSpec->ramSpecs()->attach($oldRam->id, ['quantity' => 2]);
        $pcSpec->processorSpecs()->attach($this->processor->id);

        $newRam = RamSpec::factory()->create(['type' => 'DDR4']);
        $newRam->stock()->create(['quantity' => 10]);

        $response = $this->actingAs($this->admin)
            ->put(route('pcspecs.update', $pcSpec), [
                'manufacturer' => $pcSpec->manufacturer,
                'model' => $pcSpec->model,
                'memory_type' => $pcSpec->memory_type,
                'm2_slots' => $pcSpec->m2_slots,
                'sata_ports' => $pcSpec->sata_ports,
                'ram_specs' => [$newRam->id => 2], // Switch to new RAM
                'disk_specs' => [],
                'processor_spec_id' => $this->processor->id,
            ]);

        $response->assertRedirect(route('pcspecs.index'));

        $pcSpec->refresh();
        $this->assertTrue($pcSpec->ramSpecs->contains($newRam));
        $this->assertFalse($pcSpec->ramSpecs->contains($oldRam));

        // Stock should be restored for old RAM
        $this->assertEquals(12, $oldRam->stock->fresh()->quantity); // 10 + 2 returned
    }

    #[Test]
    public function it_deletes_pc_spec_and_restores_stock()
    {
        $pcSpec = PcSpec::factory()->create();
        $pcSpec->ramSpecs()->attach($this->ram->id, ['quantity' => 2]);
        $pcSpec->diskSpecs()->attach($this->disk->id);
        $pcSpec->processorSpecs()->attach($this->processor->id);

        $initialRamStock = $this->ram->stock->quantity;
        $initialDiskStock = $this->disk->stock->quantity;
        $initialProcessorStock = $this->processor->stock->quantity;

        $response = $this->actingAs($this->admin)
            ->delete(route('pcspecs.destroy', $pcSpec));

        $response->assertRedirect(route('pcspecs.index'));

        $this->assertDatabaseMissing('pc_specs', ['id' => $pcSpec->id]);

        // Stock should be restored
        $this->assertEquals($initialRamStock + 2, $this->ram->stock->fresh()->quantity);
        $this->assertEquals($initialDiskStock + 1, $this->disk->stock->fresh()->quantity);
        $this->assertEquals($initialProcessorStock + 1, $this->processor->stock->fresh()->quantity);
    }

    #[Test]
    public function it_displays_pc_spec_details()
    {
        $pcSpec = PcSpec::factory()->create();
        $pcSpec->ramSpecs()->attach($this->ram->id, ['quantity' => 2]);
        $pcSpec->diskSpecs()->attach($this->disk->id);
        $pcSpec->processorSpecs()->attach($this->processor->id);

        $response = $this->actingAs($this->admin)
            ->get(route('pcspecs.show', $pcSpec));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Show')
                ->where('pcspec.id', $pcSpec->id)
                ->has('pcspec.ram_specs', 1)
                ->has('pcspec.disk_specs', 1)
                ->has('pcspec.processor_specs', 1)
            );
    }

    #[Test]
    public function it_filters_pc_specs_by_search()
    {
        PcSpec::factory()->create(['model' => 'PRIME B450M']);
        PcSpec::factory()->create(['model' => 'ROG STRIX B550']);
        PcSpec::factory()->create(['pc_number' => 'PC-2024-001']);

        $response = $this->actingAs($this->admin)
            ->get(route('pcspecs.index', ['search' => 'PRIME']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Index')
                ->has('pcspecs.data', 1)
                ->where('pcspecs.data.0.model', 'PRIME B450M')
            );
    }

    #[Test]
    public function unauthorized_users_cannot_create_pc_specs()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($user)
            ->post(route('pcspecs.store'), [
                'manufacturer' => 'ASUS',
                'model' => 'TEST',
                'memory_type' => 'DDR4',
                'm2_slots' => 2,
                'sata_ports' => 4,
                'processor_spec_id' => $this->processor->id,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('pc_specs', ['manufacturer' => 'ASUS', 'model' => 'TEST']);
    }

    #[Test]
    public function unauthorized_users_cannot_delete_pc_specs()
    {
        $pcSpec = PcSpec::factory()->create();
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($user)
            ->delete(route('pcspecs.destroy', $pcSpec));

        $response->assertForbidden();
        $this->assertDatabaseHas('pc_specs', ['id' => $pcSpec->id]);
    }
}
