<?php

namespace Tests\Feature;

use App\Models\PcMaintenance;
use App\Models\Station;
use App\Models\Site;
use App\Models\PcSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Carbon\Carbon;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PcMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $site;
    protected Station $station;
    protected PcSpec $pcSpec;

    protected function setUp(): void
    {
        parent::setUp();

        // IT role has pc_maintenance permissions
        $this->admin = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);

        $this->site = Site::factory()->create();
        $this->pcSpec = PcSpec::factory()->create();
        $this->station = Station::factory()->create([
            'site_id' => $this->site->id,
            'pc_spec_id' => $this->pcSpec->id,
        ]);
    }

    #[Test]
    public function it_displays_maintenance_index_page()
    {
        PcMaintenance::factory()->count(3)->create([
            'station_id' => $this->station->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/PcMaintenance/Index')
                ->has('maintenances.data', 3)
                ->has('sites')
            );
    }

    #[Test]
    public function it_displays_create_maintenance_form()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/PcMaintenance/Create')
                ->has('stations')
                ->has('sites')
            );
    }

    #[Test]
    public function it_creates_maintenance_record_for_single_station()
    {
        $data = [
            'station_ids' => [$this->station->id],
            'last_maintenance_date' => Carbon::now()->format('Y-m-d'),
            'next_due_date' => Carbon::now()->addMonths(3)->format('Y-m-d'),
            'maintenance_type' => 'Routine Maintenance',
            'notes' => 'Regular cleaning and inspection',
            'performed_by' => 'John Doe',
            'status' => 'completed',
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('pc-maintenance.store'), $data);

        $response->assertRedirect(route('pc-maintenance.index'));

        $this->assertDatabaseHas('pc_maintenances', [
            'station_id' => $this->station->id,
            'maintenance_type' => 'Routine Maintenance',
            'performed_by' => 'John Doe',
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function it_creates_maintenance_records_for_multiple_stations()
    {
        $station2 = Station::factory()->create([
            'site_id' => $this->site->id,
            'pc_spec_id' => $this->pcSpec->id,
        ]);

        $station3 = Station::factory()->create([
            'site_id' => $this->site->id,
            'pc_spec_id' => $this->pcSpec->id,
        ]);

        $data = [
            'station_ids' => [$this->station->id, $station2->id, $station3->id],
            'last_maintenance_date' => Carbon::now()->format('Y-m-d'),
            'next_due_date' => Carbon::now()->addMonths(3)->format('Y-m-d'),
            'maintenance_type' => 'Hardware Check',
            'notes' => 'Check all hardware components',
            'performed_by' => 'Jane Smith',
            'status' => 'completed',
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('pc-maintenance.store'), $data);

        $response->assertRedirect(route('pc-maintenance.index'));

        $this->assertEquals(3, PcMaintenance::count());
        $this->assertDatabaseHas('pc_maintenances', ['station_id' => $this->station->id]);
        $this->assertDatabaseHas('pc_maintenances', ['station_id' => $station2->id]);
        $this->assertDatabaseHas('pc_maintenances', ['station_id' => $station3->id]);
    }

    #[Test]
    public function it_validates_required_fields()
    {
        $response = $this->actingAs($this->admin)
            ->post(route('pc-maintenance.store'), []);

        $response->assertSessionHasErrors([
            'station_ids',
            'last_maintenance_date',
            'next_due_date',
            'status',
        ]);
    }

    #[Test]
    public function it_validates_next_due_date_after_last_maintenance()
    {
        $data = [
            'station_ids' => [$this->station->id],
            'last_maintenance_date' => Carbon::now()->format('Y-m-d'),
            'next_due_date' => Carbon::now()->subDays(1)->format('Y-m-d'), // Before last maintenance
            'maintenance_type' => 'Test',
            'performed_by' => 'Tester',
            'status' => 'completed',
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('pc-maintenance.store'), $data);

        $response->assertSessionHasErrors('next_due_date');
    }

    #[Test]
    public function it_validates_status_values()
    {
        $data = [
            'station_ids' => [$this->station->id],
            'last_maintenance_date' => Carbon::now()->format('Y-m-d'),
            'next_due_date' => Carbon::now()->addMonths(3)->format('Y-m-d'),
            'maintenance_type' => 'Test',
            'performed_by' => 'Tester',
            'status' => 'invalid-status',
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('pc-maintenance.store'), $data);

        $response->assertSessionHasErrors('status');
    }

    #[Test]
    public function it_displays_edit_maintenance_form()
    {
        $maintenance = PcMaintenance::factory()->create([
            'station_id' => $this->station->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.edit', $maintenance));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/PcMaintenance/Edit')
                ->where('maintenance.id', $maintenance->id)
                ->has('stations')
            );
    }

    #[Test]
    public function it_updates_maintenance_record()
    {
        $maintenance = PcMaintenance::factory()->create([
            'station_id' => $this->station->id,
            'maintenance_type' => 'Old Type',
            'status' => 'pending',
        ]);

        $data = [
            'station_id' => $this->station->id,
            'last_maintenance_date' => Carbon::now()->format('Y-m-d'),
            'next_due_date' => Carbon::now()->addMonths(3)->format('Y-m-d'),
            'maintenance_type' => 'Updated Type',
            'notes' => 'Updated notes',
            'performed_by' => 'Updated Performer',
            'status' => 'completed',
        ];

        $response = $this->actingAs($this->admin)
            ->put(route('pc-maintenance.update', $maintenance), $data);

        $response->assertRedirect(route('pc-maintenance.index'));

        $this->assertDatabaseHas('pc_maintenances', [
            'id' => $maintenance->id,
            'maintenance_type' => 'Updated Type',
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function it_deletes_maintenance_record()
    {
        $maintenance = PcMaintenance::factory()->create([
            'station_id' => $this->station->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('pc-maintenance.destroy', $maintenance));

        $response->assertRedirect(route('pc-maintenance.index'));
        $this->assertDatabaseMissing('pc_maintenances', ['id' => $maintenance->id]);
    }

    #[Test]
    public function it_filters_maintenance_by_status()
    {
        PcMaintenance::factory()->create([
            'station_id' => $this->station->id,
            'status' => 'completed',
        ]);

        PcMaintenance::factory()->create([
            'station_id' => $this->station->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index', ['status' => 'completed']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('maintenances.data', 1)
                ->where('maintenances.data.0.status', 'completed')
            );
    }

    #[Test]
    public function it_filters_maintenance_by_site()
    {
        $site2 = Site::factory()->create();
        $station2 = Station::factory()->create([
            'site_id' => $site2->id,
            'pc_spec_id' => $this->pcSpec->id,
        ]);

        PcMaintenance::factory()->create(['station_id' => $this->station->id]);
        PcMaintenance::factory()->create(['station_id' => $station2->id]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index', ['site' => $this->site->id]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('maintenances.data', 1)
                ->where('maintenances.data.0.station.site_id', $this->site->id)
            );
    }

    #[Test]
    public function it_filters_maintenance_by_search()
    {
        PcMaintenance::factory()->create([
            'station_id' => $this->station->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index', ['search' => $this->station->station_number]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('maintenances.data', 1)
            );
    }

    #[Test]
    public function it_auto_updates_overdue_status()
    {
        $maintenance = PcMaintenance::factory()->create([
            'station_id' => $this->station->id,
            'next_due_date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index'));

        $response->assertOk();

        $this->assertEquals('overdue', $maintenance->fresh()->status);
    }

    #[Test]
    public function it_schedules_pending_maintenance()
    {
        $data = [
            'station_ids' => [$this->station->id],
            'last_maintenance_date' => Carbon::now()->format('Y-m-d'),
            'next_due_date' => Carbon::now()->addMonths(3)->format('Y-m-d'),
            'maintenance_type' => 'Scheduled Maintenance',
            'notes' => 'To be performed in 3 months',
            'performed_by' => null,
            'status' => 'pending',
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('pc-maintenance.store'), $data);

        $response->assertRedirect(route('pc-maintenance.index'));

        $this->assertDatabaseHas('pc_maintenances', [
            'station_id' => $this->station->id,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function unauthorized_users_cannot_manage_maintenance()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($user)
            ->post(route('pc-maintenance.store'), [
                'station_ids' => [$this->station->id],
                'last_maintenance_date' => Carbon::now()->format('Y-m-d'),
                'next_due_date' => Carbon::now()->addMonths(3)->format('Y-m-d'),
                'maintenance_type' => 'Test',
                'performed_by' => 'Test',
                'status' => 'completed',
            ]);

        $response->assertForbidden();
    }
}
