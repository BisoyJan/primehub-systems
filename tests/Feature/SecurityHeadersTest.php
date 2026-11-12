<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    #[Test]
    public function login_page_sets_browser_security_headers(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            $response->headers->get('Content-Security-Policy', ''),
        );
    }

    #[Test]
    public function local_csp_allows_websocket_connections_to_the_reverb_daemon(): void
    {
        config([
            'broadcasting.connections.reverb.options.host' => 'reverb.test',
            'broadcasting.connections.reverb.options.port' => 9090,
        ]);

        $response = $this->get(route('login'));

        $csp = $response->headers->get('Content-Security-Policy', '');
        $connectSrc = collect(explode(';', $csp))
            ->map(fn (string $directive) => trim($directive))
            ->first(fn (string $directive) => str_starts_with($directive, 'connect-src'));

        $this->assertNotNull($connectSrc);
        $this->assertStringContainsString('ws://reverb.test:9090', $connectSrc);
        $this->assertStringContainsString('ws://localhost:9090', $connectSrc);
        $this->assertStringContainsString('ws://127.0.0.1:9090', $connectSrc);
    }
}
