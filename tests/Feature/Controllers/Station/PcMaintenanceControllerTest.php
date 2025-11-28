<?php

namespace Tests\Feature\Controllers\Station;

use App\Models\PcMaintenance;
use App\Models\Station;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PcMaintenanceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]));
    }

    public function test_index_displays_maintenance_records()
    {
        PcMaintenance::factory()->count(3)->create();

        $this->get(route('pc-maintenance.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/PcMaintenance/Index')
                ->has('maintenances.data', 3)
            );
    }

    public function test_store_creates_maintenance_record()
    {
        $station = Station::factory()->create();

        $data = [
            'station_ids' => [$station->id],
            'last_maintenance_date' => Carbon::yesterday()->format('Y-m-d'),
            'next_due_date' => Carbon::tomorrow()->format('Y-m-d'),
            'maintenance_type' => 'Cleaning',
            'notes' => 'Cleaned dust',
            'performed_by' => 'John Doe',
            'status' => 'completed',
        ];

        $this->post(route('pc-maintenance.store'), $data)
            ->assertRedirect(route('pc-maintenance.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('pc_maintenances', [
            'station_id' => $station->id,
            'maintenance_type' => 'Cleaning',
        ]);
    }

    public function test_update_updates_maintenance_record()
    {
        $maintenance = PcMaintenance::factory()->create();

        $data = [
            'station_id' => $maintenance->station_id,
            'last_maintenance_date' => Carbon::now()->format('Y-m-d'),
            'next_due_date' => Carbon::now()->addMonth()->format('Y-m-d'),
            'maintenance_type' => 'Repair',
            'notes' => 'Replaced fan',
            'performed_by' => 'Jane Doe',
            'status' => 'pending',
        ];

        $this->put(route('pc-maintenance.update', $maintenance), $data)
            ->assertRedirect(route('pc-maintenance.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('pc_maintenances', [
            'id' => $maintenance->id,
            'maintenance_type' => 'Repair',
        ]);
    }

    public function test_destroy_deletes_maintenance_record()
    {
        $maintenance = PcMaintenance::factory()->create();

        $this->delete(route('pc-maintenance.destroy', $maintenance))
            ->assertRedirect(route('pc-maintenance.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('pc_maintenances', ['id' => $maintenance->id]);
    }
}
