<?php

namespace Tests\Feature\Dkim;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T085 (SC-004, FR-004, FR-005): the role and key matrix of the DKIM writes. Generation
 * without overwrite is open to domain administrators for their names; overwriting, importing,
 * copying, deleting and bulk deletion are for administrators; read-only keys and restricted
 * sessions are refused; mailbox users are forbidden.
 */
class DkimWriteAuthorizationTest extends TestCase
{
    use DkimTestHelpers;

    private const CALLERS = ['anonymous', 'restrictedSession', 'adminSession', 'adminKey', 'adminReadOnlyKey', 'mailboxSession', 'daSession', 'daKey', 'daReadOnlyKey'];

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('fresh.example');
        $f->domain('target.example');
        $f->dkimKey('example.org', 'dkim', 1024);
        $f->dkimKey('unused.example', 'dkim', 1024);
        $f->admin('admin', 'Passw0rd!');
        $f->admin('restricted', 'Passw0rd!', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org', 'fresh.example', 'target.example']);
        $f->mailboxWithDomain('anna@example.org');
        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'daKey' => $this->apiKey('reseller')['secret'],
            'daReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
        ];
    }

    /**
     * operation => [method, path, body, success status, open to domain administrators]
     *
     * @return array<string, array{string, string, array<string, mixed>|null, int, bool}>
     */
    private static function operations(): array
    {
        return [
            'generateDkimKey' => ['POST', '/api/v2/dkim-keys', ['name' => 'fresh.example', 'keySize' => 1024], 201, true],
            'generateDkimKey with overwrite' => ['POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024, 'overwrite' => true], 200, false],
            'importDkimKey' => ['POST', '/api/v2/dkim-keys/fresh.example/import', ['privateKey' => DkimFixtures::RSA_1024_PRIVATE], 201, false],
            'copyDkimKey' => ['POST', '/api/v2/dkim-keys/example.org/copy', ['target' => 'target.example'], 201, false],
            'deleteDkimKey' => ['DELETE', '/api/v2/dkim-keys/example.org', null, 204, false],
            'applyDkimKeyBulkAction' => ['POST', '/api/v2/dkim-keys/bulk-actions', ['action' => 'delete', 'targets' => [['id' => 'unused.example']]], 200, false],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (array_keys(self::operations()) as $operation) {
            foreach (self::CALLERS as $caller) {
                $cases["{$operation} as {$caller}"] = [$operation, $caller];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_every_write_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body, $success, $domainAdmins] = self::operations()[$operation];
        $domainAdmin = in_array($caller, ['daSession', 'daKey', 'daReadOnlyKey'], true);
        [$status, $type] = match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            in_array($caller, ['adminReadOnlyKey', 'daReadOnlyKey'], true) => [403, 'insufficient-key-scope'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            $caller === 'mailboxSession' => [403, 'forbidden'],
            $domainAdmin && ! $domainAdmins => [403, 'forbidden'],
            default => [$success, null],
        };

        $response = $this->as($caller, $method, $path, $body)->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }

        $this->assertSame($status === 401 ? 0 : 1, $this->auditTotal(), 'One audit record per attempt of an authenticated caller.');
    }

    public function test_foreign_names_are_not_found_for_a_domain_administrator(): void
    {
        $this->mailcow()->domain('foreign.example');
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'foreign.example', 'keySize' => 1024])->assertNotFound();
        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'unused.example', 'keySize' => 1024])->assertNotFound();
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function as(string $caller, string $method, string $path, ?array $body): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'adminSession' => $this->signInAs('admin', Role::Admin),
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            'mailboxSession' => $this->signInAs('anna@example.org', Role::MailboxUser),
            'daSession' => $this->signInAs('reseller', Role::DomainAdmin),
            default => $headers = $this->keyHeader($this->keys[$caller]),
        };

        if (! isset($headers['X-API-Key']) && $caller !== 'anonymous') {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        return $body === null ? $this->json($method, $path, [], $headers) : $this->json($method, $path, $body, $headers);
    }
}
