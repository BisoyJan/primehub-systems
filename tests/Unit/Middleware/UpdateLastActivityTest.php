<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\UpdateLastActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateLastActivityTest extends TestCase
{
    use RefreshDatabase;

    protected UpdateLastActivity $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new UpdateLastActivity();

        // Register login route for redirect tests
        Route::get('/login', fn() => response('Login'))->name('login');
    }

    #[Test]
    public function it_updates_last_activity_time_for_authenticated_user(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 15]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        $beforeTime = time();

        $this->middleware->handle($request, fn($req) => response('OK'));

        $lastActivity = $request->session()->get('last_activity_time');

        $this->assertNotNull($lastActivity);
        $this->assertGreaterThanOrEqual($beforeTime, $lastActivity);
    }

    #[Test]
    public function it_does_not_update_activity_for_guest(): void
    {
        Auth::logout();

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        $this->middleware->handle($request, fn($req) => response('OK'));

        $lastActivity = $request->session()->get('last_activity_time');

        $this->assertNull($lastActivity);
    }

    #[Test]
    public function it_logs_out_inactive_user_after_timeout(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 1]); // 1 minute timeout
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        // Set last activity to 2 minutes ago (beyond timeout)
        $twoMinutesAgo = time() - (2 * 60);
        $request->session()->put('last_activity_time', $twoMinutesAgo);

        $response = $this->middleware->handle($request, fn($req) => response('OK'));

        $this->assertFalse(Auth::check());
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertTrue($response->isRedirect(route('login')));
    }

    #[Test]
    public function it_allows_user_within_activity_timeout(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 15]); // 15 minute timeout
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        // Set last activity to 5 minutes ago (within timeout)
        $fiveMinutesAgo = time() - (5 * 60);
        $request->session()->put('last_activity_time', $fiveMinutesAgo);

        $response = $this->middleware->handle($request, fn($req) => response('OK'));

        $this->assertTrue(Auth::check());
        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_skips_timeout_check_when_user_has_null_inactivity_timeout(): void
    {
        // User with null inactivity_timeout (disabled auto-logout)
        $user = User::factory()->create(['inactivity_timeout' => null]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        // Set last activity to 16 minutes ago (would normally trigger logout)
        $sixteenMinutesAgo = time() - (16 * 60);
        $request->session()->put('last_activity_time', $sixteenMinutesAgo);

        $response = $this->middleware->handle($request, fn($req) => response('OK'));

        // User should still be logged in because timeout is disabled
        $this->assertTrue(Auth::check());
        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_still_tracks_activity_when_timeout_disabled(): void
    {
        // User with null inactivity_timeout (disabled auto-logout)
        $user = User::factory()->create(['inactivity_timeout' => null]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        $beforeTime = time();

        $this->middleware->handle($request, fn($req) => response('OK'));

        $lastActivity = $request->session()->get('last_activity_time');

        // Activity should still be tracked even when timeout is disabled
        $this->assertNotNull($lastActivity);
        $this->assertGreaterThanOrEqual($beforeTime, $lastActivity);
    }

    #[Test]
    public function it_invalidates_session_on_timeout(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 1]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        $sessionId = $request->session()->getId();
        $request->session()->put('last_activity_time', time() - 120);
        $request->session()->put('test_data', 'should_be_cleared');

        $this->middleware->handle($request, fn($req) => response('OK'));

        // Session should be invalidated
        $this->assertNotEquals($sessionId, $request->session()->getId());
        $this->assertNull($request->session()->get('test_data'));
    }

    #[Test]
    public function it_regenerates_csrf_token_on_timeout(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 1]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        $oldToken = $request->session()->token();
        $request->session()->put('last_activity_time', time() - 120);

        $this->middleware->handle($request, fn($req) => response('OK'));

        $newToken = $request->session()->token();
        $this->assertNotEquals($oldToken, $newToken);
    }

    #[Test]
    public function it_shows_inactivity_message_on_logout(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 1]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));
        $request->session()->put('last_activity_time', time() - 120);

        $response = $this->middleware->handle($request, fn($req) => response('OK'));

        $this->assertEquals(302, $response->getStatusCode());

        // Check redirect has flash message
        $flashMessage = $request->session()->get('message');
        $this->assertStringContainsString('logged out due to inactivity', $flashMessage);
    }

    #[Test]
    public function it_sets_warning_flash_type(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 1]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));
        $request->session()->put('last_activity_time', time() - 120);

        $this->middleware->handle($request, fn($req) => response('OK'));

        $this->assertEquals('warning', $request->session()->get('type'));
    }

    #[Test]
    public function it_passes_request_to_next_middleware_when_active(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 15]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return response('Next');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
        $this->assertEquals('Next', $response->getContent());
    }

    #[Test]
    public function it_does_not_call_next_middleware_on_timeout(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 1]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));
        $request->session()->put('last_activity_time', time() - 120);

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return response('Next');
        };

        $this->middleware->handle($request, $next);

        $this->assertFalse($nextCalled);
    }

    #[Test]
    public function it_handles_first_request_without_last_activity(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 15]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        // No last_activity_time set (first request)

        $response = $this->middleware->handle($request, fn($req) => response('OK'));

        $this->assertEquals('OK', $response->getContent());
        $this->assertNotNull($request->session()->get('last_activity_time'));
    }

    #[Test]
    public function it_converts_minutes_to_seconds_for_timeout(): void
    {
        $user = User::factory()->create(['inactivity_timeout' => 2]); // 2 minutes
        Auth::login($user);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession(app("session.store"));

        // Set to 119 seconds ago (within 2 minute timeout)
        $request->session()->put('last_activity_time', time() - 119);

        $response = $this->middleware->handle($request, fn($req) => response('OK'));

        $this->assertTrue(Auth::check());
        $this->assertEquals('OK', $response->getContent());
    }
}
