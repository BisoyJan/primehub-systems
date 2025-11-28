<?php

namespace Tests\Feature\Station;

use App\Models\Campaign;
use App\Models\MonitorSpec;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StationCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Site $site;
    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $this->site = Site::factory()->create(['name' => 'Test Site']);
        $this->campaign = Campaign::factory()->create(['name' => 'Test Campaign']);
    }

    #[Test]
    public function station_index_page_can_be_rendered(): void
    {
        $response = $this->actingAs($this->admin)->get(route('stations.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Station/Index')
            ->has('stations.data')
            ->has('filters')
        );
    }

    #[Test]
    public function station_index_displays_stations_with_relationships(): void
    {
        $pcSpec = PcSpec::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'pc_spec_id' => $pcSpec->id,
            'station_number' => 'TEST001',
        ]);

        $response = $this->actingAs($this->admin)->get(route('stations.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Station/Index')
            ->has('stations.data', 1)
            ->has('stations.data.0', fn (Assert $item) => $item
                ->where('id', $station->id)
                ->where('station_number', 'TEST001')
                ->has('site')
                ->has('campaign')
                ->has('pc_spec_details')
                ->etc()
            )
        );
    }

    #[Test]
    public function station_create_page_can_be_rendered(): void
    {
        $response = $this->actingAs($this->admin)->get(route('stations.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Station/Create')
            ->has('sites')
            ->has('campaigns')
            ->has('pcSpecs')
            ->has('monitorSpecs')
        );
    }

    #[Test]
    public function station_can_be_created_with_valid_data(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.store'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'TEST001',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $this->assertDatabaseHas('stations', [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'TEST001',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $response->assertSessionHas('flash.message', 'Station saved');
        $response->assertSessionHas('flash.type', 'success');
    }

    #[Test]
    public function station_creation_requires_site_id(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.store'), [
            'campaign_id' => $this->campaign->id,
            'station_number' => 'TEST001',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $response->assertSessionHasErrors('site_id');
    }

    #[Test]
    public function station_creation_requires_campaign_id(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.store'), [
            'site_id' => $this->site->id,
            'station_number' => 'TEST001',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $response->assertSessionHasErrors('campaign_id');
    }

    #[Test]
    public function station_number_must_be_unique(): void
    {
        Station::factory()->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'TEST001',
        ]);

        $response = $this->actingAs($this->admin)->post(route('stations.store'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'TEST001',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $response->assertSessionHasErrors('station_number');
    }

    #[Test]
    public function station_can_be_created_with_pc_spec(): void
    {
        $pcSpec = PcSpec::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('stations.store'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'TEST001',
            'status' => 'Active',
            'monitor_type' => 'single',
            'pc_spec_id' => $pcSpec->id,
        ]);

        $this->assertDatabaseHas('stations', [
            'station_number' => 'TEST001',
            'pc_spec_id' => $pcSpec->id,
        ]);
    }

    #[Test]
    public function station_can_be_created_with_monitors(): void
    {
        $monitor = MonitorSpec::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('stations.store'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'TEST001',
            'status' => 'Active',
            'monitor_type' => 'single',
            'monitor_ids' => [
                ['id' => $monitor->id, 'quantity' => 1]
            ],
        ]);

        $station = Station::where('station_number', 'TEST001')->first();
        $this->assertNotNull($station);
        $this->assertTrue($station->monitors()->where('monitor_specs.id', $monitor->id)->exists());
    }

    #[Test]
    public function station_edit_page_can_be_rendered(): void
    {
        $station = Station::factory()->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('stations.edit', $station));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Station/Edit')
            ->has('station')
            ->has('sites')
            ->has('campaigns')
        );
    }

    #[Test]
    public function station_can_be_updated(): void
    {
        $station = Station::factory()->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'TEST001',
            'status' => 'Active',
        ]);

        $newSite = Site::factory()->create(['name' => 'New Site']);

        $response = $this->actingAs($this->admin)->put(route('stations.update', $station), [
            'site_id' => $newSite->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'TEST002',
            'status' => 'Inactive',
            'monitor_type' => 'single',
        ]);

        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'site_id' => $newSite->id,
            'station_number' => 'TEST002',
            'status' => 'Inactive',
        ]);
    }

    #[Test]
    public function station_can_be_deleted(): void
    {
        $station = Station::factory()->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('stations.destroy', $station));

        $this->assertDatabaseMissing('stations', ['id' => $station->id]);
        $response->assertSessionHas('flash.message', 'Station deleted');
    }

    #[Test]
    public function station_index_can_be_filtered_by_site(): void
    {
        $site1 = Site::factory()->create(['name' => 'Site 1']);
        $site2 = Site::factory()->create(['name' => 'Site 2']);

        Station::factory()->create([
            'site_id' => $site1->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'SITE1-001',
        ]);

        Station::factory()->create([
            'site_id' => $site2->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'SITE2-001',
        ]);

        $response = $this->actingAs($this->admin)->get(route('stations.index', ['site' => $site1->id]));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Station/Index')
            ->has('stations.data', 1)
            ->where('stations.data.0.station_number', 'SITE1-001')
        );
    }

    #[Test]
    public function station_index_can_be_filtered_by_campaign(): void
    {
        $campaign1 = Campaign::factory()->create(['name' => 'Campaign 1']);
        $campaign2 = Campaign::factory()->create(['name' => 'Campaign 2']);

        Station::factory()->create([
            'site_id' => $this->site->id,
            'campaign_id' => $campaign1->id,
            'station_number' => 'CAMP1-001',
        ]);

        Station::factory()->create([
            'site_id' => $this->site->id,
            'campaign_id' => $campaign2->id,
            'station_number' => 'CAMP2-001',
        ]);

        $response = $this->actingAs($this->admin)->get(route('stations.index', ['campaign' => $campaign1->id]));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Station/Index')
            ->has('stations.data', 1)
            ->where('stations.data.0.station_number', 'CAMP1-001')
        );
    }

    #[Test]
    public function station_index_can_be_searched(): void
    {
        Station::factory()->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'SEARCH001',
        ]);

        Station::factory()->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'OTHER002',
        ]);

        $response = $this->actingAs($this->admin)->get(route('stations.index', ['search' => 'SEARCH']));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Station/Index')
            ->has('stations.data', 1)
            ->where('stations.data.0.station_number', 'SEARCH001')
        );
    }

    #[Test]
    public function unauthorized_users_cannot_access_station_management(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($user)->get(route('stations.index'));

        $response->assertStatus(403);
    }
}
