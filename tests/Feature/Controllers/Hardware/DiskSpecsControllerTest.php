<?php

namespace Tests\Feature\Controllers\Hardware;

use App\Models\DiskSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DiskSpecsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]));
    }

    public function test_index_displays_disk_specs()
    {
        DiskSpec::factory()->count(3)->create();

        $this->get(route('diskspecs.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/DiskSpecs/Index')
                ->has('diskspecs.data', 3)
            );
    }

    public function test_store_creates_disk_spec()
    {
        $data = [
            'manufacturer' => 'Samsung',
            'model' => '970 EVO Plus',
            'capacity_gb' => 1000,
            'interface' => 'NVMe',
            'drive_type' => 'SSD',
            'sequential_read_mb' => 3500,
            'sequential_write_mb' => 3300,
            'stock_quantity' => 10,
        ];

        $this->post(route('diskspecs.store'), $data)
            ->assertRedirect(route('diskspecs.index'))
            ->assertSessionHas('message')
            ->assertSessionHas('type', 'success');

        unset($data['stock_quantity']);
        $this->assertDatabaseHas('disk_specs', $data);
    }

    public function test_update_updates_disk_spec()
    {
        $diskSpec = DiskSpec::factory()->create();

        $data = [
            'manufacturer' => 'Western Digital',
            'model' => 'WD Blue',
            'capacity_gb' => 2000,
            'interface' => 'SATA',
            'drive_type' => 'HDD',
            'sequential_read_mb' => 150,
            'sequential_write_mb' => 130,
        ];

        $this->put(route('diskspecs.update', $diskSpec), $data)
            ->assertRedirect(route('diskspecs.index'))
            ->assertSessionHas('message')
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseHas('disk_specs', array_merge(['id' => $diskSpec->id], $data));
    }

    public function test_destroy_deletes_disk_spec()
    {
        $diskSpec = DiskSpec::factory()->create();

        $this->delete(route('diskspecs.destroy', $diskSpec))
            ->assertRedirect(route('diskspecs.index'))
            ->assertSessionHas('message')
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseMissing('disk_specs', ['id' => $diskSpec->id]);
    }
}
