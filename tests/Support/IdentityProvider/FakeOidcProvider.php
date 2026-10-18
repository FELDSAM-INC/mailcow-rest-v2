<?php

namespace Tests\Support\IdentityProvider;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

/**
 * A scripted Keycloak or generic OIDC provider on Http::fake() (feature 012 T009). Requests are
 * routed by URL and grant type to the endpoints
 *
 *  - `token:authorization_code`, `token:refresh_token`, `token:client_credentials` (token endpoint),
 *  - `introspect` (Keycloak token introspection), `userinfo`, `users` (Keycloak admin users),
 *
 * each answering its scripted responses in order, the last one repeated. An endpoint without a
 * script answers 404 and is recorded as unscripted. Every request is recorded with its form fields,
 * headers and Guzzle options (`verify`, `allow_redirects`, `timeout`).
 */
final class FakeOidcProvider
{
    /** @var array<string, list<\Closure(Request): (PromiseInterface)>> */
    private array $scripts = [];

    /** @var list<array{endpoint: string, url: string, method: string, form: array<string, mixed>, headers: array<string, mixed>, options: array<string, mixed>, query: array<string, mixed>}> */
    private array $requests = [];

    public static function install(): self
    {
        $fake = new self;
        Http::fake(fn (Request $request, array $options = []): PromiseInterface => $fake->handle($request, $options));

        return $fake;
    }

    public function tokenIssued(string $grant = 'authorization_code', string $accessToken = 'provider-access-token', ?string $refreshToken = 'provider-refresh-token', int $expiresIn = 300): self
    {
        $body = ['access_token' => $accessToken, 'token_type' => 'Bearer', 'expires_in' => $expiresIn];

        if ($refreshToken !== null) {
            $body['refresh_token'] = $refreshToken;
        }

        return $this->push('token:'.$grant, fn (): PromiseInterface => Http::response($body, 200));
    }

    public function tokenRefused(string $grant = 'authorization_code', string $error = 'invalid_grant', int $status = 400): self
    {
        return $this->push('token:'.$grant, fn (): PromiseInterface => Http::response(['error' => $error, 'error_description' => 'refused'], $status));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function status(string $endpoint, int $status, string $body = '', array $headers = []): self
    {
        return $this->push($endpoint, fn (): PromiseInterface => Http::response($body, $status, $headers));
    }

    public function redirect(string $endpoint, string $location = 'https://elsewhere.example.net/'): self
    {
        return $this->status($endpoint, 302, '', ['Location' => $location]);
    }

    /**
     * A connection failure with a cURL error number (6, 7: unreachable; 28: time-out; 60: certificate).
     */
    public function connectionError(string $endpoint, int $errno): self
    {
        return $this->push($endpoint, fn (Request $request): PromiseInterface => Create::rejectionFor(new ConnectException(
            "cURL error {$errno}: simulated failure (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)",
            $request->toPsrRequest(),
        )));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function userInfo(array $claims): self
    {
        return $this->push('userinfo', fn (): PromiseInterface => Http::response($claims, 200));
    }

    public function introspection(bool $active): self
    {
        return $this->push('introspect', fn (): PromiseInterface => Http::response(['active' => $active], 200));
    }

    /**
     * @param  list<array<string, mixed>>  $users
     */
    public function users(array $users): self
    {
        return $this->push('users', fn (): PromiseInterface => Http::response($users, 200));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function handle(Request $request, array $options): PromiseInterface
    {
        $endpoint = $this->route($request);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $this->requests[] = [
            'endpoint' => $endpoint,
            'url' => $request->url(),
            'method' => $request->method(),
            'form' => $request->isForm() ? $request->data() : [],
            'headers' => $request->headers(),
            'options' => $options,
            'query' => $query,
        ];

        $script = $this->scripts[$endpoint] ?? [];

        if ($script === []) {
            return Http::response(['error' => 'unscripted'], 404);
        }

        $answer = count($script) > 1 ? array_shift($this->scripts[$endpoint]) : $script[0];

        return $answer($request);
    }

    private function route(Request $request): string
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/token/introspect')) {
            return 'introspect';
        }

        if (preg_match('#/admin/realms/[^/]+/users$#', $path) === 1) {
            return 'users';
        }

        if (str_ends_with($path, '/userinfo')) {
            return 'userinfo';
        }

        if (str_ends_with($path, '/token')) {
            $grant = $request->isForm() ? ($request->data()['grant_type'] ?? '') : '';

            return 'token:'.$grant;
        }

        return 'unscripted:'.$path;
    }

    private function push(string $endpoint, \Closure $answer): self
    {
        $this->scripts[$endpoint][] = $answer;

        return $this;
    }

    /**
     * @return list<array{endpoint: string, url: string, method: string, form: array<string, mixed>, headers: array<string, mixed>, options: array<string, mixed>, query: array<string, mixed>}>
     */
    public function sent(?string $endpoint = null): array
    {
        return array_values(array_filter($this->requests, fn (array $r): bool => $endpoint === null || $r['endpoint'] === $endpoint));
    }

    /**
     * @return array{endpoint: string, url: string, method: string, form: array<string, mixed>, headers: array<string, mixed>, options: array<string, mixed>, query: array<string, mixed>}
     */
    public function last(string $endpoint): array
    {
        $sent = $this->sent($endpoint);
        Assert::assertNotEmpty($sent, "Nothing was sent to {$endpoint}.");

        return $sent[count($sent) - 1];
    }

    public function assertSentCount(string $endpoint, int $count): void
    {
        Assert::assertCount($count, $this->sent($endpoint), "Requests to {$endpoint}.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], array_column($this->requests, 'endpoint'), 'The identity provider was contacted.');
    }

    public function assertNoUnscripted(): void
    {
        $unscripted = array_filter(array_column($this->requests, 'endpoint'), fn (string $e): bool => str_starts_with($e, 'unscripted') || ! isset($this->scripts[$e]));

        Assert::assertSame([], array_values($unscripted), 'Requests without a scripted answer.');
    }
}
