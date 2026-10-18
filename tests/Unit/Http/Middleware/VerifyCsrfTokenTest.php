<?php

namespace Tests\Unit\Http\Middleware;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Http\MarkAsApiKeyRequest;
use Tests\TestCase;

/**
 * T027 (FR-011, R7): every state-changing session request needs the session's token in the
 * X-CSRF-Token header, whatever its content type. Only API-key requests are exempt.
 */
class VerifyCsrfTokenTest extends TestCase
{
    private const PATH = '/api/v2/__testing/csrf';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api.v2', 'restv2.auth:browser', 'restv2.csrf', 'restv2.pending:allow'])
            ->match(['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'], self::PATH, fn () => response()->noContent());

        Route::middleware(['api.v2', MarkAsApiKeyRequest::class, 'restv2.csrf'])
            ->post('/api/v2/__testing/csrf-key', fn () => response()->noContent());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeMethods(): array
    {
        return ['POST' => ['POST'], 'PUT' => ['PUT'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    #[DataProvider('unsafeMethods')]
    public function test_unsafe_methods_without_a_token_are_refused(string $method): void
    {
        $this->csrfToken();

        $this->assertCsrfRefused($this->json($method, self::PATH, ['a' => 1]));
    }

    #[DataProvider('unsafeMethods')]
    public function test_unsafe_methods_with_a_wrong_token_are_refused(string $method): void
    {
        $this->csrfToken();

        $this->assertCsrfRefused($this->json($method, self::PATH, ['a' => 1], ['X-CSRF-Token' => str_repeat('x', 40)]));
    }

    #[DataProvider('unsafeMethods')]
    public function test_unsafe_methods_with_the_token_pass(string $method): void
    {
        $token = $this->csrfToken();

        $this->json($method, self::PATH, ['a' => 1], ['X-CSRF-Token' => $token])->assertNoContent();
    }

    public function test_other_content_types_are_checked_too(): void
    {
        $this->csrfToken();

        $this->assertCsrfRefused($this->call('POST', self::PATH, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'hello'));
        $this->assertCsrfRefused($this->call('POST', self::PATH, ['a' => '1'], [], ['f' => UploadedFile::fake()->create('x.txt', 1)], ['CONTENT_TYPE' => 'multipart/form-data']));
        $this->assertCsrfRefused($this->call('POST', self::PATH, ['a' => '1'], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']));
    }

    public function test_safe_methods_pass_without_a_token(): void
    {
        $this->call('GET', self::PATH)->assertNoContent();
        $this->call('HEAD', self::PATH)->assertNoContent();
        $this->call('OPTIONS', self::PATH)->assertNoContent();
    }

    public function test_a_cross_site_request_is_refused_even_with_the_token(): void
    {
        $token = $this->csrfToken();

        $this->assertCsrfRefused($this->postJson(self::PATH, [], ['X-CSRF-Token' => $token, 'Sec-Fetch-Site' => 'cross-site']));
        $this->postJson(self::PATH, [], ['X-CSRF-Token' => $token, 'Sec-Fetch-Site' => 'same-origin'])->assertNoContent();
        $this->postJson(self::PATH, [], ['X-CSRF-Token' => $token, 'Sec-Fetch-Site' => 'same-site'])->assertNoContent();
    }

    public function test_same_origin_requests_still_need_the_token(): void
    {
        $this->csrfToken();

        $this->assertCsrfRefused($this->postJson(self::PATH, [], ['Sec-Fetch-Site' => 'same-origin']));
    }

    public function test_api_key_requests_need_no_token(): void
    {
        $this->postJson('/api/v2/__testing/csrf-key')->assertNoContent();
    }

    public function test_the_token_is_read_only_from_the_x_csrf_token_header(): void
    {
        $token = $this->csrfToken();

        $this->assertCsrfRefused($this->postJson(self::PATH, ['_token' => $token]));
        $this->assertCsrfRefused($this->call('POST', self::PATH, ['_token' => $token]));
        $this->assertCsrfRefused($this->postJson(self::PATH, [], ['X-XSRF-TOKEN' => $token]));
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertCsrfRefused(TestResponse $response): void
    {
        $response->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
    }
}
