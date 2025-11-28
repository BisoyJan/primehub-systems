<?php

namespace Tests\Feature\Controllers\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'is_approved' => true,
        ]);
    }

    public function test_index_displays_user_notifications(): void
    {
        Notification::factory()->count(5)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->get(route('notifications.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notifications')
                ->has('unreadCount')
            );
    }

    public function test_index_paginates_notifications(): void
    {
        Notification::factory()->count(25)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->get(route('notifications.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 20) // Default pagination is 20
            );
    }

    public function test_unread_count_returns_correct_count(): void
    {
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        Notification::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'read_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('notifications.unread-count'));

        $response->assertStatus(200)
            ->assertJson(['count' => 3]);
    }

    public function test_recent_returns_latest_notifications(): void
    {
        Notification::factory()->count(15)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->get(route('notifications.recent'));

        $response->assertStatus(200)
            ->assertJsonCount(10, 'notifications') // Limit is 10
            ->assertJsonStructure([
                'notifications',
                'unreadCount',
            ]);
    }

    public function test_mark_as_read_marks_notification_as_read(): void
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('notifications.mark-as-read', $notification));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $notification->refresh();
        $this->assertNotNull($notification->read_at);
    }

    public function test_mark_as_read_prevents_marking_other_users_notification(): void
    {
        $otherUser = User::factory()->create();
        $notification = Notification::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('notifications.mark-as-read', $notification));

        $response->assertStatus(403);
    }

    public function test_mark_all_as_read_marks_all_unread_notifications(): void
    {
        Notification::factory()->count(5)->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('notifications.mark-all-read'));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $unreadCount = Notification::where('user_id', $this->user->id)
            ->whereNull('read_at')
            ->count();

        $this->assertEquals(0, $unreadCount);
    }

    public function test_mark_all_as_read_does_not_affect_other_users(): void
    {
        $otherUser = User::factory()->create();

        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        Notification::factory()->count(2)->create([
            'user_id' => $otherUser->id,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('notifications.mark-all-read'));

        $response->assertStatus(200);

        $otherUserUnreadCount = Notification::where('user_id', $otherUser->id)
            ->whereNull('read_at')
            ->count();

        $this->assertEquals(2, $otherUserUnreadCount);
    }

    public function test_destroy_deletes_notification(): void
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('notifications.destroy', $notification));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_destroy_prevents_deleting_other_users_notification(): void
    {
        $otherUser = User::factory()->create();
        $notification = Notification::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('notifications.destroy', $notification));

        $response->assertStatus(403);

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_delete_all_read_removes_only_read_notifications(): void
    {
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'read_at' => now(),
        ]);

        Notification::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('notifications.delete-all-read'));

        $response->assertRedirect();

        $remainingCount = Notification::where('user_id', $this->user->id)->count();
        $this->assertEquals(2, $remainingCount);
    }

    public function test_delete_all_removes_all_user_notifications(): void
    {
        Notification::factory()->count(5)->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('notifications.delete-all'));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $remainingCount = Notification::where('user_id', $this->user->id)->count();
        $this->assertEquals(0, $remainingCount);
    }

    public function test_delete_all_does_not_affect_other_users(): void
    {
        $otherUser = User::factory()->create();

        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        Notification::factory()->count(2)->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('notifications.delete-all'));

        $response->assertStatus(200);

        $otherUserCount = Notification::where('user_id', $otherUser->id)->count();
        $this->assertEquals(2, $otherUserCount);
    }
}
