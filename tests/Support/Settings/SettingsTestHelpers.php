<?php

namespace Tests\Support\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;

/**
 * Callers, requests and lookups shared by the tests of feature 013. Used in Tests\TestCase
 * subclasses, on top of feature 002's DomainTestHelpers (asAdmin(), asDomainAdmin(),
 * asMailboxUser(), csrf(), redis(), auditOf()).
 */
trait SettingsTestHelpers
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * The `X-API-Key` header of a key owned by the administrator `admin`.
     *
     * @return array<string, string>
     */
    protected function adminKey(string $accessLevel = 'readWrite'): array
    {
        if (! DB::table('admin')->where('username', 'admin')->exists()) {
            $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        }

        return $this->keyHeader($this->apiKey('admin', $accessLevel));
    }

    /**
     * A JSON request; state-changing session requests carry the anti-forgery token, key requests
     * (an `X-API-Key` header) none. PATCH is a JSON Merge Patch.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function send(string $method, string $uri, ?array $body = null, array $headers = []): TestResponse
    {
        if (! isset($headers['X-API-Key']) && $method !== 'GET') {
            $headers += $this->csrf();
        }

        return match (true) {
            $method === 'PATCH' => $this->patchMerge($uri, $body ?? [], $headers),
            $body === null && $method === 'POST' => $this->postEmpty($uri, $headers),
            $body === null => $this->json($method, $uri, [], $headers),
            default => $this->json($method, $uri, $body, $headers),
        };
    }

    /**
     * A raw request body (a logo upload) with the given content type.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function sendRaw(string $method, string $uri, string $body, string $contentType, array $headers = []): TestResponse
    {
        if (! isset($headers['X-API-Key'])) {
            $headers += $this->csrf();
        }

        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => $contentType] + $headers);

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    /**
     * A value of mailcow's shared store (connection `mailcow`, no prefix); null deletes it.
     */
    protected function sharedValue(string $key, ?string $value): void
    {
        $value === null ? $this->redis()->del($key) : $this->redis()->set($key, $value);
    }

    protected function sharedGet(string $key): ?string
    {
        $value = $this->redis()->get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * The failure audit records of an event type.
     *
     * @return list<\stdClass>
     */
    protected function refusalsOf(string $eventType): array
    {
        return array_values(array_filter($this->auditOf($eventType), fn (\stdClass $r): bool => $r->outcome === 'failure'));
    }

    /**
     * The success audit records of an event type.
     *
     * @return list<\stdClass>
     */
    protected function successesOf(string $eventType): array
    {
        return array_values(array_filter($this->auditOf($eventType), fn (\stdClass $r): bool => $r->outcome === 'success'));
    }
}
