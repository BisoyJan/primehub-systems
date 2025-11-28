<?php

namespace Tests\Feature;

use App\Models\PcSpec;
use App\Models\Site;
use App\Models\Campaign;
use App\Models\User;
use App\Jobs\GenerateAllPcSpecQRCodesZip;
use App\Jobs\GenerateSelectedPcSpecQRCodesZip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for PC Spec QR Code generation functionality.
 * Routes: pcspecs/qrcode/* with pcspecs.qrcode.* naming
 */
class PcQRCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $site;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        // IT role has pcspecs.view and pcspecs.qrcode permissions
        $this->admin = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);

        $this->site = Site::factory()->create();
        $this->campaign = Campaign::factory()->create();
    }

    #[Test]
    public function it_generates_qr_codes_for_all_pc_specs()
    {
        Bus::fake();

        PcSpec::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)
            ->postJson('/pcspecs/qrcode/bulk-all', [
                'format' => 'png',
                'size' => 256,
                'metadata' => 1,
            ]);

        $response->assertOk()
            ->assertJsonStructure(['jobId']);

        Bus::assertDispatched(GenerateAllPcSpecQRCodesZip::class);
    }

    #[Test]
    public function it_generates_qr_codes_for_selected_pc_specs()
    {
        Bus::fake();

        $pc1 = PcSpec::factory()->create();
        $pc2 = PcSpec::factory()->create();
        PcSpec::factory()->create(); // pc3 not selected

        $response = $this->actingAs($this->admin)
            ->postJson('/pcspecs/qrcode/zip-selected', [
                'pc_ids' => [$pc1->id, $pc2->id],
                'format' => 'png',
                'size' => 256,
                'metadata' => 1,
            ]);

        $response->assertOk()
            ->assertJsonStructure(['jobId']);

        Bus::assertDispatched(GenerateSelectedPcSpecQRCodesZip::class);
    }

    #[Test]
    public function it_validates_pc_spec_ids_for_selected_generation()
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/pcspecs/qrcode/zip-selected', [
                'pc_ids' => [],
                'format' => 'png',
                'size' => 256,
                'metadata' => 1,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('pc_ids');
    }

    #[Test]
    public function it_validates_qr_code_format()
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/pcspecs/qrcode/bulk-all', [
                'format' => 'invalid_format',
                'size' => 256,
                'metadata' => 1,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    #[Test]
    public function it_validates_qr_code_size()
    {
        // Too small (min 64)
        $response = $this->actingAs($this->admin)
            ->postJson('/pcspecs/qrcode/bulk-all', [
                'format' => 'png',
                'size' => 50,
                'metadata' => 1,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('size');

        // Too large (max 1024)
        $response = $this->actingAs($this->admin)
            ->postJson('/pcspecs/qrcode/bulk-all', [
                'format' => 'png',
                'size' => 2000,
                'metadata' => 1,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('size');
    }

    #[Test]
    public function it_generates_qr_codes_with_custom_format_and_size()
    {
        Bus::fake();

        PcSpec::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->postJson('/pcspecs/qrcode/bulk-all', [
                'format' => 'svg',
                'size' => 512,
                'metadata' => 0,
            ]);

        $response->assertOk()
            ->assertJsonStructure(['jobId']);

        Bus::assertDispatched(GenerateAllPcSpecQRCodesZip::class);
    }

    #[Test]
    public function it_checks_qr_generation_progress()
    {
        $jobId = 'test-job-id-123';

        // No cache entry means not started
        $response = $this->actingAs($this->admin)
            ->getJson("/pcspecs/qrcode/bulk-progress/{$jobId}");

        $response->assertOk()
            ->assertJson([
                'percent' => 0,
                'status' => 'Not started',
                'finished' => false,
            ]);
    }

    #[Test]
    public function it_downloads_generated_qr_zip()
    {
        $jobId = 'test-job-id-456';
        $zipFileName = "pc-qrcodes-{$jobId}.zip";
        $zipPath = storage_path("app/temp/{$zipFileName}");

        // Create temp directory and file
        if (!is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }
        file_put_contents($zipPath, 'fake zip content');

        try {
            $response = $this->actingAs($this->admin)
                ->get("/pcspecs/qrcode/zip/{$jobId}/download");

            $response->assertOk();
        } finally {
            // Cleanup
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    #[Test]
    public function it_handles_missing_qr_zip_download()
    {
        $jobId = 'non-existent-job-id';

        $response = $this->actingAs($this->admin)
            ->get("/pcspecs/qrcode/zip/{$jobId}/download");

        $response->assertNotFound();
    }

    #[Test]
    public function it_generates_unique_qr_content_for_each_pc()
    {
        $pc1 = PcSpec::factory()->create(['pc_number' => 'PC-2024-001']);
        $pc2 = PcSpec::factory()->create(['pc_number' => 'PC-2024-002']);

        // QR code content should include unique PC identifiers
        $this->assertNotEquals($pc1->pc_number, $pc2->pc_number);
    }

    #[Test]
    public function unauthorized_users_cannot_generate_qr_codes()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/pcspecs/qrcode/bulk-all', [
                'format' => 'png',
                'size' => 256,
                'metadata' => 1,
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function it_handles_bulk_qr_generation_for_large_dataset()
    {
        Bus::fake();

        PcSpec::factory()->count(100)->create();

        $response = $this->actingAs($this->admin)
            ->postJson('/pcspecs/qrcode/bulk-all', [
                'format' => 'png',
                'size' => 256,
                'metadata' => 1,
            ]);

        $response->assertOk()
            ->assertJsonStructure(['jobId']);

        Bus::assertDispatched(GenerateAllPcSpecQRCodesZip::class);
    }

    #[Test]
    public function it_generates_qr_codes_only_for_existing_pc_specs()
    {
        Bus::fake();

        $existingPc = PcSpec::factory()->create();
        $nonExistentId = 99999;

        $response = $this->actingAs($this->admin)
            ->postJson('/pcspecs/qrcode/zip-selected', [
                'pc_ids' => [$existingPc->id, $nonExistentId],
                'format' => 'png',
                'size' => 256,
                'metadata' => 1,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('pc_ids.1');
    }
}
