<?php

namespace Tests\Feature\Aliases;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * Feature 004 T025 (FR-001–FR-005, SC-004, US1): who may list and inspect which aliases.
 */
class AliasReadAuthorizationTest extends TestCase
{
    use AliasTestHelpers;
    use ApiKeys;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        for ($i = 1; $i <= 10; $i++) {
            $f->domain("d{$i}.example", $i === 2 ? 0 : 1);
            $this->ids["d{$i}"] = $f->alias("info@d{$i}.example", 'anna@d1.example');
        }
        $f->aliasDomain('alias1.example', 'd1.example');
        $this->ids['alias1'] = $f->alias('info@alias1.example', 'anna@d1.example');
        $this->ids['orphan'] = $f->alias('info@gone.example', 'anna@d1.example');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        // An active assignment of the inactive d2, and an inactive assignment of d3.
        $f->domainAdmin('reseller', ['d1.example', 'd2.example'], scheme: 'SSHA256');
        DB::table('domain_admins')->insert(['username' => 'reseller', 'domain' => 'd3.example', 'active' => 0]);
        $this->mailbox('user@d9.example');
    }

    public function test_a_domain_administrator_sees_the_aliases_of_their_domains_and_alias_domains(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->assertEqualsCanonicalizing(['info@d1.example', 'info@d2.example', 'info@alias1.example'], $this->addressesOf($this->getJson('/api/v2/aliases')));
        $this->getJson('/api/v2/aliases/'.$this->ids['d1'])->assertOk();
        $this->getJson('/api/v2/aliases/'.$this->ids['alias1'])->assertOk();
        $this->getJson('/api/v2/aliases/'.$this->ids['d3'])->assertNotFound()->assertJsonPath('type', 'about:blank');
        $this->getJson('/api/v2/aliases/'.$this->ids['orphan'])->assertNotFound();
        $this->getJson('/api/v2/aliases/'.$this->ids['d5'])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v2/aliases?domain=d5.example')->json('data'));
        $this->assertSame([], $this->getJson('/api/v2/aliases?primaryDomain=d5.example')->json('data'));
    }

    public function test_administrators_and_keys_read_everything_and_mailbox_users_nothing(): void
    {
        $this->signInAs('admin', Role::Admin);
        $this->assertSame(12, $this->getJson('/api/v2/aliases')->json('meta.total'));
        $this->forgetCookies();

        $this->assertSame(12, $this->getJson('/api/v2/aliases', $this->keyHeader($this->apiKey('admin', 'readWrite')))->json('meta.total'));
        $this->assertSame(12, $this->getJson('/api/v2/aliases', $this->keyHeader($this->apiKey('admin', 'readOnly')))->json('meta.total'));
        $readOnly = $this->keyHeader($this->apiKey('reseller', 'readOnly'));
        $this->assertSame(3, $this->getJson('/api/v2/aliases', $readOnly)->json('meta.total'));
        $this->getJson('/api/v2/aliases/'.$this->ids['d5'], $readOnly)->assertNotFound();

        $this->signInAs('user@d9.example', Role::MailboxUser);
        $this->getJson('/api/v2/aliases')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->getJson('/api/v2/aliases/'.$this->ids['d9'])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
