<?php

namespace Tests\Unit\Models;

use App\Models\Campaign;
use App\Models\DiskSpec;
use App\Models\MonitorSpec;
use App\Models\PcSpec;
use App\Models\PcTransfer;
use App\Models\ProcessorSpec;
use App\Models\RamSpec;
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
            'form_factor' => 'SFF',
            'memory_type' => 'DDR4',
            'ram_slots' => 4,
            'max_ram_capacity_gb' => 64,
            'issue' => 'Screen flickering',
        ]);

        $this->assertEquals('PC-001', $pcSpec->pc_number);
        $this->assertEquals('Dell', $pcSpec->manufacturer);
        $this->assertEquals('OptiPlex 7090', $pcSpec->model);
        $this->assertEquals('Screen flickering', $pcSpec->issue);
    }

    #[Test]
    public function it_casts_integer_attributes(): void
    {
        $pcSpec = PcSpec::factory()->create([
            'ram_slots' => '4',
            'max_ram_capacity_gb' => '64',
        ]);

        $this->assertIsInt($pcSpec->ram_slots);
        $this->assertIsInt($pcSpec->max_ram_capacity_gb);
    }

    #[Test]
    public function it_has_ram_specs_relationship(): void
    {
        $pcSpec = PcSpec::factory()->create();
        $ramSpec = RamSpec::factory()->create();

        $pcSpec->ramSpecs()->attach($ramSpec->id, ['quantity' => 2]);

        $this->assertTrue($pcSpec->ramSpecs()->exists());
        $this->assertEquals($ramSpec->id, $pcSpec->ramSpecs->first()->id);
        $this->assertEquals(2, $pcSpec->ramSpecs->first()->pivot->quantity);
    }

    #[Test]
    public function it_has_disk_specs_relationship(): void
    {
        $pcSpec = PcSpec::factory()->create();
        $diskSpec = DiskSpec::factory()->create();

        $pcSpec->diskSpecs()->attach($diskSpec->id);

        $this->assertTrue($pcSpec->diskSpecs()->exists());
        $this->assertEquals($diskSpec->id, $pcSpec->diskSpecs->first()->id);
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
    public function it_has_monitors_relationship(): void
    {
        $pcSpec = PcSpec::factory()->create();
        $monitor = MonitorSpec::factory()->create();

        $pcSpec->monitors()->attach($monitor->id, ['quantity' => 1]);

        $this->assertTrue($pcSpec->monitors()->exists());
        $this->assertEquals($monitor->id, $pcSpec->monitors->first()->id);
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
    public function it_gets_formatted_details_with_ram(): void
    {
        $pcSpec = PcSpec::factory()->create([
            'pc_number' => 'PC-001',
            'model' => 'OptiPlex 7090',
        ]);

        $ramSpec = RamSpec::factory()->create([
            'model' => 'Crucial 8GB DDR4',
            'capacity_gb' => 8,
            'type' => 'DDR4',
        ]);

        $pcSpec->ramSpecs()->attach($ramSpec->id, ['quantity' => 2]);

        $details = $pcSpec->getFormattedDetails();

        $this->assertEquals('PC-001', $details['pc_number']);
        $this->assertEquals('Crucial 8GB DDR4', $details['ram']);
        $this->assertEquals(16, $details['ram_gb']); // 8GB * 2
        $this->assertEquals('8 GB + 8 GB', $details['ram_capacities']);
        $this->assertEquals('DDR4', $details['ram_ddr']);
    }

    #[Test]
    public function it_gets_formatted_details_with_disk(): void
    {
        $pcSpec = PcSpec::factory()->create();

        $diskSpec = DiskSpec::factory()->create([
            'model' => 'Samsung 870 EVO',
            'capacity_gb' => 512,
            'drive_type' => 'SSD',
        ]);

        $pcSpec->diskSpecs()->attach($diskSpec->id);

        $details = $pcSpec->getFormattedDetails();

        $this->assertEquals('Samsung 870 EVO', $details['disk']);
        $this->assertEquals(512, $details['disk_gb']);
        $this->assertEquals('512 GB', $details['disk_capacities']);
        $this->assertEquals('SSD', $details['disk_type']);
    }

    #[Test]
    public function it_gets_formatted_details_with_processor(): void
    {
        $pcSpec = PcSpec::factory()->create();

        $processorSpec = ProcessorSpec::factory()->create([
            'model' => 'Intel Core i7-10700',
        ]);

        $pcSpec->processorSpecs()->attach($processorSpec->id);

        $details = $pcSpec->getFormattedDetails();

        $this->assertEquals('Intel Core i7-10700', $details['processor']);
    }

    #[Test]
    public function it_handles_multiple_disk_types(): void
    {
        $pcSpec = PcSpec::factory()->create();

        $ssd = DiskSpec::factory()->create(['drive_type' => 'SSD', 'capacity_gb' => 256]);
        $hdd = DiskSpec::factory()->create(['drive_type' => 'HDD', 'capacity_gb' => 1000]);

        $pcSpec->diskSpecs()->attach([$ssd->id, $hdd->id]);

        $details = $pcSpec->getFormattedDetails();

        $this->assertEquals('SSD/HDD', $details['disk_type']);
        $this->assertEquals(1256, $details['disk_gb']);
    }

    #[Test]
    public function it_gets_form_selection_data(): void
    {
        $pcSpec = PcSpec::factory()->create([
            'pc_number' => 'PC-001',
            'model' => 'OptiPlex 7090',
        ]);

        $ramSpec = RamSpec::factory()->create([
            'model' => 'Crucial 8GB DDR4',
            'capacity_gb' => 8,
            'type' => 'DDR4',
        ]);

        $pcSpec->ramSpecs()->attach($ramSpec->id);

        $data = $pcSpec->getFormSelectionData();

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('pc_number', $data);
        $this->assertArrayHasKey('ram', $data);
        $this->assertArrayHasKey('disk', $data);
        $this->assertArrayHasKey('processor', $data);
    }

    #[Test]
    public function it_returns_na_for_missing_disk_type(): void
    {
        $pcSpec = PcSpec::factory()->create();

        $details = $pcSpec->getFormattedDetails();

        $this->assertEquals('N/A', $details['disk_type']);
    }

    #[Test]
    public function it_returns_na_for_missing_ram_ddr(): void
    {
        $pcSpec = PcSpec::factory()->create();

        $details = $pcSpec->getFormattedDetails();

        $this->assertEquals('N/A', $details['ram_ddr']);
    }
}
