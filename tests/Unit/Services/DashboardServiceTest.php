<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Station;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\PcMaintenance;
use App\Models\ItConcern;
use App\Models\DiskSpec;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Carbon\Carbon;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardService();
    }

    #[Test]
    public function it_gets_total_stations_count(): void
    {
        $site = Site::factory()->create();
        Station::factory()->count(5)->create(['site_id' => $site->id]);

        $result = $this->service->getTotalStations();

        $this->assertEquals(5, $result['total']);
        $this->assertIsArray($result['bysite']);
    }

    #[Test]
    public function it_gets_stations_by_site_breakdown(): void
    {
        $site1 = Site::factory()->create(['name' => 'Site A']);
        $site2 = Site::factory()->create(['name' => 'Site B']);
        Station::factory()->count(3)->create(['site_id' => $site1->id]);
        Station::factory()->count(2)->create(['site_id' => $site2->id]);

        $result = $this->service->getTotalStations();

        $this->assertCount(2, $result['bysite']);
        $this->assertEquals(3, collect($result['bysite'])->firstWhere('site', 'Site A')['count']);
        $this->assertEquals(2, collect($result['bysite'])->firstWhere('site', 'Site B')['count']);
    }

    #[Test]
    public function it_gets_stations_without_pcs(): void
    {
        $site = Site::factory()->create();
        Station::factory()->count(3)->create(['site_id' => $site->id, 'pc_spec_id' => null]);

        $pcSpec = PcSpec::factory()->create();
        Station::factory()->create(['site_id' => $site->id, 'pc_spec_id' => $pcSpec->id]);

        $result = $this->service->getStationsWithoutPcs();

        $this->assertEquals(3, $result['total']);
        $this->assertCount(3, $result['stations']);
    }

    #[Test]
    public function it_gets_vacant_stations(): void
    {
        $site = Site::factory()->create();
        Station::factory()->count(2)->create(['site_id' => $site->id, 'status' => 'Vacant']);
        Station::factory()->count(3)->create(['site_id' => $site->id, 'status' => 'Occupied']);

        $result = $this->service->getVacantStations();

        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['stations']);
        $this->assertIsArray($result['bysite']);
    }

    #[Test]
    public function it_gets_pcs_with_ssd(): void
    {
        $site = Site::factory()->create();
        $pcWithSsd = PcSpec::factory()->create();
        $diskSsd = DiskSpec::factory()->create(['drive_type' => 'SSD']);
        $pcWithSsd->diskSpecs()->attach($diskSsd);
        Station::factory()->create(['site_id' => $site->id, 'pc_spec_id' => $pcWithSsd->id]);

        $result = $this->service->getPcsWithSsd();

        $this->assertEquals(1, $result['total']);
        $this->assertIsArray($result['details']);
    }

    #[Test]
    public function it_gets_pcs_with_hdd(): void
    {
        $site = Site::factory()->create();
        $pcWithHdd = PcSpec::factory()->create();
        $diskHdd = DiskSpec::factory()->create(['drive_type' => 'HDD']);
        $pcWithHdd->diskSpecs()->attach($diskHdd);
        Station::factory()->create(['site_id' => $site->id, 'pc_spec_id' => $pcWithHdd->id]);

        $result = $this->service->getPcsWithHdd();

        $this->assertEquals(1, $result['total']);
        $this->assertIsArray($result['details']);
    }

    #[Test]
    public function it_gets_dual_monitor_stations(): void
    {
        $site = Site::factory()->create();
        Station::factory()->count(3)->create(['site_id' => $site->id, 'monitor_type' => 'dual']);
        Station::factory()->count(2)->create(['site_id' => $site->id, 'monitor_type' => 'single']);

        $result = $this->service->getDualMonitorStations();

        $this->assertEquals(3, $result['total']);
        $this->assertIsArray($result['bysite']);
    }

    #[Test]
    public function it_gets_maintenance_due(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        PcMaintenance::factory()->create([
            
            'status' => 'overdue',
            'next_due_date' => Carbon::now()->subDays(5),
        ]);

        $result = $this->service->getMaintenanceDue();

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['stations']);
    }

    #[Test]
    public function it_gets_maintenance_due_including_pending(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        PcMaintenance::factory()->create([
            
            'status' => 'pending',
            'next_due_date' => Carbon::now()->subDay(),
        ]);

        $result = $this->service->getMaintenanceDue();

        $this->assertEquals(1, $result['total']);
    }

    #[Test]
    public function it_gets_unassigned_pc_specs(): void
    {
        // PC with no stations
        $unassignedPc = PcSpec::factory()->create();

        // PC assigned to station
        $assignedPc = PcSpec::factory()->create();
        $site = Site::factory()->create();
        Station::factory()->create(['site_id' => $site->id, 'pc_spec_id' => $assignedPc->id]);

        $result = $this->service->getUnassignedPcSpecs();

        $this->assertCount(1, $result);
        $this->assertEquals($unassignedPc->pc_number, $result[0]['pc_number']);
    }

    #[Test]
    public function it_gets_all_dashboard_stats(): void
    {
        $result = $this->service->getAllStats();

        $this->assertArrayHasKey('totalStations', $result);
        $this->assertArrayHasKey('noPcs', $result);
        $this->assertArrayHasKey('vacantStations', $result);
        $this->assertArrayHasKey('ssdPcs', $result);
        $this->assertArrayHasKey('hddPcs', $result);
        $this->assertArrayHasKey('dualMonitor', $result);
        $this->assertArrayHasKey('maintenanceDue', $result);
        $this->assertArrayHasKey('unassignedPcSpecs', $result);
        $this->assertArrayHasKey('itConcernStats', $result);
        $this->assertArrayHasKey('itConcernTrends', $result);
    }

    #[Test]
    public function it_gets_it_concern_stats(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        ItConcern::factory()->create([ 'site_id' => $site->id, 'status' => 'pending']);
        ItConcern::factory()->create([ 'site_id' => $site->id, 'status' => 'in_progress']);
        ItConcern::factory()->create([ 'site_id' => $site->id, 'status' => 'resolved']);

        $result = $this->service->getItConcernStats();

        $this->assertEquals(1, $result['pending']);
        $this->assertEquals(1, $result['in_progress']);
        $this->assertEquals(1, $result['resolved']);
        $this->assertIsArray($result['bySite']);
    }

    #[Test]
    public function it_gets_it_concern_stats_by_site(): void
    {
        $site1 = Site::factory()->create(['name' => 'Site A']);
        $site2 = Site::factory()->create(['name' => 'Site B']);
        $station1 = Station::factory()->create(['site_id' => $site1->id]);
        $station2 = Station::factory()->create(['site_id' => $site2->id]);

        ItConcern::factory()->count(2)->create([ 'site_id' => $site1->id, 'status' => 'pending']);
        ItConcern::factory()->create([ 'site_id' => $site2->id, 'status' => 'pending']);

        $result = $this->service->getItConcernStats();

        $this->assertCount(2, $result['bySite']);
    }

    #[Test]
    public function it_gets_it_concern_trends(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        ItConcern::factory()->create([
            
            'site_id' => $site->id,
            'status' => 'pending',
            'created_at' => Carbon::now()->subMonth(),
        ]);

        $result = $this->service->getItConcernTrends();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function it_handles_empty_data_gracefully(): void
    {
        $result = $this->service->getAllStats();

        $this->assertEquals(0, $result['totalStations']['total']);
        $this->assertEquals(0, $result['noPcs']['total']);
        $this->assertEquals(0, $result['vacantStations']['total']);
    }

    #[Test]
    public function it_formats_days_overdue_correctly(): void
    {
        $site = Site::factory()->create();
        $station = Station::factory()->create(['site_id' => $site->id]);

        PcMaintenance::factory()->create([
            
            'status' => 'overdue',
            'next_due_date' => Carbon::now()->subDays(1),
        ]);

        $result = $this->service->getMaintenanceDue();

        $this->assertStringContainsString('overdue', $result['stations'][0]['daysOverdue']);
    }
}
