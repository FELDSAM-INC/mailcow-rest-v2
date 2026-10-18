<?php

namespace Tests\Feature\Routing\Relayhosts;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T032 (FR-004, FR-005, FR-085): the relayhost operations for every kind of caller. A
 * refused write of an authenticated caller leaves exactly one failure record; `401` and CSRF
 * refusals leave none.
 */
class RelayhostAuthorizationTest extends TestCase
{
    use RoutingTestHelpers;

    private const CALLERS = ['anonymous', 'mailboxSession', 'domainAdminSession', 'domainAdminKey', 'restrictedSession', 'adminSessionWithoutCsrf', 'adminReadOnlyKey', 'adminKey', 'adminSession'];

    private int $id = 0;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $this->mailbox('anna@example.org');
        $this->id = $f->relayhost(null, 'r.example', 'acct', 's3cret');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org'], ['domain_relayhost' => 1, 'mailbox_relayhost' => 1, 'bcc_maps' => 1], scheme: 'SSHA256');
        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'domainAdminKey' => $this->apiKey('reseller')['secret'],
        ];
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>|null, int}>
     */
    private static function requests(): array
    {
        return [
            'listRelayhosts' => ['GET', '/api/v2/relayhosts', null, 200],
            'getRelayhost' => ['GET', '/api/v2/relayhosts/{id}', null, 200],
            'listRelayhostUsage' => ['GET', '/api/v2/relayhosts/{id}/usage', null, 200],
            'listRelayhostChoices' => ['GET', '/api/v2/relayhost-choices', null, 200],
            'createRelayhost' => ['POST', '/api/v2/relayhosts', ['host' => 'new.example'], 201],
            'updateRelayhost' => ['PATCH', '/api/v2/relayhosts/{id}', ['active' => false], 200],
            'deleteRelayhost' => ['DELETE', '/api/v2/relayhosts/{id}', null, 204],
            'applyRelayhostBulkAction' => ['POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => '{id}']]], 200],
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
    public function test_every_relayhost_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body, $success] = self::requests()[$operation];
        $unsafe = $method !== 'GET';
        [$status, $type] = match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            $unsafe && $caller === 'adminReadOnlyKey' => [403, 'insufficient-key-scope'],
            $unsafe && $caller === 'adminSessionWithoutCsrf' => [403, 'csrf-token-mismatch'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            $caller === 'mailboxSession' => [403, 'forbidden'],
            in_array($caller, ['domainAdminSession', 'domainAdminKey'], true) => $operation === 'listRelayhostChoices' ? [200, null] : [403, 'forbidden'],
            default => [$success, null],
        };

        $before = DB::table('restv2_audit_events')->where('outcome', 'failure')->count();
        $response = $this->sendAs($caller, $method, str_replace('{id}', (string) $this->id, $path), $body === null ? null : json_decode(str_replace('{id}', (string) $this->id, (string) json_encode($body)), true));

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }

        $failures = DB::table('restv2_audit_events')->where('outcome', 'failure')->count() - $before;
        $expectedFailures = $unsafe && $status >= 400 && ! in_array($status, [401], true) && $type !== 'csrf-token-mismatch' ? 1 : 0;
        $this->assertSame($expectedFailures, $failures);
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
