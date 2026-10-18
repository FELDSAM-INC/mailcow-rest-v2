<?php

namespace Tests\Feature\MtaSts;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T076 (FR-001 to FR-005, FR-032): the role and key matrix of the four MTA-STS
 * operations. Administrators and domain administrators (no permission flag) may use them; read-only
 * keys may read only; mailbox users are forbidden. Every refusal of an authenticated caller on a
 * state-changing operation is audited once; `401` and CSRF refusals are not.
 */
class MtaStsAuthorizationMatrixTest extends TestCase
{
    use DkimTestHelpers;

    private const CALLERS = ['anonymous', 'restrictedSession', 'adminSession', 'adminKey', 'adminReadOnlyKey', 'mailboxSession', 'daSession', 'daKey', 'daReadOnlyKey', 'sessionWithoutCsrf'];

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('new.example');
        $f->mtaSts('example.org');
        $f->admin('admin', 'Passw0rd!');
        $f->admin('restricted', 'Passw0rd!', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org', 'new.example'], ['syncjobs' => 0]);
        $f->mailboxWithDomain('anna@example.org');
        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'daKey' => $this->apiKey('reseller')['secret'],
            'daReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
        ];
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>|null, int}>
     */
    private static function operations(): array
    {
        return [
            'listMtaStsPolicies' => ['GET', '/api/v2/mta-sts-policies', null, 200],
            'createMtaStsPolicy' => ['POST', '/api/v2/mta-sts-policies', ['domain' => 'new.example', 'mode' => 'testing', 'mxHosts' => ['mail.example.org'], 'maxAge' => 600], 201],
            'getMtaStsPolicy' => ['GET', '/api/v2/mta-sts-policies/example.org', null, 200],
            'updateMtaStsPolicy' => ['PATCH', '/api/v2/mta-sts-policies/example.org', ['mode' => 'none'], 200],
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
    public function test_every_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body, $success] = self::operations()[$operation];
        $unsafe = $method !== 'GET';
        [$status, $type] = match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            $unsafe && in_array($caller, ['adminReadOnlyKey', 'daReadOnlyKey'], true) => [403, 'insufficient-key-scope'],
            $unsafe && $caller === 'sessionWithoutCsrf' => [403, 'csrf-token-mismatch'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            $caller === 'mailboxSession' => [403, 'forbidden'],
            default => [$success, null],
        };

        $response = $this->as($caller, $method, $path, $body)->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }

        $audited = DB::table('restv2_audit_events')->whereIn('event_type', ['mtaStsPolicyCreation', 'mtaStsPolicyUpdate'])->count();
        $this->assertSame($unsafe && ! in_array($status, [401], true) && $type !== 'csrf-token-mismatch' ? 1 : 0, $audited);
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
            'adminSession', 'sessionWithoutCsrf' => $this->signInAs('admin', Role::Admin),
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            'mailboxSession' => $this->signInAs('anna@example.org', Role::MailboxUser),
            'daSession' => $this->signInAs('reseller', Role::DomainAdmin),
            default => $headers = $this->keyHeader($this->keys[$caller]),
        };

        if ($method !== 'GET' && ! isset($headers['X-API-Key']) && ! in_array($caller, ['anonymous', 'sessionWithoutCsrf'], true)) {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        return match (true) {
            $method === 'PATCH' => $this->patchMerge($path, (array) $body, $headers),
            $body === null => $this->json($method, $path, [], $headers),
            default => $this->json($method, $path, $body, $headers),
        };
    }
}
