<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateAllPcSpecQRCodesZip;
use App\Models\PcSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class GenerateAllPcSpecQRCodesZipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean up temp directory before each test
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            array_map('unlink', glob("$tempDir/*"));
        }
    }

    protected function tearDown(): void
    {
        // Clean up after tests
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

        $jobId = 'test-job-123';
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);

        dispatch($job);

        Queue::assertPushed(GenerateAllPcSpecQRCodesZip::class);
    }

    #[Test]
    public function it_creates_temp_directory_if_not_exists(): void
    {
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }

        PcSpec::factory()->create(['pc_number' => 'PC-001']);

        $jobId = 'test-job-123';
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job->handle();

        $this->assertDirectoryExists($tempDir);
    }

    #[Test]
    public function it_generates_zip_file_for_all_pc_specs(): void
    {
        PcSpec::factory()->count(3)->create();

        $jobId = 'test-job-123';
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-{$jobId}.zip");
        $this->assertFileExists($zipPath);
    }

    #[Test]
    public function it_generates_png_qr_codes_by_default(): void
    {
        $pcSpec = PcSpec::factory()->create(['pc_number' => 'PC-001']);

        $jobId = 'test-job-png';
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(1, $zip->numFiles);
        $this->assertEquals('PC-001.png', $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_generates_svg_qr_codes_when_specified(): void
    {
        $pcSpec = PcSpec::factory()->create(['pc_number' => 'PC-002']);

        $jobId = 'test-job-svg';
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'svg', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals('PC-002.svg', $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_uses_pc_id_when_pc_number_is_null(): void
    {
        $pcSpec = PcSpec::factory()->create(['pc_number' => null]);

        $jobId = 'test-job-no-number';
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $expectedFilename = "PC-{$pcSpec->id}.png";
        $this->assertEquals($expectedFilename, $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_updates_cache_with_progress(): void
    {
        PcSpec::factory()->count(2)->create();

        $jobId = 'test-job-progress';
        $statusKey = "qrcode_zip_job:{$jobId}";

        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job->handle();

        $status = Cache::get($statusKey);

        $this->assertIsArray($status);
        $this->assertEquals(100, $status['percent']);
        $this->assertEquals('ZIP ready', $status['status']);
        $this->assertTrue($status['finished']);
        $this->assertArrayHasKey('downloadUrl', $status);
    }

    #[Test]
    public function it_generates_qr_code_with_route_url_when_no_metadata(): void
    {
        $pcSpec = PcSpec::factory()->create(['pc_number' => 'PC-TEST']);

        $jobId = 'test-job-no-meta';
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-{$jobId}.zip");
        $this->assertFileExists($zipPath);

        // Verify QR code was created (file size > 0)
        $this->assertGreaterThan(0, filesize($zipPath));
    }

    #[Test]
    public function it_generates_qr_code_with_metadata_when_enabled(): void
    {
        $pcSpec = PcSpec::factory()->create([
            'pc_number' => 'PC-META',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex',
            'form_factor' => 'Tower',
            'memory_type' => 'DDR4',
        ]);

        $jobId = 'test-job-meta';
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, true);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-{$jobId}.zip");
        $this->assertFileExists($zipPath);
    }

    #[Test]
    public function it_handles_multiple_pc_specs(): void
    {
        PcSpec::factory()->create(['pc_number' => 'PC-001']);
        PcSpec::factory()->create(['pc_number' => 'PC-002']);
        PcSpec::factory()->create(['pc_number' => 'PC-003']);

        $jobId = 'test-job-multiple';
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(3, $zip->numFiles);
        $zip->close();
    }

    #[Test]
    public function it_uses_custom_size_parameter(): void
    {
        PcSpec::factory()->create(['pc_number' => 'PC-SIZE']);

        $jobId = 'test-job-size';
        $customSize = 512;
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', $customSize, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-{$jobId}.zip");
        $this->assertFileExists($zipPath);
    }

    #[Test]
    public function it_stores_download_url_in_cache(): void
    {
        PcSpec::factory()->create();

        $jobId = 'test-job-url';
        $statusKey = "qrcode_zip_job:{$jobId}";

        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job->handle();

        $status = Cache::get($statusKey);

        $this->assertStringContainsString('/pcspecs/qrcode/zip/', $status['downloadUrl']);
        $this->assertStringContainsString($jobId, $status['downloadUrl']);
    }

    #[Test]
    public function it_handles_empty_pc_specs_list(): void
    {
        // No PC specs created

        $jobId = 'test-job-empty';
        $job = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-{$jobId}.zip");
        $this->assertFileExists($zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $this->assertEquals(0, $zip->numFiles);
            $zip->close();
        }
    }

    #[Test]
    public function it_overwrites_existing_zip_file(): void
    {
        PcSpec::factory()->create(['pc_number' => 'PC-OVER']);

        $jobId = 'test-job-overwrite';
        $zipPath = storage_path("app/temp/pc-qrcodes-{$jobId}.zip");

        // Create first ZIP
        $job1 = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job1->handle();
        $firstSize = filesize($zipPath);

        // Create another PC and regenerate
        PcSpec::factory()->create(['pc_number' => 'PC-NEW']);
        $job2 = new GenerateAllPcSpecQRCodesZip($jobId, 'png', 256, false);
        $job2->handle();
        $secondSize = filesize($zipPath);

        // Second ZIP should be different (2 files vs 1)
        $this->assertNotEquals($firstSize, $secondSize);
    }
}
