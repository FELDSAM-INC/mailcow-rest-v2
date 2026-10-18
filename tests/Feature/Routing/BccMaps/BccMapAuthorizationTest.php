<?php

namespace Tests\Feature\Routing\BccMaps;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T073 (FR-005): the seven BCC operations for every kind of caller, and domain
 * administrators with and without `bcc_maps`, with sessions and keys.
 */
class BccMapAuthorizationTest extends TestCase
{
    use RoutingTestHelpers;

    private const CALLERS = ['anonymous', 'mailboxSession', 'restrictedSession', 'adminSessionWithoutCsrf', 'adminReadOnlyKey', 'adminKey', 'adminSession', 'daNoFlag', 'daFlag', 'daFlagKey', 'daFlagReadOnlyKey'];

    private int $id = 0;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $this->mailbox('anna@example.org');
        $this->id = $f->bccMap('anna@example.org', 'x@backup.net', 'example.org', 'sender');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('danoflag', ['example.org'], ['bcc_maps' => 0], scheme: 'SSHA256');
        $f->domainAdmin('daflag', ['example.org'], ['bcc_maps' => 1], scheme: 'SSHA256');
        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'daFlagKey' => $this->apiKey('daflag')['secret'],
            'daFlagReadOnlyKey' => $this->apiKey('daflag', 'readOnly')['secret'],
        ];
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>|null, int}>
     */
    private static function requests(): array
    {
        return [
            'listBccMaps' => ['GET', '/api/v2/bcc-maps', null, 200],
            'getBccMap' => ['GET', '/api/v2/bcc-maps/{id}', null, 200],
            'listBccDestinationChoices' => ['GET', '/api/v2/bcc-destination-choices', null, 200],
            'createBccMap' => ['POST', '/api/v2/bcc-maps', ['localDestination' => 'example.org', 'type' => 'recipient', 'bccDestination' => 'a@backup.net'], 201],
            'updateBccMap' => ['PATCH', '/api/v2/bcc-maps/{id}', ['active' => false], 200],
            'deleteBccMap' => ['DELETE', '/api/v2/bcc-maps/{id}', null, 204],
            'applyBccMapBulkAction' => ['POST', '/api/v2/bcc-maps/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => '{id}']]], 200],
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
    public function test_every_bcc_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body, $success] = self::requests()[$operation];
        $unsafe = $method !== 'GET';
        [$status, $type] = match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            $unsafe && in_array($caller, ['adminReadOnlyKey', 'daFlagReadOnlyKey'], true) => [403, 'insufficient-key-scope'],
            $unsafe && $caller === 'adminSessionWithoutCsrf' => [403, 'csrf-token-mismatch'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            $caller === 'mailboxSession' => [403, 'forbidden'],
            $unsafe && $caller === 'daNoFlag' => [403, 'permission-flag-required'],
            default => [$success, null],
        };
        $id = (string) $this->id;

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
            'mailboxSession' => $this->signInAs('anna@example.org', Role::MailboxUser),
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            'daNoFlag' => $this->signInAs('danoflag', Role::DomainAdmin),
            'daFlag' => $this->signInAs('daflag', Role::DomainAdmin),
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
