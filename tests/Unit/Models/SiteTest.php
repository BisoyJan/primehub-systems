<?php

namespace Tests\Unit\Models;

use App\Models\Site;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_name_attribute(): void
    {
        $site = Site::create(['name' => 'Main Office']);

        $this->assertEquals('Main Office', $site->name);
    }

    #[Test]
    public function it_has_many_stations(): void
    {
        $site = Site::factory()->create();
        $campaign = \App\Models\Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $this->assertCount(1, $site->stations);
        $this->assertTrue($site->stations->contains($station));
    }

    #[Test]
    public function it_searches_by_name(): void
    {
        Site::factory()->create(['name' => 'Manila Office']);
        Site::factory()->create(['name' => 'Cebu Office']);
        Site::factory()->create(['name' => 'Davao Office']);

        $results = Site::search('Manila')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Manila Office', $results->first()->name);
    }

    #[Test]
    public function it_searches_with_partial_match(): void
    {
        Site::factory()->create(['name' => 'North Tower']);
        Site::factory()->create(['name' => 'South Tower']);

        $results = Site::search('Tower')->get();

        $this->assertCount(2, $results);
    }

    #[Test]
    public function it_returns_all_when_search_is_null(): void
    {
        Site::factory()->count(3)->create();

        $results = Site::search(null)->get();

        $this->assertCount(3, $results);
    }

    #[Test]
    public function it_returns_all_when_search_is_empty_string(): void
    {
        Site::factory()->count(2)->create();

        $results = Site::search('')->get();

        $this->assertCount(2, $results);
    }

    #[Test]
    public function it_search_is_case_insensitive(): void
    {
        Site::factory()->create(['name' => 'Building A']);

        $results = Site::search('building')->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function it_stores_timestamps(): void
    {
        $site = Site::factory()->create();

        $this->assertNotNull($site->created_at);
        $this->assertNotNull($site->updated_at);
    }
}
