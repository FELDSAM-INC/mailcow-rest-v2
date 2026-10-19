<?php

namespace Tests\Feature\DomainAdmins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T034 (AS US1-10, FR-001, FR-004, FR-005): only administrators manage domain
 * administrators, with a session or a read-write key; read-only keys may only read.
 */
class DomainAdminAuthorizationTest extends TestCase
{
    use AccountTestHelpers;

    private const WRITES = ['create', 'update', 'delete', 'suspend', 'bulk'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->admin('admin', 'x');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->mailcow()->domainAdmin('target', ['a.example']);
        $this->mailcow()->mailboxWithDomain('bob@a.example');
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function call7(string $operation, array $headers): TestResponse
    {
        return match ($operation) {
            'list' => $this->getJson('/api/v2/domain-admins', $headers),
            'get' => $this->getJson('/api/v2/domain-admins/target', $headers),
            'create' => $this->postJson('/api/v2/domain-admins', ['username' => 'newone', 'password' => 'Passw0rd!x', 'domains' => ['a.example']], $headers),
            'update' => $this->patchMerge('/api/v2/domain-admins/target', ['forcePasswordChange' => true], $headers),
            'delete' => $this->deleteJson('/api/v2/domain-admins/target', [], $headers),
            'suspend' => $this->postEmpty('/api/v2/domain-admins/target/second-factor-suspension', $headers),
            'bulk' => $this->postJson('/api/v2/domain-admins/bulk-actions', ['action' => 'activate', 'targets' => [['id' => 'target']]], $headers),
            default => throw new \LogicException($operation),
        };
    }

    /**
     * @return array<string, array{string}>
     */
    public static function operations(): array
    {
        return array_combine(
            ['list', 'get', 'create', 'update', 'delete', 'suspend', 'bulk'],
            array_map(fn (string $o): array => [$o], ['list', 'get', 'create', 'update', 'delete', 'suspend', 'bulk']),
        );
    }

    private static function successStatus(string $operation): int
    {
        return match ($operation) {
            'create' => 201,
            'delete' => 204,
            default => 200,
        };
    }

    #[DataProvider('operations')]
    public function test_an_administrator_session_is_allowed(string $operation): void
    {
        $this->signInAs('admin', Role::Admin);
        $headers = in_array($operation, self::WRITES, true) ? $this->csrf() : [];

        $this->call7($operation, $headers)->assertStatus(self::successStatus($operation));
    }

    #[DataProvider('operations')]
    public function test_an_administrator_read_write_key_is_allowed(string $operation): void
    {
        $this->call7($operation, $this->keyHeader($this->apiKey('admin')))->assertStatus(self::successStatus($operation));
    }

    #[DataProvider('operations')]
    public function test_an_administrator_read_only_key_may_only_read(string $operation): void
    {
        $response = $this->call7($operation, $this->keyHeader($this->apiKey('admin', 'readOnly')));

        if (in_array($operation, self::WRITES, true)) {
            $response->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        } else {
            $response->assertOk();
        }
    }

    #[DataProvider('operations')]
    public function test_domain_administrators_and_mailbox_users_are_forbidden(string $operation): void
    {
        $this->call7($operation, $this->keyHeader($this->apiKey('reseller1')))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->signInAs('reseller1', Role::DomainAdmin);
        $csrf = $this->csrf();
        $this->call7($operation, $csrf)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->signInAs('bob@a.example', Role::MailboxUser);
        $this->call7($operation, $this->csrf())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->assertAdminRow('target', 0, 1);
    }

    public function test_a_domain_administrator_cannot_change_its_own_account(): void
    {
        $this->signInAs('reseller1', Role::DomainAdmin);

        $this->getJson('/api/v2/domain-admins/reseller1')->assertForbidden();
        $this->patchMerge('/api/v2/domain-admins/reseller1', ['permissions' => ['unlimitedQuota' => true]], $this->csrf())->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->assertDaAcl('reseller1', ['unlimited_quota' => 0]);
    }

    #[DataProvider('operations')]
    public function test_requests_without_credentials_are_unauthenticated(string $operation): void
    {
        $headers = in_array($operation, self::WRITES, true) ? $this->csrf() : [];

        $this->call7($operation, $headers)->assertUnauthorized();
    }

    #[DataProvider('operations')]
    public function test_a_restricted_administrator_session_is_refused(string $operation): void
    {
        DB::table('admin')->where('username', 'admin')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "1"}']);
        $this->signInAs('admin', Role::Admin);
        $headers = in_array($operation, self::WRITES, true) ? $this->csrf() : [];

        $this->call7($operation, $headers)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
    }

    public function test_session_writes_need_the_anti_forgery_token(): void
    {
        $this->signInAs('admin', Role::Admin);

        foreach (self::WRITES as $operation) {
            $this->call7($operation, [])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        }

        $this->assertAdminRow('target', 0, 1);
    }
}
