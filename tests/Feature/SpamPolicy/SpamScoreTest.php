<?php

namespace Tests\Feature\SpamPolicy;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T062 (FR-039 to FR-042, AS-5.1 to AS-5.4, edge cases): a mailbox's spam score, stored
 * as legacy stores it (`filterconf` rows `lowspamlevel` and `highspamlevel`), with the spam
 * filter's thresholds as defaults and 5/15 as fallback.
 */
class SpamScoreTest extends TestCase
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
        $this->spamFilter->withActions([['action' => 'reject', 'value' => 15], ['action' => 'add header', 'value' => 8], ['action' => 'greylist', 'value' => 4]]);
    }

    private function uri(string $mailbox): string
    {
        return "/api/v2/mailboxes/{$mailbox}/spam-score";
    }

    /**
     * @return array<string, string>
     *
     * @phpstan-impure
     */
    private function rows(string $mailbox): array
    {
        return DB::table('filterconf')->where('object', $mailbox)->whereIn('option', ['lowspamlevel', 'highspamlevel'])->orderBy('prefid')->pluck('value', 'option')->map(fn (mixed $v): string => (string) $v)->all();
    }

    public function test_the_filters_thresholds_are_the_defaults(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $response = $this->getJson($this->uri('alice@example.org'))->assertOk();

        $this->assertSame(['mailbox' => 'alice@example.org', 'junkScore' => 8, 'rejectScore' => 15, 'custom' => false, 'defaults' => ['junkScore' => 8, 'rejectScore' => 15, 'fallback' => false]], array_diff_key((array) $response->json('data'), ['etag' => true]));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_fallback_defaults_when_the_filter_cannot_answer(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->spamFilter->actionsUnreachable();
        $this->getJson($this->uri('alice@example.org'))->assertOk()->assertJsonPath('data.defaults', ['junkScore' => 5, 'rejectScore' => 15, 'fallback' => true]);
    }

    public function test_a_missing_or_zero_threshold_gives_the_fallback(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->spamFilter->withActions([['action' => 'reject', 'value' => 15], ['action' => 'add header', 'value' => 0]]);

        $this->getJson($this->uri('alice@example.org'))->assertOk()->assertJsonPath('data.junkScore', 5)->assertJsonPath('data.defaults.fallback', true);
    }

    public function test_a_custom_score_is_stored_as_legacy_stores_it(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->send('PUT', $this->uri('alice@example.org'), ['junkScore' => 6.5, 'rejectScore' => 12])
            ->assertOk()
            ->assertJsonPath('data.junkScore', 6.5)
            ->assertJsonPath('data.rejectScore', 12)
            ->assertJsonPath('data.custom', true);

        $this->assertSame(['highspamlevel' => '12', 'lowspamlevel' => '6.5'], $this->rows('alice@example.org'));
        $this->assertSame(['highspamlevel', 'lowspamlevel'], DB::table('filterconf')->where('object', 'alice@example.org')->orderBy('prefid')->pluck('option')->all());
    }

    public function test_equal_scores_raise_the_reject_score_by_a_tenth(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->send('PUT', $this->uri('alice@example.org'), ['junkScore' => 8, 'rejectScore' => 8])->assertOk()->assertJsonPath('data.rejectScore', 8.1);
        $this->assertSame('8.1', $this->rows('alice@example.org')['highspamlevel']);

        $this->send('PUT', $this->uri('alice@example.org'), ['junkScore' => 0.3, 'rejectScore' => 0.3])->assertOk();
        $this->assertSame(['highspamlevel' => '0.4', 'lowspamlevel' => '0.3'], $this->rows('alice@example.org'));
    }

    public function test_invalid_scores_are_refused_and_nothing_changes(): void
    {
        $this->withoutRequestValidation();
        $this->asQuarantineUser('alice@example.org');
        $this->mailcow()->spamScore('alice@example.org', '7', '14');

        $this->send('PUT', $this->uri('alice@example.org'), ['junkScore' => 12, 'rejectScore' => 6])
            ->assertStatus(422)
            ->assertJsonPath('errors.junkScore.0.code', 'mustNotExceed')
            ->assertJsonPath('errors.junkScore.0.params.field', 'rejectScore');
        $this->send('PUT', $this->uri('alice@example.org'), ['junkScore' => 5, 'rejectScore' => 5000.1])->assertStatus(422)->assertJsonPath('errors.rejectScore.0.code', 'outOfRange');
        $this->send('PUT', $this->uri('alice@example.org'), ['junkScore' => 6.55, 'rejectScore' => 12])
            ->assertStatus(422)
            ->assertJsonPath('errors.junkScore.0.code', 'notMultipleOf')
            ->assertJsonPath('errors.junkScore.0.params.factor', 0.1);

        $this->assertSame(['highspamlevel' => '14', 'lowspamlevel' => '7'], $this->rows('alice@example.org'));
    }

    public function test_one_stored_row_is_custom_with_the_other_value_from_the_defaults(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->mailcow()->spamScore('alice@example.org', null, '20');

        $this->getJson($this->uri('alice@example.org'))->assertOk()->assertJsonPath('data.custom', true)->assertJsonPath('data.junkScore', 8)->assertJsonPath('data.rejectScore', 20);
    }

    public function test_a_reset_removes_both_rows(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->mailcow()->spamScore('alice@example.org', '6.5', '12');

        $this->send('DELETE', $this->uri('alice@example.org'))->assertNoContent();

        $this->assertSame([], $this->rows('alice@example.org'));
        $this->getJson($this->uri('alice@example.org'))->assertOk()->assertJsonPath('data.custom', false)->assertJsonPath('data.junkScore', 8);
        $records = $this->auditOfTarget('spamScore', 'alice@example.org');
        $this->assertSame('spamScoreReset', $records[0]->event_type);
        $this->assertSame(['junkScore' => ['old' => 6.5, 'new' => null], 'rejectScore' => ['old' => 12, 'new' => null]], $records[0]->details['changes']);

        $this->send('DELETE', $this->uri('alice@example.org'))->assertNoContent();
        $this->assertCount(1, $this->auditOfTarget('spamScore', 'alice@example.org'));
    }

    public function test_a_mailbox_user_without_spam_score_is_refused(): void
    {
        $this->asQuarantineUser('alice@example.org', ['spam_score' => 0]);

        $this->getJson($this->uri('alice@example.org'))->assertOk();
        $this->send('PUT', $this->uri('alice@example.org'), ['junkScore' => 6, 'rejectScore' => 12])
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['spamScore']);
        $this->send('DELETE', $this->uri('alice@example.org'))->assertForbidden();
    }

    public function test_a_domain_admin_may_always_change_within_the_scope(): void
    {
        $this->quarantineMailbox('alice@example.org', ['spam_score' => 0]);
        $this->mailcow()->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->send('PUT', $this->uri('alice@example.org'), ['junkScore' => 6, 'rejectScore' => 12])->assertOk();
        $this->send('PUT', $this->uri('otto@other.example'), ['junkScore' => 6, 'rejectScore' => 12])->assertNotFound();
        $this->getJson($this->uri('room@example.org'))->assertNotFound();
    }

    public function test_an_outdated_version_is_refused(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->send('PUT', $this->uri('alice@example.org'), ['junkScore' => 6, 'rejectScore' => 12], ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->send('DELETE', $this->uri('alice@example.org'), null, ['If-Match' => '"outdated"'])->assertStatus(412);

        $this->assertSame([], $this->rows('alice@example.org'));
    }

    public function test_the_change_is_audited_with_old_and_new_values(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->mailcow()->spamScore('alice@example.org', '7', null);

        $this->send('PUT', $this->uri('alice@example.org'), ['junkScore' => 6.5, 'rejectScore' => 12])->assertOk();

        $record = $this->auditOfTarget('spamScore', 'alice@example.org')[0];
        $this->assertSame('spamScoreUpdate', $record->event_type);
        $this->assertSame(['junkScore' => ['old' => 7, 'new' => 6.5], 'rejectScore' => ['old' => null, 'new' => 12]], $record->details['changes']);
    }
}
