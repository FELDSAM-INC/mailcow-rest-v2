<?php

namespace Tests\Feature\Security;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\ProviderPasswordTestHelpers;
use Tests\TestCase;

/**
 * T089 (SC-004, FR-055): after every flow of feature 012 with distinctive secrets, codes, states,
 * verifiers and tokens, no response (apart from the OAuth2 creation and regeneration secrets), log
 * line, audit record or session payload contains any of them.
 */
class IdentityProviderSecretScanTest extends TestCase
{
    use ProviderPasswordTestHelpers;

    private const KC_SECRET = 'Kc-Scan-Secret-4711';

    private const LDAP_SECRET = 'Ldap-Scan-Secret-4712';

    private const ACCESS = 'Provider-Access-Token-4713';

    private const REFRESH = 'Provider-Refresh-Token-4714';

    private const RENEWED = 'Provider-Renewed-Token-4715';

    private const CODE = 'Provider-Code-4716';

    private const SERVICE = 'Service-Token-4717';

    private const USER_PASSWORD = 'User-Passw0rd-4718';

    public function test_no_secret_leaks(): void
    {
        $log = storage_path('logs/testing.log');
        $offset = is_file($log) ? (int) filesize($log) : 0;
        $bodies = [];
        $shownOnce = [];

        $this->staffTemplate();
        $this->mailcow()->ensureDomain('example.org');
        $this->asAdmin();

        // Configuration: save, change, test (Keycloak), then LDAP.
        $idp = FakeOidcProvider::install()->tokenIssued('client_credentials', self::SERVICE, null);
        $bodies[] = $this->putProvider(IdentityProviderFixtures::keycloak(['clientSecret' => self::KC_SECRET, 'mailpasswordFlow' => true]))->assertCreated()->getContent();
        $bodies[] = $this->patchProvider(['syncInterval' => 30])->assertOk()->getContent();
        $bodies[] = $this->testConnection(IdentityProviderFixtures::keycloak(['clientSecret' => self::KC_SECRET]))->assertOk()->getContent();
        $bodies[] = $this->getJson('/api/v2/identity-provider')->getContent();

        // Single sign-on with renewal.
        $this->forgetCookies();
        $idp = FakeOidcProvider::install()->tokenIssued('authorization_code', self::ACCESS, self::REFRESH)->userInfo(IdentityProviderFixtures::userInfo('ben@example.org', 'Ben', 'staff'));
        $started = $this->startSso();
        // The authorization address carries the state to the browser by design; nothing else may.
        $startBody = str_replace($started['state'], '', (string) $started['response']->getContent());
        $bodies[] = $startBody;
        $bodies[] = $this->completeSso($started['state'], self::CODE)->assertCreated()->getContent();
        $session = (string) Redis::connection('mailcow')->get('restv2:session:'.$this->sessionCookie());
        $verifier = (string) $idp->last('token:authorization_code')['form']['code_verifier'];
        $idp->tokenIssued('refresh_token', self::RENEWED, null)->userInfo(IdentityProviderFixtures::userInfo('ben@example.org', 'Ben', 'staff'));
        $this->travel(6)->minutes();
        $bodies[] = $this->getJson('/api/v2/me')->assertOk()->getContent();
        $links = array_map(fn (string $k): string => (string) Redis::connection('mailcow')->get($k), Redis::connection('mailcow')->keys('restv2:sso-link:*'));

        // Keycloak mailpassword sign-in.
        $this->forgetCookies();
        $this->externalMailbox('anna@example.org', 'keycloak');
        FakeOidcProvider::install()->tokenIssued('client_credentials', self::SERVICE, null)->introspection(true)->users([IdentityProviderFixtures::keycloakUser('anna@example.org', MailcowRows::hash('BLF-CRYPT', self::USER_PASSWORD))]);
        $bodies[] = $this->passwordSignIn('anna@example.org', self::USER_PASSWORD)->assertCreated()->getContent();
        $bodies[] = $this->passwordSignIn('anna@example.org', 'wrong-'.self::USER_PASSWORD)->getContent();

        // LDAP password sign-in.
        $this->forgetCookies();
        $this->asAdmin();
        DB::table('mailbox')->whereIn('username', ['anna@example.org', 'ben@example.org'])->update(['authsource' => 'mailcow']);
        $bodies[] = $this->putProvider(IdentityProviderFixtures::ldap(['bindPassword' => self::LDAP_SECRET]))->assertOk()->getContent();
        $this->ldapFake()->password('cn=admin,dc=example,dc=org', self::LDAP_SECRET)
            ->entry(self::CARL_FILTER, self::CARL_DN, ['mail' => 'carl@example.org', 'title' => 'staff', 'displayName' => 'Carl'], self::USER_PASSWORD);
        $this->forgetCookies();
        $bodies[] = $this->passwordSignIn('carl@example.org', self::USER_PASSWORD)->assertCreated()->getContent();

        // OAuth2 clients.
        $this->forgetCookies();
        $this->asAdmin();
        $created = $this->postJson('/api/v2/oauth2-clients', ['redirectUris' => ['https://wiki.example.org/cb']], $this->csrf())->assertCreated();
        $shownOnce[] = (string) $created->json('data.clientSecret');
        $id = (int) $created->json('data.id');
        $regenerated = $this->postEmpty('/api/v2/oauth2-clients/'.$id.'/secret-regeneration', $this->csrf())->assertOk();
        $shownOnce[] = (string) $regenerated->json('data.clientSecret');
        $bodies[] = $this->postEmpty('/api/v2/oauth2-clients/'.$id.'/token-revocation', $this->csrf())->getContent();
        $bodies[] = $this->getJson('/api/v2/oauth2-clients')->getContent();
        $bodies[] = $this->postJson('/api/v2/oauth2-clients/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $id]]], $this->csrf())->getContent();

        $secrets = [self::KC_SECRET, self::LDAP_SECRET, self::ACCESS, self::REFRESH, self::RENEWED, self::CODE, self::SERVICE, self::USER_PASSWORD, $started['state'], $verifier, ...$shownOnce];
        $logged = is_file($log) ? (string) file_get_contents($log, offset: $offset) : '';
        $audit = (string) json_encode(DB::table('restv2_audit_events')->get());

        foreach ($secrets as $secret) {
            $this->assertNotSame('', $secret);

            foreach ([...$bodies, $logged, $audit, $session, ...$links] as $text) {
                $this->assertStringNotContainsString($secret, (string) $text);
            }
        }
    }
}
