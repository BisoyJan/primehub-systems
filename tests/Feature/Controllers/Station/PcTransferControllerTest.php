<?php

namespace Tests\Feature\Controllers\Station;

use App\Models\PcSpec;
use App\Models\PcTransfer;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PcTransferControllerTest extends TestCase
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

    public function test_index_displays_transfers()
    {
        Station::factory()->count(3)->create();

        $this->get(route('pc-transfers.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/PcTransfer/Index')
                ->has('stations.data', 3)
            );
    }

    public function test_transfer_page_displays_form()
    {
        $this->get(route('pc-transfers.transferPage'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/PcTransfer/Transfer')
            );
    }

    public function test_transfer_creates_record_and_updates_station()
    {
        $pcSpec = PcSpec::factory()->create();
        $fromStation = Station::factory()->create(['pc_spec_id' => $pcSpec->id]);
        $toStation = Station::factory()->create(['pc_spec_id' => null]);

        $data = [
            'from_station_id' => $fromStation->id,
            'to_station_id' => $toStation->id,
            'pc_spec_id' => $pcSpec->id,
            'transfer_type' => 'assign',
            'notes' => 'Moving PC',
        ];

        $this->post(route('pc-transfers.transfer'), $data)
            ->assertRedirect()
            ->assertSessionHas('flash');

        $this->assertDatabaseHas('pc_transfers', [
            'from_station_id' => $fromStation->id,
            'to_station_id' => $toStation->id,
            'pc_spec_id' => $pcSpec->id,
            'transfer_type' => 'assign',
        ]);

        $fromStation->refresh();
        $toStation->refresh();

        $this->assertNull($fromStation->pc_spec_id);
        $this->assertEquals($pcSpec->id, $toStation->pc_spec_id);
    }
}
