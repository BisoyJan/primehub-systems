<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\CheckRole;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CheckRoleTest extends TestCase
{
    use RefreshDatabase;

    protected PermissionService $permissionService;
    protected CheckRole $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->middleware = new CheckRole($this->permissionService);
    }

    #[Test]
    public function it_allows_user_with_required_role(): void
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'Admin'
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
            'Admin'
        );
    }

    #[Test]
    public function it_aborts_when_user_has_wrong_role(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('You do not have the required role');

        $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'Admin'
        );
    }

    #[Test]
    public function it_allows_user_with_any_of_multiple_roles(): void
    {
        $user = User::factory()->create(['role' => 'HR']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'Admin',
            'HR',
            'IT'
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_aborts_when_user_has_none_of_multiple_roles(): void
    {
        $user = User::factory()->create(['role' => 'Agent']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $this->expectException(HttpException::class);

        $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'Admin',
            'HR',
            'IT'
        );
    }

    #[Test]
    public function it_allows_super_admin_role(): void
    {
        $user = User::factory()->create(['role' => 'Super Admin']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'Super Admin'
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
                'Admin'
            );
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    #[Test]
    public function it_passes_request_to_next_middleware_when_authorized(): void
    {
        $user = User::factory()->create(['role' => 'IT']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return response('Next');
        };

        $response = $this->middleware->handle($request, $next, 'IT');

        $this->assertTrue($nextCalled);
        $this->assertEquals('Next', $response->getContent());
    }

    #[Test]
    public function it_handles_team_lead_role(): void
    {
        $user = User::factory()->create(['role' => 'Team Lead']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'Team Lead'
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_handles_utility_role(): void
    {
        $user = User::factory()->create(['role' => 'Utility']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'Utility'
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_works_with_permission_service(): void
    {
        $user = User::factory()->create(['role' => 'Admin']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        // Should use PermissionService::userHasRole
        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'Admin',
            'Super Admin'
        );

        $this->assertEquals('OK', $response->getContent());
    }

    #[Test]
    public function it_handles_case_sensitive_role_names(): void
    {
        $user = User::factory()->create(['role' => 'Super Admin']);
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $this->middleware->handle(
            $request,
            fn($req) => response('OK'),
            'Super Admin'
        );

        $this->assertEquals('OK', $response->getContent());
    }
}
