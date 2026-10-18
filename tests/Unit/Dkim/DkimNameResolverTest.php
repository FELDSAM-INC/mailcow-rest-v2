<?php

namespace Tests\Unit\Dkim;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Dkim\DkimName;
use App\Services\Dkim\DkimNameResolver;
use Tests\TestCase;

/**
 * Feature 010 T024 (research R7, FR-001, FR-003, FR-006): a path or body name is resolved for a
 * caller. Administrators reach an unused key by its exact stored spelling first; otherwise the name
 * is normalised like feature 002 (trim, lower case, UTS #46). Domain administrators reach their
 * domains, also inactive ones, and the alias domains pointing to them; everything else is out of
 * scope, exactly like a name that does not exist.
 */
class DkimNameResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('inactive.example', 0);
        $f->domain('foreign.example');
        $f->domain('xn--bcher-kva.example');
        $f->aliasDomain('alias.example', 'example.org');
        $f->aliasDomain('foreign-alias.example', 'foreign.example');
        $f->domainAdmin('reseller', ['example.org', 'inactive.example']);
        $f->redisHash('DKIM_PUB_KEYS', 'Example.ORG', 'MIIBlegacy');
        $f->redisHash('DKIM_SELECTORS', 'bücher.example', 'dkim');
        $f->redisHash('DKIM_PUB_KEYS', 'old.example', 'MIIBold');
    }

    private function resolver(): DkimNameResolver
    {
        return $this->app->make(DkimNameResolver::class);
    }

    private static function admin(): Principal
    {
        return new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::Password);
    }

    private static function domainAdmin(): Principal
    {
        return new Principal('reseller', Role::DomainAdmin, AccountKind::Admin, AuthMethod::Password);
    }

    public function test_an_administrator_reaches_an_unused_legacy_key_by_its_exact_spelling(): void
    {
        $upper = $this->resolver()->resolve(self::admin(), 'Example.ORG');
        $unicode = $this->resolver()->resolve(self::admin(), 'bücher.example');

        $this->assertEquals(new DkimName('Example.ORG', 'Example.ORG', DkimName::NONE, null), $upper);
        // `bücher.example` is not the stored domain `xn--bcher-kva.example`, so the raw field wins.
        $this->assertEquals(new DkimName('bücher.example', 'bücher.example', DkimName::NONE, null), $unicode);
    }

    public function test_other_names_are_normalised_with_uts_46(): void
    {
        $this->assertEquals(new DkimName('xn--bcher-kva.example', 'bücher.example', DkimName::DOMAIN, null), $this->resolver()->resolve(self::admin(), ' BÜCHER.example '));
        $this->assertEquals(new DkimName('example.org', 'example.org', DkimName::DOMAIN, null), $this->resolver()->resolve(self::admin(), 'EXAMPLE.org'));
        $this->assertSame('xn--bcher-kva.example', DkimNameResolver::normalize('BÜCHER.example'));
    }

    public function test_the_kind_is_domain_alias_domain_or_none(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(DkimName::DOMAIN, $resolver->resolve(self::admin(), 'example.org')?->kind);
        $alias = $resolver->resolve(self::admin(), 'alias.example');
        $this->assertSame(DkimName::ALIAS_DOMAIN, $alias?->kind);
        $this->assertSame('example.org', $alias->targetDomain);
        $this->assertSame(DkimName::NONE, $resolver->resolve(self::admin(), 'old.example')?->kind);
        $this->assertSame(DkimName::NONE, $resolver->resolve(self::admin(), 'nothing.example')?->kind);
        $this->assertEquals(new DkimName('alias.example', 'alias.example', DkimName::ALIAS_DOMAIN, 'example.org'), $resolver->kindOf('alias.example'));
    }

    public function test_a_domain_administrator_reaches_own_domains_and_their_alias_domains(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('example.org', $resolver->resolve(self::domainAdmin(), 'Example.Org')?->name);
        $this->assertSame('inactive.example', $resolver->resolve(self::domainAdmin(), 'inactive.example')?->name);
        $this->assertSame('alias.example', $resolver->resolve(self::domainAdmin(), 'alias.example')?->name);
    }

    public function test_foreign_names_and_names_without_a_domain_are_out_of_scope_for_a_domain_administrator(): void
    {
        $resolver = $this->resolver();

        foreach (['foreign.example', 'foreign-alias.example', 'old.example', 'bücher.example', 'nothing.example'] as $name) {
            $this->assertNull($resolver->resolve(self::domainAdmin(), $name), $name);
        }

        // Only administrators reach a legacy spelling; for others it is the normalised name.
        $this->assertSame('example.org', $resolver->resolve(self::domainAdmin(), 'Example.ORG')?->name);
    }

    public function test_a_revoked_assignment_is_out_of_scope_on_the_next_call(): void
    {
        $this->assertNotNull($this->resolver()->resolve(self::domainAdmin(), 'example.org'));

        \DB::table('domain_admins')->where('username', 'reseller')->where('domain', 'example.org')->delete();

        $this->assertNull($this->resolver()->resolve(self::domainAdmin(), 'example.org'));
    }

    public function test_an_unconvertible_name_is_invalid(): void
    {
        $this->assertNull(DkimNameResolver::normalize('a..b'));
        $this->assertNull(DkimNameResolver::normalize('-bad.example'));
        $this->assertNull(DkimNameResolver::normalize(''));
        $this->assertNull($this->resolver()->resolve(self::admin(), 'a..b'));
    }

    public function test_mailbox_users_reach_nothing(): void
    {
        $user = new Principal('anna@example.org', Role::MailboxUser, AccountKind::Mailbox, AuthMethod::Password);

        $this->assertNull($this->resolver()->resolve($user, 'example.org'));
    }
}
