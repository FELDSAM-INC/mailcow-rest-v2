<?php

namespace Tests\Feature\Impersonation;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T055 (AS US3-9, FR-049, research R19): during an impersonation the impersonated
 * account's sign-in credentials cannot be managed and no webmail sign-in can be obtained; app
 * passwords follow the effective flags.
 */
class ImpersonationRefusalsTest extends TestCase
{
    use AccountTestHelpers;

    private const PASSKEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->impersonationCast(['app_passwds' => 0]);
    }

    /**
     * @return array<string, callable(self): TestResponse<Response>>
     */
    private function credentialRequests(int $secondFactorId): array
    {
        $csrf = fn (): array => $this->csrf();

        return [
            'passkey registration options' => fn () => $this->postEmpty('/api/v2/me/passkeys/registration-options', $csrf()),
            'passkey registration' => fn () => $this->postJson('/api/v2/me/passkeys', ['credential' => ['id' => 'AQID', 'rawId' => 'AQID', 'type' => 'public-key', 'response' => ['clientDataJSON' => 'e30', 'attestationObject' => 'oA']]], $csrf()),
            'passkey rename' => fn () => $this->patchMerge('/api/v2/me/passkeys/'.self::PASSKEY, ['name' => 'x'], $csrf()),
            'passkey removal' => fn () => $this->deleteJson('/api/v2/me/passkeys/'.self::PASSKEY, [], $csrf()),
            'password change' => fn () => $this->putJson('/api/v2/me/password', ['currentPassword' => 'Passw0rd!', 'newPassword' => 'N3w-Passw0rd!x'], $csrf()),
            'second-factor enrolment' => fn () => $this->postJson('/api/v2/me/second-factors', ['type' => 'totp', 'name' => 'Phone', 'code' => '123456'], $csrf()),
            'TOTP enrolment start' => fn () => $this->postEmpty('/api/v2/me/second-factors/totp-enrolment', $csrf()),
            'WebAuthn registration options' => fn () => $this->postEmpty('/api/v2/me/second-factors/webauthn-registration-options', $csrf()),
            'removal of every second factor' => fn () => $this->postJson('/api/v2/me/second-factors/removal', ['currentPassword' => 'Passw0rd!'], $csrf()),
            'second-factor removal' => fn () => $this->deleteJson('/api/v2/me/second-factors/'.$secondFactorId, [], $csrf()),
            'recovery e-mail change' => fn () => $this->putJson('/api/v2/me/recovery-email', ['address' => 'new@example.net', 'currentPassword' => 'Passw0rd!'], $csrf()),
            'own webmail hand-off' => fn () => $this->postEmpty('/api/v2/me/webmail-handoff', $csrf()),
        ];
    }

    public function test_credential_operations_and_webmail_are_refused(): void
    {
        $tfa = $this->mailcow()->tfaTotp('bob@a.example', 'JBSWY3DPEHPK3PXP');
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        $password = DB::table('mailbox')->where('username', 'bob@a.example')->value('password');
        $legacySessions = $this->redis()->keys('PHPREDIS_SESSION:*');

        foreach ($this->credentialRequests($tfa) as $name => $request) {
            $request()->assertForbidden()->assertJsonPath('type', '/api/v2/problems/not-allowed-while-impersonating');
            unset($name);
        }

        $this->assertSame($password, DB::table('mailbox')->where('username', 'bob@a.example')->value('password'));
        $this->assertSame(1, DB::table('tfa')->where('username', 'bob@a.example')->count());
        $this->assertEqualsCanonicalizing($legacySessions, $this->redis()->keys('PHPREDIS_SESSION:*'));
        $this->getJson('/api/v2/me/passkeys')->assertOk();
    }

    public function test_app_passwords_follow_the_effective_flags(): void
    {
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        $this->postJson('/api/v2/mailboxes/bob@a.example/app-passwords', ['name' => 'Phone'], $this->csrf())->assertCreated();
        $this->endImpersonation()->assertNoContent();

        $this->signInAs('reseller1', Role::DomainAdmin);
        $this->impersonate('mailboxUser', 'carol@a.example')->assertCreated();
        $this->postJson('/api/v2/mailboxes/carol@a.example/app-passwords', ['name' => 'Phone'], $this->csrf())->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required');
    }
}
