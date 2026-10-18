<?php

namespace Tests\Support\IdentityProvider;

use Tests\Support\Auth\AuthRequests;
use Tests\Support\Fakes\FakeLdapDirectory;

/**
 * Helpers of feature 012's provider password sign-in tests: the LDAP configuration with a scripted
 * directory, and its user Carl.
 */
trait ProviderPasswordTestHelpers
{
    use AuthRequests;
    use SingleSignOnTestHelpers;

    protected const DIRECTORY_PASSWORD = 'Passw0rd!dev';

    protected const CARL_DN = 'uid=carl,ou=people,dc=example,dc=org';

    protected const CARL_FILTER = '(&(objectClass=inetOrgPerson)(mail=carl@example.org))';

    /**
     * The LDAP configuration and a directory with the service account and Carl.
     *
     * @param  array<string, string>  $overrides
     */
    protected function directory(array $overrides = []): FakeLdapDirectory
    {
        $this->mailcow()->ldapConfig($overrides);

        return $this->ldapFake()
            ->password('cn=admin,dc=example,dc=org', 'ldap-secret')
            ->entry(self::CARL_FILTER, self::CARL_DN, ['mail' => 'carl@example.org', 'title' => 'staff', 'displayName' => 'Carl Example'], self::DIRECTORY_PASSWORD);
    }
}
