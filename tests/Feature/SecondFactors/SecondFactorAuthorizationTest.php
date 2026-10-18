<?php

namespace Tests\Feature\SecondFactors;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T042 (feature 005 FR-004, FR-028, US2 AS-9): the own second-factor operations for every role,
 * external identity sources, and API keys.
 */
class SecondFactorAuthorizationTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    /**
     * @return array<string, array{string, string, array<string, mixed>|null}>
     */
    public static function operations(): array
    {
        return [
            'list' => ['GET', '/api/v2/me/second-factors', null],
            'read' => ['GET', '/api/v2/me/second-factors/{id}', null],
            'totp start' => ['POST', '/api/v2/me/second-factors/totp-enrolment', null],
            'webauthn options' => ['POST', '/api/v2/me/second-factors/webauthn-registration-options', null],
            'enrol' => ['POST', '/api/v2/me/second-factors', ['type' => 'totp', 'name' => 'Phone', 'code' => '123456', 'currentPassword' => 'x']],
            'remove one' => ['DELETE', '/api/v2/me/second-factors/{id}', null],
            'remove all' => ['POST', '/api/v2/me/second-factors/removal', ['currentPassword' => 'x']],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, ?array $body, array $headers): TestResponse
    {
        if ($body === null && $method === 'POST') {
            return $this->postEmpty($path, $headers);
        }

        return $this->json($method, $path, $body ?? [], $headers);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    #[DataProvider('operations')]
    public function test_external_identity_sources_are_refused(string $method, string $path, ?array $body): void
    {
        foreach (['keycloak', 'generic-oidc'] as $i => $source) {
            $username = "ext{$i}@example.org";
            $this->mailcow()->ensureDomain('example.org');
            $this->mailcow()->mailbox($username, authsource: $source);
            $id = $this->mailcow()->tfaTotp($username, 'JBSWY3DPEHPK3PXP');
            $this->signInAs($username, Role::MailboxUser);

            $headers = $method === 'GET' ? [] : $this->csrf();
            $this->send($method, str_replace('{id}', (string) $id, $path), $body, $headers)
                ->assertStatus(403)
                ->assertJsonPath('type', '/api/v2/problems/password-managed-externally');
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    #[DataProvider('operations')]
    public function test_api_keys_are_refused(string $method, string $path, ?array $body): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $id = $this->mailcow()->tfaTotp('admin', 'JBSWY3DPEHPK3PXP');

        foreach ([['admin', 'readOnly'], ['admin', 'readWrite'], ['reseller', 'readOnly'], ['reseller', 'readWrite']] as [$owner, $level]) {
            $response = $this->send($method, str_replace('{id}', (string) $id, $path), $body, $this->keyHeader($this->apiKey($owner, $level)))->assertStatus(403);
            $expected = $level === 'readOnly' && $method !== 'GET' ? '/api/v2/problems/insufficient-key-scope' : '/api/v2/problems/session-required';
            $response->assertJsonPath('type', $expected);
        }
    }

    public function test_every_role_manages_its_own_entries(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->mailcow()->mailboxWithDomain('anna@example.org');

        foreach ([['admin', Role::Admin], ['reseller', Role::DomainAdmin], ['anna@example.org', Role::MailboxUser]] as [$username, $role]) {
            $id = $this->mailcow()->tfaTotp($username, 'JBSWY3DPEHPK3PXP');
            $this->mailcow()->tfaTotp($username, 'KRSXG5CTMVRXEZLU');
            $this->signInAs($username, $role);

            $this->getJson('/api/v2/me/second-factors')->assertOk()->assertJsonPath('meta.total', 2);
            $this->postEmpty('/api/v2/me/second-factors/totp-enrolment', $this->csrf())->assertOk();
            $this->deleteJson('/api/v2/me/second-factors/'.$id, [], $this->csrf())->assertNoContent();
        }
    }

    public function test_refusals_of_authenticated_callers_are_audited(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('ext@example.org', authsource: 'keycloak');
        $this->signInAs('ext@example.org', Role::MailboxUser);

        $this->postJson('/api/v2/me/second-factors/removal', ['currentPassword' => 'x'], $this->csrf())->assertStatus(403);

        $record = $this->onlyAuditRecord('ownSecondFactorRemoval');
        $this->assertSame('externalIdentitySource', $record->failure_reason);
        $this->assertSame('ext@example.org', $record->target_id);
    }
}
