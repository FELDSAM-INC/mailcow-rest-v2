<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\FeatureRegistry;
use Tests\TestCase;

/**
 * Feature 004 T082 (constitution VI, quality gate 5, FR-001–FR-005, SC-004): every operation of
 * feature 004 for every kind of caller, derived from plan.md's operation table and the request
 * pipeline (authentication, key scope, anti-forgery, pending actions, role).
 */
class Feature004AuthorizationMatrixTest extends TestCase
{
    use AliasTestHelpers;
    use ApiKeys;

    private const CALLERS = [
        'anonymous',
        'adminSessionWithoutCsrf',
        'adminSession',
        'adminKey',
        'adminReadOnlyKey',
        'domainAdminSession',
        'domainAdminReadOnlyKey',
        'mailboxSession',
        'restrictedSession',
    ];

    private int $aliasId = 0;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('anna@example.org');
        $this->aliasId = $f->alias('sales@example.org', 'anna@example.org');
        $f->resource('Room@example.org', 'Room');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org'], ['alias_domains' => 1, 'extend_sender_acl' => 1], scheme: 'SSHA256');
        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'domainAdminReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
        ];
    }

    /**
     * operation => [method, path, body (null: none), success status]. `{alias}` is the alias ID.
     *
     * @return array<string, array{string, string, array<string, mixed>|null, int}>
     */
    private static function requests(): array
    {
        return [
            'listAliases' => ['GET', '/api/v2/aliases', null, 200],
            'createAlias' => ['POST', '/api/v2/aliases', ['address' => 'new@example.org', 'destinations' => ['anna@example.org']], 201],
            'applyAliasBulkAction' => ['POST', '/api/v2/aliases/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => '{alias}']]], 200],
            'getAlias' => ['GET', '/api/v2/aliases/{alias}', null, 200],
            'updateAlias' => ['PATCH', '/api/v2/aliases/{alias}', ['active' => false], 200],
            'deleteAlias' => ['DELETE', '/api/v2/aliases/{alias}', null, 204],
            'copyAliasOverAliasDomains' => ['POST', '/api/v2/aliases/{alias}/alias-domain-copies', null, 200],
            'getAliasesCapabilities' => ['GET', '/api/v2/capabilities/aliases', null, 200],
            'listAliasDomains' => ['GET', '/api/v2/alias-domains', null, 200],
            'createAliasDomain' => ['POST', '/api/v2/alias-domains', ['name' => 'new.example', 'targetDomain' => 'example.org', 'dkimSelector' => ''], 201],
            'applyAliasDomainBulkAction' => ['POST', '/api/v2/alias-domains/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => 'example.net']]], 200],
            'getAliasDomain' => ['GET', '/api/v2/alias-domains/example.net', null, 200],
            'updateAliasDomain' => ['PATCH', '/api/v2/alias-domains/example.net', ['active' => false], 200],
            'deleteAliasDomain' => ['DELETE', '/api/v2/alias-domains/example.net', null, 204],
            'listResources' => ['GET', '/api/v2/resources', null, 200],
            'createResource' => ['POST', '/api/v2/resources', ['description' => 'New room', 'domain' => 'example.org', 'kind' => 'location'], 201],
            'applyResourceBulkAction' => ['POST', '/api/v2/resources/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => 'Room@example.org']]], 200],
            'getResource' => ['GET', '/api/v2/resources/Room@example.org', null, 200],
            'updateResource' => ['PATCH', '/api/v2/resources/Room@example.org', ['active' => false], 200],
            'deleteResource' => ['DELETE', '/api/v2/resources/Room@example.org', null, 204],
            'getMailboxSenderSettings' => ['GET', '/api/v2/mailboxes/anna@example.org/sender-settings', null, 200],
            'updateMailboxSenderSettings' => ['PATCH', '/api/v2/mailboxes/anna@example.org/sender-settings', ['explicitPermissions' => []], 200],
            'listSenderPermissionCandidates' => ['GET', '/api/v2/sender-permission-candidates?mailbox=anna@example.org', null, 200],
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

    /**
     * @return array{int, string|null} status and problem type name (null: none or about:blank)
     */
    private static function expected(string $operation, string $caller): array
    {
        [$method, , , $success] = self::requests()[$operation];
        $unsafe = $method !== 'GET';

        return match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            $unsafe && in_array($caller, ['adminReadOnlyKey', 'domainAdminReadOnlyKey'], true) => [403, 'insufficient-key-scope'],
            $unsafe && $caller === 'adminSessionWithoutCsrf' => [403, 'csrf-token-mismatch'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            $caller === 'mailboxSession' => [403, 'forbidden'],
            default => [$success, null],
        };
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function send(string $caller, string $method, string $path, ?array $body): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'adminSession', 'adminSessionWithoutCsrf' => $this->signInAs('admin', Role::Admin),
            'domainAdminSession' => $this->signInAs('reseller', Role::DomainAdmin),
            'mailboxSession' => $this->signInAs('anna@example.org', Role::MailboxUser),
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET' && $caller !== 'adminSessionWithoutCsrf' && $caller !== 'anonymous') {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        return match (true) {
            $body === null && $method === 'POST' => $this->postEmpty($path, $headers),
            $body === null => $this->json($method, $path, [], $headers),
            $method === 'PATCH' => $this->patchMerge($path, $body, $headers),
            default => $this->json($method, $path, $body, $headers),
        };
    }

    #[DataProvider('cases')]
    public function test_every_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body] = self::requests()[$operation];
        [$status, $type] = self::expected($operation, $caller);
        $id = (string) $this->aliasId;

        if ($body !== null) {
            $body = json_decode(str_replace('{alias}', $id, (string) json_encode($body)), true);
        }

        $response = $this->send($caller, $method, str_replace('{alias}', $id, $path), $body);

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }
    }

    public function test_the_matrix_covers_every_operation_of_the_feature(): void
    {
        $this->assertEqualsCanonicalizing(FeatureRegistry::load()->features['004-aliases-alias-domains-resources']['operations'], array_keys(self::requests()));
    }
}
