<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateAllStationQRCodesZip;
use App\Models\Campaign;
use App\Models\Site;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class GenerateAllStationQRCodesZipTest extends TestCase
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

        $jobId = 'test-station-123';
        $job = new GenerateAllStationQRCodesZip($jobId, 'png', 256, 0);

        dispatch($job);

        Queue::assertPushed(GenerateAllStationQRCodesZip::class);
    }

    #[Test]
    public function it_generates_zip_file_for_all_stations(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        Station::factory()->count(3)->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-all-stations';
        $job = new GenerateAllStationQRCodesZip($jobId);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-{$jobId}.zip");
        $this->assertFileExists($zipPath);
    }

    #[Test]
    public function it_generates_png_qr_codes_by_default(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-001',
        ]);

        $jobId = 'test-png-station';
        $job = new GenerateAllStationQRCodesZip($jobId, 'png');
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(1, $zip->numFiles);
        $this->assertEquals('station-ST-001.png', $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_generates_svg_qr_codes_when_specified(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-002',
        ]);

        $jobId = 'test-svg-station';
        $job = new GenerateAllStationQRCodesZip($jobId, 'svg');
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals('station-ST-002.svg', $zip->getNameIndex(0));
        $zip->close();
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
        Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-create-dir';
        $job = new GenerateAllStationQRCodesZip($jobId);
        $job->handle();

        $this->assertDirectoryExists($tempDir);
    }

    #[Test]
    public function it_updates_cache_with_progress(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        Station::factory()->count(2)->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-progress-station';
        $statusKey = "station_qrcode_zip_job:{$jobId}";

        $job = new GenerateAllStationQRCodesZip($jobId);
        $job->handle();

        $status = Cache::get($statusKey);

        $this->assertIsArray($status);
        $this->assertEquals(100, $status['percent']);
        $this->assertEquals('Finished', $status['status']);
        $this->assertTrue($status['finished']);
        $this->assertNotNull($status['downloadUrl']);
    }

    #[Test]
    public function it_generates_qr_code_with_scan_url(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-SCAN',
        ]);

        $jobId = 'test-scan-url';
        $job = new GenerateAllStationQRCodesZip($jobId);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-{$jobId}.zip");
        $this->assertFileExists($zipPath);
        $this->assertGreaterThan(0, filesize($zipPath));
    }

    #[Test]
    public function it_handles_multiple_stations(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-001',
        ]);
        Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-002',
        ]);
        Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'ST-003',
        ]);

        $jobId = 'test-multiple-stations';
        $job = new GenerateAllStationQRCodesZip($jobId);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(3, $zip->numFiles);
        $zip->close();
    }

    #[Test]
    public function it_uses_custom_size_parameter(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-size-station';
        $customSize = 512;
        $job = new GenerateAllStationQRCodesZip($jobId, 'png', $customSize);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-{$jobId}.zip");
        $this->assertFileExists($zipPath);
    }

    #[Test]
    public function it_stores_download_url_in_cache(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-url-station';
        $statusKey = "station_qrcode_zip_job:{$jobId}";

        $job = new GenerateAllStationQRCodesZip($jobId);
        $job->handle();

        $status = Cache::get($statusKey);

        $this->assertStringContainsString('/stations/qrcode/zip/', $status['downloadUrl']);
        $this->assertStringContainsString($jobId, $status['downloadUrl']);
    }

    #[Test]
    public function it_handles_empty_stations_list(): void
    {
        // No stations created

        $jobId = 'test-empty-stations';
        $job = new GenerateAllStationQRCodesZip($jobId);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(0, $zip->numFiles);
        $zip->close();
    }

    #[Test]
    public function it_overwrites_existing_zip_file(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-overwrite-station';
        $zipPath = storage_path("app/temp/station-qrcodes-{$jobId}.zip");

        // Create first ZIP
        $job1 = new GenerateAllStationQRCodesZip($jobId);
        $job1->handle();
        $firstSize = filesize($zipPath);

        // Create another station and regenerate
        Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $job2 = new GenerateAllStationQRCodesZip($jobId);
        $job2->handle();
        $secondSize = filesize($zipPath);

        // Second ZIP should be different
        $this->assertNotEquals($firstSize, $secondSize);
    }

    #[Test]
    public function it_includes_station_number_in_filename(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'station_number' => 'CUSTOM-123',
        ]);

        $jobId = 'test-filename';
        $job = new GenerateAllStationQRCodesZip($jobId);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals('station-CUSTOM-123.png', $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_uses_default_parameters_when_not_specified(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $jobId = 'test-defaults';
        $job = new GenerateAllStationQRCodesZip($jobId);
        $job->handle();

        $zipPath = storage_path("app/temp/station-qrcodes-{$jobId}.zip");
        $this->assertFileExists($zipPath);
    }
}
