<?php

namespace Tests\Feature\Auth;

use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * T026 (FR-011, R6, R7): getCsrfToken starts an anonymous session and returns its token. The
 * session cookie is __Host-, Secure, HttpOnly, SameSite=Strict, Path=/ and has no Domain; no
 * script-readable XSRF-TOKEN cookie exists.
 */
class CsrfTokenTest extends TestCase
{
    public function test_it_returns_the_session_token(): void
    {
        $response = $this->getJson('/api/v2/csrf-token');

        $response->assertOk()->assertJsonPath('data.headerName', 'X-CSRF-Token');
        $this->assertGreaterThanOrEqual(40, strlen((string) $response->json('data.token')));
    }

    public function test_the_token_is_stable_for_the_session(): void
    {
        $first = $this->getJson('/api/v2/csrf-token')->json('data.token');
        $second = $this->getJson('/api/v2/csrf-token')->json('data.token');

        $this->assertSame($first, $second);
    }

    public function test_a_new_session_gets_a_new_token(): void
    {
        $first = $this->getJson('/api/v2/csrf-token')->json('data.token');
        $this->forgetCookies();
        $second = $this->getJson('/api/v2/csrf-token')->json('data.token');

        $this->assertNotSame($first, $second);
    }

    public function test_the_session_cookie_is_locked_down(): void
    {
        $response = $this->getJson('/api/v2/csrf-token');

        $cookies = collect($response->headers->getCookies())->keyBy(fn (Cookie $c): string => $c->getName());

        $this->assertSame('__Host-restv2_session', config('session.cookie'));
        $this->assertTrue($cookies->has('__Host-restv2_session'));
        $cookie = $cookies->get('__Host-restv2_session');
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertFalse($cookies->has('XSRF-TOKEN'));

        $header = implode("\n", $response->headers->all('set-cookie'));
        $this->assertStringContainsString('secure', strtolower($header));
        $this->assertStringContainsString('httponly', strtolower($header));
        $this->assertStringContainsString('samesite=strict', strtolower($header));
        $this->assertStringNotContainsString('domain=', strtolower($header));
    }
}
