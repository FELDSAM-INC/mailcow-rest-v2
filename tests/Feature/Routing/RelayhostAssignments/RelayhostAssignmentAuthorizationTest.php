<?php

namespace Tests\Feature\Routing\RelayhostAssignments;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T041 (FR-001–FR-005, FR-029): the four assignment operations for every kind of
 * caller, domain administrators with each flag combination, and keys owned by them. Reads need no
 * flag; writes need the matching flag; objects outside the scope answer `404`.
 */
class RelayhostAssignmentAuthorizationTest extends TestCase
{
    use RoutingTestHelpers;

    private const CALLERS = [
        'anonymous', 'mailboxSession', 'restrictedSession', 'adminSessionWithoutCsrf', 'adminReadOnlyKey', 'adminKey', 'adminSession',
        'daNone', 'daDomainFlag', 'daMailboxFlag', 'daBothFlags', 'daBothFlagsKey', 'daBothFlagsReadOnlyKey',
    ];

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->relayhost(3, 'relay.example');
        $f->domain('example.org');
        $f->domain('foreign.example');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@foreign.example');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $flags = ['daNone' => [0, 0], 'daDomainFlag' => [1, 0], 'daMailboxFlag' => [0, 1], 'daBothFlags' => [1, 1]];

        foreach ($flags as $name => [$domain, $mailbox]) {
            $f->domainAdmin(strtolower($name), ['example.org'], ['domain_relayhost' => $domain, 'mailbox_relayhost' => $mailbox, 'bcc_maps' => 0], scheme: 'SSHA256');
        }

        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'daBothFlagsKey' => $this->apiKey('dabothflags')['secret'],
            'daBothFlagsReadOnlyKey' => $this->apiKey('dabothflags', 'readOnly')['secret'],
        ];
    }

    /**
     * @return array<string, array{string, string, bool, string}>
     */
    private static function requests(): array
    {
        return [
            'getDomainRelayhostAssignment' => ['GET', '/api/v2/domains/{domain}/relayhost-assignment', false, 'domain'],
            'replaceDomainRelayhostAssignment' => ['PUT', '/api/v2/domains/{domain}/relayhost-assignment', true, 'domain'],
            'getMailboxRelayhostAssignment' => ['GET', '/api/v2/mailboxes/{mailbox}/relayhost-assignment', false, 'mailbox'],
            'replaceMailboxRelayhostAssignment' => ['PUT', '/api/v2/mailboxes/{mailbox}/relayhost-assignment', true, 'mailbox'],
        ];
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (array_keys(self::requests()) as $operation) {
            foreach (self::CALLERS as $caller) {
                foreach ([false, true] as $foreign) {
                    $cases["{$operation} as {$caller}".($foreign ? ' (foreign)' : '')] = [$operation, $caller, $foreign];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_every_assignment_operation_for_every_caller(string $operation, string $caller, bool $foreign): void
    {
        [$method, $path, $write, $kind] = self::requests()[$operation];
        $path = strtr($path, ['{domain}' => $foreign ? 'foreign.example' : 'example.org', '{mailbox}' => $foreign ? 'bob@foreign.example' : 'anna@example.org']);
        $flag = $kind === 'domain' ? 'domainRelayhost' : 'mailboxRelayhost';
        $hasFlag = match ($caller) {
            'daDomainFlag' => $kind === 'domain',
            'daMailboxFlag' => $kind === 'mailbox',
            'daBothFlags', 'daBothFlagsKey', 'daBothFlagsReadOnlyKey' => true,
            default => false,
        };
        $domainAdmin = str_starts_with($caller, 'da');

        [$status, $type] = match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            $write && in_array($caller, ['adminReadOnlyKey', 'daBothFlagsReadOnlyKey'], true) => [403, 'insufficient-key-scope'],
            $write && $caller === 'adminSessionWithoutCsrf' => [403, 'csrf-token-mismatch'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            $caller === 'mailboxSession' => [403, 'forbidden'],
            $domainAdmin && $write && ! $hasFlag => [403, 'permission-flag-required'],
            $domainAdmin && $foreign => [404, null],
            default => [200, null],
        };

        $response = $this->sendAs($caller, $method, $path, $write ? ['relayhostId' => 3] : null);

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }

        if ($type === 'permission-flag-required') {
            $response->assertJsonPath('requiredFlags', [$flag]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function sendAs(string $caller, string $method, string $path, ?array $body): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'adminSession', 'adminSessionWithoutCsrf' => $this->signInAs('admin', Role::Admin),
            'mailboxSession' => $this->signInAs('anna@example.org', Role::MailboxUser),
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            'daNone', 'daDomainFlag', 'daMailboxFlag', 'daBothFlags' => $this->signInAs(strtolower($caller), Role::DomainAdmin),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET' && ! in_array($caller, ['adminSessionWithoutCsrf', 'anonymous'], true)) {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        return $body === null ? $this->json($method, $path, [], $headers) : $this->json($method, $path, $body, $headers);
    }
}
