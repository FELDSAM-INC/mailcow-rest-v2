<?php

namespace Tests\Unit\Clients;

use App\Clients\Ldap\ExtLdapDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T014 (research R2): the pure parts of the LDAP adapter reproduce what legacy's LdapRecord does
 * for mailcow: connection URIs (SSL on 389 uses 636), the user search filter with escaped values,
 * and the connection options.
 */
class ExtLdapDirectoryTest extends TestCase
{
    public function test_connection_uris(): void
    {
        $this->assertSame('ldap://a:389 ldap://b:389', ExtLdapDirectory::uris(['a', 'b'], 389, false));
        $this->assertSame('ldaps://a:636', ExtLdapDirectory::uris(['a'], 389, true));
        $this->assertSame('ldaps://a:1636 ldaps://b:1636', ExtLdapDirectory::uris(['a', 'b'], 1636, true));
        $this->assertSame('ldap://[2001:db8::1]:389', ExtLdapDirectory::uris(['2001:db8::1'], 389, false));
        $this->assertSame('ldap://192.0.2.1:10389', ExtLdapDirectory::uris(['192.0.2.1'], 10389, false));
    }

    public function test_the_user_filter_wraps_the_configured_filter(): void
    {
        $this->assertSame('(&(objectClass=person)(mail=carl@example.org))', ExtLdapDirectory::userFilter('(objectClass=person)', 'mail', 'carl@example.org'));
        $this->assertSame('(mail=carl@example.org)', ExtLdapDirectory::userFilter(null, 'mail', 'carl@example.org'));
        $this->assertSame('(uid=carl)', ExtLdapDirectory::userFilter('', 'uid', 'carl'));
    }

    public function test_values_are_escaped_so_that_they_match_literally(): void
    {
        $this->assertSame('(mail=a\\2ab\\28c\\29\\5c)', ExtLdapDirectory::userFilter(null, 'mail', 'a*b(c)\\'));
        $this->assertSame('(&(o=x)(mail=\\2a))', ExtLdapDirectory::userFilter('(o=x)', 'mail', '*'));
    }

    public function test_connection_options(): void
    {
        $base = [
            LDAP_OPT_PROTOCOL_VERSION => 3,
            LDAP_OPT_REFERRALS => 0,
            LDAP_OPT_NETWORK_TIMEOUT => 7,
            LDAP_OPT_TIMELIMIT => 7,
        ];

        $this->assertSame($base + [LDAP_OPT_X_TLS_REQUIRE_CERT => LDAP_OPT_X_TLS_DEMAND], ExtLdapDirectory::options(false, 7));

        // A new TLS context applies the option to the connection where the extension knows it.
        $ignoring = $base + [LDAP_OPT_X_TLS_REQUIRE_CERT => LDAP_OPT_X_TLS_NEVER];
        if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
            $ignoring[(int) constant('LDAP_OPT_X_TLS_NEWCTX')] = 0;
        }
        $this->assertSame($ignoring, ExtLdapDirectory::options(true, 7));
        $this->assertSame(3, ExtLdapDirectory::options(false, 3)[LDAP_OPT_NETWORK_TIMEOUT]);
    }
}
