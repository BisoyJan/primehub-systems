<?php

namespace Tests\Feature\Station;

use App\Models\User;
use App\Models\Site;
use App\Models\Campaign;
use App\Models\Station;
use App\Models\PcSpec;
use App\Models\PcTransfer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class StationTransferTest extends TestCase
{
    use DatabaseMigrations;

    protected User $admin;
    protected Site $site;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        // IT role has pc_transfers permissions
        $this->admin = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);
    }

    #[Test]
    public function transfer_page_can_be_accessed(): void
    {
        $this->actingAs($this->admin)
            ->get('/pc-transfers/transfer')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Station/PcTransfer/Transfer')
                ->has('stations')
                ->has('pcSpecs')
                ->has('filters')
            );
    }

    #[Test]
    public function pc_can_be_assigned_to_empty_station(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'pc_spec_id' => null,
        ]);
        $pcSpec = PcSpec::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post('/pc-transfers/bulk', [
                'transfers' => [
                    [
                        'to_station_id' => $station->id,
                        'pc_spec_id' => $pcSpec->id,
                        'from_station_id' => null,
                    ],
                ],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'pc_spec_id' => $pcSpec->id,
        ]);
        $this->assertDatabaseHas('pc_transfers', [
            'to_station_id' => $station->id,
            'pc_spec_id' => $pcSpec->id,
            'user_id' => $this->admin->id,
            'transfer_type' => 'assign',
        ]);
    }

    #[Test]
    public function pc_can_be_transferred_between_stations(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $fromStation = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $toStation = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $pcSpec = PcSpec::factory()->create();

        // Assign PC to first station
        $fromStation->update(['pc_spec_id' => $pcSpec->id]);

        $response = $this->actingAs($this->admin)
            ->post('/pc-transfers/bulk', [
                'transfers' => [
                    [
                        'to_station_id' => $toStation->id,
                        'pc_spec_id' => $pcSpec->id,
                        'from_station_id' => $fromStation->id,
                    ],
                ],
            ]);

        $response->assertRedirect();

        // Check PC was moved
        $this->assertDatabaseHas('stations', [
            'id' => $toStation->id,
            'pc_spec_id' => $pcSpec->id,
        ]);
        $this->assertDatabaseHas('stations', [
            'id' => $fromStation->id,
            'pc_spec_id' => null,
        ]);

        // Check transfer was logged
        $this->assertDatabaseHas('pc_transfers', [
            'from_station_id' => $fromStation->id,
            'to_station_id' => $toStation->id,
            'pc_spec_id' => $pcSpec->id,
            'user_id' => $this->admin->id,
        ]);
    }

    #[Test]
    public function bulk_transfer_handles_multiple_pcs(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $station1 = Station::factory()->create(['site_id' => $site->id, 'campaign_id' => $campaign->id]);
        $station2 = Station::factory()->create(['site_id' => $site->id, 'campaign_id' => $campaign->id]);
        $pcSpec1 = PcSpec::factory()->create();
        $pcSpec2 = PcSpec::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post('/pc-transfers/bulk', [
                'transfers' => [
                    [
                        'to_station_id' => $station1->id,
                        'pc_spec_id' => $pcSpec1->id,
                        'from_station_id' => null,
                    ],
                    [
                        'to_station_id' => $station2->id,
                        'pc_spec_id' => $pcSpec2->id,
                        'from_station_id' => null,
                    ],
                ],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('stations', ['id' => $station1->id, 'pc_spec_id' => $pcSpec1->id]);
        $this->assertDatabaseHas('stations', ['id' => $station2->id, 'pc_spec_id' => $pcSpec2->id]);
        $this->assertCount(2, PcTransfer::all());
    }

    #[Test]
    public function transfer_replaces_existing_pc_at_station(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $existingPc = PcSpec::factory()->create();
        $newPc = PcSpec::factory()->create();

        // Assign existing PC to station
        $station->update(['pc_spec_id' => $existingPc->id]);

        $response = $this->actingAs($this->admin)
            ->post('/pc-transfers/bulk', [
                'transfers' => [
                    [
                        'to_station_id' => $station->id,
                        'pc_spec_id' => $newPc->id,
                        'from_station_id' => null,
                    ],
                ],
            ]);

        $response->assertRedirect();

        // New PC should be at station
        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'pc_spec_id' => $newPc->id,
        ]);

        // Old PC is now floating (not assigned to any station)
        $this->assertDatabaseMissing('stations', [
            'pc_spec_id' => $existingPc->id,
        ]);
    }

    #[Test]
    public function pc_can_be_removed_from_station(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $pcSpec = PcSpec::factory()->create();
        $station->update(['pc_spec_id' => $pcSpec->id]);

        $response = $this->actingAs($this->admin)
            ->delete('/pc-transfers/remove', [
                'station_id' => $station->id,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'pc_spec_id' => null,
        ]);
        $this->assertDatabaseHas('pc_transfers', [
            'from_station_id' => $station->id,
            'to_station_id' => $station->id, // Same station indicates removal
            'pc_spec_id' => $pcSpec->id,
            'transfer_type' => 'remove',
        ]);
    }

    #[Test]
    public function remove_requires_station_to_have_pc(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'pc_spec_id' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete('/pc-transfers/remove', [
                'station_id' => $station->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash', fn ($flash) =>
            $flash['type'] === 'error' && str_contains($flash['message'], 'no PC')
        );
    }

    #[Test]
    public function transfer_validates_required_fields(): void
    {
        // Test empty transfers array
        $response = $this->actingAs($this->admin)
            ->post('/pc-transfers/bulk', [
                'transfers' => [],
            ]);

        $response->assertSessionHasErrors('transfers');
    }

    #[Test]
    public function transfer_validates_station_exists(): void
    {
        $pcSpec = PcSpec::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post('/pc-transfers/bulk', [
                'transfers' => [
                    [
                        'to_station_id' => 99999, // Non-existent station
                        'pc_spec_id' => $pcSpec->id,
                        'from_station_id' => null,
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('transfers.0.to_station_id');
    }

    #[Test]
    public function transfer_validates_pc_spec_exists(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post('/pc-transfers/bulk', [
                'transfers' => [
                    [
                        'to_station_id' => $station->id,
                        'pc_spec_id' => 99999, // Non-existent PC spec
                        'from_station_id' => null,
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('transfers.0.pc_spec_id');
    }

    #[Test]
    public function transfer_logs_user_who_performed_action(): void
    {
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'pc_spec_id' => null,
        ]);
        $pcSpec = PcSpec::factory()->create();

        $this->actingAs($this->admin)
            ->post('/pc-transfers/bulk', [
                'transfers' => [
                    [
                        'to_station_id' => $station->id,
                        'pc_spec_id' => $pcSpec->id,
                        'from_station_id' => null,
                    ],
                ],
            ]);

        // Verify the transfer was logged with the correct user
        $transfer = PcTransfer::latest()->first();
        $this->assertNotNull($transfer);
        $this->assertEquals($this->admin->id, $transfer->user_id);
        $this->assertEquals($station->id, $transfer->to_station_id);
        $this->assertEquals($pcSpec->id, $transfer->pc_spec_id);
    }

    #[Test]
    public function unauthorized_user_cannot_transfer_pcs(): void
    {
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        $station = Station::factory()->create([
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);
        $pcSpec = PcSpec::factory()->create();

        $response = $this->actingAs($agent)
            ->post('/pc-transfers/bulk', [
                'transfers' => [
                    [
                        'to_station_id' => $station->id,
                        'pc_spec_id' => $pcSpec->id,
                        'from_station_id' => null,
                    ],
                ],
            ]);

        $response->assertForbidden();
    }
}
