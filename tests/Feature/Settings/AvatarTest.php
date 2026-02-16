<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_can_upload_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('account.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('account.edit'));

        $user->refresh();
        $this->assertNotNull($user->avatar);
        $this->assertStringEndsWith('.webp', $user->avatar);
        Storage::disk('public')->assertExists($user->avatar);
    }

    #[Test]
    public function uploaded_avatar_is_optimized_and_converted_to_webp(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('account.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('avatar.png', 1024, 1024),
            ]);

        $response->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNotNull($user->avatar);
        $this->assertStringStartsWith('avatars/', $user->avatar);
        $this->assertStringEndsWith('.webp', $user->avatar);
        Storage::disk('public')->assertExists($user->avatar);
    }

    #[Test]
    public function avatar_must_be_an_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('account.avatar.update'), [
                'avatar' => UploadedFile::fake()->create('document.pdf', 100),
            ]);

        $response->assertSessionHasErrors('avatar');
    }

    #[Test]
    public function avatar_must_not_exceed_max_size(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('account.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('large.jpg')->size(3000),
            ]);

        $response->assertSessionHasErrors('avatar');
    }

    #[Test]
    public function avatar_must_be_valid_mime_type(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('account.avatar.update'), [
                'avatar' => UploadedFile::fake()->create('image.gif', 100, 'image/gif'),
            ]);

        $response->assertSessionHasErrors('avatar');
    }

    #[Test]
    public function uploading_new_avatar_deletes_old_one(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        // Upload first avatar
        $this->actingAs($user)
            ->post(route('account.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('first.jpg', 200, 200),
            ]);

        $user->refresh();
        $oldAvatar = $user->avatar;
        Storage::disk('public')->assertExists($oldAvatar);

        // Upload second avatar
        $this->actingAs($user)
            ->post(route('account.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('second.jpg', 200, 200),
            ]);

        $user->refresh();
        Storage::disk('public')->assertMissing($oldAvatar);
        Storage::disk('public')->assertExists($user->avatar);
        $this->assertNotEquals($oldAvatar, $user->avatar);
    }

    #[Test]
    public function user_can_delete_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        // Upload avatar first
        $this->actingAs($user)
            ->post(route('account.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ]);

        $user->refresh();
        $avatarPath = $user->avatar;
        Storage::disk('public')->assertExists($avatarPath);

        // Delete avatar
        $response = $this->actingAs($user)
            ->delete(route('account.avatar.destroy'));

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('account.edit'));

        $user->refresh();
        $this->assertNull($user->avatar);
        Storage::disk('public')->assertMissing($avatarPath);
    }

    #[Test]
    public function deleting_avatar_when_none_exists_succeeds(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->delete(route('account.avatar.destroy'));

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('account.edit'));

        $user->refresh();
        $this->assertNull($user->avatar);
    }

    #[Test]
    public function avatar_requires_authentication(): void
    {
        $response = $this->post(route('account.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function avatar_url_attribute_returns_null_when_no_avatar(): void
    {
        $user = User::factory()->create(['avatar' => null]);

        $this->assertNull($user->avatar_url);
    }

    #[Test]
    public function avatar_url_attribute_returns_url_when_avatar_exists(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['avatar' => 'avatars/test.jpg']);

        $this->assertNotNull($user->avatar_url);
        $this->assertStringContainsString('avatars/test.jpg', $user->avatar_url);
    }
}
