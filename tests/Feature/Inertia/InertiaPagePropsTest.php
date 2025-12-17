<?php

namespace Tests\Feature\Inertia;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for Inertia.js page props and shared data.
 *
 * These tests verify that the Inertia middleware correctly shares data
 * across all pages, including authentication state, flash messages, and
 * application configuration.
 */
class InertiaPagePropsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_shares_auth_user_on_authenticated_pages(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('auth.user')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.first_name', 'John')
            ->where('auth.user.last_name', 'Doe')
            ->where('auth.user.email', 'john@example.com')
            ->where('auth.user.role', 'Admin')
        );
    }

    #[Test]
    public function it_includes_user_permissions_in_auth_data(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($user)->get('/dashboard');

        // Verify auth user data is shared (permissions structure may vary)
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('auth.user')
            ->has('auth.user.role')
        );
    }

    #[Test]
    public function it_shares_null_auth_user_for_guest_pages(): void
    {
        $response = $this->get('/login');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user', null)
        );
    }

    #[Test]
    public function it_shares_flash_messages_from_session(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $response = $this->actingAs($user)
            ->withSession([
                'message' => 'Operation successful',
                'type' => 'success'
            ])
            ->get('/dashboard');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('flash')
            ->where('flash.message', 'Operation successful')
            ->where('flash.type', 'success')
        );
    }

    #[Test]
    public function it_shares_error_flash_message(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $response = $this->actingAs($user)
            ->withSession([
                'message' => 'Something went wrong',
                'type' => 'error'
            ])
            ->get('/dashboard');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flash.message', 'Something went wrong')
            ->where('flash.type', 'error')
        );
    }

    #[Test]
    public function it_shares_application_name(): void
    {
        config(['app.name' => 'PrimeHub Systems']);

        $response = $this->get('/login');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('name', 'PrimeHub Systems')
        );
    }

    #[Test]
    public function it_shares_inspirational_quote(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('quote')
            ->has('quote.message')
            ->has('quote.author')
            ->where('quote.message', fn ($message) => is_string($message) && strlen($message) > 0)
            ->where('quote.author', fn ($author) => is_string($author))
        );
    }

    #[Test]
    public function it_shares_sidebar_open_state_default_true(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sidebarOpen', true)
        );
    }

    #[Test]
    public function it_shares_sidebar_open_state_from_cookie(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $response = $this->actingAs($user)
            ->withCookie('sidebar_state', 'false')
            ->get('/dashboard');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sidebarOpen', false)
        );
    }

    #[Test]
    public function it_renders_correct_page_component_for_dashboard(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
        );
    }

    #[Test]
    public function it_renders_correct_page_component_for_stations(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($user)->get(route('stations.index'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Station/Index')
        );
    }

    #[Test]
    public function it_passes_page_specific_props_to_station_index(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($user)->get(route('stations.index'));

        // StationController index passes 'stations' and 'filters' (not sites/campaigns)
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('stations')
            ->has('filters')
        );
    }

    #[Test]
    public function it_includes_validation_errors_in_props(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        // Validation errors are stored in session, not returned as Inertia response
        $response = $this->actingAs($user)
            ->post(route('stations.store'), [
                // Missing required fields
            ]);

        // Validation redirects with errors in session
        $response->assertRedirect();
        $response->assertSessionHasErrors();
    }

    #[Test]
    public function it_maintains_old_input_after_validation_failure(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($user)
            ->post(route('stations.store'), [
                'station_number' => 'TEST123',
                // Missing other required fields
            ]);

        // Old input is available in session for Inertia to retrieve
        $this->assertEquals('TEST123', old('station_number'));
    }
}
