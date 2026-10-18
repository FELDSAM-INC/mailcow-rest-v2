<?php

namespace Tests\Feature\TemporaryAliases;

use App\Auth\Role;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T082 (feature 005 FR-081): listTemporaryAliasDomains, the mailbox's domain first, then its alias
 * domains by name, the same list as the overview's.
 */
class TemporaryAliasDomainsTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->aliasDomain('xn--mnchen-3ya.de', 'example.org');
        $this->mailcow()->aliasDomain('example.net', 'example.org');
        $this->mailcow()->mailboxWithDomain('dora@example.com');
    }

    public function test_the_domains_in_their_order(): void
    {
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $data = $this->getJson('/api/v2/mailboxes/anna@example.org/temporary-alias-domains')->assertOk()->assertJsonPath('meta.total', 3)->json('data');

        $this->assertSame([
            ['domain' => 'example.org', 'domainUnicode' => 'example.org', 'kind' => 'mailboxDomain'],
            ['domain' => 'example.net', 'domainUnicode' => 'example.net', 'kind' => 'aliasDomain'],
            ['domain' => 'xn--mnchen-3ya.de', 'domainUnicode' => 'münchen.de', 'kind' => 'aliasDomain'],
        ], $data);
        $this->assertSame(array_column($data, 'domain'), $this->getJson('/api/v2/me/mailbox')->json('data.temporaryAliasDomains'));
        $this->assertSame(['xn--mnchen-3ya.de'], array_column($this->getJson('/api/v2/mailboxes/anna@example.org/temporary-alias-domains?q=m%C3%BCnchen')->json('data'), 'domain'));
    }

    public function test_every_permitted_caller_may_read(): void
    {
        $this->asAdmin();
        $this->getJson('/api/v2/mailboxes/anna@example.org/temporary-alias-domains')->assertOk();

        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/mailboxes/anna@example.org/temporary-alias-domains')->assertOk();
        $this->getJson('/api/v2/mailboxes/dora@example.com/temporary-alias-domains')->assertNotFound();

        $this->forgetCookies();
        $this->getJson('/api/v2/mailboxes/anna@example.org/temporary-alias-domains', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();
    }
}
