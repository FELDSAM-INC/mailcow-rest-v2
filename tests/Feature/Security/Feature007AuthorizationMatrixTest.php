<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T093 (FR-005, research R24, SC-002, constitution quality gate 5): every operation of
 * the feature against every kind of caller, with the status and problem type of the FR-005 matrix;
 * targets outside a domain administrator's scope answer like missing ones.
 */
class Feature007AuthorizationMatrixTest extends TestCase
{
    use AccountTestHelpers;

    private const CALLERS = ['adminSession', 'adminKey', 'adminReadOnlyKey', 'domainAdminSession', 'domainAdminKey', 'mailboxSession', 'restrictedSession', 'impersonatingSession', 'anonymous'];

    /** Operation => [method, path, body, session-only?, success status for an administrator session]. */
    private const OPERATIONS = [
        'listAdmins' => ['GET', '/api/v2/admins', null],
        'getAdmin' => ['GET', '/api/v2/admins/target', null],
        'getAccountCapabilities' => ['GET', '/api/v2/capabilities/accounts', null],
        'createAdmin' => ['POST', '/api/v2/admins', ['username' => 'newone', 'password' => 'Passw0rd!x']],
        'updateAdmin' => ['PATCH', '/api/v2/admins/target', ['forcePasswordChange' => true]],
        'deleteAdmin' => ['DELETE', '/api/v2/admins/target', null],
        'suspendAdminSecondFactors' => ['POST', '/api/v2/admins/target/second-factor-suspension', null],
        'applyAdminBulkAction' => ['POST', '/api/v2/admins/bulk-actions', ['action' => 'activate', 'targets' => [['id' => 'target']]]],
        'listDomainAdmins' => ['GET', '/api/v2/domain-admins', null],
        'getDomainAdmin' => ['GET', '/api/v2/domain-admins/reseller2', null],
        'createDomainAdmin' => ['POST', '/api/v2/domain-admins', ['username' => 'newone', 'password' => 'Passw0rd!x', 'domains' => ['a.example']]],
        'updateDomainAdmin' => ['PATCH', '/api/v2/domain-admins/reseller2', ['forcePasswordChange' => true]],
        'deleteDomainAdmin' => ['DELETE', '/api/v2/domain-admins/reseller2', null],
        'suspendDomainAdminSecondFactors' => ['POST', '/api/v2/domain-admins/reseller2/second-factor-suspension', null],
        'applyDomainAdminBulkAction' => ['POST', '/api/v2/domain-admins/bulk-actions', ['action' => 'activate', 'targets' => [['id' => 'reseller2']]]],
        'issueDomainAdminSsoToken' => ['POST', '/api/v2/domain-admins/reseller2/sso-token', null],
        'createSessionWithSsoToken' => ['POST', '/api/v2/session/sso-token', ['token' => 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA']],
        'getImpersonation' => ['GET', '/api/v2/session/impersonation', null],
        'startImpersonation' => ['POST', '/api/v2/session/impersonation', ['role' => 'mailboxUser', 'username' => 'carol@a.example']],
        'endImpersonation' => ['DELETE', '/api/v2/session/impersonation', null],
        'createWebmailSignInOnBehalf' => ['POST', '/api/v2/mailboxes/carol@a.example/webmail-sign-in', null],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->impersonationCast();
        $this->mailcow()->admin('target', 'x');
        $this->mailcow()->domainAdmin('reseller2', ['a.example']);
        DB::table('admin')->where('username', 'alice')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "0"}']);
        config(['restv2.allow_admin_email_login' => true]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function matrix(): array
    {
        $cases = [];

        foreach (array_keys(self::OPERATIONS) as $operation) {
            foreach (self::CALLERS as $caller) {
                $cases["{$operation} by {$caller}"] = [$operation, $caller];
            }
        }

        return $cases;
    }

    /**
     * The expected status and problem type (null for a success or an `about:blank` answer).
     *
     * @return array{int, string|null}
     */
    private static function expected(string $operation, string $caller): array
    {
        [$method] = self::OPERATIONS[$operation];
        $read = $method === 'GET';
        $sessionOnly = in_array($operation, ['createAdmin', 'updateAdmin', 'deleteAdmin', 'suspendAdminSecondFactors', 'applyAdminBulkAction', 'getImpersonation', 'startImpersonation', 'endImpersonation', 'createWebmailSignInOnBehalf'], true);
        $success = match ($operation) {
            'createAdmin', 'createDomainAdmin', 'startImpersonation' => 201,
            'deleteAdmin', 'deleteDomainAdmin', 'endImpersonation' => 204,
            default => 200,
        };

        if ($operation === 'createSessionWithSsoToken') {
            return match ($caller) {
                'adminKey', 'domainAdminKey' => [403, 'session-required'],
                'adminReadOnlyKey' => [403, 'insufficient-key-scope'],
                default => [401, 'sign-in-failed'],
            };
        }

        // Keys: a read-only key is refused for unsafe methods first, then session-only operations.
        if (in_array($caller, ['adminKey', 'adminReadOnlyKey', 'domainAdminKey'], true)) {
            if ($caller === 'adminReadOnlyKey' && ! $read) {
                return [403, 'insufficient-key-scope'];
            }

            if ($sessionOnly) {
                return [403, 'session-required'];
            }

            return $caller === 'domainAdminKey' ? [403, 'forbidden'] : [$success, null];
        }

        if ($caller === 'anonymous') {
            return [401, 'unauthenticated'];
        }

        if (in_array($operation, ['getImpersonation', 'endImpersonation'], true)) {
            if ($operation === 'endImpersonation') {
                return [204, null];
            }

            return $caller === 'impersonatingSession' ? [200, null] : [404, null];
        }

        if ($caller === 'restrictedSession') {
            return [403, 'pending-action-required'];
        }

        if ($operation === 'startImpersonation') {
            return match ($caller) {
                'adminSession', 'domainAdminSession' => [201, null],
                'impersonatingSession' => [409, 'already-impersonating'],
                default => [403, 'forbidden'],
            };
        }

        if ($operation === 'createWebmailSignInOnBehalf') {
            return match ($caller) {
                'adminSession', 'domainAdminSession' => [200, null],
                'impersonatingSession' => [403, 'not-allowed-while-impersonating'],
                default => [403, 'forbidden'],
            };
        }

        return $caller === 'adminSession' ? [$success, null] : [403, 'forbidden'];
    }

    /**
     * Prepares the caller and returns the headers of its request.
     *
     * @return array<string, string>
     */
    private function caller(string $caller): array
    {
        return match ($caller) {
            'adminKey' => $this->keyHeader($this->apiKey('alice')),
            'adminReadOnlyKey' => $this->keyHeader($this->apiKey('alice', 'readOnly')),
            'domainAdminKey' => $this->keyHeader($this->apiKey('reseller1')),
            'adminSession' => $this->sessionOf('alice', Role::Admin),
            'domainAdminSession' => $this->sessionOf('reseller1', Role::DomainAdmin),
            'mailboxSession' => $this->sessionOf('bob@a.example', Role::MailboxUser),
            'restrictedSession' => $this->restricted(),
            'impersonatingSession' => $this->impersonating(),
            default => $this->csrf(),
        };
    }

    /**
     * @return array<string, string>
     */
    private function sessionOf(string $username, Role $role): array
    {
        $this->signInAs($username, $role);

        return $this->csrf();
    }

    /**
     * @return array<string, string>
     */
    private function restricted(): array
    {
        DB::table('admin')->where('username', 'alice')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "1"}']);

        return $this->sessionOf('alice', Role::Admin);
    }

    /**
     * @return array<string, string>
     */
    private function impersonating(): array
    {
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();

        return $this->csrf();
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function send(string $operation, array $headers): TestResponse
    {
        [$method, $path, $body] = self::OPERATIONS[$operation];

        if ($body === null) {
            return $method === 'POST' ? $this->postEmpty($path, $headers) : $this->json($method, $path, [], $headers);
        }

        return $method === 'PATCH' ? $this->patchMerge($path, $body, $headers) : $this->json($method, $path, $body, $headers);
    }

    #[DataProvider('matrix')]
    public function test_the_operation_answers_the_caller_as_the_matrix_says(string $operation, string $caller): void
    {
        [$status, $type] = self::expected($operation, $caller);

        $response = $this->send($operation, $this->caller($caller))->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }
    }

    public function test_targets_outside_a_domain_administrator_scope_answer_like_missing_ones(): void
    {
        $this->signInAs('reseller1', Role::DomainAdmin);

        foreach (['/api/v2/session/impersonation' => fn (string $m) => ['role' => 'mailboxUser', 'username' => $m]] as $path => $body) {
            $outside = $this->postJson($path, $body('dave@b.example'), $this->csrf())->assertNotFound()->json();
            $missing = $this->postJson($path, $body('nobody@a.example'), $this->csrf())->assertNotFound()->json();
            unset($outside['requestId'], $missing['requestId']);
            $this->assertSame($missing, $outside);
        }

        $outside = $this->postEmpty('/api/v2/mailboxes/dave@b.example/webmail-sign-in', $this->csrf())->assertNotFound()->json();
        $missing = $this->postEmpty('/api/v2/mailboxes/nobody@a.example/webmail-sign-in', $this->csrf())->assertNotFound()->json();
        unset($outside['requestId'], $missing['requestId']);
        $this->assertSame($missing, $outside);
    }
}
