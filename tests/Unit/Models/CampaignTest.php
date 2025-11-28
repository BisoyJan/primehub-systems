<?php

namespace Tests\Unit\Models;

use App\Models\Campaign;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_name_attribute(): void
    {
        $campaign = Campaign::create(['name' => 'Sales Campaign']);

        $this->assertEquals('Sales Campaign', $campaign->name);
    }

    #[Test]
    public function it_has_many_stations(): void
    {
        $campaign = Campaign::factory()->create();
        $site = \App\Models\Site::factory()->create();
        $station = Station::factory()->create([
            'campaign_id' => $campaign->id,
            'site_id' => $site->id,
        ]);

        $this->assertCount(1, $campaign->stations);
        $this->assertTrue($campaign->stations->contains($station));
    }

    #[Test]
    public function it_searches_by_name(): void
    {
        Campaign::factory()->create(['name' => 'Sales Team']);
        Campaign::factory()->create(['name' => 'Support Team']);
        Campaign::factory()->create(['name' => 'Marketing Team']);

        $results = Campaign::search('Sales')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Sales Team', $results->first()->name);
    }

    #[Test]
    public function it_searches_with_partial_match(): void
    {
        Campaign::factory()->create(['name' => 'Customer Service']);
        Campaign::factory()->create(['name' => 'Sales Team']);

        $results = Campaign::search('Service')->get();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Service', $results->first()->name);
    }

    #[Test]
    public function it_returns_all_when_search_is_null(): void
    {
        Campaign::factory()->count(3)->create();

        $results = Campaign::search(null)->get();

        $this->assertCount(3, $results);
    }

    #[Test]
    public function it_returns_all_when_search_is_empty_string(): void
    {
        Campaign::factory()->count(2)->create();

        $results = Campaign::search('')->get();

        $this->assertCount(2, $results);
    }

    #[Test]
    public function it_search_is_case_insensitive(): void
    {
        Campaign::factory()->create(['name' => 'Technical Support']);

        $results = Campaign::search('technical')->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function it_stores_timestamps(): void
    {
        $campaign = Campaign::factory()->create();

        $this->assertNotNull($campaign->created_at);
        $this->assertNotNull($campaign->updated_at);
    }
}
