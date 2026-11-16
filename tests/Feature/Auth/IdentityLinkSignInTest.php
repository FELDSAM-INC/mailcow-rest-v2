<?php

namespace Tests\Feature\Auth;

use App\Models\Mailcow\SchemaVersion;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\ProviderPasswordTestHelpers;
use Tests\TestCase;

class IdentityLinkSignInTest extends TestCase
{
    use ProviderPasswordTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->markTestSkipped('Stable identity links require the 2026-11 schema.');
        }
        $this->staffTemplate();
        $this->mailcow()->ensureDomain('example.org');
    }

    public function test_reassigned_ldap_email_cannot_open_or_modify_a_previously_linked_mailbox(): void
    {
        $ldap = $this->directory(['id_attribute' => 'entryuuid']);
        $ldap->entry(self::CARL_FILTER, self::CARL_DN, ['mail' => 'carl@example.org', 'entryUUID' => 'original-person', 'title' => 'staff'], self::DIRECTORY_PASSWORD);
        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated();
        $this->assertDatabaseHas('identity_link', ['username' => 'carl@example.org', 'external_id' => 'original-person']);
        $this->forgetCookies();
        $before = (array) DB::table('mailbox')->where('username', 'carl@example.org')->first();
        $ldap->entry(self::CARL_FILTER, self::CARL_DN, ['mail' => 'carl@example.org', 'entryUUID' => 'different-person', 'title' => 'staff', 'displayName' => 'Attacker'], self::DIRECTORY_PASSWORD);
        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertUnauthorized();
        $this->assertEquals($before, (array) DB::table('mailbox')->where('username', 'carl@example.org')->first());
        $this->assertDatabaseHas('identity_link', ['username' => 'carl@example.org', 'external_id' => 'original-person']);
    }

    public function test_one_provider_identity_cannot_provision_a_second_mailbox(): void
    {
        $this->directory(['id_attribute' => 'entryuuid'])->entry(self::CARL_FILTER, self::CARL_DN,
            ['mail' => 'carl@example.org', 'entryUUID' => 'existing-person', 'title' => 'staff'], self::DIRECTORY_PASSWORD);
        $this->externalMailbox('original@example.org', 'ldap');
        DB::table('identity_link')->insert(['username' => 'original@example.org', 'provider' => 'ldap', 'external_id' => 'existing-person']);
        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertUnauthorized();
        $this->assertDatabaseMissing('mailbox', ['username' => 'carl@example.org']);
    }

    public function test_ldap_binary_objectguid_matches_upstream_stable_identity_format(): void
    {
        $this->directory()->entry(self::CARL_FILTER, self::CARL_DN,
            ['mail' => 'carl@example.org', 'objectGUID' => hex2bin('33221100554477668899aabbccddeeff'), 'title' => 'staff'], self::DIRECTORY_PASSWORD);
        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated();
        $this->assertDatabaseHas('identity_link', ['username' => 'carl@example.org', 'external_id' => '00112233-4455-6677-8899-aabbccddeeff']);
    }

    public function test_oidc_email_reassignment_is_refused_and_unlink_ends_existing_session(): void
    {
        $this->mailcow()->keycloakConfig(['mappers' => '[]', 'templates' => '[]']);
        $this->externalMailbox('anna@example.org');
        $claims = IdentityProviderFixtures::userInfo('anna@example.org');
        DB::table('identity_link')->insert(['username' => 'anna@example.org', 'provider' => 'keycloak', 'external_id' => 'original-person']);
        $this->keycloakSignIn($claims);
        $this->completeSso($this->startSso()['state'])->assertUnauthorized();
        $claims['sub'] = 'original-person';
        $this->keycloakSignIn($claims);
        $this->completeSso($this->startSso()['state'])->assertCreated();
        $this->getJson('/api/v2/me')->assertOk();
        DB::table('identity_link')->where('username', 'anna@example.org')->delete();
        $this->getJson('/api/v2/me')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/identity-provider-session-ended');
        $this->assertDatabaseMissing('identity_link', ['username' => 'anna@example.org']);
    }

    public function test_oidc_subject_change_on_refresh_ends_session_even_when_email_is_the_same(): void
    {
        $this->mailcow()->keycloakConfig(['mappers' => '[]', 'templates' => '[]']);
        $this->externalMailbox('anna@example.org');
        $claims = IdentityProviderFixtures::userInfo('anna@example.org');
        $provider = $this->keycloakSignIn($claims);
        $this->completeSso($this->startSso()['state'])->assertCreated();
        $claims['sub'] = 'replacement-person';
        $provider->tokenIssued('refresh_token')->userInfo($claims);
        $this->travel(6)->minutes();
        $this->getJson('/api/v2/me')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/identity-provider-session-ended');
    }
}
