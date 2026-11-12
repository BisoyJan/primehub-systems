<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Events\ItConcernCreated;
use App\Events\ItConcernUpdated;
use App\Models\EmployeeSchedule;
use App\Models\ItConcern;
use App\Models\Notification;
use App\Models\Site;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

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
            $mock->shouldReceive('notifyItConcernStatusChange')->andReturn(\Mockery::mock(Notification::class));
            $mock->shouldReceive('notifyItRolesAboutConcernDeletion')->andReturnNull();
            $mock->shouldReceive('notifyItRolesAboutConcernCancellation')->andReturnNull();
        });
    }

    #[Test]
    public function it_displays_it_concerns_index()
    {
        $user = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
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
        EmployeeSchedule::factory()->create(['user_id' => $user->id]);

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
        EmployeeSchedule::factory()->create(['user_id' => $user->id]);
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
    public function it_broadcasts_it_concern_created_event_on_store()
    {
        Event::fake([ItConcernCreated::class]);

        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create(['user_id' => $user->id]);
        $site = Site::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'ST-002',
            'category' => 'Network/Connectivity',
            'priority' => 'urgent',
            'description' => 'No internet connection',
        ];

        $this->actingAs($user)->post(route('it-concerns.store'), $data);

        Event::assertDispatched(ItConcernCreated::class, function (ItConcernCreated $event) {
            return $event->concern->station_number === 'ST-002'
                && $event->concern->priority === 'urgent';
        });
    }

    #[Test]
    public function it_shows_it_concern_details()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create(['user_id' => $user->id]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
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
        EmployeeSchedule::factory()->create(['user_id' => $user->id]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'description' => 'Old description',
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
    public function it_redirects_to_return_to_when_relative_path_on_update()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create(['user_id' => $user->id]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'description' => 'Old description',
        ]);

        $newData = [
            'site_id' => $site->id,
            'station_number' => (string) $concern->station_number,
            'category' => $concern->category,
            'priority' => $concern->priority,
            'description' => 'New description',
            '_return_to' => '/form-requests/it-concerns?status=pending&page=2',
        ];

        $response = $this->actingAs($user)->put(route('it-concerns.update', $concern), $newData);

        $response->assertRedirect('/form-requests/it-concerns?status=pending&page=2');
        $this->assertDatabaseHas('it_concerns', [
            'id' => $concern->id,
            'description' => 'New description',
        ]);
    }

    #[Test]
    public function it_ignores_external_return_to_url_on_update()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create(['user_id' => $user->id]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
        ]);

        $newData = [
            'site_id' => $site->id,
            'station_number' => (string) $concern->station_number,
            'category' => $concern->category,
            'priority' => $concern->priority,
            'description' => 'New description',
            '_return_to' => 'https://evil.example',
        ];

        $response = $this->actingAs($user)->put(route('it-concerns.update', $concern), $newData);

        $response->assertRedirect(route('it-concerns.index'));
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
            'status' => 'pending',
        ]);

        $response = $this->actingAs($itUser)->post(route('it-concerns.updateStatus', $concern), [
            'status' => 'in_progress',
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
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($itUser)->post(route('it-concerns.resolve', $concern), [
            'resolution_notes' => 'Fixed the issue',
            'status' => 'resolved',
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
        EmployeeSchedule::factory()->create(['user_id' => $user->id]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
        ]);

        $response = $this->actingAs($user)->delete(route('it-concerns.destroy', $concern));

        $response->assertRedirect(route('it-concerns.index'));
        $this->assertDatabaseMissing('it_concerns', [
            'id' => $concern->id,
        ]);
    }

    #[Test]
    public function it_broadcasts_it_concern_updated_event_when_resolved(): void
    {
        Event::fake([ItConcernUpdated::class]);

        $itUser = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $concern = ItConcern::factory()->create([
            'user_id' => $agent->id,
            'site_id' => Site::factory()->create()->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($itUser)->post(route('it-concerns.resolve', $concern), [
            'resolution_notes' => 'Replaced the headset',
            'status' => 'resolved',
        ]);

        Event::assertDispatched(ItConcernUpdated::class, function (ItConcernUpdated $event) use ($concern, $itUser) {
            $payload = $event->broadcastWith();

            return $event->concern->id === $concern->id
                && $event->action === 'resolved'
                && $event->actor->id === $itUser->id
                && $payload['status'] === 'resolved'
                && $payload['updated_by']['id'] === $itUser->id
                && collect($event->broadcastOn())->map(fn ($channel) => (string) $channel)->all()
                    === ["private-it-concern.{$concern->id}", 'private-it-concerns'];
        });
    }

    #[Test]
    public function it_broadcasts_it_concern_updated_event_when_cancelled_and_deleted(): void
    {
        Event::fake([ItConcernUpdated::class]);

        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create(['user_id' => $agent->id]);
        $site = Site::factory()->create();
        $cancelled = ItConcern::factory()->create(['user_id' => $agent->id, 'site_id' => $site->id, 'status' => 'pending']);
        $deleted = ItConcern::factory()->create(['user_id' => $agent->id, 'site_id' => $site->id, 'status' => 'pending']);

        $this->actingAs($agent)->post(route('it-concerns.cancel', $cancelled));
        $this->actingAs($agent)->delete(route('it-concerns.destroy', $deleted));

        Event::assertDispatched(ItConcernUpdated::class, fn (ItConcernUpdated $e) => $e->concern->id === $cancelled->id && $e->action === 'cancelled');
        Event::assertDispatched(ItConcernUpdated::class, fn (ItConcernUpdated $e) => $e->concern->id === $deleted->id && $e->action === 'deleted');
    }

    #[Test]
    public function it_authorizes_per_concern_channel_for_owner_and_it_only(): void
    {
        $owner = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $otherAgent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $itUser = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $concern = ItConcern::factory()->create([
            'user_id' => $owner->id,
            'site_id' => Site::factory()->create()->id,
        ]);

        $callback = Broadcast::getChannels()->get('it-concern.{concern}');
        $this->assertIsCallable($callback, 'it-concern.{concern} channel is not registered');

        $this->assertTrue($callback($owner, $concern));
        $this->assertTrue($callback($itUser, $concern));
        $this->assertFalse($callback($otherAgent, $concern));
    }
}
