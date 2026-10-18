<?php

namespace App\Clients\Ldap;

/**
 * The LDAP calls of feature 012 (research R2): a connection test with the service account, and the
 * lookup and bind of a user. Faked in feature tests (tests/Support/Fakes/FakeLdapDirectory.php).
 */
interface LdapDirectory
{
    /**
     * Connects with the SSL, StartTLS and certificate settings and binds as the service account.
     */
    public function testBind(LdapConnectionSettings $settings): LdapResult;

    /**
     * Binds as the service account, looks up the entry whose username field equals the username
     * below the base DN and within the filter, and binds as that entry with the password. On
     * success the result carries the entry's DN and the requested attributes.
     *
     * @param  list<string>  $attributes  attribute names to read from the entry
     */
    public function findAndBind(LdapConnectionSettings $settings, string $username, #[\SensitiveParameter] string $password, array $attributes): LdapResult;
}
