<?php

namespace Tests\Feature\Controllers\Accounts;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
    }

    public function test_index_displays_user_accounts(): void
    {
        User::factory()->count(5)->create();

        $response = $this->actingAs($this->adminUser)
            ->get(route('accounts.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Index')
                ->has('users.data')
                ->has('filters')
            );
    }

    public function test_index_filters_by_search(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('accounts.index', ['search' => 'John']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Index')
                ->where('filters.search', 'John')
            );
    }

    public function test_index_filters_by_role(): void
    {
        User::factory()->create(['role' => 'Agent']);
        User::factory()->create(['role' => 'Team Lead']);

        $response = $this->actingAs($this->adminUser)
            ->get(route('accounts.index', ['role' => 'Agent']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Index')
                ->where('filters.role', 'Agent')
            );
    }

    public function test_create_displays_create_form(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get(route('accounts.create'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Create')
                ->has('roles')
            );
    }

    public function test_store_creates_new_user_account(): void
    {
        $userData = [
            'first_name' => 'Jane',
            'middle_name' => 'M',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'Agent',
            'hired_date' => '2024-01-15',
        ];

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.store'), $userData);

        $response->assertRedirect(route('accounts.index'))
            ->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseHas('users', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $user = User::where('email', 'jane.smith@example.com')->first();
        $this->assertNotNull($user->approved_at);
        $this->assertTrue(Hash::check('Password123!', $user->password));
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.store'), []);

        $response->assertSessionHasErrors([
            'first_name',
            'last_name',
            'email',
            'password',
            'role',
            'hired_date',
        ]);
    }

    public function test_store_validates_unique_email(): void
    {
        $existingUser = User::factory()->create(['email' => 'existing@example.com']);

        $userData = [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'existing@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'Agent',
            'hired_date' => '2024-01-15',
        ];

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.store'), $userData);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_edit_displays_edit_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->get(route('accounts.edit', $user));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Edit')
                ->has('user')
                ->where('user.id', $user->id)
                ->has('roles')
            );
    }

    public function test_update_updates_user_account(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Old',
            'role' => 'Agent',
        ]);

        $updateData = [
            'first_name' => 'Updated',
            'middle_name' => 'X',
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role' => 'Team Lead',
            'hired_date' => '2024-02-20',
        ];

        $response = $this->actingAs($this->adminUser)
            ->put(route('accounts.update', $user), $updateData);

        $response->assertRedirect(route('accounts.index'))
            ->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Updated',
            'role' => 'Team Lead',
        ]);
    }

    public function test_update_changes_password_when_provided(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!')
        ]);

        $updateData = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
            'role' => $user->role,
            'hired_date' => $user->hired_date?->format('Y-m-d') ?? now()->format('Y-m-d'),
        ];

        $response = $this->actingAs($this->adminUser)
            ->put(route('accounts.update', $user), $updateData);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertFalse(Hash::check('OldPassword123!', $user->password));
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));
    }

    public function test_update_does_not_change_password_when_not_provided(): void
    {
        $user = User::factory()->create();
        $oldPassword = $user->password;

        $updateData = [
            'first_name' => 'Updated',
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role' => $user->role,
            'hired_date' => $user->hired_date,
        ];

        $response = $this->actingAs($this->adminUser)
            ->put(route('accounts.update', $user), $updateData);

        $user->refresh();
        $this->assertEquals($oldPassword, $user->password);
    }

    public function test_destroy_deletes_user_account(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->delete(route('accounts.destroy', $user));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_destroy_prevents_deleting_own_account(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->delete(route('accounts.destroy', $this->adminUser));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'error');

        $this->assertDatabaseHas('users', ['id' => $this->adminUser->id]);
    }

    public function test_approve_approves_unapproved_user(): void
    {
        $user = User::factory()->create([
            'is_approved' => false,
            'approved_at' => null,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.approve', $user));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_approved' => true,
        ]);

        $user->refresh();
        $this->assertNotNull($user->approved_at);
    }

    public function test_approve_returns_info_message_if_already_approved(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.approve', $user));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'info');
    }

    public function test_unapprove_revokes_approval(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.unapprove', $user));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_approved' => false,
            'approved_at' => null,
        ]);
    }

    public function test_unapprove_prevents_unapproving_own_account(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.unapprove', $this->adminUser));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'error');

        $this->assertDatabaseHas('users', [
            'id' => $this->adminUser->id,
            'is_approved' => true,
        ]);
    }

    public function test_unapprove_returns_info_message_if_already_unapproved(): void
    {
        $user = User::factory()->create([
            'is_approved' => false,
            'approved_at' => null,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.unapprove', $user));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'info');
    }
}
