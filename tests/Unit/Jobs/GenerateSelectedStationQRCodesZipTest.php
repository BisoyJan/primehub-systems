<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateSelectedStationQRCodesZip;
use App\Models\Campaign;
use App\Models\Site;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class GenerateSelectedStationQRCodesZipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            array_map('unlink', glob("$tempDir/*"));
        }
    }

    protected function tearDown(): void
    {
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            array_map('unlink', glob("$tempDir/*"));
        }

        parent::tearDown();
    }

    #[Test]
    public function it_can_be_dispatched_to_queue(): void
    {
        Queue::fake();

        $jobId = 'test-selected-st-123';
        $stationIds = [1, 2, 3];
        $job = new GenerateSelectedStationQRCodesZip($jobId, $stationIds, 'png', 256, 0);

        dispatch($job);

        Queue::assertPushed(GenerateSelectedStationQRCodesZip::class);
    }

    #[Test]
    public function it_generates_zip_only_for_selected_stations(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $st1 = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-001',
        ]);
        $st2 = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-002',
        ]);
        $st3 = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-003',
        ]);

        $selectedIds = [$st1->id, $st3->id]; // Only 2 out of 3

        $jobId = 'test-selected-stations';
        $job = new GenerateSelectedStationQRCodesZip($jobId, $selectedIds);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(2, $zip->numFiles);

        $filenames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filenames[] = $zip->getNameIndex($i);
        }

        $this->assertContains('station-ST-001.png', $filenames);
        $this->assertContains('station-ST-003.png', $filenames);
        $this->assertNotContains('station-ST-002.png', $filenames);

        $zip->close();
    }

    #[Test]
    public function it_creates_zip_with_selected_prefix(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-prefix-st';
        $job = new GenerateSelectedStationQRCodesZip($jobId, [$station->id]);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-selected-{$jobId}.zip");
        $this->assertFileExists($zipPath);
    }

    #[Test]
    public function it_generates_svg_format_for_selected_stations(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-SVG',
        ]);

        $jobId = 'test-svg-selected-st';
        $job = new GenerateSelectedStationQRCodesZip($jobId, [$station->id], 'svg');
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals('station-ST-SVG.svg', $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_updates_cache_with_progress_for_selected_stations(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $st1 = Station::factory()->create(['site_id' => $site->id, 'campaign_id' => $campaign->id]);
        $st2 = Station::factory()->create(['site_id' => $site->id, 'campaign_id' => $campaign->id]);

        $jobId = 'test-progress-selected-st';
        $statusKey = "station_qrcode_zip_selected_job:{$jobId}";

        $job = new GenerateSelectedStationQRCodesZip($jobId, [$st1->id, $st2->id]);
        $job->handle();

        $status = Cache::get($statusKey);

        $this->assertIsArray($status);
        $this->assertEquals(100, $status['percent']);
        $this->assertEquals('Finished', $status['status']);
        $this->assertTrue($status['finished']);
    }

    #[Test]
    public function it_handles_empty_selection(): void
    {
        $jobId = 'test-empty-selection-st';
        $job = new GenerateSelectedStationQRCodesZip($jobId, []);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(0, $zip->numFiles);
        $zip->close();
    }

    #[Test]
    public function it_handles_non_existent_station_ids(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $validStation = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-VALID',
        ]);

        $nonExistentIds = [999, 1000];

        $jobId = 'test-invalid-ids-st';
        $job = new GenerateSelectedStationQRCodesZip($jobId, array_merge([$validStation->id], $nonExistentIds));
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        // Should only include the valid station
        $this->assertEquals(1, $zip->numFiles);
        $this->assertEquals('station-ST-VALID.png', $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_stores_correct_download_url_for_selected(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-url-selected-st';
        $statusKey = "station_qrcode_zip_selected_job:{$jobId}";

        $job = new GenerateSelectedStationQRCodesZip($jobId, [$station->id]);
        $job->handle();

        $status = Cache::get($statusKey);

        $this->assertStringContainsString('/stations/qrcode/selected-zip/', $status['downloadUrl']);
        $this->assertStringContainsString($jobId, $status['downloadUrl']);
    }

    #[Test]
    public function it_handles_large_selection(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $stations = Station::factory()->count(10)->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $selectedIds = $stations->pluck('id')->toArray();

        $jobId = 'test-large-selection-st';
        $job = new GenerateSelectedStationQRCodesZip($jobId, $selectedIds);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(10, $zip->numFiles);
        $zip->close();
    }

    #[Test]
    public function it_uses_custom_size_for_selected_stations(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-custom-size-st';
        $customSize = 512;
        $job = new GenerateSelectedStationQRCodesZip($jobId, [$station->id], 'png', $customSize);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-selected-{$jobId}.zip");
        $this->assertFileExists($zipPath);
        $this->assertGreaterThan(0, filesize($zipPath));
    }

    #[Test]
    public function it_creates_temp_directory_if_not_exists(): void
    {
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }

        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-create-dir-st';
        $job = new GenerateSelectedStationQRCodesZip($jobId, [$station->id]);
        $job->handle();

        $this->assertDirectoryExists($tempDir);
    }

    #[Test]
    public function it_includes_station_number_in_filename(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'CUSTOM-ST-456',
        ]);

        $jobId = 'test-filename-st';
        $job = new GenerateSelectedStationQRCodesZip($jobId, [$station->id]);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals('station-CUSTOM-ST-456.png', $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_uses_default_parameters_when_not_specified(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-defaults-st';
        $job = new GenerateSelectedStationQRCodesZip($jobId, [$station->id]);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-selected-{$jobId}.zip");
        $this->assertFileExists($zipPath);
    }
}
