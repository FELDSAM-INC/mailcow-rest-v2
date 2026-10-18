<?php

namespace Tests\Support\Dkim;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\Support\Redis\UnavailableSharedStore;

/**
 * Callers, requests and lookups shared by the tests of feature 010. Used in Tests\TestCase
 * subclasses.
 */
trait DkimTestHelpers
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * A JSON request with the anti-forgery token of the signed-in session (none for a key or a
     * safe method).
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
     * The audit records of an event type (and target ID), oldest first.
     *
     * @return list<stdClass>
     *
     * @phpstan-impure
     */
    protected function auditOfType(string $eventType, ?string $targetId = null): array
    {
        $query = DB::table('restv2_audit_events')->where('event_type', $eventType);

        if ($targetId !== null) {
            $query->where('target_id', $targetId);
        }

        return $query->orderBy('occurred_at')->orderBy('id')->get()->map(function (stdClass $row): stdClass {
            $row->details = json_decode((string) $row->details, true);

            return $row;
        })->all();
    }

    /**
     * The only audit record of an event type.
     */
    protected function onlyAudit(string $eventType): stdClass
    {
        $records = $this->auditOfType($eventType);
        $this->assertCount(1, $records, "Expected exactly one {$eventType} audit record.");

        return $records[0];
    }

    /**
     * @phpstan-impure
     */
    protected function auditTotal(): int
    {
        return DB::table('restv2_audit_events')->count();
    }

    /**
     * Switches the FeldHost ISPConfig publication on, with the faked client.
     */
    protected function enablePublication(): void
    {
        config([
            'restv2.dkim.dns_publication' => true,
            'restv2.ispconfig.url' => 'https://ispconfig.example:8080/remote/json.php',
            'restv2.ispconfig.username' => 'remote',
            'restv2.ispconfig.password' => 'IspSecret-42',
        ]);
    }

    /**
     * mailcow's shared store as unreachable for the rest of the test.
     */
    protected function storeUnavailable(): void
    {
        UnavailableSharedStore::bind($this->app);
    }

    /**
     * A stored DKIM field, read directly.
     */
    protected function dkimField(string $hash, string $field): ?string
    {
        $value = $this->redis()->hGet($hash, $field);

        return is_string($value) ? $value : null;
    }
}
