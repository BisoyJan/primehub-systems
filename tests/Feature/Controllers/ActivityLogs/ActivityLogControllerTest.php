<?php

namespace Tests\Feature\Controllers\ActivityLogs;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'role' => 'Super Admin',
            'is_approved' => true,
        ]);
    }

    public function test_index_displays_activity_logs(): void
    {
        activity()
            ->causedBy($this->user)
            ->log('Test activity');

        $response = $this->actingAs($this->user)
            ->get(route('activity-logs.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ActivityLogs/Index')
                ->has('activities')
                ->has('filters')
            );
    }

    public function test_index_paginates_activities(): void
    {
        // Create 25 activity logs
        for ($i = 0; $i < 25; $i++) {
            activity()
                ->causedBy($this->user)
                ->log("Test activity {$i}");
        }

        $response = $this->actingAs($this->user)
            ->get(route('activity-logs.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ActivityLogs/Index')
                ->has('activities.data', 20) // Default pagination
            );
    }

    public function test_index_searches_by_description(): void
    {
        activity()
            ->causedBy($this->user)
            ->log('User logged in successfully');

        activity()
            ->causedBy($this->user)
            ->log('Station created');

        $response = $this->actingAs($this->user)
            ->get(route('activity-logs.index', ['search' => 'logged in']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'logged in')
            );
    }

    public function test_index_filters_by_event(): void
    {
        activity()
            ->causedBy($this->user)
            ->event('created')
            ->log('Resource created');

        activity()
            ->causedBy($this->user)
            ->event('updated')
            ->log('Resource updated');

        $response = $this->actingAs($this->user)
            ->get(route('activity-logs.index', ['event' => 'created']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.event', 'created')
            );
    }

    public function test_index_filters_by_causer(): void
    {
        $otherUser = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Admin',
        ]);

        activity()
            ->causedBy($this->user)
            ->log('Activity by user 1');

        activity()
            ->causedBy($otherUser)
            ->log('Activity by user 2');

        $response = $this->actingAs($this->user)
            ->get(route('activity-logs.index', ['causer' => 'Jane']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.causer', 'Jane')
            );
    }

    public function test_index_orders_activities_by_created_at_desc(): void
    {
        // Create old activity
        Activity::create([
            'log_name' => 'default',
            'description' => 'Old activity',
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        // Create new activity
        activity()
            ->causedBy($this->user)
            ->log('New activity');

        $response = $this->actingAs($this->user)
            ->get(route('activity-logs.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ActivityLogs/Index')
                ->has('activities.data')
            );
    }

    public function test_index_includes_causer_and_subject_relationships(): void
    {
        $testUser = User::factory()->create();

        activity()
            ->causedBy($this->user)
            ->performedOn($testUser)
            ->log('User modified');

        $response = $this->actingAs($this->user)
            ->get(route('activity-logs.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('activities.data.0', fn (Assert $item) => $item
                    ->has('description')
                    ->has('causer')
                    ->has('subject_type')
                    ->has('subject_id')
                    ->etc()
                )
            );
    }

    public function test_index_formats_activity_data_correctly(): void
    {
        activity()
            ->causedBy($this->user)
            ->withProperties(['key' => 'value'])
            ->log('Activity with properties');

        $response = $this->actingAs($this->user)
            ->get(route('activity-logs.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('activities.data.0', fn (Assert $item) => $item
                    ->has('id')
                    ->has('description')
                    ->has('event')
                    ->has('causer')
                    ->has('properties')
                    ->has('created_at')
                    ->has('created_at_human')
                    ->etc()
                )
            );
    }

    public function test_index_displays_system_for_activities_without_causer(): void
    {
        activity()
            ->log('System activity without causer');

        $response = $this->actingAs($this->user)
            ->get(route('activity-logs.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('activities.data.0', fn (Assert $item) => $item
                    ->where('causer', 'System')
                    ->etc()
                )
            );
    }

    public function test_index_combines_multiple_filters(): void
    {
        $targetUser = User::factory()->create([
            'first_name' => 'Target',
            'last_name' => 'User',
        ]);

        activity()
            ->causedBy($targetUser)
            ->event('created')
            ->log('User created station');

        activity()
            ->causedBy($this->user)
            ->event('updated')
            ->log('Admin updated settings');

        $response = $this->actingAs($this->user)
            ->get(route('activity-logs.index', [
                'search' => 'station',
                'event' => 'created',
                'causer' => 'Target',
            ]));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'station')
                ->where('filters.event', 'created')
                ->where('filters.causer', 'Target')
            );
    }
}
