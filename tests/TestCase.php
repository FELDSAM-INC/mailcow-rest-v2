<?php

namespace Tests;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\Dns\DnsResolver;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\Ispconfig\IspconfigDnsClient;
use App\Clients\Memcached\MemcachedFlusher;
use App\Clients\PublicAddress\PublicAddressLookup;
use App\Clients\Releases\ReleaseSource;
use App\Clients\Rspamd\RspamdController;
use App\Clients\Smtp\SmtpProbe;
use App\Services\Auth\SessionManager;
use App\Services\Dkim\DkimDnsPublisher;
use App\Support\Clock\Clock;
use App\Support\Clock\Sleeper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Clock\FakeSleeper;
use Tests\Support\Contract\OpenApiContract;
use Tests\Support\Dkim\DkimAssertions;
use Tests\Support\Dns\FakeClock;
use Tests\Support\Dns\FakeDnsResolver;
use Tests\Support\Fakes\FakeDockerApiClient;
use Tests\Support\Fakes\FakeIspconfigDnsClient;
use Tests\Support\Fakes\FakeMemcachedFlusher;
use Tests\Support\Fakes\FakePublicAddressLookup;
use Tests\Support\Fakes\FakeReleaseSource;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\Rspamd\FakeRspamdController;
use Tests\Support\Smtp\FakeSmtpProbe;

/**
 * Base class of every Laravel test (research R3, R13).
 *
 *  - Runs on MariaDB with the upstream schema fixture, inside a transaction per test
 *    (never RefreshDatabase: it would drop mailcow's tables).
 *  - Flushes the test Redis database (index 15 only) before each test.
 *  - Validates every request and response against the contract. Response validation cannot be
 *    turned off; request validation only in tests named *Invalid* or *Malformed*.
 *  - Keeps the cookies a response sets, like a browser, for the following requests.
 *  - Records sign-in throttling delays instead of waiting (`sleeper()`).
 *  - Fakes the outbound services of feature 002 and 010 with asserting fakes (`dockerApi()`,
 *    `memcached()`, `ispconfig()`) and binds a clock that moves only when the test advances it
 *    (`clock()`). Feature 011 adds the Rspamd controller (`rspamd()`), the update check's release
 *    source (`releases()`) and the IP check service (`publicAddresses()`). DNS and the SMTP
 *    connection test of feature 009 are faked as well, so that no test resolves a real name or
 *    opens an SMTP connection (Tests\Support\Routing\RoutingTestHelpers).
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;
    use DkimAssertions;

    /** The User-Agent that Laravel's test requests send. */
    protected const USER_AGENT = 'Symfony';

    private bool $validateRequests = true;

    private FakeSleeper $sleeper;

    private FakeDockerApiClient $dockerApi;

    private FakeMemcachedFlusher $memcached;

    private FakeIspconfigDnsClient $ispconfig;

    private FakeClock $clock;

    private FakeRspamdController $rspamd;

    private FakeReleaseSource $releases;

    private FakePublicAddressLookup $publicAddresses;

    /** @var array<string, string> */
    private array $cookieJar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushTestRedis();
        $this->app->instance(Sleeper::class, $this->sleeper = new FakeSleeper);
        $this->app->instance(Clock::class, $this->clock = new FakeClock);
        $this->app->instance(DockerApiClient::class, $this->dockerApi = new FakeDockerApiClient);
        $this->app->instance(MemcachedFlusher::class, $this->memcached = new FakeMemcachedFlusher);
        $this->app->instance(IspconfigDnsClient::class, $this->ispconfig = new FakeIspconfigDnsClient($this->clock));
        $this->app->instance(RspamdController::class, $this->rspamd = new FakeRspamdController);
        $this->app->instance(ReleaseSource::class, $this->releases = new FakeReleaseSource);
        $this->app->instance(PublicAddressLookup::class, $this->publicAddresses = new FakePublicAddressLookup);
        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
        $this->app->instance(SmtpProbe::class, new FakeSmtpProbe);
        DkimDnsPublisher::resetWarnings();
    }

    protected function dockerApi(): FakeDockerApiClient
    {
        return $this->dockerApi;
    }

    protected function memcached(): FakeMemcachedFlusher
    {
        return $this->memcached;
    }

    protected function ispconfig(): FakeIspconfigDnsClient
    {
        return $this->ispconfig;
    }

    protected function clock(): FakeClock
    {
        return $this->clock;
    }

    protected function rspamd(): FakeRspamdController
    {
        return $this->rspamd;
    }

    protected function releases(): FakeReleaseSource
    {
        return $this->releases;
    }

    protected function publicAddresses(): FakePublicAddressLookup
    {
        return $this->publicAddresses;
    }

    /**
     * The recording Sleeper bound for the test: the throttling delays the requests asked for.
     */
    protected function sleeper(): FakeSleeper
    {
        return $this->sleeper;
    }

    /**
     * @param  string  $method
     * @param  string  $uri
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     * @param  string|null  $content
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, array_merge($this->cookieJar, $cookies), $files, $server, $content);

        $this->rememberCookies($response->baseResponse);

        $request = $response->baseRequest;
        $contract = OpenApiContract::shared();
        $violations = [];

        if ($this->validateRequests) {
            $violations = $contract->validateRequest($request);
        }

        $violations = [...$violations, ...$contract->validateResponse($request, $response->baseResponse)];

        if ($violations !== []) {
            $this->fail("Contract violation:\n  - ".implode("\n  - ", $violations)."\nResponse body: ".substr((string) $response->baseResponse->getContent(), 0, 2000));
        }

        return $response;
    }

    /**
     * Like Laravel's json(), but a body-less method without data sends no body at all (Laravel
     * would send `[]`, which no operation without a request body documents).
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @param  int  $options
     * @return TestResponse<Response>
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        if ($data === [] && in_array(strtoupper($method), ['GET', 'HEAD', 'DELETE', 'OPTIONS'], true)) {
            return $this->call(
                $method,
                $uri,
                [],
                $this->prepareCookiesForJsonRequest(),
                [],
                $this->transformHeadersToServerVars(array_merge(['Accept' => 'application/json'], $headers)),
            );
        }

        return parent::json($method, $uri, $data, $headers, $options);
    }

    /**
     * A POST without a request body, for operations that document none (challenges).
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function postEmpty(string $uri, array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars(array_merge(['Accept' => 'application/json'], $headers)));
    }

    /**
     * A JSON Merge Patch (RFC 7396) request, `application/merge-patch+json`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function patchMerge(string $uri, array $data, array $headers = []): TestResponse
    {
        return $this->json('PATCH', $uri, $data, array_merge(['Content-Type' => 'application/merge-patch+json'], $headers));
    }

    /**
     * Skips request validation for the rest of the test. Only tests that deliberately send
     * contract-violating input (a name containing "Invalid" or "Malformed") may do so.
     */
    protected function withoutRequestValidation(): static
    {
        if (preg_match('/invalid|malformed/i', $this->name()) !== 1) {
            throw new LogicException('withoutRequestValidation() is only allowed in tests whose name contains "Invalid" or "Malformed".');
        }

        $this->validateRequests = false;

        return $this;
    }

    /**
     * The session's anti-forgery token, from `GET /csrf-token`; the session cookie is kept.
     */
    protected function csrfToken(): string
    {
        return (string) $this->getJson('/api/v2/csrf-token')->assertOk()->json('data.token');
    }

    /**
     * Writes a signed-in principal into a new session, as a completed sign-in would, and keeps
     * its cookie. Returns the session identifier.
     */
    protected function signInAs(string $username, Role $role, AuthMethod $method = AuthMethod::Password, string $userAgent = self::USER_AGENT): string
    {
        $session = $this->newSessionStore();

        $this->app->make(SessionManager::class)->promote($session, new Principal(
            username: $username,
            role: $role,
            accountKind: $role === Role::MailboxUser ? AccountKind::Mailbox : AccountKind::Admin,
            authMethod: $method,
            signedInAt: CarbonImmutable::now('UTC'),
        ), $userAgent);

        return $this->persistSession($session);
    }

    /**
     * Writes a pending sign-in (password verified, second factor outstanding) into a new session
     * and keeps its cookie. Returns the session identifier.
     *
     * @param  list<int>  $methodIds
     */
    protected function signInPending(string $username, Role $role, array $methodIds = [], string $userAgent = self::USER_AGENT): string
    {
        $session = $this->newSessionStore();

        $this->app->make(SessionManager::class)->beginPending(
            $session,
            $username,
            $role,
            $role === Role::MailboxUser ? AccountKind::Mailbox : AccountKind::Admin,
            $methodIds,
            $userAgent,
        );

        return $this->persistSession($session);
    }

    protected function travelPastChallengeTtl(): void
    {
        $this->travel((int) config('restv2.challenge_ttl') + 1)->seconds();
    }

    /**
     * Drops every cookie the test client keeps.
     */
    protected function forgetCookies(): void
    {
        $this->cookieJar = [];
    }

    /**
     * Sends the given session identifier as the session cookie from now on.
     */
    protected function useSessionCookie(string $sessionId): void
    {
        $this->cookieJar[$this->sessionCookieName()] = $sessionId;
    }

    protected function sessionCookieName(): string
    {
        return (string) config('session.cookie');
    }

    protected function sessionCookie(): ?string
    {
        return $this->cookieJar[$this->sessionCookieName()] ?? null;
    }

    protected function mailcow(): MailcowFactory
    {
        return new MailcowFactory;
    }

    private function newSessionStore(): Store
    {
        $handler = $this->app['session']->driver()->getHandler();

        $session = new Store($this->sessionCookieName(), $handler, null, 'json');
        $session->start();

        return $session;
    }

    private function persistSession(Store $session): string
    {
        $session->save();
        $this->cookieJar[$session->getName()] = $session->getId();

        return $session->getId();
    }

    private function rememberCookies(Response $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookieJar[$cookie->getName()]);
            } else {
                $this->cookieJar[$cookie->getName()] = (string) $cookie->getValue();
            }
        }
    }

    /**
     * FLUSHDB on the test Redis database. Refuses unless the environment is `testing` and the
     * selected database is 15, so that it can never empty mailcow's database 0.
     */
    private function flushTestRedis(): void
    {
        if (! $this->app->environment('testing')) {
            throw new RuntimeException('Tests must run with APP_ENV=testing.');
        }

        if ((int) config('database.redis.restv2.database') !== 15) {
            throw new RuntimeException('Tests must use Redis database 15 (REDIS_DB=15 in .env.testing).');
        }

        $connection = Redis::connection('restv2');
        $client = $connection->client();

        if (! $client instanceof \Redis || $client->getDbNum() !== 15) {
            throw new RuntimeException('Refusing to flush a Redis database other than 15.');
        }

        $connection->flushdb();
    }
}
