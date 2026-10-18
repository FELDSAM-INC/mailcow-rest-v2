<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use App\Clients\DockerApi\DockerCallResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T076 (FR-054, FR-055, FR-067, US7): one action applied to up to 100 mailboxes, each
 * checked, processed and audited like the single operation, with one result per distinct mailbox.
 */
class MailboxBulkActionTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('a.example');
        $this->mailcow()->domain('b.example');
    }

    /**
     * @param  list<array<string, string>>|list<string>  $targets
     * @return TestResponse<Response>
     */
    private function bulk(string $action, array $targets, ?string $protocol = null): TestResponse
    {
        $body = ['action' => $action, 'targets' => array_map(fn (mixed $t): array => is_string($t) ? ['id' => $t] : $t, $targets)];

        if ($protocol !== null) {
            $body['protocol'] = $protocol;
        }

        return $this->postJson('/api/v2/mailboxes/bulk-actions', $body, $this->csrf());
    }

    public function test_fifty_mailboxes_are_deactivated_with_one_record_each(): void
    {
        $addresses = [];

        for ($i = 1; $i <= 50; $i++) {
            $this->mailcow()->legacyMailbox($addresses[] = sprintf('u%02d@a.example', $i));
        }

        $this->asAdmin();
        $data = $this->bulk('deactivate', $addresses)->assertOk()->json('data');

        $this->assertSame(['total' => 50, 'done' => 50, 'notFound' => 0, 'refused' => 0, 'outdated' => 0, 'failed' => 0], $data['summary']);
        $this->assertSame($addresses, array_column($data['results'], 'id'));
        $this->assertSame($this->getJson('/api/v2/mailboxes/u01@a.example')->headers->get('ETag'), $data['results'][0]['version']);
        $this->assertSame(0, DB::table('mailbox')->where('domain', 'a.example')->where('active', '!=', 0)->count());
        $this->assertSame(0, DB::table('alias')->where('domain', 'a.example')->where('active', '!=', 0)->count());

        $records = $this->auditOf('mailboxUpdate');
        $this->assertCount(50, $records);
        $this->assertSame([$data['bulkRequestId']], array_values(array_unique(array_map(fn (object $r): string => $r->details['bulkRequestId'], $records))));
        $this->assertSame(1, $this->memcached()->flushCount(), 'One cache flush per request.');
    }

    public function test_every_action_has_the_single_operations_effect(): void
    {
        $this->mailcow()->legacyMailbox('anna@a.example', active: 0);
        $this->asAdmin();

        $this->bulk('activate', ['anna@a.example'])->assertOk();
        $this->assertSame(1, (int) $this->mailboxRow('anna@a.example')?->active);
        $this->bulk('disableLogin', ['anna@a.example'])->assertOk();
        $this->assertSame(2, (int) $this->mailboxRow('anna@a.example')?->active);

        foreach (['imap' => 'imap_access', 'pop3' => 'pop3_access', 'smtp' => 'smtp_access', 'sieve' => 'sieve_access', 'activeSync' => 'eas_access', 'dav' => 'dav_access'] as $protocol => $key) {
            $this->bulk('disallowProtocol', ['anna@a.example'], $protocol)->assertOk()->assertJsonPath('data.results.0.outcome', 'done');
            $this->assertSame('0', $this->mailboxAttributes('anna@a.example')[$key], $protocol);
            $this->bulk('allowProtocol', ['anna@a.example'], $protocol)->assertOk();
            $this->assertSame('1', $this->mailboxAttributes('anna@a.example')[$key], $protocol);
        }

        $this->bulk('delete', ['anna@a.example'])->assertOk()->assertJsonPath('data.results.0.outcome', 'done')->assertJsonMissingPath('data.results.0.version');
        $this->assertNull($this->mailboxRow('anna@a.example'));
        $this->assertCount(1, $this->auditOf('mailboxDeletion'));
    }

    public function test_a_domain_administrator_reaches_only_its_mailboxes(): void
    {
        foreach (['one', 'two', 'three'] as $local) {
            $this->mailcow()->legacyMailbox("{$local}@a.example");
        }

        $this->mailcow()->legacyMailbox('x@b.example');
        $this->mailcow()->legacyMailbox('y@b.example');
        $this->asDomainAdmin(['a.example']);

        $data = $this->bulk('disableLogin', ['one@a.example', 'x@b.example', 'two@a.example', 'y@b.example', 'three@a.example'])->assertOk()->json('data');

        $this->assertSame(['done', 'notFound', 'done', 'notFound', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame([1, 1], [(int) $this->mailboxRow('x@b.example')?->active, (int) $this->mailboxRow('y@b.example')?->active]);
    }

    public function test_a_protocol_action_needs_the_flag_as_a_whole(): void
    {
        $this->mailcow()->legacyMailbox('anna@a.example');
        $this->asDomainAdmin(['a.example'], daAcl: ['protocol_access' => 0]);

        $this->bulk('disallowProtocol', ['anna@a.example'], 'pop3')->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['protocolAccess']);

        $this->assertSame('1', $this->mailboxAttributes('anna@a.example')['pop3_access']);
        $records = $this->auditOf('mailboxUpdate');
        $this->assertCount(1, $records);
        $this->assertSame([null, ['anna@a.example'], 'disallowProtocol'], [$records[0]->target_type, $records[0]->details['targetIds'], $records[0]->details['bulkAction']]);
    }

    public function test_invalid_requests_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->bulk('deactivate', array_map(fn (int $i): string => "u{$i}@a.example", range(1, 101)))->assertStatus(422)
            ->assertJsonPath('errors.targets.0.code', 'tooMany')
            ->assertJsonPath('errors.targets.0.params.max', 100);
        $this->bulk('disallowProtocol', ['anna@a.example'])->assertStatus(422)->assertJsonPath('errors.protocol.0.code', 'required');
        $this->bulk('deactivate', ['anna@a.example'], 'pop3')->assertStatus(422)->assertJsonPath('errors.protocol.0.code', 'conflictsWith');
    }

    public function test_a_failed_mail_store_cleanup_fails_only_that_mailbox(): void
    {
        $this->mailcow()->legacyMailbox('one@a.example');
        $this->mailcow()->legacyMailbox('two@a.example');
        $this->mailcow()->legacyMailbox('three@a.example');
        $this->dockerApi()->script('dovecot-mailcow', 'maildir', 'cleanup', DockerCallResult::success(), DockerCallResult::failed('busy'), DockerCallResult::success());
        $this->asAdmin();

        $data = $this->bulk('delete', ['one@a.example', 'two@a.example', 'three@a.example'])->assertOk()->json('data');

        $this->assertSame(['done', 'failed', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame('/api/v2/problems/temporary-failure', $data['results'][1]['problem']['type']);
        $this->assertNotNull($this->mailboxRow('two@a.example'));
        $this->assertNull($this->mailboxRow('one@a.example'));
        $this->assertNull($this->mailboxRow('three@a.example'));

        $failure = array_values(array_filter($this->auditOf('mailboxDeletion'), fn (object $r): bool => $r->outcome === 'failure'))[0];
        $this->assertSame(['two@a.example', 'failed', $data['bulkRequestId']], [$failure->target_id, $failure->details['mailStoreCleanup'], $failure->details['bulkRequestId']]);
    }

    public function test_outdated_versions_affect_only_their_item(): void
    {
        foreach (['one', 'two', 'three'] as $local) {
            $this->mailcow()->legacyMailbox("{$local}@a.example");
        }

        $this->asAdmin();
        $tags = [];

        foreach ((array) $this->getJson('/api/v2/mailboxes')->json('data') as $entry) {
            $tags[$entry['address']] = $entry['etag'];
        }

        DB::table('mailbox')->where('username', 'two@a.example')->update(['name' => 'Changed in the legacy UI']);

        $data = $this->bulk('deactivate', [
            ['id' => 'one@a.example', 'version' => $tags['one@a.example']],
            ['id' => 'two@a.example', 'version' => trim($tags['two@a.example'], '"')],
            ['id' => 'three@a.example'],
        ])->assertOk()->json('data');

        $this->assertSame(['done', 'outdated', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame(412, $data['results'][1]['problem']['status']);
        $this->assertSame(1, (int) $this->mailboxRow('two@a.example')?->active);
    }

    public function test_an_address_named_twice_is_processed_once_in_request_order(): void
    {
        $this->mailcow()->domain('xn--bcher-kva.example');
        $this->mailcow()->legacyMailbox('anna@xn--bcher-kva.example');
        $this->asAdmin();

        $data = $this->bulk('deactivate', ['anna@xn--bcher-kva.example', 'ANNA@bücher.example', 'not an address'])->assertOk()->json('data');

        $this->assertSame([['anna@xn--bcher-kva.example', 'done'], ['not an address', 'notFound']], array_map(fn (array $r): array => [$r['id'], $r['outcome']], $data['results']));
        $this->assertSame(['success', 'failure'], array_map(fn (object $r): string => $r->outcome, $this->auditOf('mailboxUpdate')), 'One record per distinct target.');
    }

    public function test_after_a_mail_store_timeout_the_remaining_deletions_fail_at_once(): void
    {
        foreach (['one', 'two', 'three'] as $local) {
            $this->mailcow()->legacyMailbox("{$local}@a.example");
        }

        $this->dockerApi()->script('dovecot-mailcow', 'maildir', 'cleanup', DockerCallResult::unknown('timed out'), DockerCallResult::success());
        $this->asAdmin();
        $started = microtime(true);

        $data = $this->bulk('delete', ['one@a.example', 'two@a.example', 'three@a.example'])->assertOk()->json('data');

        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertSame(['failed', 'failed', 'failed'], array_column($data['results'], 'outcome'));
        $this->assertSame(['/api/v2/problems/outcome-unknown'], array_values(array_unique(array_map(fn (array $r): string => $r['problem']['type'], $data['results']))));
        $this->assertCount(1, $this->dockerApi()->commands(), 'The mail store was not called again.');
        $this->assertSame(3, DB::table('mailbox')->where('domain', 'a.example')->count());
        $outcomes = array_map(fn (object $r): string => $r->details['mailStoreCleanup'], $this->auditOf('mailboxDeletion'));
        $this->assertSame(['unknown', 'notAttempted', 'notAttempted'], $outcomes);
    }

    public function test_mailbox_users_and_read_only_keys_are_refused(): void
    {
        $this->mailcow()->legacyMailbox('anna@a.example');
        $this->signInAs('anna@a.example', Role::MailboxUser);
        $this->bulk('deactivate', ['anna@a.example'])->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->postJson('/api/v2/mailboxes/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => 'anna@a.example']]], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->assertSame(1, (int) $this->mailboxRow('anna@a.example')?->active);
    }
}
