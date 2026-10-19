<?php

namespace Tests\Feature\Quarantine;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T077 (FR-063, FR-064, AS-8.1 to AS-8.3): every change of this feature, and every
 * refusal of an authenticated caller, is found in the audit log with the generic `targetType` and
 * `targetId` filters, with the actor, time, client address and outcome, and without subjects,
 * message content, quick-action hashes or template text.
 */
class SpamFilterAuditTrailTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->quarantineMailbox('alice@example.org');
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trail(string $query): array
    {
        $this->forgetCookies();
        $this->signInAs('admin', Role::Admin);

        return (array) $this->getJson('/api/v2/audit-events?'.$query)->assertOk()->json('data');
    }

    public function test_a_release_is_listed_for_its_item_with_the_steps_and_without_the_content(): void
    {
        $id = $this->item('alice@example.org', 'plain', ['subject' => 'Secret subject', 'qid' => 'QID12345', 'sender' => 'news@example.net']);
        $qhash = (string) DB::table('quarantine')->where('id', $id)->value('qhash');
        $this->signInAs('admin', Role::Admin);
        $this->send('POST', "/api/v2/quarantine/{$id}/release")->assertOk();

        $events = $this->trail("targetType=quarantineItem&targetId={$id}");

        $this->assertCount(1, $events);
        $this->assertSame('quarantineItemRelease', $events[0]['eventType']);
        $this->assertSame('success', $events[0]['outcome']);
        $this->assertSame('admin', $events[0]['actor']['username']);
        $this->assertNotEmpty($events[0]['occurredAt']);
        $this->assertNotEmpty($events[0]['clientAddress']);
        $this->assertSame(['type' => 'quarantineItem', 'id' => (string) $id, 'name' => 'QID12345'], $events[0]['target']);
        $this->assertSame('QID12345', $events[0]['details']['queueId']);
        $this->assertSame('alice@example.org', $events[0]['details']['recipient']);
        $this->assertSame('news@example.net', $events[0]['details']['sender']);
        $this->assertSame(['delivery' => 'done', 'learning' => 'done', 'fuzzy' => 'done'], $events[0]['details']['steps']);
        $json = (string) json_encode($events);
        $this->assertStringNotContainsString('Secret subject', $json);
        $this->assertStringNotContainsString($qhash, $json);
    }

    public function test_a_template_change_is_listed_with_its_sizes_and_without_its_text(): void
    {
        $old = (string) file_get_contents(base_path('resources/legacy/quarantine.tpl'));
        $template = '<p>MARKER-TEMPLATE {{ username }}</p>';
        $this->signInAs('admin', Role::Admin);
        $this->send('PATCH', '/api/v2/quarantine/settings', ['notificationTemplate' => $template])->assertOk();

        $events = $this->trail('targetType=quarantineSettings');

        $this->assertCount(1, $events);
        $this->assertSame('quarantineSettingsUpdate', $events[0]['eventType']);
        $this->assertSame(['changed' => true, 'oldSize' => strlen($old), 'newSize' => strlen($template)], $events[0]['details']['changes']['notificationTemplate']);
        $this->assertStringNotContainsString('MARKER-TEMPLATE', (string) json_encode($events));
    }

    public function test_every_target_type_of_the_feature_can_be_filtered(): void
    {
        $this->signInAs('admin', Role::Admin);
        $this->send('PATCH', '/api/v2/quarantine/settings', ['retentionPerMailbox' => 10])->assertOk();
        $this->send('PATCH', '/api/v2/mailboxes/alice@example.org/quarantine-notification-settings', ['frequency' => 'daily'])->assertOk();
        $this->send('PUT', '/api/v2/mailboxes/alice@example.org/spam-score', ['junkScore' => 6, 'rejectScore' => 12])->assertOk();
        $entry = (int) $this->send('POST', '/api/v2/domains/example.org/spam-policy-entries', ['list' => 'block', 'pattern' => '*@spam.example'])->assertCreated()->json('data.id');
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 100, 'frame' => 'hour'])->assertOk();
        $this->redis()->hSet('RLabc=', 'cnt', '1');
        $this->send('DELETE', '/api/v2/rate-limit-buckets/RLabc=')->assertNoContent();

        $this->assertSame(['quarantineSettingsUpdate'], array_column($this->trail('targetType=quarantineSettings'), 'eventType'));
        $this->assertSame(['quarantineNotificationSettingsUpdate'], array_column($this->trail('targetType=quarantineNotificationSettings&targetId=alice@example.org'), 'eventType'));
        $this->assertSame(['spamScoreUpdate'], array_column($this->trail('targetType=spamScore&targetId=alice@example.org'), 'eventType'));
        $entries = $this->trail('targetType=domain&targetId=example.org&eventType=spamPolicyEntryCreation');
        $this->assertCount(1, $entries);
        $this->assertSame(['entryId' => $entry, 'list' => 'block', 'pattern' => '*@spam.example'], $entries[0]['details']);
        $this->assertSame(['rateLimitUpdate'], array_column($this->trail('targetType=rateLimit&targetId=example.org'), 'eventType'));
        $this->assertSame(['rateLimitBucketReset'], array_column($this->trail('targetType=rateLimitBucket&targetId=RLabc%3D'), 'eventType'));
    }

    public function test_refusals_of_authenticated_callers_are_listed_with_their_reason(): void
    {
        $item = $this->item('alice@example.org');
        $this->quarantineMailbox('bob@example.org', ['spam_score' => 0, 'spam_policy' => 1]);
        $this->mailcow()->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $readOnly = $this->apiKey('admin', 'readOnly');

        $this->signInAs('bob@example.org', Role::MailboxUser);
        $this->send('PUT', '/api/v2/mailboxes/bob@example.org/spam-score', ['junkScore' => 6, 'rejectScore' => 12])->assertForbidden();
        $this->send('POST', '/api/v2/mailboxes/bob@example.org/spam-policy-entries', ['list' => 'block', 'pattern' => '*@x.example'])->assertCreated();
        $this->send('POST', '/api/v2/mailboxes/bob@example.org/spam-policy-entries', ['list' => 'allow', 'pattern' => '*@x.example'])->assertStatus(409);

        $this->forgetCookies();
        $this->signInAs('admin', Role::Admin);
        $this->send('DELETE', '/api/v2/quarantine/999999')->assertNotFound();
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 1, 'frame' => 'hour'], ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->send('PUT', '/api/v2/mailboxes/alice@example.org/spam-score', ['junkScore' => 6.55, 'rejectScore' => 12])->assertStatus(422);
        $this->send('DELETE', '/api/v2/mailboxes/alice@example.org/rate-limit', null, ['X-API-Key' => $readOnly['secret']])->assertForbidden();

        $this->forgetCookies();
        $this->signInAs('restricted', Role::Admin);
        $this->send('POST', "/api/v2/quarantine/{$item}/release")->assertForbidden();

        $this->forgetCookies();
        $this->signInAs('admin', Role::Admin);
        $this->breakSharedStore();
        $this->send('DELETE', '/api/v2/rate-limit-buckets/RLabc=')->assertStatus(503);

        $failure = fn (string $query): array => array_map(fn (array $e): array => [$e['eventType'], $e['outcome'], $e['failureReason'], $e['details']['problemType']], $this->trail($query.'&outcome=failure'));

        $this->assertSame([['spamScoreUpdate', 'failure', 'permissionDenied', '/api/v2/problems/permission-flag-required']], $failure('targetType=spamScore&targetId=bob@example.org'));
        $this->assertSame([['spamPolicyEntryCreation', 'failure', 'conflict', '/api/v2/problems/spam-policy-entry-exists']], $failure('targetType=mailbox&targetId=bob@example.org'));
        $this->assertSame([['quarantineItemDeletion', 'failure', 'notFound', 'about:blank']], $failure('targetType=quarantineItem&targetId=999999'));
        $this->assertSame([['rateLimitUpdate', 'failure', 'versionOutdated', 'about:blank']], $failure('targetType=rateLimit&targetId=example.org'));
        $this->assertSame([['spamScoreUpdate', 'failure', 'validationFailed', '/api/v2/problems/validation-failed']], $failure('targetType=spamScore&targetId=alice@example.org'));
        $this->assertSame([['rateLimitRemoval', 'failure', 'permissionDenied', '/api/v2/problems/insufficient-key-scope']], $failure('targetType=rateLimit&targetId=alice@example.org'));
        $this->assertSame([['quarantineItemRelease', 'failure', 'permissionDenied', '/api/v2/problems/pending-action-required']], $failure("targetType=quarantineItem&targetId={$item}"));
        $this->assertSame([['rateLimitBucketReset', 'failure', 'temporaryFailure', '/api/v2/problems/temporary-failure']], $failure('targetType=rateLimitBucket&targetId=RLabc%3D'));
    }

    public function test_csrf_failures_and_unauthenticated_requests_are_not_listed(): void
    {
        $item = $this->item('alice@example.org');

        $this->deleteJson("/api/v2/quarantine/{$item}")->assertUnauthorized();
        $this->signInAs('admin', Role::Admin);
        $this->deleteJson("/api/v2/quarantine/{$item}", [], ['X-CSRF-Token' => 'wrong'])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');

        $this->assertSame([], $this->trail("targetType=quarantineItem&targetId={$item}"));
        $this->assertNotNull($this->itemRow($item));
    }

    public function test_a_domain_administrator_may_not_read_the_audit_log(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->getJson('/api/v2/audit-events?targetType=quarantineItem')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
