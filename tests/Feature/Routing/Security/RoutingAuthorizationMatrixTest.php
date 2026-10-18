<?php

namespace Tests\Feature\Routing\Security;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FeatureRegistry;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T098 (constitution VI, quality gate 5, FR-001–FR-005, SC-004): every operation of
 * feature 009 for every kind of caller, from plan.md's operation table and FR-005: the request
 * pipeline (authentication, key scope, pending actions), then the role, then the permission flag,
 * then the scope (`404` for foreign objects).
 */
class RoutingAuthorizationMatrixTest extends TestCase
{
    use RoutingTestHelpers;

    private const CALLERS = [
        'anonymous', 'restrictedSession', 'adminSession', 'adminKey', 'adminReadOnlyKey', 'mailboxSession',
        'daNone', 'daDomainRelayhost', 'daMailboxRelayhost', 'daBccMaps', 'daAll', 'daAllKey', 'daAllReadOnlyKey',
    ];

    /** domain administrator caller => its `da_acl` flags (domain_relayhost, mailbox_relayhost, bcc_maps) */
    private const DOMAIN_ADMINS = [
        'daNone' => [0, 0, 0],
        'daDomainRelayhost' => [1, 0, 0],
        'daMailboxRelayhost' => [0, 1, 0],
        'daBccMaps' => [0, 0, 1],
        'daAll' => [1, 1, 1],
    ];

    /** @var array<string, string> */
    private array $ids = [];

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@foreign.example');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);

        foreach (self::DOMAIN_ADMINS as $name => [$domain, $mailbox, $bcc]) {
            $f->domainAdmin(strtolower($name), ['example.org'], ['domain_relayhost' => $domain, 'mailbox_relayhost' => $mailbox, 'bcc_maps' => $bcc], scheme: 'SSHA256');
        }

        $this->ids = [
            '{relayhost}' => (string) $f->relayhost(null, '[192.0.2.10]', 'acct', 'pw'),
            '{transport}' => (string) $f->transport('example.com', '[192.0.2.11]'),
            '{bccMap}' => (string) $f->bccMap('anna@example.org', 'a@backup.net', 'example.org', 'sender'),
            '{foreignBccMap}' => (string) $f->bccMap('@foreign.example', 'a@backup.net', 'foreign.example'),
            '{recipientMap}' => (string) $f->recipientMap('@old.example', '@new.example'),
            '{tlsPolicyMap}' => (string) $f->tlsPolicyMap('tls.example', 'dane'),
        ];
        $f->forwardingHost('192.0.2.0/24', 'src');
        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'daAllKey' => $this->apiKey('daall')['secret'],
            'daAllReadOnlyKey' => $this->apiKey('daall', 'readOnly')['secret'],
        ];
    }

    /**
     * operation => [method, path, body, success status, who besides administrators: `admin` (none),
     * `domainAdmin` (every domain administrator) or the required flag].
     *
     * @return array<string, array{string, string, array<string, mixed>|null, int, string}>
     */
    private static function requests(): array
    {
        return [
            'listRelayhosts' => ['GET', '/api/v2/relayhosts', null, 200, 'admin'],
            'createRelayhost' => ['POST', '/api/v2/relayhosts', ['host' => 'new.example'], 201, 'admin'],
            'listRelayhostChoices' => ['GET', '/api/v2/relayhost-choices', null, 200, 'relayhostFlag'],
            'applyRelayhostBulkAction' => ['POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => '{relayhost}']]], 200, 'admin'],
            'getRelayhost' => ['GET', '/api/v2/relayhosts/{relayhost}', null, 200, 'admin'],
            'updateRelayhost' => ['PATCH', '/api/v2/relayhosts/{relayhost}', ['active' => false], 200, 'admin'],
            'deleteRelayhost' => ['DELETE', '/api/v2/relayhosts/{relayhost}', null, 204, 'admin'],
            'listRelayhostUsage' => ['GET', '/api/v2/relayhosts/{relayhost}/usage', null, 200, 'admin'],
            'testRelayhostConnection' => ['POST', '/api/v2/relayhosts/{relayhost}/connection-test', null, 200, 'admin'],
            'getDomainRelayhostAssignment' => ['GET', '/api/v2/domains/example.org/relayhost-assignment', null, 200, 'domainAdmin'],
            'replaceDomainRelayhostAssignment' => ['PUT', '/api/v2/domains/example.org/relayhost-assignment', ['relayhostId' => null], 200, 'domainRelayhost'],
            'getMailboxRelayhostAssignment' => ['GET', '/api/v2/mailboxes/anna@example.org/relayhost-assignment', null, 200, 'domainAdmin'],
            'replaceMailboxRelayhostAssignment' => ['PUT', '/api/v2/mailboxes/anna@example.org/relayhost-assignment', ['relayhostId' => null], 200, 'mailboxRelayhost'],
            'listTransports' => ['GET', '/api/v2/transports', null, 200, 'admin'],
            'createTransport' => ['POST', '/api/v2/transports', ['destination' => 'new.example', 'nextHop' => 'hop.example'], 201, 'admin'],
            'applyTransportBulkAction' => ['POST', '/api/v2/transports/bulk-actions', ['action' => 'activate', 'targets' => [['id' => '{transport}']]], 200, 'admin'],
            'getTransport' => ['GET', '/api/v2/transports/{transport}', null, 200, 'admin'],
            'updateTransport' => ['PATCH', '/api/v2/transports/{transport}', ['active' => false], 200, 'admin'],
            'deleteTransport' => ['DELETE', '/api/v2/transports/{transport}', null, 204, 'admin'],
            'testTransportConnection' => ['POST', '/api/v2/transports/{transport}/connection-test', null, 200, 'admin'],
            'listBccMaps' => ['GET', '/api/v2/bcc-maps', null, 200, 'domainAdmin'],
            'createBccMap' => ['POST', '/api/v2/bcc-maps', ['localDestination' => 'example.org', 'type' => 'recipient', 'bccDestination' => 'a@backup.net'], 201, 'bccMaps'],
            'listBccDestinationChoices' => ['GET', '/api/v2/bcc-destination-choices', null, 200, 'domainAdmin'],
            'applyBccMapBulkAction' => ['POST', '/api/v2/bcc-maps/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => '{bccMap}']]], 200, 'bccMaps'],
            'getBccMap' => ['GET', '/api/v2/bcc-maps/{bccMap}', null, 200, 'domainAdmin'],
            'updateBccMap' => ['PATCH', '/api/v2/bcc-maps/{bccMap}', ['active' => false], 200, 'bccMaps'],
            'deleteBccMap' => ['DELETE', '/api/v2/bcc-maps/{bccMap}', null, 204, 'bccMaps'],
            'listRecipientMaps' => ['GET', '/api/v2/recipient-maps', null, 200, 'admin'],
            'createRecipientMap' => ['POST', '/api/v2/recipient-maps', ['originalDestination' => 'a.example', 'newDestination' => 'b.example'], 201, 'admin'],
            'applyRecipientMapBulkAction' => ['POST', '/api/v2/recipient-maps/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => '{recipientMap}']]], 200, 'admin'],
            'getRecipientMap' => ['GET', '/api/v2/recipient-maps/{recipientMap}', null, 200, 'admin'],
            'updateRecipientMap' => ['PATCH', '/api/v2/recipient-maps/{recipientMap}', ['active' => false], 200, 'admin'],
            'deleteRecipientMap' => ['DELETE', '/api/v2/recipient-maps/{recipientMap}', null, 204, 'admin'],
            'listTlsPolicyMaps' => ['GET', '/api/v2/tls-policy-maps', null, 200, 'admin'],
            'createTlsPolicyMap' => ['POST', '/api/v2/tls-policy-maps', ['destination' => 'new.example', 'policy' => 'may'], 201, 'admin'],
            'applyTlsPolicyMapBulkAction' => ['POST', '/api/v2/tls-policy-maps/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => '{tlsPolicyMap}']]], 200, 'admin'],
            'getTlsPolicyMap' => ['GET', '/api/v2/tls-policy-maps/{tlsPolicyMap}', null, 200, 'admin'],
            'updateTlsPolicyMap' => ['PATCH', '/api/v2/tls-policy-maps/{tlsPolicyMap}', ['active' => false], 200, 'admin'],
            'deleteTlsPolicyMap' => ['DELETE', '/api/v2/tls-policy-maps/{tlsPolicyMap}', null, 204, 'admin'],
            'listForwardingHosts' => ['GET', '/api/v2/forwarding-hosts', null, 200, 'admin'],
            'createForwardingHost' => ['POST', '/api/v2/forwarding-hosts', ['host' => '198.51.100.7'], 201, 'admin'],
            'applyForwardingHostBulkAction' => ['POST', '/api/v2/forwarding-hosts/bulk-actions', ['action' => 'disableSpamFilter', 'targets' => [['id' => '192.0.2.0/24']]], 200, 'admin'],
            'getForwardingHost' => ['GET', '/api/v2/forwarding-hosts/192.0.2.0%2F24', null, 200, 'admin'],
            'updateForwardingHost' => ['PATCH', '/api/v2/forwarding-hosts/192.0.2.0%2F24', ['spamFilter' => false], 200, 'admin'],
            'deleteForwardingHost' => ['DELETE', '/api/v2/forwarding-hosts/192.0.2.0%2F24', null, 204, 'admin'],
            'getRoutingCapabilities' => ['GET', '/api/v2/capabilities/routing', null, 200, 'domainAdmin'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (array_keys(self::requests()) as $operation) {
            foreach (self::CALLERS as $caller) {
                $cases["{$operation} as {$caller}"] = [$operation, $caller];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_every_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body, $success, $who] = self::requests()[$operation];
        [$status, $type] = self::expected($method, $success, $who, $caller);

        $response = $this->sendAs($caller, $method, strtr($path, $this->ids), $body === null ? null : json_decode(strtr((string) json_encode($body), $this->ids), true));

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }
    }

    /**
     * @return array{int, string|null}
     */
    private static function expected(string $method, int $success, string $who, string $caller): array
    {
        $unsafe = $method !== 'GET';
        $flags = self::DOMAIN_ADMINS[$caller === 'daAllKey' || $caller === 'daAllReadOnlyKey' ? 'daAll' : $caller] ?? null;
        $hasFlag = $flags !== null && match ($who) {
            'relayhostFlag' => $flags[0] === 1 || $flags[1] === 1,
            'domainRelayhost' => $flags[0] === 1,
            'mailboxRelayhost' => $flags[1] === 1,
            'bccMaps' => $flags[2] === 1,
            default => true,
        };

        return match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            $unsafe && in_array($caller, ['adminReadOnlyKey', 'daAllReadOnlyKey'], true) => [403, 'insufficient-key-scope'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            $caller === 'mailboxSession' => [403, 'forbidden'],
            $flags === null => [$success, null],
            $who === 'admin' => [403, 'forbidden'],
            ! $hasFlag => [403, 'permission-flag-required'],
            default => [$success, null],
        };
    }

    public function test_foreign_objects_answer_not_found_for_a_domain_administrator_with_every_flag(): void
    {
        $this->signInAs('daall', Role::DomainAdmin);

        foreach ([
            ['GET', '/api/v2/bcc-maps/'.$this->ids['{foreignBccMap}'], null],
            ['PATCH', '/api/v2/bcc-maps/'.$this->ids['{foreignBccMap}'], ['active' => false]],
            ['DELETE', '/api/v2/bcc-maps/'.$this->ids['{foreignBccMap}'], null],
            ['GET', '/api/v2/domains/foreign.example/relayhost-assignment', null],
            ['PUT', '/api/v2/domains/foreign.example/relayhost-assignment', ['relayhostId' => null]],
            ['GET', '/api/v2/mailboxes/bob@foreign.example/relayhost-assignment', null],
            ['PUT', '/api/v2/mailboxes/bob@foreign.example/relayhost-assignment', ['relayhostId' => null]],
        ] as [$method, $uri, $body]) {
            $this->send($method, $uri, $body)->assertNotFound();
        }

        $this->send('POST', '/api/v2/bcc-maps/bulk-actions', ['action' => 'delete', 'targets' => [['id' => $this->ids['{foreignBccMap}']]]])->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'notFound');
    }

    public function test_the_matrix_covers_every_operation_of_the_feature(): void
    {
        $this->assertEqualsCanonicalizing(FeatureRegistry::load()->features['009-routing-address-rewriting']['operations'], array_keys(self::requests()));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function sendAs(string $caller, string $method, string $path, ?array $body): TestResponse
    {
        $headers = [];

        match (true) {
            $caller === 'anonymous' => null,
            $caller === 'adminSession' => $this->signInAs('admin', Role::Admin),
            $caller === 'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            $caller === 'mailboxSession' => $this->signInAs('anna@example.org', Role::MailboxUser),
            isset(self::DOMAIN_ADMINS[$caller]) => $this->signInAs(strtolower($caller), Role::DomainAdmin),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET' && $caller !== 'anonymous') {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        return match (true) {
            $body === null && $method === 'POST' => $this->postEmpty($path, $headers),
            $body === null => $this->json($method, $path, [], $headers),
            $method === 'PATCH' => $this->patchMerge($path, $body, $headers),
            default => $this->json($method, $path, $body, $headers),
        };
    }
}
