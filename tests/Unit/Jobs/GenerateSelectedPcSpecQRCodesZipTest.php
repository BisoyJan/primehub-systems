<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateSelectedPcSpecQRCodesZip;
use App\Models\PcSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class GenerateSelectedPcSpecQRCodesZipTest extends TestCase
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

        $jobId = 'test-selected-123';
        $pcIds = [1, 2, 3];
        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, $pcIds, 'png', 256, false);

        dispatch($job);

        Queue::assertPushed(GenerateSelectedPcSpecQRCodesZip::class);
    }

    #[Test]
    public function it_generates_zip_only_for_selected_pc_specs(): void
    {
        $pc1 = PcSpec::factory()->create(['pc_number' => 'PC-001']);
        $pc2 = PcSpec::factory()->create(['pc_number' => 'PC-002']);
        $pc3 = PcSpec::factory()->create(['pc_number' => 'PC-003']);

        $selectedIds = [$pc1->id, $pc3->id]; // Only 2 out of 3

        $jobId = 'test-selected';
        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, $selectedIds, 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(2, $zip->numFiles);

        $filenames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filenames[] = $zip->getNameIndex($i);
        }

        $this->assertContains('PC-001.png', $filenames);
        $this->assertContains('PC-003.png', $filenames);
        $this->assertNotContains('PC-002.png', $filenames);

        $zip->close();
    }

    #[Test]
    public function it_creates_zip_with_selected_prefix(): void
    {
        $pc = PcSpec::factory()->create();

        $jobId = 'test-prefix';
        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, [$pc->id], 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-selected-{$jobId}.zip");
        $this->assertFileExists($zipPath);
    }

    #[Test]
    public function it_generates_svg_format_for_selected_specs(): void
    {
        $pc = PcSpec::factory()->create(['pc_number' => 'PC-SVG']);

        $jobId = 'test-svg-selected';
        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, [$pc->id], 'svg', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals('PC-SVG.svg', $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_updates_cache_with_progress_for_selected(): void
    {
        $pc1 = PcSpec::factory()->create();
        $pc2 = PcSpec::factory()->create();

        $jobId = 'test-progress-selected';
        $statusKey = "qrcode_zip_selected_job:{$jobId}";

        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, [$pc1->id, $pc2->id], 'png', 256, false);
        $job->handle();

        $status = Cache::get($statusKey);

        $this->assertIsArray($status);
        $this->assertEquals(100, $status['percent']);
        $this->assertEquals('ZIP ready', $status['status']);
        $this->assertTrue($status['finished']);
    }

    #[Test]
    public function it_handles_empty_selection(): void
    {
        $jobId = 'test-empty-selection';
        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, [], 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(0, $zip->numFiles);
        $zip->close();
    }

    #[Test]
    public function it_handles_non_existent_pc_ids(): void
    {
        $validPc = PcSpec::factory()->create(['pc_number' => 'PC-VALID']);
        $nonExistentIds = [999, 1000];

        $jobId = 'test-invalid-ids';
        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, array_merge([$validPc->id], $nonExistentIds), 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        // Should only include the valid PC
        $this->assertEquals(1, $zip->numFiles);
        $this->assertEquals('PC-VALID.png', $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_generates_with_metadata_for_selected_specs(): void
    {
        $pc = PcSpec::factory()->create([
            'pc_number' => 'PC-META-SELECT',
            'manufacturer' => 'HP',
            'memory_type' => 'DDR5',
        ]);

        $jobId = 'test-meta-selected';
        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, [$pc->id], 'png', 256, true);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-selected-{$jobId}.zip");
        $this->assertFileExists($zipPath);
        $this->assertGreaterThan(0, filesize($zipPath));
    }

    #[Test]
    public function it_stores_correct_download_url_for_selected(): void
    {
        $pc = PcSpec::factory()->create();

        $jobId = 'test-url-selected';
        $statusKey = "qrcode_zip_selected_job:{$jobId}";

        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, [$pc->id], 'png', 256, false);
        $job->handle();

        $status = Cache::get($statusKey);

        $this->assertStringContainsString('/pcspecs/qrcode/selected-zip/', $status['downloadUrl']);
        $this->assertStringContainsString($jobId, $status['downloadUrl']);
    }

    #[Test]
    public function it_uses_pc_id_fallback_for_null_pc_number(): void
    {
        $pc = PcSpec::factory()->create(['pc_number' => null]);

        $jobId = 'test-null-number';
        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, [$pc->id], 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $expectedFilename = "PC-{$pc->id}.png";
        $this->assertEquals($expectedFilename, $zip->getNameIndex(0));
        $zip->close();
    }

    #[Test]
    public function it_handles_large_selection(): void
    {
        $pcSpecs = PcSpec::factory()->count(10)->create();
        $selectedIds = $pcSpecs->pluck('id')->toArray();

        $jobId = 'test-large-selection';
        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, $selectedIds, 'png', 256, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-selected-{$jobId}.zip");
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertEquals(10, $zip->numFiles);
        $zip->close();
    }

    #[Test]
    public function it_uses_custom_size_for_selected_specs(): void
    {
        $pc = PcSpec::factory()->create(['pc_number' => 'PC-CUSTOM']);

        $jobId = 'test-custom-size';
        $customSize = 512;
        $job = new GenerateSelectedPcSpecQRCodesZip($jobId, [$pc->id], 'png', $customSize, false);
        $job->handle();

        $zipPath = storage_path("app/temp/pc-qrcodes-selected-{$jobId}.zip");
        $this->assertFileExists($zipPath);
        $this->assertGreaterThan(0, filesize($zipPath));
    }
}
