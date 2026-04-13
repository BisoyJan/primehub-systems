<?php

namespace Tests\Feature\Controllers\Hardware;

use App\Models\PcSpec;
use App\Models\ProcessorSpec;
use App\Models\Station;
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
                ->has('processorOptions')
            );
    }

    public function test_store_creates_pc_spec()
    {
        $processorSpec = ProcessorSpec::factory()->create();

        $data = [
            'pc_number' => 'PC-TEST-001',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex',
            'memory_type' => 'DDR4',
            'm2_slots' => 1,
            'sata_ports' => 2,
            'ram_gb' => 16,
            'disk_gb' => 512,
            'available_ports' => 'USB 3.0 x4, HDMI x1',
            'processor_mode' => 'existing',
            'processor_spec_id' => $processorSpec->id,
            'quantity' => 1,
        ];

        $response = $this->actingAs($this->user)
            ->post(route('pcspecs.store'), $data);

        $response->assertRedirect(route('pcspecs.index'));
        $this->assertDatabaseHas('pc_specs', [
            'pc_number' => 'PC-TEST-001',
            'ram_gb' => 16,
            'disk_gb' => 512,
        ]);

        $pcSpec = PcSpec::where('pc_number', 'PC-TEST-001')->first();
        $this->assertTrue($pcSpec->processorSpecs->contains($processorSpec));
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

    public function test_update_updates_pc_spec()
    {
        $pcSpec = PcSpec::factory()->create();

        $processorSpec = ProcessorSpec::factory()->create();
        $pcSpec->processorSpecs()->attach($processorSpec);

        $data = [
            'manufacturer' => 'Updated Manufacturer',
            'model' => 'Updated Model',
            'memory_type' => 'DDR5',
            'm2_slots' => 2,
            'sata_ports' => 4,
            'ram_gb' => 32,
            'disk_gb' => 1024,
            'available_ports' => 'USB-C x2, HDMI x2',
            'processor_mode' => 'existing',
            'processor_spec_id' => $processorSpec->id,
        ];

        $response = $this->actingAs($this->user)
            ->put(route('pcspecs.update', $pcSpec), $data);

        $response->assertRedirect(route('pcspecs.index'));

        $this->assertDatabaseHas('pc_specs', [
            'id' => $pcSpec->id,
            'manufacturer' => 'Updated Manufacturer',
            'model' => 'Updated Model',
            'ram_gb' => 32,
            'disk_gb' => 1024,
        ]);
    }

    public function test_destroy_deletes_pc_spec()
    {
        $pcSpec = PcSpec::factory()->create();

        $response = $this->actingAs($this->user)
            ->delete(route('pcspecs.destroy', $pcSpec));

        $response->assertRedirect(route('pcspecs.index'));
        $this->assertDatabaseMissing('pc_specs', ['id' => $pcSpec->id]);
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

    public function test_scan_result_displays_pc_spec_details()
    {
        $processorSpec = ProcessorSpec::factory()->create();
        $pcSpec = PcSpec::factory()->create();
        $pcSpec->processorSpecs()->attach($processorSpec);

        $response = $this->actingAs($this->user)
            ->get(route('pcspecs.scanResult', $pcSpec));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/ScanResult')
                ->has('pcspec')
                ->where('pcspec.id', $pcSpec->id)
                ->has('pcspec.processorSpecs')
                ->has('pcspec.stations')
            );
    }

    public function test_scan_result_shows_error_for_invalid_id()
    {
        $response = $this->actingAs($this->user)
            ->get(route('pcspecs.scanResult', 99999));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/ScanResult')
                ->where('error', 'PC Spec not found.')
            );
    }

    public function test_scan_result_includes_assigned_stations()
    {
        $pcSpec = PcSpec::factory()->create();
        Station::factory()->count(2)->create(['pc_spec_id' => $pcSpec->id]);

        $response = $this->actingAs($this->user)
            ->get(route('pcspecs.scanResult', $pcSpec));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/ScanResult')
                ->has('pcspec.stations', 2)
            );
    }
}
