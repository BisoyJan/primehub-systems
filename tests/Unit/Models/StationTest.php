<?php

namespace Tests\Unit\Models;

use App\Models\Campaign;
use App\Models\MonitorSpec;
use App\Models\PcSpec;
use App\Models\PcTransfer;
use App\Models\Site;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $pcSpec = PcSpec::factory()->create();

        $station = Station::factory()->create([
            'site_id' => $site->id,
            'station_number' => 'ST-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'Dual Monitor',
            'pc_spec_id' => $pcSpec->id,
        ]);

        $this->assertEquals('ST-001', $station->station_number);
        $this->assertEquals($site->id, $station->site_id);
        $this->assertEquals($campaign->id, $station->campaign_id);
        $this->assertEquals('active', $station->status);
        $this->assertEquals('Dual Monitor', $station->monitor_type);
        $this->assertEquals($pcSpec->id, $station->pc_spec_id);
    }

    #[Test]
    public function it_casts_integer_attributes(): void
    {
        $station = Station::factory()->create();

        $this->assertIsInt($station->site_id);
        $this->assertIsInt($station->campaign_id);
    }

    #[Test]
    public function it_has_site_relationship(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        $this->assertNotNull($station->site);
        $this->assertEquals($site->id, $station->site->id);
        $this->assertEquals($site->name, $station->site->name);
    }

    #[Test]
    public function it_has_campaign_relationship(): void
    {
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create(['campaign_id' => $campaign->id]);

        $this->assertNotNull($station->campaign);
        $this->assertEquals($campaign->id, $station->campaign->id);
    }

    #[Test]
    public function it_has_pc_spec_relationship(): void
    {
        $pcSpec = PcSpec::factory()->create();
        $station = Station::factory()->create(['pc_spec_id' => $pcSpec->id]);

        $this->assertNotNull($station->pcSpec);
        $this->assertEquals($pcSpec->id, $station->pcSpec->id);
    }

    #[Test]
    public function it_has_monitors_relationship(): void
    {
        $station = Station::factory()->create();
        $monitor = MonitorSpec::factory()->create();

        $station->monitors()->attach($monitor->id, ['quantity' => 2]);

        $this->assertTrue($station->monitors()->exists());
        $this->assertEquals($monitor->id, $station->monitors->first()->id);
        $this->assertEquals(2, $station->monitors->first()->pivot->quantity);
    }

    #[Test]
    public function it_has_transfers_from_relationship(): void
    {
        $station = Station::factory()->create();
        $transfer = PcTransfer::factory()->create(['from_station_id' => $station->id]);

        $this->assertTrue($station->transfersFrom()->exists());
        $this->assertEquals($transfer->id, $station->transfersFrom->first()->id);
    }

    #[Test]
    public function it_has_transfers_to_relationship(): void
    {
        $station = Station::factory()->create();
        $transfer = PcTransfer::factory()->create(['to_station_id' => $station->id]);

        $this->assertTrue($station->transfersTo()->exists());
        $this->assertEquals($transfer->id, $station->transfersTo->first()->id);
    }

    #[Test]
    public function it_scopes_search_by_station_number(): void
    {
        Station::factory()->create(['station_number' => 'ST-001']);
        Station::factory()->create(['station_number' => 'ST-002']);
        Station::factory()->create(['station_number' => 'DK-003']);

        $results = Station::search('ST-0')->get();

        $this->assertEquals(2, $results->count());
    }

    #[Test]
    public function it_scopes_search_by_site_name(): void
    {
        $site1 = Site::factory()->create(['name' => 'Manila Office']);
        $site2 = Site::factory()->create(['name' => 'Cebu Office']);

        Station::factory()->create(['site_id' => $site1->id]);
        Station::factory()->create(['site_id' => $site2->id]);

        $results = Station::search('Manila')->get();

        $this->assertEquals(1, $results->count());
        $this->assertEquals($site1->id, $results->first()->site_id);
    }

    #[Test]
    public function it_scopes_search_by_campaign_name(): void
    {
        $campaign1 = Campaign::factory()->create(['name' => 'Campaign A']);
        $campaign2 = Campaign::factory()->create(['name' => 'Campaign B']);

        Station::factory()->create(['campaign_id' => $campaign1->id]);
        Station::factory()->create(['campaign_id' => $campaign2->id]);

        $results = Station::search('Campaign A')->get();

        $this->assertEquals(1, $results->count());
        $this->assertEquals($campaign1->id, $results->first()->campaign_id);
    }

    #[Test]
    public function it_returns_all_stations_when_search_is_null(): void
    {
        Station::factory()->count(3)->create();

        $results = Station::search(null)->get();

        $this->assertEquals(3, $results->count());
    }

    #[Test]
    public function it_filters_by_site(): void
    {
        $site1 = Site::factory()->create();
        $site2 = Site::factory()->create();

        Station::factory()->create(['site_id' => $site1->id]);
        Station::factory()->create(['site_id' => $site1->id]);
        Station::factory()->create(['site_id' => $site2->id]);

        $results = Station::filterBySite($site1->id)->get();

        $this->assertEquals(2, $results->count());
    }

    #[Test]
    public function it_returns_all_stations_when_site_filter_is_null(): void
    {
        Station::factory()->count(3)->create();

        $results = Station::filterBySite(null)->get();

        $this->assertEquals(3, $results->count());
    }

    #[Test]
    public function it_filters_by_campaign(): void
    {
        $campaign1 = Campaign::factory()->create();
        $campaign2 = Campaign::factory()->create();

        Station::factory()->create(['campaign_id' => $campaign1->id]);
        Station::factory()->create(['campaign_id' => $campaign2->id]);

        $results = Station::filterByCampaign($campaign1->id)->get();

        $this->assertEquals(1, $results->count());
        $this->assertEquals($campaign1->id, $results->first()->campaign_id);
    }

    #[Test]
    public function it_filters_by_status(): void
    {
        Station::factory()->create(['status' => 'active']);
        Station::factory()->create(['status' => 'active']);
        Station::factory()->create(['status' => 'inactive']);

        $results = Station::filterByStatus('active')->get();

        $this->assertEquals(2, $results->count());
    }

    #[Test]
    public function it_returns_all_stations_when_status_filter_is_null(): void
    {
        Station::factory()->count(3)->create();

        $results = Station::filterByStatus(null)->get();

        $this->assertEquals(3, $results->count());
    }

    #[Test]
    public function it_chains_multiple_scopes(): void
    {
        $site = Site::factory()->create(['name' => 'Manila Office']);
        $campaign = Campaign::factory()->create();

        Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'status' => 'active',
        ]);

        Station::factory()->create([
            'site_id' => $site->id,
            'status' => 'inactive',
        ]);

        $results = Station::filterBySite($site->id)
            ->filterByCampaign($campaign->id)
            ->filterByStatus('active')
            ->get();

        $this->assertEquals(1, $results->count());
    }
}
