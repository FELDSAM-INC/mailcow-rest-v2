<?php

namespace Tests\Feature\Routing\Transports;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T051 (FR-005, FR-090, FR-091): transport bulk actions, and the transport operations
 * for every kind of caller (domain administrators are refused, AS-9).
 */
class TransportBulkActionAndAuthorizationTest extends TestCase
{
    use RoutingTestHelpers;

    private const CALLERS = ['anonymous', 'mailboxSession', 'domainAdminSession', 'restrictedSession', 'adminSessionWithoutCsrf', 'adminReadOnlyKey', 'adminKey', 'adminSession'];

    /** @var list<int> */
    private array $ids = [];

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $this->mailbox('anna@example.org');
        $this->ids = [$f->transport('a.example', 'hop.example'), $f->transport('b.example', 'hop.example'), $f->transport('c.example', 'other.example', '', '', 0, 1)];
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org'], ['domain_relayhost' => 1, 'mailbox_relayhost' => 1, 'bcc_maps' => 1], scheme: 'SSHA256');
        $this->keys = ['adminKey' => $this->apiKey('admin')['secret'], 'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret']];
    }

    public function test_bulk_activation_deactivation_and_deletion(): void
    {
        $this->signInAs('admin', Role::Admin);
        $stale = (string) $this->getJson('/api/v2/transports/'.$this->ids[2])->headers->get('ETag');
        $this->send('PATCH', '/api/v2/transports/'.$this->ids[2], ['destination' => 'moved.example'])->assertOk();

        $response = $this->send('POST', '/api/v2/transports/bulk-actions', ['action' => 'activate', 'targets' => [
            ['id' => (string) $this->ids[0]], ['id' => (string) $this->ids[0]], ['id' => (string) $this->ids[1]], ['id' => '999'], ['id' => (string) $this->ids[2], 'version' => $stale],
        ]])->assertOk();

        $this->assertSame(['done', 'done', 'notFound', 'outdated'], array_column((array) $response->json('data.results'), 'outcome'));
        $this->assertSame(1, (int) $this->transportRow($this->ids[0])?->active);
        $this->assertSame(1, (int) $this->transportRow($this->ids[1])?->active);
        $bulkRequestId = $response->json('data.bulkRequestId');
        [$record] = $this->auditRecords('transportUpdate', 'transport', (string) $this->ids[0]);
        $this->assertSame($bulkRequestId, $record->details['bulkRequestId']);

        $this->send('POST', '/api/v2/transports/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => (string) $this->ids[0]]]])->assertOk();
        $this->assertSame(0, (int) $this->transportRow($this->ids[0])?->active);

        $this->send('POST', '/api/v2/transports/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $this->ids[0]], ['id' => (string) $this->ids[1]]]])->assertOk()
            ->assertJsonPath('data.summary.done', 2);
        $this->assertNull($this->transportRow($this->ids[0]));
        $this->assertCount(1, $this->auditRecords('transportDeletion', 'transport', (string) $this->ids[1]));
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>|null, int}>
     */
    private static function requests(): array
    {
        return [
            'listTransports' => ['GET', '/api/v2/transports', null, 200],
            'getTransport' => ['GET', '/api/v2/transports/{id}', null, 200],
            'createTransport' => ['POST', '/api/v2/transports', ['destination' => 'new.example', 'nextHop' => 'hop.example'], 201],
            'updateTransport' => ['PATCH', '/api/v2/transports/{id}', ['active' => true], 200],
            'deleteTransport' => ['DELETE', '/api/v2/transports/{id}', null, 204],
            'applyTransportBulkAction' => ['POST', '/api/v2/transports/bulk-actions', ['action' => 'activate', 'targets' => [['id' => '{id}']]], 200],
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
    public function test_every_transport_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body, $success] = self::requests()[$operation];
        $unsafe = $method !== 'GET';
        [$status, $type] = match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            $unsafe && $caller === 'adminReadOnlyKey' => [403, 'insufficient-key-scope'],
            $unsafe && $caller === 'adminSessionWithoutCsrf' => [403, 'csrf-token-mismatch'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            in_array($caller, ['mailboxSession', 'domainAdminSession'], true) => [403, 'forbidden'],
            default => [$success, null],
        };
        $id = (string) $this->ids[0];

        $response = $this->sendAs($caller, $method, str_replace('{id}', $id, $path), $body === null ? null : json_decode(str_replace('{id}', $id, (string) json_encode($body)), true));

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
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
            'domainAdminSession' => $this->signInAs('reseller', Role::DomainAdmin),
            'mailboxSession' => $this->signInAs('anna@example.org', Role::MailboxUser),
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET' && ! in_array($caller, ['adminSessionWithoutCsrf', 'anonymous'], true)) {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        return match (true) {
            $body === null => $this->json($method, $path, [], $headers),
            $method === 'PATCH' => $this->patchMerge($path, $body, $headers),
            default => $this->json($method, $path, $body, $headers),
        };
    }
}
