<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\StationRequest;
use App\Models\Campaign;
use App\Models\Site;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StationRequestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_authorizes_all_users(): void
    {
        $request = new StationRequest();

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_validates_with_complete_data(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'single',
        ];

        $request = new StationRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_all_mandatory_fields(): void
    {
        $request = new StationRequest();
        $data = [];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('site_id', $errors);
        $this->assertArrayHasKey('station_number', $errors);
        $this->assertArrayHasKey('campaign_id', $errors);
        $this->assertArrayHasKey('status', $errors);
        $this->assertArrayHasKey('monitor_type', $errors);
    }

    #[Test]
    public function it_requires_site_to_exist(): void
    {
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => 99999,
            'station_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'single',
        ];

        $request = new StationRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('site_id', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_campaign_to_exist(): void
    {
        $site = Site::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'campaign_id' => 99999,
            'status' => 'active',
            'monitor_type' => 'single',
        ];

        $request = new StationRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('campaign_id', $validator->errors()->toArray());
    }

    #[Test]
    public function it_only_accepts_single_or_dual_monitor_type(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'triple',
        ];

        $request = new StationRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('monitor_type', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_monitor_ids_structure(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'dual',
            'monitor_ids' => 'not-an-array',
        ];

        $request = new StationRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('monitor_ids', $validator->errors()->toArray());
    }

    #[Test]
    public function it_allows_nullable_optional_fields(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'single',
            'pc_spec_id' => null,
            'monitor_ids' => null,
        ];

        $request = new StationRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_has_custom_attributes(): void
    {
        $request = new StationRequest();

        $attributes = $request->attributes();

        $this->assertEquals('site', $attributes['site_id']);
        $this->assertEquals('station number', $attributes['station_number']);
        $this->assertEquals('campaign', $attributes['campaign_id']);
        $this->assertEquals('monitor type', $attributes['monitor_type']);
        $this->assertEquals('PC spec', $attributes['pc_spec_id']);
        $this->assertEquals('monitors', $attributes['monitor_ids']);
    }

    #[Test]
    public function it_has_custom_messages(): void
    {
        $request = new StationRequest();

        $messages = $request->messages();

        $this->assertStringContainsString('already been used', $messages['station_number.unique']);
    }

    #[Test]
    public function it_enforces_unique_station_number(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $existingStation = Station::factory()->create([
            'site_id' => $site->id,
            'station_number' => 'PC-001',
        ]);

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'single',
        ];

        $request = new StationRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('station_number', $validator->errors()->toArray());
    }
}
