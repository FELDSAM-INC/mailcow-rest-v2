<?php

namespace Tests\Support\Fakes;

use App\Clients\Ldap\ExtLdapDirectory;
use App\Clients\Ldap\LdapConnectionSettings;
use App\Clients\Ldap\LdapDirectory;
use App\Clients\Ldap\LdapResult;
use PHPUnit\Framework\Assert;

/**
 * A scripted directory for feature tests (feature 012 T008): entries per search filter, passwords
 * and scripted bind results per DN, a failure of every connection, and the calls made. Passwords
 * are compared but never recorded or printed.
 *
 *  - A DN with a registered password binds only with it (otherwise result code 49).
 *  - The service account binds with any password unless a password or a result is registered for it.
 *  - A user DN without a registered password never binds.
 */
class FakeLdapDirectory implements LdapDirectory
{
    /** @var array<string, array{dn: string, attributes: array<string, list<string>>}> filter => entry */
    private array $entries = [];

    /** @var array<string, string> dn => sha256 of the password */
    private array $passwords = [];

    /** @var array<string, LdapResult> dn => scripted bind result */
    private array $bindResults = [];

    private ?LdapResult $connectionFailure = null;

    /** @var list<array{method: string, uris: string, settings: array<string, mixed>, filter?: string, username?: string, attributes?: list<string>}> */
    private array $calls = [];

    /** @var list<string> */
    private array $boundAs = [];

    /** @var list<string> */
    private array $searched = [];

    /**
     * An entry returned for exactly this filter, with attributes as `name => value|list`.
     *
     * @param  array<string, string|list<string>>  $attributes
     */
    public function entry(string $filter, string $dn, array $attributes = [], ?string $password = null): static
    {
        $normalised = [];

        foreach ($attributes as $name => $values) {
            $normalised[strtolower($name)] = is_array($values) ? $values : [$values];
        }

        $this->entries[$filter] = ['dn' => $dn, 'attributes' => $normalised];

        if ($password !== null) {
            $this->password($dn, $password);
        }

        return $this;
    }

    public function password(string $dn, #[\SensitiveParameter] string $password): static
    {
        $this->passwords[$dn] = hash('sha256', $password);

        return $this;
    }

    /**
     * Every bind as this DN gives the result (for example LdapResult::credentialsRejected()).
     */
    public function bindResult(string $dn, LdapResult $result): static
    {
        $this->bindResults[$dn] = $result;

        return $this;
    }

    /**
     * Every connection fails with the result (unreachable, time-out, TLS failure).
     */
    public function failConnections(LdapResult $result): static
    {
        $this->connectionFailure = $result;

        return $this;
    }

    public function testBind(LdapConnectionSettings $settings): LdapResult
    {
        $this->calls[] = ['method' => 'testBind', 'uris' => $settings->uris(), 'settings' => self::describe($settings)];

        return $this->connectionFailure ?? $this->bind($settings->bindDn, $settings->bindPassword, true) ?? LdapResult::ok();
    }

    public function findAndBind(LdapConnectionSettings $settings, string $username, #[\SensitiveParameter] string $password, array $attributes): LdapResult
    {
        $filter = ExtLdapDirectory::userFilter($settings->filter, $settings->usernameField, $username);
        $this->calls[] = ['method' => 'findAndBind', 'uris' => $settings->uris(), 'settings' => self::describe($settings), 'filter' => $filter, 'username' => $username, 'attributes' => $attributes];

        if ($this->connectionFailure !== null) {
            return $this->connectionFailure;
        }

        $refused = $this->bind($settings->bindDn, $settings->bindPassword, true);

        if ($refused !== null) {
            return $refused;
        }

        $this->searched[] = $settings->baseDn.' '.$filter;
        $entry = $this->entries[$filter] ?? null;

        if ($entry === null) {
            return LdapResult::unknownUser();
        }

        if ($password === '') {
            return LdapResult::wrongPassword($entry['dn']);
        }

        $refused = $this->bind($entry['dn'], $password, false);

        if ($refused !== null) {
            return $refused;
        }

        $wanted = array_map('strtolower', $attributes);

        return LdapResult::ok($entry['dn'], array_intersect_key($entry['attributes'], array_flip($wanted)));
    }

    private function bind(string $dn, #[\SensitiveParameter] string $password, bool $service): ?LdapResult
    {
        $this->boundAs[] = $dn;

        if (isset($this->bindResults[$dn])) {
            return $this->bindResults[$dn];
        }

        if (isset($this->passwords[$dn])) {
            if (hash_equals($this->passwords[$dn], hash('sha256', $password))) {
                return null;
            }

            return $service ? LdapResult::credentialsRejected() : LdapResult::wrongPassword($dn);
        }

        return $service ? null : LdapResult::wrongPassword($dn);
    }

    /**
     * @return list<array{method: string, uris: string, settings: array<string, mixed>, filter?: string, username?: string, attributes?: list<string>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function assertSearched(string $filter, ?string $baseDn = null): void
    {
        $matches = array_filter($this->searched, fn (string $s): bool => $baseDn === null ? str_ends_with($s, ' '.$filter) : $s === $baseDn.' '.$filter);

        Assert::assertNotEmpty($matches, "The directory was not searched with {$filter}. Searches: ".implode('; ', $this->searched));
    }

    public function assertBoundAs(string $dn): void
    {
        Assert::assertContains($dn, $this->boundAs, "No bind as {$dn}.");
    }

    public function assertNotContacted(): void
    {
        Assert::assertSame([], $this->calls, 'The directory was contacted.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(LdapConnectionSettings $settings): array
    {
        return [
            'hosts' => $settings->hosts,
            'port' => $settings->port,
            'useSsl' => $settings->useSsl,
            'useStartTls' => $settings->useStartTls,
            'ignoreTlsErrors' => $settings->ignoreTlsErrors,
            'baseDn' => $settings->baseDn,
            'bindDn' => $settings->bindDn,
            'usernameField' => $settings->usernameField,
            'filter' => $settings->filter,
            'attributeField' => $settings->attributeField,
        ];
    }
}
