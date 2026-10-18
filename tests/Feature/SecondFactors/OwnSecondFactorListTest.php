<?php

namespace Tests\Feature\SecondFactors;

use App\Auth\Role;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\Support\Totp\TestTotp;
use Tests\TestCase;

/**
 * T036 (feature 005 FR-027, US2 AS-8): listOwnSecondFactors and getOwnSecondFactor list every entry
 * of the signed-in account with its state and usability, without secret material.
 */
class OwnSecondFactorListTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    /**
     * @return array<string, int>
     */
    private function entries(): array
    {
        return [
            'totp' => $this->mailcow()->tfaTotp('anna@example.org', self::SECRET, 'Phone'),
            'webauthn' => $this->mailcow()->tfaWebAuthn('anna@example.org', random_bytes(32), "-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----\n", 'Key'),
            'yubi' => $this->mailcow()->tfaYubi('anna@example.org', '12345', 'c2VjcmV0', 'cccjgjgkhcbb', 'YubiKey'),
            'u2f' => $this->mailcow()->tfaU2f('anna@example.org'),
            'hotp' => $this->mailcow()->tfaHotp('anna@example.org', 0),
        ];
    }

    public function test_every_entry_is_listed_without_secrets(): void
    {
        $this->asSelfServiceUser();
        $ids = $this->entries();

        $response = $this->getJson('/api/v2/me/second-factors')->assertOk()->assertJsonPath('meta.total', 5);

        $this->assertSame([
            ['id' => $ids['totp'], 'type' => 'totp', 'name' => 'Phone', 'active' => true, 'usable' => true, 'yubicoPublicId' => null, 'usedForCurrentSignIn' => false],
            ['id' => $ids['webauthn'], 'type' => 'webauthn', 'name' => 'Key', 'active' => true, 'usable' => true, 'yubicoPublicId' => null, 'usedForCurrentSignIn' => false],
            ['id' => $ids['yubi'], 'type' => 'yubiOtp', 'name' => 'YubiKey', 'active' => true, 'usable' => true, 'yubicoPublicId' => 'cccjgjgkhcbb', 'usedForCurrentSignIn' => false],
            ['id' => $ids['u2f'], 'type' => 'u2f', 'name' => 'U2F key', 'active' => true, 'usable' => false, 'yubicoPublicId' => null, 'usedForCurrentSignIn' => false],
            ['id' => $ids['hotp'], 'type' => 'hotp', 'name' => 'HOTP', 'active' => false, 'usable' => false, 'yubicoPublicId' => null, 'usedForCurrentSignIn' => false],
        ], $response->json('data'));

        $body = (string) $response->getContent();
        foreach ([self::SECRET, 'c2VjcmV0', '12345', 'BEGIN PUBLIC KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $this->getJson('/api/v2/me/second-factors/'.$ids['yubi'])
            ->assertOk()
            ->assertJsonPath('data.type', 'yubiOtp')
            ->assertJsonPath('data.yubicoPublicId', 'cccjgjgkhcbb');
    }

    public function test_the_entry_of_the_current_sign_in_is_marked(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $first = $this->mailcow()->tfaTotp('anna@example.org', self::SECRET, 'Phone');
        $second = $this->mailcow()->tfaTotp('anna@example.org', 'KRSXG5CTMVRXEZLU', 'Tablet');

        $this->passwordSignIn('anna@example.org')->assertCreated();
        $this->verifySecondFactor(['type' => 'totp', 'methodId' => $second, 'code' => TestTotp::code('KRSXG5CTMVRXEZLU')])->assertOk();

        $data = $this->getJson('/api/v2/me/second-factors')->assertOk()->json('data');
        $this->assertSame([$first => false, $second => true], array_column($data, 'usedForCurrentSignIn', 'id'));
    }

    public function test_sorting_paging_and_search(): void
    {
        $this->asSelfServiceUser();
        $this->entries();

        $this->assertSame(['HOTP', 'Key', 'Phone', 'U2F key', 'YubiKey'], array_column($this->getJson('/api/v2/me/second-factors?sort=name')->assertOk()->json('data'), 'name'));
        $this->assertSame(['hotp', 'totp'], array_column($this->getJson('/api/v2/me/second-factors?sort=type&order=asc&limit=2')->assertOk()->json('data'), 'type'));
        $this->getJson('/api/v2/me/second-factors?limit=2&offset=4')->assertOk()->assertJsonPath('meta', ['total' => 5, 'limit' => 2, 'offset' => 4])->assertJsonCount(1, 'data');
        $this->assertSame(['YubiKey'], array_column($this->getJson('/api/v2/me/second-factors?q=yubi')->assertOk()->json('data'), 'name'));
    }

    public function test_another_accounts_entry_is_not_found(): void
    {
        $this->mailcow()->mailboxWithDomain('carl@example.org');
        $foreign = $this->mailcow()->tfaTotp('carl@example.org', self::SECRET);
        $this->asSelfServiceUser();

        $this->getJson('/api/v2/me/second-factors/'.$foreign)->assertNotFound();
        $this->getJson('/api/v2/me/second-factors/999999')->assertNotFound();
    }

    public function test_restricted_sessions_and_keys(): void
    {
        $this->asSelfServiceUser('bob@example.org', ['force_tfa' => '1']);
        $this->getJson('/api/v2/me/second-factors')->assertOk()->assertJsonPath('data', []);

        $this->asSelfServiceUser('dora@example.org', ['force_pw_update' => '1']);
        $this->getJson('/api/v2/me/second-factors')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->forgetCookies();
        $this->getJson('/api/v2/me/second-factors', $this->keyHeader($this->apiKey('admin')))
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/session-required');
    }

    public function test_an_administrator_lists_its_own_entries(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $id = $this->mailcow()->tfaTotp('admin', self::SECRET);
        $this->signInAs('admin', Role::Admin);

        $this->getJson('/api/v2/me/second-factors')->assertOk()->assertJsonPath('data.0.id', $id);
    }
}
