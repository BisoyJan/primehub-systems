<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function session_is_created_on_successful_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'is_approved' => true,
        ]);

        $this->assertFalse(Session::has('_token'));

        $response = $this->post(route('login.store'), [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->assertTrue(Session::has('_token'));
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function session_is_regenerated_on_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'is_approved' => true,
        ]);

        // Start a session
        $this->get(route('login'));
        $oldSessionId = Session::getId();

        // Login should regenerate session
        $this->post(route('login.store'), [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $newSessionId = Session::getId();
        $this->assertNotEquals($oldSessionId, $newSessionId);
    }

    #[Test]
    public function session_is_invalidated_on_logout(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $this->actingAs($user);
        $this->get(route('dashboard'));

        $sessionId = Session::getId();
        $this->assertNotEmpty($sessionId);

        $this->post(route('logout'));

        $this->assertNotEquals($sessionId, Session::getId());
        $this->assertGuest();
    }

    #[Test]
    public function session_token_is_regenerated_on_logout(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $this->actingAs($user);
        $oldToken = Session::token();

        $this->post(route('logout'));

        $newToken = Session::token();
        $this->assertNotEquals($oldToken, $newToken);
    }

    #[Test]
    public function authenticated_session_persists_across_requests(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'is_approved' => true,
        ]);

        $this->post(route('login.store'), [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();

        // Make another request
        $response = $this->get(route('dashboard'));
        $response->assertStatus(200);
        $this->assertAuthenticated();
    }

    #[Test]
    public function session_data_is_cleared_on_logout(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        $this->actingAs($user);

        // Add some session data
        Session::put('test_key', 'test_value');
        $this->assertEquals('test_value', Session::get('test_key'));

        $this->post(route('logout'));

        // Session data should be cleared
        $this->assertNull(Session::get('test_key'));
    }

    #[Test]
    public function concurrent_sessions_can_be_managed(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'is_approved' => true,
        ]);

        // First session
        $this->post(route('login.store'), [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);
        $firstSessionId = Session::getId();

        // Logout
        $this->post(route('logout'));

        // Second session (new login)
        $this->post(route('login.store'), [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);
        $secondSessionId = Session::getId();

        $this->assertNotEquals($firstSessionId, $secondSessionId);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function csrf_token_is_present_in_session(): void
    {
        $response = $this->get(route('login'));

        $this->assertTrue(Session::has('_token'));
        $this->assertNotEmpty(Session::token());
    }
}
