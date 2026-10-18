<?php

namespace Tests\Feature\Mailboxes;

use App\Support\Totp\NoQrCodeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RobThree\Auth\TwoFactorAuth;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\TestCase;

/**
 * Feature 003 T082 (FR-046, US8 AS-5, SC-008): second factors and passkeys keep working under the
 * new address, with the same password.
 */
class RenameMailboxSecondFactorTest extends TestCase
{
    use AuthRequests;
    use MailboxTestHelpers;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_totp_and_passkeys_follow_the_rename(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $totp = $this->mailcow()->tfaTotp('anna@example.org', self::SECRET);
        $authenticator = new VirtualAuthenticator;
        $credential = $authenticator->createCredential('mail.example.org', 'anna@example.org');
        $this->mailcow()->fido2Credential('anna@example.org', $credential);
        $this->asAdmin();

        $this->postJson('/api/v2/mailboxes/anna@example.org/rename', ['newLocalPart' => 'anna.smith', 'keepOldAddressAsAlias' => false], $this->csrf())->assertOk();
        $this->assertSame(1, DB::table('fido2')->where('username', 'anna.smith@example.org')->count());

        $this->forgetCookies();
        $this->passwordSignIn('anna.smith@example.org', MailcowFactory::PASSWORD)->assertCreated()
            ->assertJsonPath('data.state', 'pendingSecondFactor')
            ->assertJsonPath('data.pendingSignIn.methods.0.id', $totp);

        $code = (new TwoFactorAuth(new NoQrCodeProvider))->getCode(self::SECRET, CarbonImmutable::now()->getTimestamp());
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $totp, 'code' => $code])->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.username', 'anna.smith@example.org');

        $this->forgetCookies();
        $host = 'https://mail.example.org';
        $options = $this->postEmpty($host.'/api/v2/session/passkey-challenge', ['X-CSRF-Token' => $this->csrfToken()])->assertOk()->json('data.publicKey');
        $assertion = $authenticator->assert($credential, $options, $host);
        $this->postJson($host.'/api/v2/session/passkey-assertion', ['credential' => $assertion], ['X-CSRF-Token' => $this->csrfToken()])->assertCreated()
            ->assertJsonPath('data.username', 'anna.smith@example.org');
    }
}
