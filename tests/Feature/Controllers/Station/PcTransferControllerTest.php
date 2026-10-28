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
                ->has('pcSpecs', 3)
                ->has('stationOptions', 3)
                ->has('pcSpecs.0.station')
            );
    }

    public function test_transfer_page_displays_form()
    {
        $this->get(route('pc-transfers.transferPage'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/PcTransfer/Transfer')
                ->where('initialFilters.site', 'all')
            );
    }

    public function test_transfer_page_limits_to_requested_stations_and_defaults_site_filter()
    {
        $stations = Station::factory()->count(3)->create();
        $ids = $stations->take(2)->pluck('id')->join(',');

        $this->get(route('pc-transfers.transferPage', ['stations' => $ids, 'site' => $stations[0]->site_id]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/PcTransfer/Transfer')
                ->has('stations', 2)
                ->where('initialFilters.site', (string) $stations[0]->site_id)
            );
    }

    public function test_transfer_page_defaults_site_filter_to_preselected_station()
    {
        $station = Station::factory()->create(['pc_spec_id' => null]);

        $this->get(route('pc-transfers.transferPage', $station))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('preselectedStationId', $station->id)
                ->where('initialFilters.site', (string) $station->site_id)
            );
    }

    public function test_transfer_creates_record_and_updates_station()
    {
        $pcSpec = PcSpec::factory()->create();
        $fromStation = Station::factory()->create(['pc_spec_id' => $pcSpec->id]);
        $toStation = Station::factory()->create(['pc_spec_id' => null]);

        $data = [
            'to_station_id' => $toStation->id,
            'pc_spec_id' => $pcSpec->id,
            'transfer_type' => 'assign',
            'notes' => 'Moving PC',
        ];

        $this->post(route('pc-transfers.transfer'), $data)
            ->assertRedirect()
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseHas('pc_transfers', [
            'from_station_id' => $fromStation->id,
            'to_station_id' => $toStation->id,
            'pc_spec_id' => $pcSpec->id,
            'transfer_type' => 'assign',
            'notes' => 'Moving PC',
        ]);

        $fromStation->refresh();
        $toStation->refresh();

        $this->assertNull($fromStation->pc_spec_id);
        $this->assertEquals($pcSpec->id, $toStation->pc_spec_id);
    }

    public function test_transfer_swap_exchanges_pcs_between_stations()
    {
        $pcA = PcSpec::factory()->create();
        $pcB = PcSpec::factory()->create();
        $stationA = Station::factory()->create(['pc_spec_id' => $pcA->id]);
        $stationB = Station::factory()->create(['pc_spec_id' => $pcB->id]);

        $this->post(route('pc-transfers.transfer'), [
            'to_station_id' => $stationB->id,
            'pc_spec_id' => $pcA->id,
            'transfer_type' => 'swap',
        ])->assertRedirect()->assertSessionHas('type', 'success');

        $this->assertEquals($pcA->id, $stationB->refresh()->pc_spec_id);
        $this->assertEquals($pcB->id, $stationA->refresh()->pc_spec_id);

        $this->assertDatabaseHas('pc_transfers', [
            'from_station_id' => $stationA->id,
            'to_station_id' => $stationB->id,
            'pc_spec_id' => $pcA->id,
            'transfer_type' => 'swap',
        ]);
        $this->assertDatabaseHas('pc_transfers', [
            'from_station_id' => $stationB->id,
            'to_station_id' => $stationA->id,
            'pc_spec_id' => $pcB->id,
            'transfer_type' => 'swap',
        ]);
    }

    public function test_transfer_replacing_occupied_station_floats_replaced_pc()
    {
        $pcA = PcSpec::factory()->create();
        $pcB = PcSpec::factory()->create();
        $stationB = Station::factory()->create(['pc_spec_id' => $pcB->id]);

        $this->post(route('pc-transfers.transfer'), [
            'to_station_id' => $stationB->id,
            'pc_spec_id' => $pcA->id,
            'transfer_type' => 'assign',
        ])->assertRedirect()->assertSessionHas('message', fn (string $message) => str_contains($message, $pcB->pc_number));

        $this->assertEquals($pcA->id, $stationB->refresh()->pc_spec_id);
        $this->assertDatabaseMissing('stations', ['pc_spec_id' => $pcB->id]);
    }

    public function test_transfer_rejects_pc_already_at_destination()
    {
        $pc = PcSpec::factory()->create();
        $station = Station::factory()->create(['pc_spec_id' => $pc->id]);

        $this->from(route('pc-transfers.index'))
            ->post(route('pc-transfers.transfer'), [
                'to_station_id' => $station->id,
                'pc_spec_id' => $pc->id,
                'transfer_type' => 'assign',
            ])
            ->assertRedirect(route('pc-transfers.index'))
            ->assertSessionHasErrors('transfers');

        $this->assertDatabaseCount('pc_transfers', 0);
    }

    public function test_bulk_transfer_preserves_origin_for_chained_moves()
    {
        $pcA = PcSpec::factory()->create();
        $pcB = PcSpec::factory()->create();
        $station1 = Station::factory()->create(['pc_spec_id' => $pcA->id]);
        $station2 = Station::factory()->create(['pc_spec_id' => $pcB->id]);
        $station3 = Station::factory()->create(['pc_spec_id' => null]);

        $this->post(route('pc-transfers.bulk'), [
            'transfers' => [
                ['to_station_id' => $station2->id, 'pc_spec_id' => $pcA->id],
                ['to_station_id' => $station3->id, 'pc_spec_id' => $pcB->id],
            ],
            'notes' => 'Reshuffle',
        ])->assertRedirect(route('pc-transfers.index'))->assertSessionHas('type', 'success');

        $this->assertNull($station1->refresh()->pc_spec_id);
        $this->assertEquals($pcA->id, $station2->refresh()->pc_spec_id);
        $this->assertEquals($pcB->id, $station3->refresh()->pc_spec_id);

        $this->assertDatabaseHas('pc_transfers', [
            'pc_spec_id' => $pcB->id,
            'from_station_id' => $station2->id,
            'to_station_id' => $station3->id,
        ]);
        $this->assertDatabaseCount('pc_transfers', 2);
    }

    public function test_bulk_transfer_redirects_to_return_url()
    {
        $pc = PcSpec::factory()->create();
        $station = Station::factory()->create(['pc_spec_id' => null]);

        $this->post(route('pc-transfers.bulk'), [
            'transfers' => [['to_station_id' => $station->id, 'pc_spec_id' => $pc->id]],
            '_return_to' => '/pc-transfers?site=3',
        ])->assertRedirect('/pc-transfers?site=3');
    }

    public function test_bulk_transfer_rejects_duplicate_destinations_without_changes()
    {
        $pcA = PcSpec::factory()->create();
        $pcB = PcSpec::factory()->create();
        $station = Station::factory()->create(['pc_spec_id' => null]);

        $this->post(route('pc-transfers.bulk'), [
            'transfers' => [
                ['to_station_id' => $station->id, 'pc_spec_id' => $pcA->id],
                ['to_station_id' => $station->id, 'pc_spec_id' => $pcB->id],
            ],
        ])->assertSessionHasErrors('transfers');

        $this->assertNull($station->refresh()->pc_spec_id);
        $this->assertDatabaseCount('pc_transfers', 0);
    }

    public function test_bulk_transfer_rejects_swap_when_replaced_pc_is_also_in_batch()
    {
        $pcA = PcSpec::factory()->create();
        $pcB = PcSpec::factory()->create();
        $station1 = Station::factory()->create(['pc_spec_id' => $pcA->id]);
        $station2 = Station::factory()->create(['pc_spec_id' => $pcB->id]);
        $station3 = Station::factory()->create(['pc_spec_id' => null]);

        $this->post(route('pc-transfers.bulk'), [
            'transfers' => [
                ['to_station_id' => $station2->id, 'pc_spec_id' => $pcA->id, 'transfer_type' => 'swap'],
                ['to_station_id' => $station3->id, 'pc_spec_id' => $pcB->id],
            ],
        ])->assertSessionHasErrors('transfers');

        $this->assertEquals($pcA->id, $station1->refresh()->pc_spec_id);
        $this->assertEquals($pcB->id, $station2->refresh()->pc_spec_id);
    }

    public function test_remove_unassigns_pc_and_logs_history()
    {
        $pc = PcSpec::factory()->create();
        $station = Station::factory()->create(['pc_spec_id' => $pc->id]);

        $this->delete(route('pc-transfers.remove'), ['station_id' => $station->id])
            ->assertRedirect()
            ->assertSessionHas('type', 'success');

        $this->assertNull($station->refresh()->pc_spec_id);
        $this->assertDatabaseHas('pc_transfers', [
            'pc_spec_id' => $pc->id,
            'from_station_id' => $station->id,
            'transfer_type' => 'remove',
        ]);
    }

    public function test_remove_on_empty_station_returns_validation_error()
    {
        $station = Station::factory()->create(['pc_spec_id' => null]);

        $this->delete(route('pc-transfers.remove'), ['station_id' => $station->id])
            ->assertSessionHasErrors('station_id');

        $this->assertDatabaseCount('pc_transfers', 0);
    }

    public function test_history_filters_by_search_type_and_date()
    {
        $pcMatch = PcSpec::factory()->create(['pc_number' => 'PC-FIND-001']);
        $pcOther = PcSpec::factory()->create(['pc_number' => 'PC-OTHER-002']);

        $match = PcTransfer::factory()->create(['pc_spec_id' => $pcMatch->id, 'transfer_type' => 'assign']);
        PcTransfer::factory()->create(['pc_spec_id' => $pcOther->id, 'transfer_type' => 'assign']);
        PcTransfer::factory()->create(['pc_spec_id' => $pcMatch->id, 'transfer_type' => 'remove']);
        PcTransfer::factory()->create([
            'pc_spec_id' => $pcMatch->id,
            'transfer_type' => 'assign',
            'created_at' => now()->subDays(10),
        ]);

        $this->get(route('pc-transfers.history', [
            'search' => 'FIND',
            'type' => 'assign',
            'date_from' => now()->subDay()->toDateString(),
        ]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/PcTransfer/History')
                ->has('transfers.data', 1)
                ->where('transfers.data.0.id', $match->id)
                ->where('filters.search', 'FIND')
                ->where('filters.type', 'assign')
            );
    }

    public function test_history_requires_history_permission()
    {
        $this->actingAs(User::factory()->create(['role' => 'agent', 'is_approved' => true]));

        $this->get(route('pc-transfers.history'))->assertForbidden();
    }
}
