<?php

namespace Tests\Feature\Station;

use App\Jobs\GenerateAllStationQRCodesZip;
use App\Jobs\GenerateSelectedStationQRCodesZip;
use App\Models\Campaign;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StationQRCodeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Site $site;
    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        // IT role has stations.qrcode permission
        $this->admin = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);

        $this->site = Site::factory()->create();
        $this->campaign = Campaign::factory()->create();
    }

    #[Test]
    public function bulk_all_qr_codes_dispatches_job(): void
    {
        Queue::fake();

        Station::factory()->count(3)->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
        ]);

        $response = $this->actingAs($this->admin)->post('/stations/qrcode/bulk-all', [
            'format' => 'png',
            'size' => 256,
            'metadata' => 0,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['jobId']);

        Queue::assertPushed(GenerateAllStationQRCodesZip::class);
    }

    #[Test]
    public function selected_qr_codes_dispatches_job(): void
    {
        Queue::fake();

        $stations = Station::factory()->count(3)->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
        ]);

        $stationIds = $stations->pluck('id')->toArray();

        $response = $this->actingAs($this->admin)->post('/stations/qrcode/zip-selected', [
            'station_ids' => $stationIds,
            'format' => 'png',
            'size' => 256,
            'metadata' => 0,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['jobId']);

        Queue::assertPushed(GenerateSelectedStationQRCodesZip::class);
    }

    #[Test]
    public function qr_code_generation_validates_format(): void
    {
        $response = $this->actingAs($this->admin)->post('/stations/qrcode/bulk-all', [
            'format' => 'invalid',
            'size' => 256,
            'metadata' => 0,
        ]);

        $response->assertSessionHasErrors('format');
    }

    #[Test]
    public function qr_code_generation_validates_size(): void
    {
        $response = $this->actingAs($this->admin)->post('/stations/qrcode/bulk-all', [
            'format' => 'png',
            'size' => 50, // Too small
            'metadata' => 0,
        ]);

        $response->assertSessionHasErrors('size');
    }

    #[Test]
    public function qr_code_generation_validates_size_maximum(): void
    {
        $response = $this->actingAs($this->admin)->post('/stations/qrcode/bulk-all', [
            'format' => 'png',
            'size' => 2000, // Too large
            'metadata' => 0,
        ]);

        $response->assertSessionHasErrors('size');
    }

    #[Test]
    public function selected_qr_codes_requires_station_ids(): void
    {
        $response = $this->actingAs($this->admin)->post('/stations/qrcode/zip-selected', [
            'format' => 'png',
            'size' => 256,
            'metadata' => 0,
        ]);

        $response->assertSessionHasErrors('station_ids');
    }

    #[Test]
    public function selected_qr_codes_validates_station_ids_array(): void
    {
        $response = $this->actingAs($this->admin)->post('/stations/qrcode/zip-selected', [
            'station_ids' => 'not-an-array',
            'format' => 'png',
            'size' => 256,
            'metadata' => 0,
        ]);

        $response->assertSessionHasErrors('station_ids');
    }

    #[Test]
    public function qr_code_bulk_progress_returns_status(): void
    {
        $jobId = 'test-job-id';

        $response = $this->actingAs($this->admin)->get("/stations/qrcode/bulk-progress/{$jobId}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'percent',
            'status',
            'finished',
        ]);
    }

    #[Test]
    public function unauthorized_users_cannot_generate_qr_codes(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($user)->post('/stations/qrcode/bulk-all', [
            'format' => 'png',
            'size' => 256,
            'metadata' => 0,
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function qr_code_supports_svg_format(): void
    {
        Queue::fake();

        Station::factory()->count(2)->create([
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
        ]);

        $response = $this->actingAs($this->admin)->post('/stations/qrcode/bulk-all', [
            'format' => 'svg',
            'size' => 256,
            'metadata' => 0,
        ]);

        $response->assertStatus(200);
        Queue::assertPushed(GenerateAllStationQRCodesZip::class);
    }
}
