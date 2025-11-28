<?php

namespace Tests\Feature\Station;

use App\Models\Campaign;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StationBulkOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Site $site;
    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        // IT role has stations.bulk permission
        $this->admin = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);

        $this->site = Site::factory()->create(['name' => 'Test Site']);
        $this->campaign = Campaign::factory()->create(['name' => 'Test Campaign']);
    }

    #[Test]
    public function bulk_stations_can_be_created_with_number_increment(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST001',
            'quantity' => 3,
            'increment_type' => 'number',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $this->assertDatabaseHas('stations', ['station_number' => 'TEST001']);
        $this->assertDatabaseHas('stations', ['station_number' => 'TEST002']);
        $this->assertDatabaseHas('stations', ['station_number' => 'TEST003']);

        $response->assertSessionHas('flash.message');
        $response->assertSessionHasNoErrors();
    }

    #[Test]
    public function bulk_stations_can_be_created_with_letter_increment(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TESTA',
            'quantity' => 3,
            'increment_type' => 'letter',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        // Letter increment appends numbers (TESTA1, TESTA2, TESTA3)
        $this->assertDatabaseHas('stations', ['station_number' => 'TESTA1']);
        $this->assertDatabaseHas('stations', ['station_number' => 'TESTA2']);
        $this->assertDatabaseHas('stations', ['station_number' => 'TESTA3']);
    }

    #[Test]
    public function bulk_stations_can_be_created_with_both_increment(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST1A',
            'quantity' => 3,
            'increment_type' => 'both',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $this->assertDatabaseHas('stations', ['station_number' => 'TEST1A']);
        $this->assertDatabaseHas('stations', ['station_number' => 'TEST2B']);
        $this->assertDatabaseHas('stations', ['station_number' => 'TEST3C']);
    }

    #[Test]
    public function bulk_creation_requires_quantity(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST001',
            'increment_type' => 'number',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $response->assertSessionHasErrors('quantity');
    }

    #[Test]
    public function bulk_creation_quantity_must_be_at_least_one(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST001',
            'quantity' => 0,
            'increment_type' => 'number',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $response->assertSessionHasErrors('quantity');
    }

    #[Test]
    public function bulk_creation_quantity_cannot_exceed_maximum(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST001',
            'quantity' => 101, // Assuming max is 100
            'increment_type' => 'number',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $response->assertSessionHasErrors('quantity');
    }

    #[Test]
    public function bulk_creation_prevents_duplicate_station_numbers(): void
    {
        // Create existing station
        Station::factory()->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'station_number' => 'TEST002',
        ]);

        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST001',
            'quantity' => 3,
            'increment_type' => 'number',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $response->assertSessionHasErrors('starting_number');
    }

    #[Test]
    public function bulk_stations_can_be_created_with_pc_spec(): void
    {
        $pcSpec = PcSpec::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST001',
            'quantity' => 2,
            'increment_type' => 'number',
            'status' => 'Active',
            'monitor_type' => 'single',
            'pc_spec_ids' => [$pcSpec->id],
        ]);

        $this->assertDatabaseHas('stations', [
            'station_number' => 'TEST001',
            'pc_spec_id' => $pcSpec->id,
        ]);
    }

    #[Test]
    public function bulk_stations_distribute_pc_specs_when_multiple_provided(): void
    {
        $pcSpec1 = PcSpec::factory()->create();
        $pcSpec2 = PcSpec::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST001',
            'quantity' => 4,
            'increment_type' => 'number',
            'status' => 'Active',
            'monitor_type' => 'single',
            'pc_spec_ids' => [$pcSpec1->id, $pcSpec2->id],
        ]);

        // Should alternate between specs
        $station1 = Station::where('station_number', 'TEST001')->first();
        $station2 = Station::where('station_number', 'TEST002')->first();

        $this->assertEquals($pcSpec1->id, $station1->pc_spec_id);
        $this->assertEquals($pcSpec2->id, $station2->pc_spec_id);
    }

    #[Test]
    public function bulk_creation_validates_increment_type(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST001',
            'quantity' => 2,
            'increment_type' => 'invalid',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $response->assertSessionHasErrors('increment_type');
    }

    #[Test]
    public function bulk_creation_requires_status(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST001',
            'quantity' => 2,
            'increment_type' => 'number',
            'monitor_type' => 'single',
        ]);

        $response->assertSessionHasErrors('status');
    }

    #[Test]
    public function unauthorized_users_cannot_bulk_create_stations(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($user)->post(route('stations.bulk'), [
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'starting_number' => 'TEST001',
            'quantity' => 2,
            'increment_type' => 'number',
            'status' => 'Active',
            'monitor_type' => 'single',
        ]);

        $response->assertStatus(403);
    }
}
