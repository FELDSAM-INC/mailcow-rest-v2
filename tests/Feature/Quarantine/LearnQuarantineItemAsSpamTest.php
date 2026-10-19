<?php

namespace Tests\Feature\Quarantine;

use App\Clients\Rspamd\LearnOutcome;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\LargeMessage;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T031 (FR-023, FR-025, AS-1.5, AS-2.7): learning as spam asks the spam filter to learn
 * the stored message as spam, then to store its fuzzy hashes as unwanted, and removes the item only
 * afterwards; a failed learning keeps the item (deviation: legacy deletes it first).
 */
class LearnQuarantineItemAsSpamTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->asQuarantineUser('alice@example.org');
    }

    public function test_the_message_is_learned_as_spam_then_removed(): void
    {
        $id = $this->item('alice@example.org', 'plain', ['qid' => 'QIDSPAM', 'sender' => 'sender@example.net']);

        $this->send('POST', "/api/v2/quarantine/{$id}/spam-learning")
            ->assertOk()
            ->assertJsonPath('data', [
                'itemId' => $id,
                'queueId' => 'QIDSPAM',
                'action' => 'learnAsSpam',
                'delivery' => 'notApplicable',
                'learning' => 'done',
                'fuzzy' => 'done',
                'itemRemoved' => true,
                'learningError' => null,
                'fuzzyError' => null,
            ]);

        $this->spamFilter->assertCalls(['/learnspam', ['/fuzzyadd', 11]], LargeMessage::fixture('plain'));
        $this->assertNull($this->itemRow($id));
        $this->mailer->assertNothingDelivered();
        $record = $this->auditOfTarget('quarantineItem', (string) $id)[0];
        $this->assertSame('quarantineItemSpamLearning', $record->event_type);
        $this->assertSame(['delivery' => 'notApplicable', 'learning' => 'done', 'fuzzy' => 'done'], $record->details['steps']);
        $this->assertSame('learnAsSpam', $record->details['action']);
    }

    public function test_already_learned_counts_as_success(): void
    {
        $this->spamFilter->respond('/learnspam', LearnOutcome::alreadyLearned());
        $id = $this->item('alice@example.org');

        $this->send('POST', "/api/v2/quarantine/{$id}/spam-learning")->assertOk()->assertJsonPath('data.learning', 'alreadyLearned');

        $this->assertNull($this->itemRow($id));
    }

    public function test_a_failed_learning_keeps_the_item(): void
    {
        $this->spamFilter->respond('/learnspam', LearnOutcome::failed('Learning failed'));
        $id = $this->item('alice@example.org');

        $this->send('POST', "/api/v2/quarantine/{$id}/spam-learning")
            ->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertJsonPath('dependency', 'spamFilter')
            ->assertJsonPath('step', 'learning');

        $this->assertNotNull($this->itemRow($id));
        $this->spamFilter->assertCalls(['/learnspam']);
        $record = $this->auditOfTarget('quarantineItem', (string) $id)[0];
        $this->assertSame('failure', $record->outcome);
        $this->assertSame('temporaryFailure', $record->failure_reason);
    }

    public function test_an_unreachable_spam_filter_keeps_the_item(): void
    {
        $this->spamFilter->unreachable();
        $id = $this->item('alice@example.org');

        $this->send('POST', "/api/v2/quarantine/{$id}/spam-learning")->assertStatus(503)->assertJsonPath('dependency', 'spamFilter');

        $this->assertNotNull($this->itemRow($id));
    }

    public function test_a_failed_fuzzy_step_is_reported_and_the_item_removed(): void
    {
        $this->spamFilter->respond('/fuzzyadd', LearnOutcome::failed('fuzzy storage error'));
        $id = $this->item('alice@example.org');

        $this->send('POST', "/api/v2/quarantine/{$id}/spam-learning")
            ->assertOk()
            ->assertJsonPath('data.fuzzy', 'failed')
            ->assertJsonPath('data.fuzzyError', 'fuzzy storage error');

        $this->assertNull($this->itemRow($id));
    }

    public function test_a_mailbox_user_without_quarantine_is_refused(): void
    {
        DB::table('user_acl')->where('username', 'alice@example.org')->update(['quarantine' => 0]);
        $id = $this->item('alice@example.org');

        $this->send('POST', "/api/v2/quarantine/{$id}/spam-learning")->assertForbidden()->assertJsonPath('requiredFlags', ['quarantine']);

        $this->spamFilter->assertNoCalls();
    }
}
