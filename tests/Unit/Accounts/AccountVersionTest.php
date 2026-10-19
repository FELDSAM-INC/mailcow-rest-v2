<?php

namespace Tests\Unit\Accounts;

use App\Services\Accounts\AccountVersion;
use stdClass;
use Tests\TestCase;

/**
 * Feature 007 T007 (FR-064, research R3): the version tag of an account covers its username,
 * creation time, status and forced flags and, for a domain administrator, its assignments and
 * permission flags; never the modification time, second factors or passkeys.
 */
class AccountVersionTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function admin(array $overrides = []): stdClass
    {
        return (object) ($overrides + [
            'username' => 'alice',
            'created' => '2026-10-01 10:00:00',
            'modified' => null,
            'active' => 1,
            'attributes' => '{"force_tfa": "0", "force_pw_update": "0"}',
        ]);
    }

    /**
     * @return list<stdClass>
     */
    private function assignments(): array
    {
        return [
            (object) ['domain' => 'b.example', 'active' => 1, 'created' => '2026-10-02 09:00:00'],
            (object) ['domain' => 'a.example', 'active' => 1, 'created' => '2026-10-02 09:00:00'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function daAcl(array $overrides = []): stdClass
    {
        $columns = ['syncjobs' => 1, 'quarantine' => 1, 'login_as' => 1, 'sogo_access' => 1, 'app_passwds' => 1, 'bcc_maps' => 1,
            'pushover' => 0, 'filters' => 1, 'ratelimit' => 1, 'spam_policy' => 1, 'extend_sender_acl' => 0, 'unlimited_quota' => 0,
            'protocol_access' => 1, 'smtp_ip_access' => 1, 'alias_domains' => 0, 'mailbox_relayhost' => 1, 'domain_relayhost' => 1,
            'domain_desc' => 0, 'alias_external_goto' => 1];

        return (object) (['username' => 'reseller1'] + $overrides + $columns);
    }

    public function test_the_tag_is_a_strong_etag(): void
    {
        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]{43}"$/', AccountVersion::forAdmin($this->admin()));
    }

    public function test_equal_input_gives_equal_tags(): void
    {
        $this->assertSame(AccountVersion::forAdmin($this->admin()), AccountVersion::forAdmin($this->admin()));
        $this->assertSame(
            AccountVersion::forDomainAdmin($this->admin(), $this->assignments(), $this->daAcl()),
            AccountVersion::forDomainAdmin($this->admin(), array_reverse($this->assignments()), $this->daAcl()),
        );
    }

    public function test_every_covered_value_changes_the_tag(): void
    {
        $base = AccountVersion::forAdmin($this->admin());

        foreach ([
            ['username' => 'bob'],
            ['created' => '2026-10-01 10:00:01'],
            ['active' => 0],
            ['attributes' => '{"force_tfa": "1", "force_pw_update": "0"}'],
            ['attributes' => '{"force_tfa": "0", "force_pw_update": "1"}'],
        ] as $change) {
            $this->assertNotSame($base, AccountVersion::forAdmin($this->admin($change)), json_encode($change) ?: '');
        }
    }

    public function test_modification_time_and_unrelated_attributes_do_not_change_the_tag(): void
    {
        $base = AccountVersion::forAdmin($this->admin());

        $this->assertSame($base, AccountVersion::forAdmin($this->admin(['modified' => '2026-10-05 12:00:00'])));
        $this->assertSame($base, AccountVersion::forAdmin($this->admin(['attributes' => '{"force_pw_update": 0, "force_tfa": "0", "recovery_email": "x"}'])));
        $this->assertSame($base, AccountVersion::forAdmin($this->admin(['attributes' => null])));
    }

    public function test_assignments_and_permission_flags_change_a_domain_administrator_tag(): void
    {
        $base = AccountVersion::forDomainAdmin($this->admin(), $this->assignments(), $this->daAcl());
        $assignments = $this->assignments();
        $assignments[0]->active = 0;

        $this->assertNotSame($base, AccountVersion::forDomainAdmin($this->admin(), $assignments, $this->daAcl()));
        $this->assertNotSame($base, AccountVersion::forDomainAdmin($this->admin(), [$this->assignments()[0]], $this->daAcl()));
        $this->assertNotSame($base, AccountVersion::forDomainAdmin($this->admin(), $this->assignments(), $this->daAcl(['login_as' => 0])));
        $this->assertNotSame($base, AccountVersion::forDomainAdmin($this->admin(), $this->assignments(), null));

        $later = $this->assignments();
        $later[1]->created = '2026-10-03 09:00:00';
        $this->assertNotSame($base, AccountVersion::forDomainAdmin($this->admin(), $later, $this->daAcl()));
    }

    public function test_a_missing_permission_record_and_an_all_zero_record_differ(): void
    {
        $zero = $this->daAcl(array_fill_keys(['syncjobs', 'quarantine', 'login_as', 'sogo_access', 'app_passwds', 'bcc_maps', 'pushover', 'filters', 'ratelimit',
            'spam_policy', 'extend_sender_acl', 'unlimited_quota', 'protocol_access', 'smtp_ip_access', 'alias_domains', 'mailbox_relayhost', 'domain_relayhost',
            'domain_desc', 'alias_external_goto'], 0));

        $this->assertNotSame(
            AccountVersion::forDomainAdmin($this->admin(), [], null),
            AccountVersion::forDomainAdmin($this->admin(), [], $zero),
        );
    }

    public function test_an_administrator_and_a_domain_administrator_with_equal_values_differ(): void
    {
        $this->assertNotSame(AccountVersion::forAdmin($this->admin()), AccountVersion::forDomainAdmin($this->admin(), [], null));
    }
}
