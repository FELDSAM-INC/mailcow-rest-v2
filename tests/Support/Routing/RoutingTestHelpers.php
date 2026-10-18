<?php

namespace Tests\Support\Routing;

use App\Auth\Role;
use App\Clients\Dns\DnsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Dns\FakeDnsResolver;

/**
 * Callers, rows, requests and lookups shared by the tests of feature 009. Used in Tests\TestCase
 * subclasses.
 */
trait RoutingTestHelpers
{
    use AliasTestHelpers;
    use ApiKeys;

    private ?FakeDnsResolver $fakeDns = null;

    /**
     * The DNS fake bound for the rest of the test.
     */
    protected function dns(): FakeDnsResolver
    {
        if ($this->fakeDns === null) {
            $this->fakeDns = new FakeDnsResolver;
            $this->app->instance(DnsResolver::class, $this->fakeDns);
        }

        return $this->fakeDns;
    }

    /**
     * A domain administrator of the given domains with the routing flags (columns of `da_acl`)
     * set as given and every other routing flag off, signed in.
     *
     * @param  list<string>  $domains
     * @param  array<string, int|bool>  $flags
     */
    protected function asRoutingDomainAdmin(array $domains, array $flags = [], string $username = 'reseller'): void
    {
        $this->asDomainAdmin($domains, $username, array_merge(['domain_relayhost' => 0, 'mailbox_relayhost' => 0, 'bcc_maps' => 0], $flags));
    }

    /**
     * Sends a JSON request with the anti-forgery token of the signed-in session (none for a key).
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
     * @phpstan-impure
     */
    protected function relayhostRow(int $id): ?stdClass
    {
        $row = DB::table('relayhosts')->where('id', $id)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * @phpstan-impure
     */
    protected function transportRow(int $id): ?stdClass
    {
        $row = DB::table('transports')->where('id', $id)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The audit records of one event type and target, oldest first.
     *
     * @return list<stdClass>
     *
     * @phpstan-impure
     */
    protected function auditRecords(string $eventType, ?string $targetType = null, ?string $targetId = null): array
    {
        $query = DB::table('restv2_audit_events')->where('event_type', $eventType);

        if ($targetType !== null) {
            $query->where('target_type', $targetType);
        }

        if ($targetId !== null) {
            $query->where('target_id', $targetId);
        }

        return $query->orderBy('occurred_at')->orderBy('id')->get()->map(function (stdClass $row): stdClass {
            $row->details = json_decode((string) $row->details, true);

            return $row;
        })->all();
    }

    /**
     * Every audit record written so far.
     *
     * @phpstan-impure
     */
    protected function auditCount(): int
    {
        return DB::table('restv2_audit_events')->count();
    }

    protected function signInAsMailboxUser(string $address = 'anna@example.org'): void
    {
        $this->signInAs($address, Role::MailboxUser);
    }
}
