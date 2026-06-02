<?php

namespace Tests\Feature\Controllers\Accounts;

use App\Models\Attendance;
use App\Models\EmployeeSchedule;
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
            'email' => 'jane.smith@primehubmail.com',
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
            'email' => 'jane.smith@primehubmail.com',
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $user = User::where('email', 'jane.smith@primehubmail.com')->first();
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
            'email' => 'old.user@primehubmail.com',
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
            'password' => Hash::make('OldPassword123!'),
            'email' => 'changepw.user@primehubmail.com',
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

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertNotNull($user->fresh()->deleted_at);
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

    public function test_toggle_active_deactivating_deletes_employee_schedules(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'hired_date' => now()->subYear(),
        ]);

        $schedules = EmployeeSchedule::factory()->count(3)->create([
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseCount('employee_schedules', 3);

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.toggleActive', $user));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseCount('employee_schedules', 0);
    }

    public function test_toggle_active_deactivating_sets_attendance_schedule_to_null(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'hired_date' => now()->subYear(),
        ]);

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
        ]);

        $attendance = Attendance::factory()->create([
            'user_id' => $user->id,
            'employee_schedule_id' => $schedule->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.toggleActive', $user));

        $response->assertRedirect();

        $this->assertNull($attendance->fresh()->employee_schedule_id);
        $this->assertDatabaseMissing('employee_schedules', ['id' => $schedule->id]);
    }

    public function test_toggle_active_activating_requires_hired_date(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'hired_date' => now()->subYear(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.toggleActive', $user), []);

        $response->assertSessionHasErrors('hired_date');
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_toggle_active_activating_does_not_auto_reactivate_schedules(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'hired_date' => now()->subYear(),
        ]);

        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
        ]);

        $newHireDate = now()->format('Y-m-d');

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.toggleActive', $user), ['hired_date' => $newHireDate]);

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertTrue($user->fresh()->is_active);
        $this->assertEquals($newHireDate, $user->fresh()->hired_date->format('Y-m-d'));
        // Schedules are NOT auto-reactivated; admin must manage them via the Schedules page
        $this->assertDatabaseHas('employee_schedules', ['id' => $schedule->id, 'is_active' => false]);
    }

    public function test_toggle_active_activating_updates_hired_date(): void
    {
        $oldDate = now()->subYear()->format('Y-m-d');
        $newDate = now()->format('Y-m-d');

        $user = User::factory()->create([
            'is_active' => false,
            'hired_date' => $oldDate,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.toggleActive', $user), ['hired_date' => $newDate]);

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertTrue($user->fresh()->is_active);
        $this->assertEquals($newDate, $user->fresh()->hired_date->format('Y-m-d'));
    }

    public function test_toggle_active_prevents_toggling_own_account(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.toggleActive', $this->adminUser));

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'error');

        $this->assertTrue($this->adminUser->fresh()->is_active);
    }

    public function test_toggle_active_deactivating_sets_resigned_at(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'hired_date' => now()->subYear(),
            'resigned_at' => null,
        ]);

        $this->actingAs($this->adminUser)
            ->post(route('accounts.toggleActive', $user));

        $fresh = $user->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertNotNull($fresh->resigned_at);
    }

    public function test_toggle_active_rehiring_clears_resigned_at(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'is_approved' => false,
            'hired_date' => now()->subYear(),
            'resigned_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->adminUser)
            ->post(route('accounts.toggleActive', $user), ['hired_date' => now()->format('Y-m-d')]);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->resigned_at);
    }

    public function test_stale_accounts_returns_resigned_accounts_older_than_two_years(): void
    {
        $staleUser = User::factory()->create([
            'is_active' => false,
            'is_approved' => false,
            'hired_date' => now()->subYears(5),
            'resigned_at' => now()->subYears(3),
        ]);

        $recentUser = User::factory()->create([
            'is_active' => false,
            'is_approved' => false,
            'hired_date' => now()->subYears(2),
            'resigned_at' => now()->subMonths(6),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson(route('accounts.staleAccounts'));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($staleUser->id));
        $this->assertFalse($ids->contains($recentUser->id));
    }

    public function test_bulk_delete_stale_permanently_deletes_eligible_accounts(): void
    {
        $staleUser = User::factory()->create([
            'is_active' => false,
            'is_approved' => false,
            'hired_date' => now()->subYears(5),
            'resigned_at' => now()->subYears(3),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.bulkDeleteStale'), ['ids' => [$staleUser->id]]);

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseMissing('users', ['id' => $staleUser->id]);
    }

    public function test_bulk_delete_stale_rejects_non_stale_accounts(): void
    {
        $activeUser = User::factory()->create([
            'is_active' => true,
            'hired_date' => now()->subYear(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('accounts.bulkDeleteStale'), ['ids' => [$activeUser->id]]);

        $response->assertRedirect()
            ->assertSessionHas('flash.type', 'error');

        $this->assertDatabaseHas('users', ['id' => $activeUser->id]);
    }
}
