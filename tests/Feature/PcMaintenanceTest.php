<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\PcMaintenance;
use App\Models\PcSpec;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PcMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Site $site;

    protected PcSpec $pcSpec;

    protected Station $station;

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
        // Assign pcSpec to a station so it shows up in default views
        $this->station = Station::factory()->create([
            'site_id' => $this->site->id,
            'pc_spec_id' => $this->pcSpec->id,
        ]);
    }

    #[Test]
    public function it_displays_maintenance_index_page()
    {
        PcMaintenance::factory()->count(3)->create([
            'pc_spec_id' => $this->pcSpec->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcMaintenance/Index')
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
                ->component('Computer/PcMaintenance/Create')
                ->has('pcSpecs', 1) // Only PCs assigned to stations
                ->has('sites')
            );
    }

    #[Test]
    public function it_creates_maintenance_record_for_single_station()
    {
        $data = [
            'pc_spec_ids' => [$this->pcSpec->id],
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
            'pc_spec_id' => $this->pcSpec->id,
            'maintenance_type' => 'Routine Maintenance',
            'performed_by' => 'John Doe',
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function it_creates_maintenance_records_for_multiple_stations()
    {
        $pcSpec2 = PcSpec::factory()->create();
        $pcSpec3 = PcSpec::factory()->create();

        $data = [
            'pc_spec_ids' => [$this->pcSpec->id, $pcSpec2->id, $pcSpec3->id],
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
        $this->assertDatabaseHas('pc_maintenances', ['pc_spec_id' => $this->pcSpec->id]);
        $this->assertDatabaseHas('pc_maintenances', ['pc_spec_id' => $pcSpec2->id]);
        $this->assertDatabaseHas('pc_maintenances', ['pc_spec_id' => $pcSpec3->id]);
    }

    #[Test]
    public function it_validates_required_fields()
    {
        $response = $this->actingAs($this->admin)
            ->post(route('pc-maintenance.store'), []);

        $response->assertSessionHasErrors([
            'pc_spec_ids',
            'last_maintenance_date',
            'next_due_date',
            'status',
        ]);
    }

    #[Test]
    public function it_validates_next_due_date_after_last_maintenance()
    {
        $data = [
            'pc_spec_ids' => [$this->pcSpec->id],
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
            'pc_spec_ids' => [$this->pcSpec->id],
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
            'pc_spec_id' => $this->pcSpec->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.edit', $maintenance));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcMaintenance/Edit')
                ->where('maintenance.id', $maintenance->id)
                ->has('pcSpecs')
            );
    }

    #[Test]
    public function it_updates_maintenance_record()
    {
        $maintenance = PcMaintenance::factory()->create([
            'pc_spec_id' => $this->pcSpec->id,
            'maintenance_type' => 'Old Type',
            'status' => 'pending',
        ]);

        $data = [
            'pc_spec_id' => $this->pcSpec->id,
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
            'pc_spec_id' => $this->pcSpec->id,
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
            'pc_spec_id' => $this->pcSpec->id,
            'status' => 'completed',
        ]);

        $pcSpec2 = PcSpec::factory()->create();
        Station::factory()->create(['pc_spec_id' => $pcSpec2->id]);
        PcMaintenance::factory()->create([
            'pc_spec_id' => $pcSpec2->id,
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
        $pcSpec2 = PcSpec::factory()->create();

        // Link pcSpecs to sites via stations
        Station::factory()->create(['site_id' => $this->site->id, 'pc_spec_id' => $this->pcSpec->id]);
        Station::factory()->create(['site_id' => $site2->id, 'pc_spec_id' => $pcSpec2->id]);

        PcMaintenance::factory()->create(['pc_spec_id' => $this->pcSpec->id]);
        PcMaintenance::factory()->create(['pc_spec_id' => $pcSpec2->id]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index', ['site' => $this->site->id]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('maintenances.data', 1)
            );
    }

    #[Test]
    public function it_filters_maintenance_by_search()
    {
        PcMaintenance::factory()->create([
            'pc_spec_id' => $this->pcSpec->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index', ['search' => $this->pcSpec->pc_number]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('maintenances.data', 1)
            );
    }

    #[Test]
    public function it_auto_updates_overdue_status()
    {
        $maintenance = PcMaintenance::factory()->create([
            'pc_spec_id' => $this->pcSpec->id,
            'next_due_date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index'));

        $response->assertOk();

        $this->assertEquals('overdue', $maintenance->fresh()->status);
    }

    #[Test]
    public function it_filters_maintenance_by_assignment_status()
    {
        // Maintenance for assigned PC (via setUp station)
        PcMaintenance::factory()->create([
            'pc_spec_id' => $this->pcSpec->id,
        ]);

        // Maintenance for unassigned PC
        $unassignedPcSpec = PcSpec::factory()->create();
        PcMaintenance::factory()->create([
            'pc_spec_id' => $unassignedPcSpec->id,
        ]);

        // Default (assigned) - only assigned PCs
        $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('maintenances.data', 1)
            );

        // Unassigned - only unassigned PCs
        $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index', ['assignment' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('maintenances.data', 1)
            );

        // All - both
        $this->actingAs($this->admin)
            ->get(route('pc-maintenance.index', ['assignment' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('maintenances.data', 2)
            );
    }

    #[Test]
    public function it_only_shows_assigned_pcs_on_create_form()
    {
        // Create an unassigned PcSpec
        PcSpec::factory()->create();

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcMaintenance/Create')
                ->has('pcSpecs', 1) // Only the assigned one from setUp
            );
    }

    #[Test]
    public function it_shows_current_pc_plus_assigned_pcs_on_edit_form()
    {
        // Create maintenance for an unassigned PC
        $unassignedPcSpec = PcSpec::factory()->create();
        $maintenance = PcMaintenance::factory()->create([
            'pc_spec_id' => $unassignedPcSpec->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('pc-maintenance.edit', $maintenance));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcMaintenance/Edit')
                // Should include: the assigned pcSpec from setUp + the unassigned current one
                ->has('pcSpecs', 2)
            );
    }

    #[Test]
    public function it_schedules_pending_maintenance()
    {
        $data = [
            'pc_spec_ids' => [$this->pcSpec->id],
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
            'pc_spec_id' => $this->pcSpec->id,
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

        // Agent needs EmployeeSchedule to avoid redirect to /schedule-setup
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $response = $this->actingAs($user)
            ->post(route('pc-maintenance.store'), [
                'pc_spec_ids' => [$this->pcSpec->id],
                'last_maintenance_date' => Carbon::now()->format('Y-m-d'),
                'next_due_date' => Carbon::now()->addMonths(3)->format('Y-m-d'),
                'maintenance_type' => 'Test',
                'performed_by' => 'Test',
                'status' => 'completed',
            ]);

        $response->assertForbidden();
    }
}
