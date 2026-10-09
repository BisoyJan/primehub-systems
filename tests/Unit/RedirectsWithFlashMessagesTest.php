<?php

namespace Tests\Unit;

use App\Http\Traits\RedirectsWithFlashMessages;
use Illuminate\Http\RedirectResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RedirectsWithFlashMessagesTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class
        {
            use RedirectsWithFlashMessages;

            public function sanitize(mixed $url): ?string
            {
                return $this->safeReturnTo($url);
            }

            public function redirectTo(string $route): RedirectResponse
            {
                return $this->redirectToIndexOrReturnUrl($route);
            }

            public function flash(string $route, string $message): RedirectResponse
            {
                return $this->redirectWithFlash($route, $message);
            }
        };
    }

    #[Test]
    public function it_accepts_relative_paths_with_query_strings(): void
    {
        $this->assertSame('/stations?page=2&site_id=1', $this->subject->sanitize('/stations?page=2&site_id=1'));
        $this->assertSame('/employee-schedules', $this->subject->sanitize('  /employee-schedules  '));
    }

    public static function unsafeReturnToProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'array' => [['/x']],
            'absolute http' => ['https://evil.example/phish'],
            'protocol relative' => ['//evil.example/phish'],
            'backslash protocol relative' => ['/\\evil.example'],
            'relative without slash' => ['stations?page=2'],
            'scheme inside path' => ['/redirect?to=https://evil.example'],
            'control chars' => ["/stations\r\nLocation: evil"],
            'too long' => ['/'.str_repeat('a', 2048)],
        ];
    }

    #[Test]
    #[DataProvider('unsafeReturnToProvider')]
    public function it_rejects_unsafe_return_urls(mixed $url): void
    {
        $this->assertNull($this->subject->sanitize($url));
    }

    #[Test]
    public function it_redirects_to_return_to_when_present_in_request(): void
    {
        request()->merge(['_return_to' => '/stations?search=abc&page=3']);

        $response = $this->subject->redirectTo('home');

        $this->assertSame(url('/stations?search=abc&page=3'), $response->getTargetUrl());
    }

    #[Test]
    public function it_falls_back_to_named_route_when_return_to_is_unsafe(): void
    {
        request()->merge(['_return_to' => 'https://evil.example']);

        $response = $this->subject->redirectTo('home');

        $this->assertSame(route('home'), $response->getTargetUrl());
    }

    #[Test]
    public function it_falls_back_to_named_route_when_return_to_is_missing(): void
    {
        $response = $this->subject->redirectTo('home');

        $this->assertSame(route('home'), $response->getTargetUrl());
    }

    #[Test]
    public function redirect_with_flash_honors_return_to_and_keeps_flash_data(): void
    {
        request()->merge(['_return_to' => '/accounts?role=agent']);

        $response = $this->subject->flash('home', 'Saved');

        $this->assertSame(url('/accounts?role=agent'), $response->getTargetUrl());
        $this->assertSame('Saved', $response->getSession()->get('message'));
        $this->assertSame('success', $response->getSession()->get('type'));
    }
}
