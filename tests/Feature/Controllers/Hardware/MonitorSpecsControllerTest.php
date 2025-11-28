<?php

namespace Tests\Feature\Controllers\Hardware;

use App\Models\MonitorSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonitorSpecsControllerTest extends TestCase
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

    public function test_index_displays_monitor_specs()
    {
        MonitorSpec::factory()->count(3)->create();

        $this->get(route('monitorspecs.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/MonitorSpecs/Index')
                ->has('monitorspecs.data', 3)
            );
    }

    public function test_store_creates_monitor_spec()
    {
        $data = [
            'brand' => 'Dell',
            'model' => 'P2419H',
            'screen_size' => 24.0,
            'resolution' => '1920x1080',
            'panel_type' => 'IPS',
            'ports' => ['HDMI', 'DisplayPort', 'VGA'],
            'notes' => 'Standard office monitor',
        ];

        $this->post(route('monitorspecs.store'), $data)
            ->assertRedirect(route('monitorspecs.index'))
            ->assertSessionHas('message')
            ->assertSessionHas('type', 'success');

        // Cast ports to json for database assertion if needed, but assertDatabaseHas handles array casting if model casts it?
        // Laravel's assertDatabaseHas doesn't automatically cast arrays to JSON.
        // So we need to json_encode ports.
        $dbData = $data;
        $dbData['ports'] = $this->castAsJson($data['ports']);

        $this->assertDatabaseHas('monitor_specs', $dbData);
    }

    public function test_update_updates_monitor_spec()
    {
        $monitorSpec = MonitorSpec::factory()->create();

        $data = [
            'brand' => 'LG',
            'model' => '27GL850',
            'screen_size' => 27.0,
            'resolution' => '2560x1440',
            'panel_type' => 'IPS',
            'ports' => ['HDMI', 'DisplayPort'],
            'notes' => 'Gaming monitor',
        ];

        $this->put(route('monitorspecs.update', $monitorSpec), $data)
            ->assertRedirect(route('monitorspecs.index'))
            ->assertSessionHas('message')
            ->assertSessionHas('type', 'success');

        $dbData = $data;
        $dbData['ports'] = $this->castAsJson($data['ports']);

        $this->assertDatabaseHas('monitor_specs', array_merge(['id' => $monitorSpec->id], $dbData));
    }

    public function test_destroy_deletes_monitor_spec()
    {
        $monitorSpec = MonitorSpec::factory()->create();

        // Set stock quantity to 0 to allow deletion
        $monitorSpec->stock->update(['quantity' => 0]);

        $response = $this->delete(route('monitorspecs.destroy', $monitorSpec));

        $response->assertRedirect(route('monitorspecs.index'))
            ->assertSessionHas('message', 'Monitor specification deleted successfully.')
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseMissing('monitor_specs', ['id' => $monitorSpec->id]);
    }
}
