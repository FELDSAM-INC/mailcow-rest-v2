<?php

namespace Tests\Feature\Quarantine;

use App\Auth\Role;
use App\Clients\Rspamd\LearnOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Quarantine\LargeMessage;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T030 (FR-019 to FR-022, FR-025, FR-028, AS-1.3, AS-1.4, edge cases): a release
 * delivers the stored message in the configured format from the stored sender to the recipient,
 * removes the item once the mail server accepted it, then (unless turned off) learns it as ham and
 * stores its fuzzy hashes as wanted; a refused or failed delivery keeps the item and learns
 * nothing. Refusals of authenticated callers are audited.
 */
class ReleaseQuarantineItemTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->quarantineMailbox('bob@example.org');
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function release(int $id, ?array $body = null, array $headers = []): TestResponse
    {
        return $this->send('POST', "/api/v2/quarantine/{$id}/release", $body, $headers);
    }

    public function test_the_unmodified_original_is_delivered_and_learned_as_ham(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->sharedValue('Q_RELEASE_FORMAT', 'raw');
        $stored = LargeMessage::fixture('spam-flag');
        $id = $this->item('alice@example.org', 'spam-flag', ['qid' => '4XyZ1q2w3eR', 'sender' => 'sender@example.net']);

        $response = $this->release($id)->assertOk();

        $this->assertSame([
            'itemId' => $id,
            'queueId' => '4XyZ1q2w3eR',
            'action' => 'release',
            'delivery' => 'done',
            'learning' => 'done',
            'fuzzy' => 'done',
            'itemRemoved' => true,
            'learningError' => null,
            'fuzzyError' => null,
        ], $response->json('data'));
        $delivery = $this->mailer->lastDelivery();
        $this->assertSame('sender@example.net', $delivery['sender']);
        $this->assertSame('alice@example.org', $delivery['recipient']);
        $this->assertSame(preg_replace('/^X-Spam-Flag: (.*)/m', 'X-Pre-Release-Spam-Flag: $1', $stored), $delivery['message']);
        $this->spamFilter->assertCalls(['/learnham', ['/fuzzyadd', 13]], $stored);
        $this->assertNull($this->itemRow($id));
    }

    public function test_without_a_stored_release_format_the_original_is_delivered(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org', 'spam-flag');

        $this->release($id)->assertOk();

        $this->assertStringContainsString('X-Pre-Release-Spam-Flag: YES', $this->mailer->lastDelivery()['message']);
        $this->mailer->assertDelivered(1);
    }

    public function test_the_attachment_format_delivers_a_wrapper(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->sharedValue('Q_RELEASE_FORMAT', 'attachment');
        DB::table('restv2_preferences')->insert(['account_kind' => 'mailbox', 'username' => 'alice@example.org', 'language' => 'en-gb', 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->item('alice@example.org', 'plain', ['qid' => 'QID42', 'sender' => 'sender@example.net']);

        $this->release($id)->assertOk();

        $message = $this->mailer->lastDelivery()['message'];
        $this->assertStringContainsString('Subject: Potentially damaging quarantine item QID42', $message);
        $this->assertStringContainsString('We have attached your message as eml file to this message.', $message);
        $this->assertStringContainsString('QID42.eml', $message);
        $this->assertStringContainsString('message/rfc822', $message);
        $this->assertSame('sender@example.net', $this->mailer->lastDelivery()['sender']);
    }

    public function test_an_unknown_sender_is_released_from_sender_unknown_at_rspamd(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org', overrides: ['sender' => 'unknown']);

        $this->release($id)->assertOk();

        $this->assertSame('sender-unknown@rspamd', $this->mailer->lastDelivery()['sender']);
    }

    public function test_already_learned_counts_and_fuzzy_follows(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->spamFilter->respond('/learnham', LearnOutcome::alreadyLearned());
        $id = $this->item('alice@example.org');

        $this->release($id)->assertOk()->assertJsonPath('data.learning', 'alreadyLearned')->assertJsonPath('data.fuzzy', 'done');

        $this->spamFilter->assertCalls(['/learnham', ['/fuzzyadd', 13]]);
    }

    public function test_no_fuzzy_content_is_skipped(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->spamFilter->respond('/fuzzyadd', LearnOutcome::noFuzzyContent());
        $id = $this->item('alice@example.org');

        $this->release($id)->assertOk()->assertJsonPath('data.fuzzy', 'skipped')->assertJsonPath('data.fuzzyError', null);
    }

    public function test_a_failed_learning_is_reported_and_the_release_stands(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->spamFilter->respond('/learnham', LearnOutcome::failed('Learning failed: database locked'));
        $id = $this->item('alice@example.org');

        $this->release($id)
            ->assertOk()
            ->assertJsonPath('data.delivery', 'done')
            ->assertJsonPath('data.learning', 'failed')
            ->assertJsonPath('data.learningError', 'Learning failed: database locked')
            ->assertJsonPath('data.fuzzy', 'notRequested');

        $this->spamFilter->assertCalls(['/learnham']);
        $this->assertNull($this->itemRow($id));
    }

    public function test_a_failed_fuzzy_call_is_reported(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->spamFilter->respond('/fuzzyadd', LearnOutcome::failed('fuzzy storage error'));
        $id = $this->item('alice@example.org');

        $this->release($id)->assertOk()->assertJsonPath('data.fuzzy', 'failed')->assertJsonPath('data.fuzzyError', 'fuzzy storage error');
    }

    public function test_a_release_without_learning_never_calls_the_spam_filter(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org');

        $this->release($id, ['learnAsHam' => false])
            ->assertOk()
            ->assertJsonPath('data.action', 'releaseWithoutLearning')
            ->assertJsonPath('data.learning', 'notRequested')
            ->assertJsonPath('data.fuzzy', 'notRequested');

        $this->mailer->assertDelivered(1);
        $this->spamFilter->assertNoCalls();
        $this->assertNull($this->itemRow($id));
    }

    public function test_a_refused_delivery_keeps_the_item_and_learns_nothing(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->mailer->refuse(550, '550 5.1.1 <alice@example.org>: Recipient address rejected');
        $id = $this->item('alice@example.org');

        $this->release($id)
            ->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/quarantine-delivery-refused')
            ->assertJsonPath('smtpCode', 550)
            ->assertJsonPath('smtpResponse', '550 5.1.1 <alice@example.org>: Recipient address rejected');

        $this->assertNotNull($this->itemRow($id));
        $this->spamFilter->assertNoCalls();
        $audit = $this->auditOfTarget('quarantineItem', (string) $id);
        $this->assertCount(1, $audit);
        $this->assertSame('failure', $audit[0]->outcome);
        $this->assertSame('serviceFailure', $audit[0]->failure_reason);
    }

    public function test_an_unreachable_mail_server_is_a_temporary_failure(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->mailer->temporaryFailure('Connection could not be established with host "postfix:590"');
        $id = $this->item('alice@example.org');

        $this->release($id)
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertJsonPath('dependency', 'mailServer')
            ->assertJsonPath('step', 'delivery');

        $this->assertNotNull($this->itemRow($id));
        $this->spamFilter->assertNoCalls();
    }

    public function test_an_item_of_a_deleted_mailbox_is_kept_when_the_mail_server_refuses_it(): void
    {
        $this->asAdmin();
        $this->mailer->refuse(550, '550 5.1.1 <gone@example.org>: Recipient address rejected: User unknown in virtual mailbox table');
        $id = $this->item('gone@example.org');

        $this->release($id)->assertStatus(409)->assertJsonPath('smtpCode', 550);

        $this->assertNotNull($this->itemRow($id));
    }

    public function test_notification_state_is_never_touched(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->redis()->hSet('Q_LAST_NOTIFIED', 'alice@example.org', '1760000000');
        $other = $this->item('alice@example.org', overrides: ['notified' => 1]);
        $unnotified = $this->item('alice@example.org', overrides: ['notified' => 0]);
        $id = $this->item('alice@example.org');

        $this->release($id)->assertOk();

        $this->assertSame(1, (int) $this->itemRow($other)?->notified);
        $this->assertSame(0, (int) $this->itemRow($unnotified)?->notified);
        $this->assertSame('1760000000', $this->redis()->hGet('Q_LAST_NOTIFIED', 'alice@example.org'));
    }

    public function test_a_mailbox_user_without_quarantine_is_refused_before_the_lookup(): void
    {
        $this->asQuarantineUser('alice@example.org', ['quarantine' => 0]);
        $id = $this->item('alice@example.org');

        $this->release($id)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/permission-flag-required')->assertJsonPath('requiredFlags', ['quarantine']);
        $this->release(999999)->assertForbidden()->assertJsonPath('requiredFlags', ['quarantine']);

        $this->mailer->assertNothingDelivered();
        $record = $this->auditOfTarget('quarantineItem', (string) $id)[0];
        $this->assertSame('permissionDenied', $record->failure_reason);
        $this->assertSame(['quarantine'], $record->details['requiredFlags']);
    }

    public function test_another_mailboxs_item_is_not_found(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $bobs = $this->item('bob@example.org');

        $this->release($bobs)->assertNotFound();

        $this->mailer->assertNothingDelivered();
        $this->assertNotNull($this->itemRow($bobs));
        $this->assertSame('notFound', $this->auditOfTarget('quarantineItem', (string) $bobs)[0]->failure_reason);
    }

    public function test_if_match_is_honoured(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org');
        $etag = (string) $this->getJson("/api/v2/quarantine/{$id}")->assertOk()->json('data.etag');

        $this->release($id, null, ['If-Match' => '"stale"'])->assertStatus(412);
        $this->mailer->assertNothingDelivered();

        $this->release($id, null, ['If-Match' => $etag])->assertOk();
        $this->mailer->assertDelivered(1);
    }

    public function test_an_unavailable_api_store_delivers_nothing(): void
    {
        $this->quarantineMailbox('alice@example.org');
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $key = $this->apiKey('admin');
        $id = $this->item('alice@example.org');
        $this->breakApiStore();

        $this->release($id, null, $this->keyHeader($key))
            ->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertJsonPath('dependency', 'sharedStore');

        $this->mailer->assertNothingDelivered();
        $this->assertNotNull($this->itemRow($id));
    }

    public function test_one_audit_record_with_the_steps_and_no_content(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org', 'spam-flag', ['qid' => 'QIDAUDIT', 'sender' => 'sender@example.net', 'subject' => 'Cheap pills']);
        $qhash = (string) $this->itemRow($id)?->qhash;

        $this->release($id)->assertOk();

        $records = $this->auditOfTarget('quarantineItem', (string) $id);
        $this->assertCount(1, $records);
        $this->assertSame('quarantineItemRelease', $records[0]->event_type);
        $this->assertSame('success', $records[0]->outcome);
        $this->assertSame('QIDAUDIT', $records[0]->target_name);
        $this->assertSame([
            'queueId' => 'QIDAUDIT',
            'recipient' => 'alice@example.org',
            'sender' => 'sender@example.net',
            'action' => 'release',
            'steps' => ['delivery' => 'done', 'learning' => 'done', 'fuzzy' => 'done'],
        ], $records[0]->details);
        $raw = (string) DB::table('restv2_audit_events')->where('id', $records[0]->id)->value('details');
        $this->assertStringNotContainsString('Cheap pills', $raw);
        $this->assertStringNotContainsString($qhash, $raw);
    }

    public function test_a_read_only_key_and_a_restricted_session_are_refused_and_audited(): void
    {
        $this->quarantineMailbox('alice@example.org');
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->mailcow()->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $id = $this->item('alice@example.org');

        $this->release($id, null, $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->signInAs('restricted', Role::Admin);
        $this->release($id)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $records = $this->auditOfTarget('quarantineItem', (string) $id);
        $this->assertCount(2, $records);
        $this->assertSame(['permissionDenied', 'permissionDenied'], array_column($records, 'failure_reason'));
        $this->mailer->assertNothingDelivered();
    }

    public function test_csrf_failures_and_anonymous_requests_write_no_record(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org');

        $this->postJson("/api/v2/quarantine/{$id}/release", ['learnAsHam' => true], ['X-CSRF-Token' => 'wrong'])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->forgetCookies();
        $this->postJson("/api/v2/quarantine/{$id}/release", ['learnAsHam' => true])->assertUnauthorized();

        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }
}
