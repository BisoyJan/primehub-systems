<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\CheckUserApproved;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckUserApprovedTest extends TestCase
{
    use RefreshDatabase;

    protected CheckUserApproved $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new CheckUserApproved();

        // Register pending-approval route for redirect tests
        Route::get('/pending-approval', fn() => response('Pending'))->name('pending-approval');
    }

    #[Test]
    public function it_allows_approved_user_to_proceed(): void
    {
        $user = User::factory()->create(['is_approved' => true]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK')
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_redirects_unapproved_user_to_pending_approval_page(): void
    {
        $user = User::factory()->create(['is_approved' => false]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK')
        );

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertTrue($response->isRedirect(route('pending-approval')));
    }

    #[Test]
    public function it_allows_guest_to_proceed(): void
    {
        // No user authenticated
        Auth::logout();

        $request = Request::create('/test', 'GET');

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK')
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_passes_request_to_next_middleware_for_approved_user(): void
    {
        $user = User::factory()->create(['is_approved' => true]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');

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
    public function it_does_not_call_next_middleware_for_unapproved_user(): void
    {
        $user = User::factory()->create(['is_approved' => false]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return response('Next');
        };

        $this->middleware->handle($request, $next);

        $this->assertFalse($nextCalled);
    }

    #[Test]
    public function it_checks_is_approved_flag_specifically(): void
    {
        // User with is_approved = false (not null, not 0, but boolean false)
        $user = User::factory()->create(['is_approved' => false]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK')
        );

        $this->assertEquals(302, $response->getStatusCode());
    }

    #[Test]
    public function it_allows_newly_approved_user(): void
    {
        $user = User::factory()->create(['is_approved' => false]);
        Auth::login($user);

        // Approve the user
        $user->update(['is_approved' => true]);
        $user->refresh();

        $request = Request::create('/test', 'GET');

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK')
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_uses_auth_facade_to_get_user(): void
    {
        $user = User::factory()->create(['is_approved' => true]);

        // Use Auth::login instead of setUserResolver
        Auth::login($user);

        $request = Request::create('/test', 'GET');

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK')
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_handles_false_is_approved_as_unapproved(): void
    {
        $user = User::factory()->create(['is_approved' => false]);
        Auth::login($user);

        $request = Request::create('/test', 'GET');

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK')
        );

        // false means unapproved, should redirect
        $this->assertEquals(302, $response->getStatusCode());
    }

    #[Test]
    public function it_works_on_different_routes(): void
    {
        $user = User::factory()->create(['is_approved' => false]);
        Auth::login($user);

        $adminRequest = Request::create('/admin/users', 'GET');
        $profileRequest = Request::create('/profile', 'GET');

        $adminResponse = $this->middleware->handle($adminRequest, fn($req) => response('Admin'));
        $profileResponse = $this->middleware->handle($profileRequest, fn($req) => response('Profile'));

        $this->assertEquals(302, $adminResponse->getStatusCode());
        $this->assertEquals(302, $profileResponse->getStatusCode());
    }
}
