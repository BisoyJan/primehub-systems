<?php

namespace Tests\Feature\Controllers\Station;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SiteControllerTest extends TestCase
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

    public function test_index_displays_sites()
    {
        Site::factory()->count(3)->create();

        $this->get(route('sites.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Station/Site/Index')
                ->has('sites.data', 3)
            );
    }

    public function test_store_creates_site()
    {
        $data = ['name' => 'Davao'];

        $this->post(route('sites.store'), $data)
            ->assertRedirect()
            ->assertSessionHas('flash');

        $this->assertDatabaseHas('sites', $data);
    }

    public function test_update_updates_site()
    {
        $site = Site::factory()->create();
        $data = ['name' => 'Cebu'];

        $this->put(route('sites.update', $site), $data)
            ->assertRedirect()
            ->assertSessionHas('flash');

        $this->assertDatabaseHas('sites', array_merge(['id' => $site->id], $data));
    }

    public function test_destroy_deletes_site()
    {
        $site = Site::factory()->create();

        $this->delete(route('sites.destroy', $site))
            ->assertRedirect()
            ->assertSessionHas('flash');

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }
}
