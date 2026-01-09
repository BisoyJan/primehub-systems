<?php

use App\Http\Middleware\EnsureUserHasSchedule;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\UpdateLastActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Trust all proxies (needed for ngrok, load balancers, etc.)
        $middleware->trustProxies(
            at: '*',
            headers: \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR |
                    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST |
                    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT |
                    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO |
                    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PREFIX
        );

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            UpdateLastActivity::class,
            EnsureUserHasSchedule::class,
        ]);

        // Register permission and role middleware aliases
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            'approved' => \App\Http\Middleware\CheckUserApproved::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle HTTP exceptions with Inertia error pages
        $exceptions->respond(function (Response $response, \Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            // Only handle specific error codes with Inertia
            if (! in_array($status, [403, 404, 500, 503])) {
                return $response;
            }

            // For Inertia requests or regular web requests, render the error page
            // Skip for API requests or non-HTML requests
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return $response;
            }

            // Only show custom messages in production, not technical details
            $message = null;
            if ($e instanceof HttpExceptionInterface && ! empty($e->getMessage())) {
                // Only use the message if it's user-friendly (doesn't contain technical terms)
                $technicalPatterns = [
                    '/model\s+\[.*\]/i',
                    '/query/i',
                    '/exception/i',
                    '/error in/i',
                    '/call to/i',
                    '/undefined/i',
                    '/class/i',
                    '/method/i',
                ];

                $exceptionMessage = $e->getMessage();
                $isTechnical = false;

                foreach ($technicalPatterns as $pattern) {
                    if (preg_match($pattern, $exceptionMessage)) {
                        $isTechnical = true;
                        break;
                    }
                }

                if (! $isTechnical) {
                    $message = $exceptionMessage;
                }
            }

            return Inertia::render('Errors/Error', [
                'status' => $status,
                'message' => $message,
            ])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
