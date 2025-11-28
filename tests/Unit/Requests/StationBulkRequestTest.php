<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\StationBulkRequest;
use App\Models\Campaign;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StationBulkRequestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_authorizes_all_users(): void
    {
        $request = new StationBulkRequest();

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_validates_with_complete_data(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'starting_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'single',
            'quantity' => 10,
            'increment_type' => 'number',
        ];

        $request = new StationBulkRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_all_mandatory_fields(): void
    {
        $request = new StationBulkRequest();
        $data = [];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('site_id', $errors);
        $this->assertArrayHasKey('starting_number', $errors);
        $this->assertArrayHasKey('campaign_id', $errors);
        $this->assertArrayHasKey('status', $errors);
        $this->assertArrayHasKey('monitor_type', $errors);
        $this->assertArrayHasKey('quantity', $errors);
        $this->assertArrayHasKey('increment_type', $errors);
    }

    #[Test]
    public function it_requires_quantity_to_be_at_least_1(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'starting_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'single',
            'quantity' => 0,
            'increment_type' => 'number',
        ];

        $request = new StationBulkRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('quantity', $validator->errors()->toArray());
    }

    #[Test]
    public function it_limits_quantity_to_100(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'starting_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'single',
            'quantity' => 101,
            'increment_type' => 'number',
        ];

        $request = new StationBulkRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('quantity', $validator->errors()->toArray());
    }

    #[Test]
    public function it_only_accepts_valid_increment_types(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'starting_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'single',
            'quantity' => 10,
            'increment_type' => 'invalid',
        ];

        $request = new StationBulkRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('increment_type', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_all_valid_increment_types(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $validTypes = ['number', 'letter', 'both'];

        foreach ($validTypes as $type) {
            $data = [
                'site_id' => $site->id,
                'starting_number' => 'PC-001',
                'campaign_id' => $campaign->id,
                'status' => 'active',
                'monitor_type' => 'single',
                'quantity' => 10,
                'increment_type' => $type,
            ];

            $request = new StationBulkRequest();
            $validator = Validator::make($data, $request->rules());

            $this->assertFalse($validator->fails(), "Increment type {$type} should be valid");
        }
    }

    #[Test]
    public function it_only_accepts_single_or_dual_monitor_type(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'starting_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'triple',
            'quantity' => 10,
            'increment_type' => 'number',
        ];

        $request = new StationBulkRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('monitor_type', $validator->errors()->toArray());
    }

    #[Test]
    public function it_allows_nullable_pc_spec_fields(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'starting_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'single',
            'quantity' => 10,
            'increment_type' => 'number',
            'pc_spec_id' => null,
            'pc_spec_ids' => null,
        ];

        $request = new StationBulkRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_validates_pc_spec_ids_as_array(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $data = [
            'site_id' => $site->id,
            'starting_number' => 'PC-001',
            'campaign_id' => $campaign->id,
            'status' => 'active',
            'monitor_type' => 'single',
            'quantity' => 10,
            'increment_type' => 'number',
            'pc_spec_ids' => 'not-an-array',
        ];

        $request = new StationBulkRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('pc_spec_ids', $validator->errors()->toArray());
    }

    #[Test]
    public function it_has_custom_attributes(): void
    {
        $request = new StationBulkRequest();

        $attributes = $request->attributes();

        $this->assertEquals('site', $attributes['site_id']);
        $this->assertEquals('starting station number', $attributes['starting_number']);
        $this->assertEquals('campaign', $attributes['campaign_id']);
        $this->assertEquals('monitor type', $attributes['monitor_type']);
        $this->assertEquals('PC spec', $attributes['pc_spec_id']);
        $this->assertEquals('PC specs', $attributes['pc_spec_ids']);
        $this->assertEquals('increment type', $attributes['increment_type']);
    }
}
