<?php

namespace Tests\Integration\Ldap;

use App\Clients\Ldap\ExtLdapDirectory;
use App\Clients\Ldap\LdapConnectionSettings;
use App\Clients\Ldap\LdapStatus;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * T090 (research R2): the real LDAP adapter against the OpenLDAP server of the dev stack's `idp`
 * profile (`openldap`, dc=example,dc=org, LDAPS and StartTLS with a self-signed certificate). The
 * people of tests/fixtures/ldap/bootstrap.ldif are added when they are missing, because CI starts
 * the server before the checkout. Run with `vendor/bin/phpunit --group idp`.
 */
#[Group('idp')]
class ExtLdapDirectoryTest extends TestCase
{
    private const HOST = 'openldap';

    private const BASE_DN = 'ou=people,dc=example,dc=org';

    private const ADMIN_DN = 'cn=admin,dc=example,dc=org';

    private const PASSWORD = 'Passw0rd!dev';

    private const ATTRIBUTES = ['mail', 'title', 'displayname', 'distinguishedname', 'dn'];

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['restv2.identity_provider.timeout' => 7]);

        if (! self::$seeded) {
            self::seedDirectory();
            self::$seeded = true;
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private static function settings(array $overrides = []): LdapConnectionSettings
    {
        $values = array_merge([
            'hosts' => [self::HOST],
            'port' => 389,
            'useSsl' => false,
            'useStartTls' => false,
            'ignoreTlsErrors' => false,
            'baseDn' => self::BASE_DN,
            'bindDn' => self::ADMIN_DN,
            'bindPassword' => 'ldap-secret',
            'usernameField' => 'mail',
            'filter' => '(objectClass=inetOrgPerson)',
            'attributeField' => 'title',
        ], $overrides);

        return new LdapConnectionSettings(...$values);
    }

    public function test_a_user_is_found_and_bound_with_the_directory_password(): void
    {
        $result = (new ExtLdapDirectory)->findAndBind(self::settings(), 'carl@example.org', self::PASSWORD, self::ATTRIBUTES);

        $this->assertSame(LdapStatus::Ok, $result->status);
        $this->assertSame('uid=carl,'.self::BASE_DN, $result->dn);
        $this->assertSame('Carl Example', $result->first('displayName'));
        $this->assertSame('staff', $result->first('title'));
    }

    public function test_characters_with_a_meaning_in_a_filter_match_literally(): void
    {
        $directory = new ExtLdapDirectory;

        $this->assertSame(LdapStatus::Ok, $directory->findAndBind(self::settings(), 'a*b@example.org', self::PASSWORD, self::ATTRIBUTES)->status);
        $this->assertSame(LdapStatus::UnknownUser, $directory->findAndBind(self::settings(), 'a*@example.org', self::PASSWORD, self::ATTRIBUTES)->status);
        $this->assertSame(LdapStatus::UnknownUser, $directory->findAndBind(self::settings(), '*', self::PASSWORD, self::ATTRIBUTES)->status);
    }

    public function test_a_filter_that_excludes_the_entry_finds_nothing(): void
    {
        $result = (new ExtLdapDirectory)->findAndBind(self::settings(['filter' => '(title=student)']), 'carl@example.org', self::PASSWORD, self::ATTRIBUTES);

        $this->assertSame(LdapStatus::UnknownUser, $result->status);
    }

    public function test_a_wrong_password_is_result_code_49(): void
    {
        $result = (new ExtLdapDirectory)->findAndBind(self::settings(), 'carl@example.org', 'wrong', self::ATTRIBUTES);

        $this->assertSame(LdapStatus::WrongPassword, $result->status);
        $this->assertSame(49, $result->code);
    }

    public function test_a_wrong_bind_password_rejects_the_service_account(): void
    {
        $directory = new ExtLdapDirectory;

        $this->assertSame(LdapStatus::CredentialsRejected, $directory->testBind(self::settings(['bindPassword' => 'wrong']))->status);
        $this->assertSame(LdapStatus::CredentialsRejected, $directory->findAndBind(self::settings(['bindPassword' => 'wrong']), 'carl@example.org', self::PASSWORD, self::ATTRIBUTES)->status);
        $this->assertSame(LdapStatus::Ok, $directory->testBind(self::settings())->status);
    }

    public function test_ldaps_with_the_self_signed_certificate_needs_ignored_tls_errors(): void
    {
        $directory = new ExtLdapDirectory;

        $this->assertSame(LdapStatus::TlsFailure, $directory->testBind(self::settings(['useSsl' => true]))->status, 'SSL on 389 connects to 636');
        $this->assertSame(LdapStatus::Ok, $directory->testBind(self::settings(['useSsl' => true, 'ignoreTlsErrors' => true]))->status);
        $this->assertSame(LdapStatus::Ok, $directory->findAndBind(self::settings(['useSsl' => true, 'ignoreTlsErrors' => true]), 'carl@example.org', self::PASSWORD, self::ATTRIBUTES)->status);
        // Verification is on again for the next connection.
        $this->assertSame(LdapStatus::TlsFailure, $directory->testBind(self::settings(['useSsl' => true]))->status);
    }

    public function test_starttls_with_the_self_signed_certificate_needs_ignored_tls_errors(): void
    {
        $directory = new ExtLdapDirectory;

        $this->assertSame(LdapStatus::TlsFailure, $directory->testBind(self::settings(['useStartTls' => true]))->status);
        $this->assertSame(LdapStatus::Ok, $directory->testBind(self::settings(['useStartTls' => true, 'ignoreTlsErrors' => true]))->status);
    }

    public function test_a_stopped_server_is_unavailable_within_the_time_out(): void
    {
        config(['restv2.identity_provider.timeout' => 2]);
        $directory = new ExtLdapDirectory;

        $started = microtime(true);
        $closed = $directory->testBind(self::settings(['port' => 1389]));
        $this->assertSame(LdapStatus::Unreachable, $closed->status);

        // A non-routable address never answers.
        $silent = $directory->findAndBind(self::settings(['hosts' => ['10.255.255.1']]), 'carl@example.org', self::PASSWORD, self::ATTRIBUTES);
        $this->assertContains($silent->status, [LdapStatus::TimedOut, LdapStatus::Unreachable]);
        $this->assertTrue($silent->status->isUnavailable());
        $this->assertLessThan(7.0, microtime(true) - $started);
    }

    /**
     * Adds the entries of bootstrap.ldif that are missing (result code 68: already exists).
     */
    private static function seedDirectory(): void
    {
        $link = ldap_connect('ldap://'.self::HOST.':389');
        self::assertNotFalse($link);
        ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, 5);

        $deadline = microtime(true) + 30;

        while (! @ldap_bind($link, self::ADMIN_DN, 'ldap-secret')) {
            if (microtime(true) > $deadline) {
                self::fail('The OpenLDAP service of the idp profile is not reachable: '.ldap_error($link));
            }

            usleep(500000);
        }

        $ldif = (string) file_get_contents(__DIR__.'/../../fixtures/ldap/bootstrap.ldif');

        foreach (preg_split('/\R\R+/', $ldif) ?: [] as $block) {
            $dn = null;
            $entry = [];

            foreach (preg_split('/\R/', trim($block)) ?: [] as $line) {
                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, ': ')) {
                    continue;
                }

                [$name, $value] = explode(': ', $line, 2);
                $name === 'dn' ? $dn = $value : $entry[$name][] = $value;
            }

            if ($dn !== null && ! @ldap_add($link, $dn, $entry) && ldap_errno($link) !== 68) {
                self::fail("Adding {$dn} failed: ".ldap_error($link));
            }
        }

        ldap_unbind($link);
    }
}
