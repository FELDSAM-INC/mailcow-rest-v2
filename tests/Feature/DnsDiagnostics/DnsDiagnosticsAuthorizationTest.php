<?php

namespace Tests\Feature\DnsDiagnostics;

use App\Auth\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DnsDiagnostics\DiagnosticsTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T060 (US3 AS5, FR-001 to FR-005, FR-041): the role and key matrix of diagnostics
 * (read-only keys allowed). Domain administrators diagnose their domains and alias domains without
 * the server-wide checks; foreign names are `404`; mailbox users are forbidden.
 */
class DnsDiagnosticsAuthorizationTest extends TestCase
{
    use DiagnosticsTestHelpers;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->aliasDomain('alias.example', 'example.org');
        $f->admin('admin', 'Passw0rd!');
        $f->admin('restricted', 'Passw0rd!', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org']);
        $f->mailboxWithDomain('anna@example.org');
        $this->server();
        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'daKey' => $this->apiKey('reseller')['secret'],
            'daReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
        ];
    }

    /**
     * @return array<string, array{string, int, string|null, bool|null}>
     */
    public static function callers(): array
    {
        return [
            'anonymous' => ['anonymous', 401, 'unauthenticated', null],
            'restricted session' => ['restrictedSession', 403, 'pending-action-required', null],
            'mailbox user' => ['mailboxSession', 403, 'forbidden', null],
            'administrator session' => ['adminSession', 200, null, true],
            'administrator key' => ['adminKey', 200, null, true],
            'administrator read-only key' => ['adminReadOnlyKey', 200, null, true],
            'domain administrator session' => ['daSession', 200, null, false],
            'domain administrator key' => ['daKey', 200, null, false],
            'domain administrator read-only key' => ['daReadOnlyKey', 200, null, false],
        ];
    }

    #[DataProvider('callers')]
    public function test_every_caller(string $caller, int $status, ?string $type, ?bool $serverChecks): void
    {
        $headers = $this->as($caller);

        $response = $this->diagnose('example.org', $headers)->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        } else {
            $response->assertJsonPath('data.serverChecksIncluded', $serverChecks);
        }
    }

    public function test_a_domain_administrator_gets_the_domain_checks_without_the_server_checks(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        foreach (['example.org', 'alias.example'] as $name) {
            $response = $this->diagnose($name)->assertOk()->assertJsonPath('data.serverChecksIncluded', false);
            $scopes = array_unique(array_column((array) $response->json('data.checks'), 'scope'));
            $this->assertSame(['domain'], array_values($scopes));
            $this->assertContains('serverChecksOmitted', array_column((array) $response->json('data.notes'), 'code'));
        }

        $this->diagnose('foreign.example')->assertNotFound();
    }

    /**
     * @return array<string, string>
     */
    private function as(string $caller): array
    {
        return match ($caller) {
            'anonymous' => [],
            'adminSession' => $this->signedIn('admin', Role::Admin),
            'restrictedSession' => $this->signedIn('restricted', Role::Admin),
            'mailboxSession' => $this->signedIn('anna@example.org', Role::MailboxUser),
            'daSession' => $this->signedIn('reseller', Role::DomainAdmin),
            default => $this->keyHeader($this->keys[$caller]),
        };
    }

    /**
     * @return array<string, string>
     */
    private function signedIn(string $username, Role $role): array
    {
        $this->signInAs($username, $role);

        return [];
    }
}
