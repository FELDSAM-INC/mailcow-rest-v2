<?php

namespace Tests\Feature\Admins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T074 (AS US4-8, FR-001, FR-004, FR-005): administrator accounts are read by
 * administrators with a session or a key, and written only in a signed-in administrator session.
 * Keys are refused on writes before the role is checked (`session-required`), as feature 001's key
 * creation does; a read-only key earlier still (`insufficient-key-scope`).
 */
class AdminAuthorizationTest extends TestCase
{
    use AccountTestHelpers;

    private const READS = ['list', 'get', 'capabilities'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->admin('alice', 'x');
        $this->mailcow()->admin('target', 'x');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->mailcow()->mailboxWithDomain('bob@a.example');
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function send(string $operation, array $headers): TestResponse
    {
        return match ($operation) {
            'list' => $this->getJson('/api/v2/admins', $headers),
            'get' => $this->getJson('/api/v2/admins/target', $headers),
            'capabilities' => $this->getJson('/api/v2/capabilities/accounts', $headers),
            'create' => $this->postJson('/api/v2/admins', ['username' => 'newone', 'password' => 'Passw0rd!x'], $headers),
            'update' => $this->patchMerge('/api/v2/admins/target', ['forcePasswordChange' => true], $headers),
            'delete' => $this->deleteJson('/api/v2/admins/target', [], $headers),
            'suspend' => $this->postEmpty('/api/v2/admins/target/second-factor-suspension', $headers),
            'bulk' => $this->postJson('/api/v2/admins/bulk-actions', ['action' => 'activate', 'targets' => [['id' => 'target']]], $headers),
            default => throw new \LogicException($operation),
        };
    }

    /**
     * @return array<string, array{string}>
     */
    public static function operations(): array
    {
        $operations = ['list', 'get', 'capabilities', 'create', 'update', 'delete', 'suspend', 'bulk'];

        return array_combine($operations, array_map(fn (string $o): array => [$o], $operations));
    }

    private static function success(string $operation): int
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
        $this->signInAs('alice', Role::Admin);

        $this->send($operation, in_array($operation, self::READS, true) ? [] : $this->csrf())->assertStatus(self::success($operation));
    }

    #[DataProvider('operations')]
    public function test_administrator_keys_may_only_read(string $operation): void
    {
        $readWrite = $this->send($operation, $this->keyHeader($this->apiKey('alice')));
        $readOnly = $this->send($operation, $this->keyHeader($this->apiKey('alice', 'readOnly')));

        if (in_array($operation, self::READS, true)) {
            $readWrite->assertOk();
            $readOnly->assertOk();
        } else {
            $readWrite->assertForbidden()->assertJsonPath('type', '/api/v2/problems/session-required');
            $readOnly->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        }

        $this->assertAdminRow('target', 1, 1);
    }

    #[DataProvider('operations')]
    public function test_domain_administrators_and_mailbox_users_are_refused(string $operation): void
    {
        $read = in_array($operation, self::READS, true);
        $this->send($operation, $this->keyHeader($this->apiKey('reseller1')))->assertForbidden()
            ->assertJsonPath('type', $read ? '/api/v2/problems/forbidden' : '/api/v2/problems/session-required');

        $this->signInAs('reseller1', Role::DomainAdmin);
        $this->send($operation, $read ? [] : $this->csrf())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->signInAs('bob@a.example', Role::MailboxUser);
        $this->send($operation, $read ? [] : $this->csrf())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    #[DataProvider('operations')]
    public function test_anonymous_and_restricted_sessions_are_refused(string $operation): void
    {
        $read = in_array($operation, self::READS, true);
        $this->send($operation, $read ? [] : $this->csrf())->assertUnauthorized();

        DB::table('admin')->where('username', 'alice')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "1"}']);
        $this->signInAs('alice', Role::Admin);
        $this->send($operation, $read ? [] : $this->csrf())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
    }
}
