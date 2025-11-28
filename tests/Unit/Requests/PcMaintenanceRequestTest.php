<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\PcMaintenanceRequest;
use App\Models\Campaign;
use App\Models\Site;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PcMaintenanceRequestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_authorizes_all_users(): void
    {
        $request = new PcMaintenanceRequest();

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_validates_with_complete_create_data(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $stations = Station::factory()->count(3)->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $request = PcMaintenanceRequest::create('/test', 'POST');

        $data = [
            'station_ids' => $stations->pluck('id')->toArray(),
            'last_maintenance_date' => now()->format('Y-m-d'),
            'next_due_date' => now()->addMonths(3)->format('Y-m-d'),
            'maintenance_type' => 'Preventive',
            'notes' => 'Regular maintenance check',
            'performed_by' => 'IT Team',
            'status' => 'completed',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_station_ids_on_create(): void
    {
        $request = PcMaintenanceRequest::create('/test', 'POST');

        $data = [
            'last_maintenance_date' => now()->format('Y-m-d'),
            'next_due_date' => now()->addMonths(3)->format('Y-m-d'),
            'status' => 'completed',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('station_ids', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_at_least_one_station_id_on_create(): void
    {
        $request = PcMaintenanceRequest::create('/test', 'POST');

        $data = [
            'station_ids' => [],
            'last_maintenance_date' => now()->format('Y-m-d'),
            'next_due_date' => now()->addMonths(3)->format('Y-m-d'),
            'status' => 'completed',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('station_ids', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_station_id_on_update(): void
    {
        $request = PcMaintenanceRequest::create('/test', 'PUT');

        $data = [
            'last_maintenance_date' => now()->format('Y-m-d'),
            'next_due_date' => now()->addMonths(3)->format('Y-m-d'),
            'status' => 'completed',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('station_id', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_with_complete_update_data(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $request = PcMaintenanceRequest::create('/test', 'PUT');

        $data = [
            'station_id' => $station->id,
            'last_maintenance_date' => now()->format('Y-m-d'),
            'next_due_date' => now()->addMonths(3)->format('Y-m-d'),
            'maintenance_type' => 'Corrective',
            'notes' => 'Fixed hardware issue',
            'performed_by' => 'IT Support',
            'status' => 'completed',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_next_due_date_after_last_maintenance(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $request = PcMaintenanceRequest::create('/test', 'PUT');

        $data = [
            'station_id' => $station->id,
            'last_maintenance_date' => now()->format('Y-m-d'),
            'next_due_date' => now()->subDays(1)->format('Y-m-d'),
            'status' => 'completed',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('next_due_date', $validator->errors()->toArray());
    }

    #[Test]
    public function it_only_accepts_valid_status_values(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $request = PcMaintenanceRequest::create('/test', 'PUT');

        $data = [
            'station_id' => $station->id,
            'last_maintenance_date' => now()->format('Y-m-d'),
            'next_due_date' => now()->addMonths(3)->format('Y-m-d'),
            'status' => 'invalid',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_all_valid_status_values(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $request = PcMaintenanceRequest::create('/test', 'PUT');
        $validStatuses = ['completed', 'pending', 'overdue'];

        foreach ($validStatuses as $status) {
            $data = [
                'station_id' => $station->id,
                'last_maintenance_date' => now()->format('Y-m-d'),
                'next_due_date' => now()->addMonths(3)->format('Y-m-d'),
                'status' => $status,
            ];

            $validator = Validator::make($data, $request->rules());

            $this->assertFalse($validator->fails(), "Status {$status} should be valid");
        }
    }

    #[Test]
    public function it_allows_nullable_optional_fields(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $request = PcMaintenanceRequest::create('/test', 'PUT');

        $data = [
            'station_id' => $station->id,
            'last_maintenance_date' => now()->format('Y-m-d'),
            'next_due_date' => now()->addMonths(3)->format('Y-m-d'),
            'maintenance_type' => null,
            'notes' => null,
            'performed_by' => null,
            'status' => 'completed',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_limits_notes_to_1000_characters(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $request = PcMaintenanceRequest::create('/test', 'PUT');

        $data = [
            'station_id' => $station->id,
            'last_maintenance_date' => now()->format('Y-m-d'),
            'next_due_date' => now()->addMonths(3)->format('Y-m-d'),
            'notes' => str_repeat('a', 1001),
            'status' => 'completed',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('notes', $validator->errors()->toArray());
    }

    #[Test]
    public function it_has_custom_attributes(): void
    {
        $request = new PcMaintenanceRequest();

        $attributes = $request->attributes();

        $this->assertEquals('stations', $attributes['station_ids']);
        $this->assertEquals('station', $attributes['station_ids.*']);
        $this->assertEquals('last maintenance date', $attributes['last_maintenance_date']);
        $this->assertEquals('next due date', $attributes['next_due_date']);
        $this->assertEquals('maintenance type', $attributes['maintenance_type']);
        $this->assertEquals('performed by', $attributes['performed_by']);
    }

    #[Test]
    public function it_has_custom_messages(): void
    {
        $request = new PcMaintenanceRequest();

        $messages = $request->messages();

        $this->assertStringContainsString('after the last maintenance date', $messages['next_due_date.after']);
        $this->assertStringContainsString('at least one station', $messages['station_ids.min']);
        $this->assertStringContainsString('completed, pending, or overdue', $messages['status.in']);
    }
}
