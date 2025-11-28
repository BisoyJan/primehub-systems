<?php

namespace Tests\Feature\Controllers\Station;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampaignControllerTest extends TestCase
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

    public function test_index_displays_campaigns()
    {
        Campaign::factory()->count(3)->create();

        $this->get(route('campaigns.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/Campaigns/Index')
                ->has('campaigns.data', 3)
            );
    }

    public function test_store_creates_campaign()
    {
        $data = ['name' => 'Sales'];

        $this->post(route('campaigns.store'), $data)
            ->assertRedirect()
            ->assertSessionHas('flash');

        $this->assertDatabaseHas('campaigns', $data);
    }

    public function test_update_updates_campaign()
    {
        $campaign = Campaign::factory()->create();
        $data = ['name' => 'Support'];

        $this->put(route('campaigns.update', $campaign), $data)
            ->assertRedirect()
            ->assertSessionHas('flash');

        $this->assertDatabaseHas('campaigns', array_merge(['id' => $campaign->id], $data));
    }

    public function test_destroy_deletes_campaign()
    {
        $campaign = Campaign::factory()->create();

        $this->delete(route('campaigns.destroy', $campaign))
            ->assertRedirect()
            ->assertSessionHas('flash');

        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
    }
}
