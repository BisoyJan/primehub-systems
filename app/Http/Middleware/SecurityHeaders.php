<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Add browser security headers to every response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()', false);
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin', false);
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin', false);

        $response->headers->set(
            'Content-Security-Policy',
            $this->buildContentSecurityPolicy($request),
            false,
        );

        if ($request->isSecure() || $request->secure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }

        return $response;
    }

    protected function buildContentSecurityPolicy(Request $request): string
    {
        $connectSources = "'self'";
        $scriptSources = "'self' 'unsafe-inline' 'unsafe-eval'";

        if (config('app.env') === 'local' || ! $request->isSecure()) {
            // Vite picks the next free port (5173+) when the default is taken.
            $viteHosts = collect(range(5173, 5180))
                ->flatMap(fn (int $port) => [
                    "http://localhost:{$port}",
                    "ws://localhost:{$port}",
                    "http://127.0.0.1:{$port}",
                    "ws://127.0.0.1:{$port}",
                ])
                ->implode(' ');

            $connectSources .= ' '.$viteHosts;
            $scriptSources .= ' '.$viteHosts;
        }

        $basePolicy = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "script-src {$scriptSources}",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "img-src 'self' data: blob:",
            "font-src 'self' data: https://fonts.gstatic.com",
            "connect-src {$connectSources}",
            "media-src 'self'",
            "worker-src 'self' blob:",
        ]);

        if ($request->isSecure() || app()->environment('production')) {
            return $basePolicy.'; upgrade-insecure-requests';
        }

        return $basePolicy;
    }
}
