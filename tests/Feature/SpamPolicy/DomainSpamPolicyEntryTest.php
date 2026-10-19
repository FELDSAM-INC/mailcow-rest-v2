<?php

namespace Tests\Feature\SpamPolicy;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T064 (FR-043, FR-045 to FR-048, AS-5.8): the allow and block lists of a domain, with
 * its mailboxes' entries shown read-only; domain administrators need `spamPolicy` to change them,
 * and mailbox users may neither read nor change them.
 */
class DomainSpamPolicyEntryTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('other.example');
        $this->mailcow()->aliasDomain('example.info', 'example.org');
        $this->quarantineMailbox('alice@example.org');
    }

    private function uri(string $domain = 'example.org', ?int $id = null): string
    {
        return "/api/v2/domains/{$domain}/spam-policy-entries".($id === null ? '' : "/{$id}");
    }

    public function test_the_list_has_the_domains_and_its_mailboxes_entries(): void
    {
        $this->asAdmin();
        $own = $this->mailcow()->filterconf('example.org', 'blacklist_from', '*@spam.example');
        $mailbox = $this->mailcow()->filterconf('alice@example.org', 'whitelist_from', 'friend@example.net');
        $this->mailcow()->filterconf('other.example', 'blacklist_from', '*@x.example');

        $data = (array) $this->getJson($this->uri())->assertOk()->json('data');

        $this->assertSame([$own, $mailbox], array_column($data, 'id'));
        $this->assertSame(['kind' => 'domain', 'name' => 'example.org'], $data[0]['owner']);
        $this->assertTrue($data[0]['own']);
        $this->assertSame(['kind' => 'mailbox', 'name' => 'alice@example.org'], $data[1]['owner']);
        $this->assertFalse($data[1]['own']);
        $this->getJson($this->uri(id: $mailbox))->assertNotFound();
        $this->getJson($this->uri(id: $own))->assertOk();
    }

    public function test_a_domain_admin_with_spam_policy_adds_and_removes(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['spam_policy' => 1], scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);

        $id = (int) $this->send('POST', $this->uri('EXAMPLE.org'), ['list' => 'allow', 'pattern' => 'partner@example.net'])
            ->assertCreated()
            ->assertHeader('Location', '/api/v2/domains/example.org/spam-policy-entries/'.DB::table('filterconf')->max('prefid'))
            ->json('data.id');
        $this->assertSame('example.org', DB::table('filterconf')->where('prefid', $id)->value('object'));

        $this->send('DELETE', $this->uri(id: $id))->assertNoContent();
        $this->assertFalse(DB::table('filterconf')->where('prefid', $id)->exists());
        $this->assertSame(['spamPolicyEntryCreation', 'spamPolicyEntryRemoval'], array_column($this->auditOfTarget('domain', 'example.org'), 'event_type'));
    }

    public function test_a_domain_admin_without_spam_policy_reads_only(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['spam_policy' => 0], scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);
        $own = $this->mailcow()->filterconf('example.org', 'blacklist_from', '*@spam.example');

        $this->getJson($this->uri())->assertOk();
        $this->send('POST', $this->uri(), ['list' => 'allow', 'pattern' => 'partner@example.net'])->assertForbidden()->assertJsonPath('requiredFlags', ['spamPolicy']);
        $this->send('DELETE', $this->uri(id: $own))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/permission-flag-required');
    }

    public function test_a_mailbox_user_may_neither_read_nor_change(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->getJson($this->uri())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->send('POST', $this->uri(), ['list' => 'allow', 'pattern' => 'partner@example.net'])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    public function test_foreign_domains_and_alias_domain_names_are_not_found(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['spam_policy' => 1], scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->getJson($this->uri('other.example'))->assertNotFound();
        $this->send('POST', $this->uri('other.example'), ['list' => 'allow', 'pattern' => 'partner@example.net'])->assertNotFound();
        $this->getJson($this->uri('example.info'))->assertNotFound();
    }

    public function test_the_same_pattern_may_exist_for_another_owner(): void
    {
        $this->asAdmin();
        $this->mailcow()->filterconf('alice@example.org', 'blacklist_from', '*@spam.example');

        $this->send('POST', $this->uri(), ['list' => 'block', 'pattern' => '*@spam.example'])->assertCreated();
    }
}
