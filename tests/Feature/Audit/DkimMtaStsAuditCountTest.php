<?php

namespace Tests\Feature\Audit;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\DnsDiagnostics\DiagnosticsTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T098 (SC-010, FR-054): every state-changing request and every export of the feature by
 * an authenticated caller, successful or refused (read-only keys and restricted sessions included),
 * writes exactly one audit record; `401` and CSRF refusals, lists, reads, the capabilities and
 * diagnostics write none.
 */
class DkimMtaStsAuditCountTest extends TestCase
{
    use DiagnosticsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('fresh.example');
        $f->domain('target.example');
        $f->dkimKey('example.org', 'dkim', 1024);
        $f->mtaSts('example.org');
        $f->admin('admin', 'Passw0rd!');
        $f->admin('restricted', 'Passw0rd!', attributes: ['force_pw_update' => '1']);
        $this->server();
        config(['restv2.vars_overrides.SHOW_DKIM_PRIV_KEYS' => 'true']);
    }

    /**
     * operation => [method, path, body, audited]
     *
     * @return array<string, array{string, string, array<string, mixed>|null, bool}>
     */
    private static function requests(): array
    {
        return [
            'generateDkimKey' => ['POST', '/api/v2/dkim-keys', ['name' => 'fresh.example', 'keySize' => 1024], true],
            'importDkimKey' => ['POST', '/api/v2/dkim-keys/fresh.example/import', ['privateKey' => DkimFixtures::RSA_1024_PRIVATE], true],
            'copyDkimKey' => ['POST', '/api/v2/dkim-keys/example.org/copy', ['target' => 'target.example'], true],
            'deleteDkimKey' => ['DELETE', '/api/v2/dkim-keys/example.org', null, true],
            'applyDkimKeyBulkAction' => ['POST', '/api/v2/dkim-keys/bulk-actions', ['action' => 'delete', 'targets' => [['id' => 'example.org']]], true],
            'exportDkimPrivateKey' => ['GET', '/api/v2/dkim-keys/example.org/private-key', null, true],
            'publishDkimDnsRecord' => ['POST', '/api/v2/dkim-keys/example.org/dns-publication', null, true],
            'createMtaStsPolicy' => ['POST', '/api/v2/mta-sts-policies', ['domain' => 'fresh.example', 'mode' => 'none', 'mxHosts' => ['mail.example.org'], 'maxAge' => 60], true],
            'updateMtaStsPolicy' => ['PATCH', '/api/v2/mta-sts-policies/example.org', ['mode' => 'testing'], true],
            'listDkimKeys' => ['GET', '/api/v2/dkim-keys', null, false],
            'getDkimKey' => ['GET', '/api/v2/dkim-keys/example.org', null, false],
            'getDnsCapabilities' => ['GET', '/api/v2/capabilities/dns', null, false],
            'listMtaStsPolicies' => ['GET', '/api/v2/mta-sts-policies', null, false],
            'getMtaStsPolicy' => ['GET', '/api/v2/mta-sts-policies/example.org', null, false],
            'runDnsDiagnostics' => ['GET', '/api/v2/dns-diagnostics/example.org', null, false],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (array_keys(self::requests()) as $operation) {
            foreach (['adminSession', 'readOnlyKey', 'restrictedSession', 'anonymous', 'withoutCsrf'] as $caller) {
                $cases["{$operation} as {$caller}"] = [$operation, $caller];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_one_record_per_attempt_of_an_authenticated_caller(string $operation, string $caller): void
    {
        [$method, $path, $body, $audited] = self::requests()[$operation];
        $before = $this->auditTotal();

        $response = $this->as($caller, $method, $path, $body);

        $expected = match (true) {
            ! $audited, $caller === 'anonymous' => 0,
            $caller === 'withoutCsrf' && $method !== 'GET' => 0,
            default => 1,
        };

        $this->assertSame($expected, $this->auditTotal() - $before, "status {$response->getStatusCode()}");
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function as(string $caller, string $method, string $path, ?array $body): TestResponse
    {
        $headers = [];

        match ($caller) {
            'adminSession', 'withoutCsrf' => $this->signInAs('admin', Role::Admin),
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            'readOnlyKey' => $headers = $this->keyHeader($this->apiKey('admin', 'readOnly')),
            default => null,
        };

        if ($method !== 'GET' && in_array($caller, ['adminSession', 'restrictedSession'], true)) {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        return match (true) {
            $method === 'PATCH' => $this->patchMerge($path, (array) $body, $headers),
            $body === null && $method === 'POST' => $this->postEmpty($path, $headers),
            $body === null => $this->json($method, $path, [], $headers),
            default => $this->json($method, $path, $body, $headers),
        };
    }
}
