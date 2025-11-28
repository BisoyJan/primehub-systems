<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\CheckPermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CheckPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected PermissionService $permissionService;
    protected CheckPermission $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->middleware = new CheckPermission($this->permissionService);
    }

    #[Test]
    public function it_allows_user_with_required_permission(): void
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'accounts.view'
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_aborts_when_user_is_not_authenticated(): void
    {
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => null);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unauthorized: User not authenticated');

        $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'accounts.view'
        );
    }

    #[Test]
    public function it_aborts_when_user_lacks_permission(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('You do not have permission to access this resource');

        $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'accounts.create'
        );
    }

    #[Test]
    public function it_allows_user_with_any_of_multiple_permissions(): void
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'accounts.view',
            'accounts.create'
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_aborts_when_user_has_none_of_multiple_permissions(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $this->expectException(HttpException::class);

        $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'accounts.create',
            'accounts.delete'
        );
    }

    #[Test]
    public function it_allows_super_admin_with_any_permission(): void
    {
        $user = User::factory()->create(['role' => 'Super Admin']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'any_permission'
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_returns_403_status_code_on_authorization_failure(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        try {
            $this->middleware->handle(
                $request,
                fn($req) => response('OK'),
                'accounts.create'
            );
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    #[Test]
    public function it_passes_request_to_next_middleware_when_authorized(): void
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return response('Next');
        };

        $response = $this->middleware->handle($request, $next, 'accounts.view');

        $this->assertTrue($nextCalled);
        $this->assertEquals('Next', $response->getContent());
    }

    #[Test]
    public function it_handles_single_permission_requirement(): void
    {
        $user = User::factory()->create(['role' => 'HR']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'leave.approve'
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_works_with_permission_service(): void
    {
        $user = User::factory()->create(['role' => 'IT']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        // IT role should have stations.create permission
        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'stations.create'
        );

        $this->assertEquals('OK', $response->getContent());
    }
}
