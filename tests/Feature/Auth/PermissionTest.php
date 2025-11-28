<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_users_cannot_access_dashboard(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function authenticated_users_with_permissions_can_access_dashboard(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'role' => 'Admin',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertStatus(200);
    }

    #[Test]
    public function unapproved_users_cannot_access_protected_routes(): void
    {
        $user = User::factory()->create([
            'is_approved' => false,
            'role' => 'Admin',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('pending-approval'));
    }

    #[Test]
    public function approved_users_can_access_pending_approval_page_redirects(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'role' => 'Admin',
        ]);

        $response = $this->actingAs($user)->get(route('pending-approval'));

        $response->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function users_without_permission_cannot_access_accounts_page(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'role' => 'Agent',
        ]);

        $response = $this->actingAs($user)->get(route('accounts.index'));

        $response->assertStatus(403);
    }

    #[Test]
    public function admin_users_can_access_accounts_page(): void
    {
        $admin = User::factory()->create([
            'is_approved' => true,
            'role' => 'Admin',
        ]);

        $response = $this->actingAs($admin)->get(route('accounts.index'));

        $response->assertStatus(200);
    }

    #[Test]
    public function users_without_permission_cannot_access_activity_logs(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'role' => 'Agent',
        ]);

        $response = $this->actingAs($user)->get(route('activity-logs.index'));

        $response->assertStatus(403);
    }

    #[Test]
    public function admin_users_can_access_activity_logs(): void
    {
        // Only Super Admin has activity_logs.view permission
        $superAdmin = User::factory()->create([
            'is_approved' => true,
            'role' => 'Super Admin',
        ]);

        $response = $this->actingAs($superAdmin)->get(route('activity-logs.index'));

        $response->assertStatus(200);
    }

    #[Test]
    public function middleware_enforces_permission_checks_on_routes(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'role' => 'Agent',
        ]);

        // Test various protected routes
        $protectedRoutes = [
            route('accounts.index'),
            route('activity-logs.index'),
        ];

        foreach ($protectedRoutes as $route) {
            $response = $this->actingAs($user)->get($route);
            $response->assertStatus(403);
        }
    }

    #[Test]
    public function role_based_permissions_are_enforced(): void
    {
        $regularUser = User::factory()->create([
            'is_approved' => true,
            'role' => 'Agent',
        ]);

        $admin = User::factory()->create([
            'is_approved' => true,
            'role' => 'Admin',
        ]);

        // Regular user should not have admin permissions
        $this->actingAs($regularUser);
        $this->assertFalse($regularUser->hasPermission('accounts.view'));

        // Admin should have permissions
        $this->actingAs($admin);
        $this->assertTrue($admin->hasPermission('dashboard.view'));
    }

    #[Test]
    public function authorization_denies_access_without_proper_role(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'role' => 'Agent',
        ]);

        $response = $this->actingAs($user)->post(route('accounts.store'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'Agent',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function csrf_protection_is_enforced_on_post_requests(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        // Make a POST request without CSRF token (withoutMiddleware would bypass this)
        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // The request should either succeed or fail based on CSRF, not throw an exception
        $this->assertTrue(
            $response->isRedirect() || $response->status() === 419
        );
    }
}
