<?php

namespace Tests\Feature\Controllers\Station;

use App\Models\Campaign;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StationControllerTest extends TestCase
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

    public function test_index_displays_stations()
    {
        Station::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->get(route('stations.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/Index')
                ->has('stations.data', 3)
            );
    }

    public function test_create_displays_create_form()
    {
        $response = $this->actingAs($this->user)
            ->get(route('stations.create'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/Create')
                ->has('sites')
                ->has('campaigns')
                ->has('pcSpecs')
            );
    }

    public function test_store_creates_station()
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $pcSpec = PcSpec::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'ST-TEST-001',
            'campaign_id' => $campaign->id,
            'status' => 'Active',
            'monitor_type' => 'single',
            'pc_spec_id' => $pcSpec->id,
        ];

        $response = $this->actingAs($this->user)
            ->post(route('stations.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('stations', [
            'station_number' => 'ST-TEST-001',
            'site_id' => $site->id,
        ]);
    }

    public function test_store_bulk_creates_multiple_stations()
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'starting_number' => 'BULK-001',
            'campaign_id' => $campaign->id,
            'status' => 'Active',
            'monitor_type' => 'single',
            'quantity' => 3,
            'increment_type' => 'number',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('stations.bulk'), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('stations', ['station_number' => 'BULK-001']);
        $this->assertDatabaseHas('stations', ['station_number' => 'BULK-002']);
        $this->assertDatabaseHas('stations', ['station_number' => 'BULK-003']);
    }

    public function test_edit_displays_edit_form()
    {
        $station = Station::factory()->create();

        $response = $this->actingAs($this->user)
            ->get(route('stations.edit', $station));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/Edit')
                ->has('station')
                ->where('station.id', $station->id)
            );
    }

    public function test_update_updates_station()
    {
        $station = Station::factory()->create();
        $newSite = Site::factory()->create();

        $data = [
            'site_id' => $newSite->id,
            'station_number' => $station->station_number,
            'campaign_id' => $station->campaign_id,
            'status' => 'Inactive',
            'monitor_type' => 'single',
            'pc_spec_id' => $station->pc_spec_id,
        ];

        $response = $this->actingAs($this->user)
            ->put(route('stations.update', $station), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'site_id' => $newSite->id,
            'status' => 'Inactive',
        ]);
    }

    public function test_destroy_deletes_station()
    {
        $station = Station::factory()->create();

        $response = $this->actingAs($this->user)
            ->delete(route('stations.destroy', $station));

        $response->assertRedirect();
        $this->assertDatabaseMissing('stations', ['id' => $station->id]);
    }
}
