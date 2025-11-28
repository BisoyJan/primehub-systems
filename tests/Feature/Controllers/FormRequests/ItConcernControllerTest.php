<?php

namespace Tests\Feature\Controllers\FormRequests;

use Tests\TestCase;
use App\Models\User;
use App\Models\ItConcern;
use App\Models\Site;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Inertia\Testing\AssertableInertia as Assert;

class ItConcernControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock NotificationService to avoid actual notifications
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyItRolesAboutNewConcern')->andReturnNull();
            $mock->shouldReceive('notifyItRolesAboutConcernUpdate')->andReturnNull();
            $mock->shouldReceive('notifyItConcernStatusChange')->andReturn(\Mockery::mock(\App\Models\Notification::class));
            $mock->shouldReceive('notifyItRolesAboutConcernDeletion')->andReturnNull();
        });
    }

    #[Test]
    public function it_displays_it_concerns_index()
    {
        $user = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id
        ]);

        $response = $this->actingAs($user)->get(route('it-concerns.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/ItConcerns/Index')
                ->has('concerns.data', 1)
                ->where('concerns.data.0.id', $concern->id)
            );
    }

    #[Test]
    public function it_displays_create_form()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        $response = $this->actingAs($user)->get(route('it-concerns.create'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/ItConcerns/Create')
                ->has('sites')
            );
    }

    #[Test]
    public function it_stores_new_it_concern()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'ST-001',
            'category' => 'Hardware',
            'priority' => 'high',
            'description' => 'Mouse not working',
        ];

        $response = $this->actingAs($user)->post(route('it-concerns.store'), $data);

        $response->assertRedirect(route('it-concerns.index'));
        $this->assertDatabaseHas('it_concerns', [
            'user_id' => $user->id,
            'station_number' => 'ST-001',
            'description' => 'Mouse not working',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function it_shows_it_concern_details()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id
        ]);

        $response = $this->actingAs($user)->get(route('it-concerns.show', $concern));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/ItConcerns/Show')
                ->where('concern.id', $concern->id)
            );
    }

    #[Test]
    public function it_allows_agent_to_edit_own_concern()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'description' => 'Old description'
        ]);

        $response = $this->actingAs($user)->get(route('it-concerns.edit', $concern));
        $response->assertStatus(200);

        $newData = [
            'site_id' => $site->id,
            'station_number' => (string) $concern->station_number,
            'category' => $concern->category,
            'priority' => $concern->priority,
            'description' => 'New description',
        ];

        $response = $this->actingAs($user)->put(route('it-concerns.update', $concern), $newData);

        $response->assertRedirect(route('it-concerns.index'));
        $this->assertDatabaseHas('it_concerns', [
            'id' => $concern->id,
            'description' => 'New description',
        ]);
    }

    #[Test]
    public function it_allows_it_to_update_status()
    {
        $itUser = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'status' => 'pending'
        ]);

        $response = $this->actingAs($itUser)->post(route('it-concerns.updateStatus', $concern), [
            'status' => 'in_progress'
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('it_concerns', [
            'id' => $concern->id,
            'status' => 'in_progress',
        ]);
    }

    #[Test]
    public function it_allows_it_to_resolve_concern()
    {
        $itUser = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'status' => 'in_progress'
        ]);

        $response = $this->actingAs($itUser)->post(route('it-concerns.resolve', $concern), [
            'resolution_notes' => 'Fixed the issue',
            'status' => 'resolved'
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('it_concerns', [
            'id' => $concern->id,
            'status' => 'resolved',
            'resolution_notes' => 'Fixed the issue',
            'resolved_by' => $itUser->id,
        ]);
    }

    #[Test]
    public function it_allows_admin_to_create_concern_for_another_user()
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();

        $data = [
            'user_id' => $user->id,
            'site_id' => $site->id,
            'station_number' => 'ST-002',
            'category' => 'Software',
            'priority' => 'medium',
            'description' => 'Software update needed',
        ];

        $response = $this->actingAs($admin)->post(route('it-concerns.store'), $data);

        $response->assertRedirect(route('it-concerns.index'));
        $this->assertDatabaseHas('it_concerns', [
            'user_id' => $user->id,
            'station_number' => 'ST-002',
            'description' => 'Software update needed',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function it_allows_agent_to_delete_own_concern()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id
        ]);

        $response = $this->actingAs($user)->delete(route('it-concerns.destroy', $concern));

        $response->assertRedirect(route('it-concerns.index'));
        $this->assertDatabaseMissing('it_concerns', [
            'id' => $concern->id,
        ]);
    }
}
