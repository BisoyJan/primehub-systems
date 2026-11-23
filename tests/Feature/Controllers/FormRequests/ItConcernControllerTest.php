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
    public function it_keeps_the_first_resolver_when_another_it_user_edits_a_resolved_concern(): void
    {
        $firstIt = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $secondIt = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();
        $concern = ItConcern::factory()->create([
            'user_id' => $agent->id,
            'site_id' => $site->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($firstIt)->post(route('it-concerns.resolve', $concern), [
            'resolution_notes' => 'Replaced cable',
            'status' => 'resolved',
        ]);

        $resolvedAt = $concern->fresh()->resolved_at;
        $this->travel(2)->hours();

        $this->actingAs($secondIt)->put(route('it-concerns.update', $concern), [
            'site_id' => $site->id,
            'station_number' => (string) $concern->station_number,
            'category' => $concern->category,
            'priority' => 'high',
            'description' => 'Edited by second IT',
            'status' => 'resolved',
            'resolution_notes' => 'Replaced cable and router',
        ])->assertRedirect();

        $this->actingAs($secondIt)->post(route('it-concerns.resolve', $concern), [
            'resolution_notes' => 'Updated notes again',
            'status' => 'resolved',
        ])->assertRedirect();

        $concern->refresh();
        $this->assertSame($firstIt->id, $concern->resolved_by);
        $this->assertTrue($resolvedAt->equalTo($concern->resolved_at));
        $this->assertSame('Updated notes again', $concern->resolution_notes);
    }

    #[Test]
    public function it_clears_the_resolver_on_reopen_and_credits_the_next_it_user_who_resolves(): void
    {
        $firstIt = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $secondIt = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $concern = ItConcern::factory()->create([
            'user_id' => User::factory()->create(['role' => 'Agent', 'is_approved' => true])->id,
            'site_id' => Site::factory()->create()->id,
            'status' => 'resolved',
            'resolved_by' => $firstIt->id,
            'resolved_at' => now(),
        ]);

        $this->actingAs($secondIt)->post(route('it-concerns.updateStatus', $concern), [
            'status' => 'in_progress',
        ])->assertRedirect();

        $concern->refresh();
        $this->assertNull($concern->resolved_by);
        $this->assertNull($concern->resolved_at);

        $this->actingAs($secondIt)->post(route('it-concerns.updateStatus', $concern), [
            'status' => 'resolved',
        ])->assertRedirect();

        $concern->refresh();
        $this->assertSame($secondIt->id, $concern->resolved_by);
        $this->assertNotNull($concern->resolved_at);
    }

    #[Test]
    public function it_lists_every_edit_with_editor_and_timestamp_on_show_and_edit_pages(): void
    {
        $firstIt = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $secondIt = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $concern = ItConcern::factory()->create([
            'user_id' => User::factory()->create(['role' => 'Agent', 'is_approved' => true])->id,
            'site_id' => Site::factory()->create()->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($firstIt)->post(route('it-concerns.resolve', $concern), [
            'resolution_notes' => 'Fixed',
            'status' => 'resolved',
        ]);
        $this->travel(5)->minutes();
        $this->actingAs($secondIt)->post(route('it-concerns.resolve', $concern), [
            'resolution_notes' => 'Fixed (details added)',
            'status' => 'resolved',
        ]);
        $this->travel(5)->minutes();
        $this->actingAs($firstIt)->post(route('it-concerns.resolve', $concern), [
            'resolution_notes' => 'Fixed (final)',
            'status' => 'resolved',
        ]);

        $this->actingAs($firstIt)->get(route('it-concerns.show', $concern))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/ItConcerns/Show')
                ->where('concern.resolved_by.id', $firstIt->id)
                ->has('editHistory', 3)
                ->where('editHistory.0.name', $firstIt->name)
                ->where('editHistory.0.changes.0.field', 'resolution_notes')
                ->where('editHistory.0.changes.0.old', 'Fixed (details added)')
                ->where('editHistory.0.changes.0.new', 'Fixed (final)')
                ->where('editHistory.1.name', $secondIt->name)
                ->where('editHistory.2.name', $firstIt->name)
                ->where('editHistory.2.changes', fn ($changes) => collect($changes)->contains(
                    fn ($change) => $change['field'] === 'resolved_by' && $change['old'] === null && $change['new'] === $firstIt->name
                ))
                ->where('editHistory', fn ($entries) => collect($entries)->pluck('at')->unique()->count() === 3)
            );

        $this->actingAs($firstIt)->get(route('it-concerns.edit', $concern))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/ItConcerns/Edit')
                ->has('editHistory', 3)
                ->where('editHistory.1.name', $secondIt->name)
            );
    }

    #[Test]
    public function it_shows_station_number_changes_in_the_edit_history(): void
    {
        $firstIt = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $secondIt = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        $concern = ItConcern::factory()->create([
            'user_id' => User::factory()->create(['role' => 'Agent', 'is_approved' => true])->id,
            'site_id' => Site::factory()->create()->id,
            'station_number' => 'PC-OLD',
            'status' => 'in_progress',
        ]);

        $this->actingAs($firstIt)->put(route('it-concerns.update', $concern), [
            'site_id' => $concern->site_id,
            'station_number' => 'PC-NEW',
            'reported_station_number' => 'PC-NEW',
            'category' => $concern->category,
            'priority' => $concern->priority,
            'description' => $concern->description,
            'status' => 'in_progress',
        ])->assertRedirect();

        $this->actingAs($secondIt)->get(route('it-concerns.show', $concern))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/ItConcerns/Show')
                ->where('concern.reported_station_number', 'PC-OLD')
                ->where('concern.station_number', 'PC-NEW')
                ->where('editHistory.0.name', $firstIt->name)
                ->where('editHistory.0.changes', fn ($changes) => collect($changes)->contains(
                    fn ($change) => $change['field'] === 'station_number'
                        && $change['old'] === 'PC-OLD'
                        && $change['new'] === 'PC-NEW'
                ))
            );

        $this->actingAs($secondIt)->put(route('it-concerns.update', $concern), [
            'site_id' => $concern->site_id,
            'station_number' => 'PC-FINAL',
            'category' => $concern->category,
            'priority' => $concern->priority,
            'description' => $concern->description,
        ])->assertSessionHasNoErrors();

        $this->get(route('it-concerns.edit', $concern))
            ->assertInertia(fn (Assert $page) => $page
                ->where('concern.reported_station_number', 'PC-OLD')
                ->where('concern.station_number', 'PC-FINAL')
                ->where('editHistory.0.name', $secondIt->name)
                ->where('editHistory.1.name', $firstIt->name)
            );
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
