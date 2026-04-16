<?php

namespace Tests\Unit\Models;

use App\Models\PcSpec;
use App\Models\PcTransfer;
use App\Models\ProcessorSpec;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PcSpecTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $pcSpec = PcSpec::factory()->create([
            'pc_number' => 'PC-001',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex 7090',
            'memory_type' => 'DDR4',
            'ram_gb' => 32,
            'disk_gb' => 512,
            'available_ports' => 'USB 3.0 x4, HDMI x1',
            'issue' => 'Screen flickering',
        ]);

        $this->assertEquals('PC-001', $pcSpec->pc_number);
        $this->assertEquals('Dell', $pcSpec->manufacturer);
        $this->assertEquals('OptiPlex 7090', $pcSpec->model);
        $this->assertEquals(32, $pcSpec->ram_gb);
        $this->assertEquals(512, $pcSpec->disk_gb);
        $this->assertEquals('USB 3.0 x4, HDMI x1', $pcSpec->available_ports);
        $this->assertEquals('Screen flickering', $pcSpec->issue);
    }

    #[Test]
    public function it_casts_integer_attributes(): void
    {
        $pcSpec = PcSpec::factory()->create([
            'ram_gb' => '16',
            'disk_gb' => '512',
        ]);

        $this->assertIsInt($pcSpec->ram_gb);
        $this->assertIsInt($pcSpec->disk_gb);
    }

    #[Test]
    public function it_has_processor_specs_relationship(): void
    {
        $pcSpec = PcSpec::factory()->create();
        $processorSpec = ProcessorSpec::factory()->create();

        $pcSpec->processorSpecs()->attach($processorSpec->id);

        $this->assertTrue($pcSpec->processorSpecs()->exists());
        $this->assertEquals($processorSpec->id, $pcSpec->processorSpecs->first()->id);
    }

    #[Test]
    public function it_has_stations_relationship(): void
    {
        $pcSpec = PcSpec::factory()->create();
        $station = Station::factory()->create(['pc_spec_id' => $pcSpec->id]);

        $this->assertTrue($pcSpec->stations()->exists());
        $this->assertEquals($station->id, $pcSpec->stations->first()->id);
    }

    #[Test]
    public function it_has_transfers_relationship(): void
    {
        $pcSpec = PcSpec::factory()->create();
        $transfer = PcTransfer::factory()->create(['pc_spec_id' => $pcSpec->id]);

        $this->assertTrue($pcSpec->transfers()->exists());
        $this->assertEquals($transfer->id, $pcSpec->transfers->first()->id);
    }

    #[Test]
    public function it_gets_formatted_details(): void
    {
        $pcSpec = PcSpec::factory()->create([
            'pc_number' => 'PC-001',
            'model' => 'OptiPlex 7090',
            'ram_gb' => 32,
            'disk_gb' => 512,
            'available_ports' => 'USB 3.0 x4, HDMI x1',
        ]);

        $processorSpec = ProcessorSpec::factory()->create([
            'model' => 'Intel Core i7-10700',
        ]);

        $pcSpec->processorSpecs()->attach($processorSpec->id);

        $details = $pcSpec->getFormattedDetails();

        $this->assertEquals('PC-001', $details['pc_number']);
        $this->assertEquals(32, $details['ram_gb']);
        $this->assertEquals(512, $details['disk_gb']);
        $this->assertCount(1, $details['processorSpecs']);
        $this->assertEquals('Intel Core i7-10700', $details['processorSpecs'][0]['model']);
    }

    #[Test]
    public function it_gets_form_selection_data(): void
    {
        $pcSpec = PcSpec::factory()->create([
            'pc_number' => 'PC-001',
            'model' => 'OptiPlex 7090',
            'ram_gb' => 16,
            'disk_gb' => 256,
        ]);

        $data = $pcSpec->getFormSelectionData();

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('pc_number', $data);
        $this->assertArrayHasKey('ram_gb', $data);
        $this->assertArrayHasKey('disk_gb', $data);
        $this->assertArrayHasKey('available_ports', $data);
        $this->assertArrayHasKey('processor', $data);
    }
}
