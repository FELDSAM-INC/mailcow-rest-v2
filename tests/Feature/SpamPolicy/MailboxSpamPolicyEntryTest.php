<?php

namespace Tests\Feature\SpamPolicy;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T063 (FR-044 to FR-049, AS-5.5, AS-5.6, AS-5.9): the allow and block lists of a
 * mailbox, stored as legacy stores them (`filterconf` `whitelist_from` / `blacklist_from`), with
 * the domain's entries shown read-only; a pattern exists on one list of an owner at most.
 */
class MailboxSpamPolicyEntryTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('other.example');
        $this->quarantineMailbox('otto@other.example');
        $this->quarantineMailbox('room@example.org', kind: 'location');
    }

    private function uri(string $mailbox = 'alice@example.org', ?int $id = null): string
    {
        return "/api/v2/mailboxes/{$mailbox}/spam-policy-entries".($id === null ? '' : "/{$id}");
    }

    public function test_an_entry_is_created_trimmed_and_lower_cased(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $response = $this->send('POST', $this->uri(), ['list' => 'block', 'pattern' => ' *@SPAM.example '])->assertCreated();

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', "/api/v2/mailboxes/alice@example.org/spam-policy-entries/{$id}");
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
        $this->assertSame(['id' => $id, 'list' => 'block', 'owner' => ['kind' => 'mailbox', 'name' => 'alice@example.org'], 'pattern' => '*@spam.example', 'own' => true], array_intersect_key((array) $response->json('data'), array_flip(['id', 'list', 'owner', 'pattern', 'own'])));
        $row = DB::table('filterconf')->where('prefid', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame(['alice@example.org', 'blacklist_from', '*@spam.example'], [$row->object, $row->option, $row->value]);
    }

    public function test_the_same_pattern_on_the_other_list_is_a_conflict(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = (int) $this->send('POST', $this->uri(), ['list' => 'block', 'pattern' => '*@spam.example'])->assertCreated()->json('data.id');

        $this->send('POST', $this->uri(), ['list' => 'allow', 'pattern' => '*@SPAM.example'])
            ->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/spam-policy-entry-exists')
            ->assertJsonPath('list', 'block')
            ->assertJsonPath('entryId', $id);

        $this->assertSame(1, DB::table('filterconf')->where('object', 'alice@example.org')->count());
        $this->assertSame('conflict', DB::table('restv2_audit_events')->where('outcome', 'failure')->value('failure_reason'));
    }

    public function test_invalid_patterns_are_refused(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->send('POST', $this->uri(), ['list' => 'block', 'pattern' => 'user+tag@example.org'])->assertStatus(422)->assertJsonPath('errors.pattern.0.code', 'invalidFormat')->assertJsonPath('errors.pattern.0.params.format', 'senderPattern');
        $this->send('POST', $this->uri(), ['list' => 'block', 'pattern' => '*'])->assertStatus(422)->assertJsonPath('errors.pattern.0.params.format', 'senderPattern');
        $this->send('POST', $this->uri(), ['list' => 'block', 'pattern' => str_repeat('a', 101)])->assertStatus(422)->assertJsonPath('errors.pattern.0.code', 'tooLong')->assertJsonPath('errors.pattern.0.params.max', 100);
    }

    public function test_the_list_includes_the_domains_entries_as_inherited(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $domainEntry = $this->mailcow()->filterconf('example.org', 'blacklist_from', '*@domain-block.example');
        $own = $this->mailcow()->filterconf('alice@example.org', 'whitelist_from', 'friend@example.net');
        $own2 = $this->mailcow()->filterconf('alice@example.org', 'blacklist_from', 'boss@spam.example');
        $this->mailcow()->filterconf('alice@example.org', 'highspamlevel', '12');
        $this->mailcow()->filterconf('bob@example.org', 'blacklist_from', '*@bob-only.example');

        $data = (array) $this->getJson($this->uri())->assertOk()->json('data');

        $this->assertSame([$domainEntry, $own2, $own], array_column($data, 'id'));
        $this->assertSame(['kind' => 'domain', 'name' => 'example.org'], $data[0]['owner']);
        $this->assertFalse($data[0]['own']);
        $this->assertTrue($data[1]['own']);

        $this->assertSame([$own], array_column((array) $this->getJson($this->uri().'?list=allow')->assertOk()->json('data'), 'id'));
        $this->assertSame([$domainEntry], array_column((array) $this->getJson($this->uri().'?ownership=inherited')->assertOk()->json('data'), 'id'));
        $this->assertSame([$own2, $own], array_column((array) $this->getJson($this->uri().'?ownership=own')->assertOk()->json('data'), 'id'));
        $this->assertSame([$own2], array_column((array) $this->getJson($this->uri().'?q=BOSS')->assertOk()->json('data'), 'id'));
        $this->assertSame([$own, $own2, $domainEntry], array_column((array) $this->getJson($this->uri().'?sort=pattern&order=desc')->assertOk()->json('data'), 'id'));
        $this->assertSame([$domainEntry, $own, $own2], array_column((array) $this->getJson($this->uri().'?sort=id')->assertOk()->json('data'), 'id'));
        $this->assertSame(3, count(array_column((array) $this->getJson($this->uri().'?sort=createdAt')->assertOk()->json('data'), 'id')));
    }

    public function test_a_domain_entry_is_neither_readable_nor_removable_through_the_mailbox(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $domainEntry = $this->mailcow()->filterconf('example.org', 'blacklist_from', '*@domain-block.example');
        $own = $this->mailcow()->filterconf('alice@example.org', 'whitelist_from', 'friend@example.net');

        $this->getJson($this->uri(id: $domainEntry))->assertNotFound();
        $this->send('DELETE', $this->uri(id: $domainEntry))->assertNotFound();
        $this->getJson($this->uri(id: $own))->assertOk()->assertJsonPath('data.pattern', 'friend@example.net');
        $this->send('DELETE', $this->uri(id: $own))->assertNoContent();

        $this->assertFalse(DB::table('filterconf')->where('prefid', $own)->exists());
        $this->assertTrue(DB::table('filterconf')->where('prefid', $domainEntry)->exists());
    }

    public function test_a_mailbox_user_without_spam_policy_reads_but_cannot_change(): void
    {
        $this->asQuarantineUser('alice@example.org', ['spam_policy' => 0]);
        $own = $this->mailcow()->filterconf('alice@example.org', 'whitelist_from', 'friend@example.net');

        $this->getJson($this->uri())->assertOk();
        $this->send('POST', $this->uri(), ['list' => 'block', 'pattern' => '*@spam.example'])->assertForbidden()->assertJsonPath('requiredFlags', ['spamPolicy']);
        $this->send('DELETE', $this->uri(id: $own))->assertForbidden()->assertJsonPath('requiredFlags', ['spamPolicy']);
    }

    public function test_resources_and_foreign_mailboxes_are_not_found(): void
    {
        $this->asAdmin();
        $this->getJson($this->uri('room@example.org'))->assertNotFound();
        $this->send('POST', $this->uri('room@example.org'), ['list' => 'block', 'pattern' => '*@spam.example'])->assertNotFound();

        $this->forgetCookies();
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['spam_policy' => 1], scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->getJson($this->uri('otto@other.example'))->assertNotFound();
        $this->send('POST', $this->uri('otto@other.example'), ['list' => 'block', 'pattern' => '*@spam.example'])->assertNotFound();
    }

    public function test_creation_and_removal_are_audited_on_the_owner(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $id = (int) $this->send('POST', $this->uri(), ['list' => 'block', 'pattern' => '*@spam.example'])->assertCreated()->json('data.id');
        $this->send('DELETE', $this->uri(id: $id))->assertNoContent();

        $records = $this->auditOfTarget('mailbox', 'alice@example.org');
        $this->assertSame(['spamPolicyEntryCreation', 'spamPolicyEntryRemoval'], array_column($records, 'event_type'));
        $this->assertSame(['entryId' => $id, 'list' => 'block', 'pattern' => '*@spam.example'], $records[0]->details);
        $this->assertSame(['entryId' => $id, 'list' => 'block', 'pattern' => '*@spam.example'], $records[1]->details);
    }
}
