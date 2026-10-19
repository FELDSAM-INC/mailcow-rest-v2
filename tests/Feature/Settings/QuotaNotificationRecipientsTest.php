<?php

namespace Tests\Feature\Settings;

use Illuminate\Support\Facades\DB;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T062 (FR-046 to FR-049, AS-5.3 to AS-5.6, SC-010): the per-domain copy recipients of
 * the quota notifications in legacy's `QW_BCC` hash, for administrators and for domain
 * administrators within their domains; anything else answers like an unknown domain.
 */
class QuotaNotificationRecipientsTest extends TestCase
{
    use SettingsTestHelpers;

    private function uri(string $domain): string
    {
        return "/api/v2/domains/{$domain}/quota-notification-recipients";
    }

    private function stored(string $domain): ?string
    {
        $value = $this->redis()->hGet('QW_BCC', $domain);

        return is_string($value) ? $value : null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('example.net');
        $this->mailcow()->aliasDomain('example.info', 'example.org');
    }

    public function test_a_domain_administrator_saves_the_recipients_of_their_domain_as_a_list(): void
    {
        $this->asDomainAdmin(['example.org']);

        $response = $this->send('PATCH', $this->uri('example.org'), ['recipients' => ['it@example.org', 'boss@example.net'], 'active' => true])->assertOk();

        $response->assertJsonPath('data', ['domain' => 'example.org', 'recipients' => ['it@example.org', 'boss@example.net'], 'active' => true, 'etag' => $response->json('data.etag')]);
        $this->assertSame('{"bcc_rcpts":["it@example.org","boss@example.net"],"active":1}', $this->stored('example.org'));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_duplicates_differing_only_in_case_are_stored_once_keeping_the_first(): void
    {
        $this->asAdmin();

        $this->send('PATCH', $this->uri('example.org'), ['recipients' => ['it@example.org', ' IT@example.org', 'Boss@Example.net', 'boss@example.net'], 'active' => true])->assertOk()
            ->assertJsonPath('data.recipients', ['it@example.org', 'Boss@Example.net']);
    }

    public function test_an_invalid_entry_refuses_the_whole_list(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $this->redis()->hSet('QW_BCC', 'example.org', '{"bcc_rcpts":["kept@example.org"],"active":1}');

        $this->send('PATCH', $this->uri('example.org'), ['recipients' => ["x\n@example.org"]])->assertStatus(422);
        $errors = (array) $this->send('PATCH', $this->uri('example.org'), ['recipients' => ['not-an-address', 'ok@example.org']])->assertStatus(422)->json('errors');
        $this->assertSame('email', $errors['recipients.0'][0]['params']['format']);
        $this->assertArrayNotHasKey('recipients.1', $errors);
        $this->send('PATCH', $this->uri('example.org'), ['recipients' => array_map(fn (int $i): string => "u{$i}@example.org", range(1, 101))])->assertStatus(422)->assertJsonPath('errors.recipients.0.code', 'tooMany');
        $this->send('PATCH', $this->uri('example.org'), ['active' => 'yes'])->assertStatus(422);

        $this->assertSame('{"bcc_rcpts":["kept@example.org"],"active":1}', $this->stored('example.org'));
    }

    public function test_an_empty_list_is_stored_inactive(): void
    {
        $this->asAdmin();

        $this->send('PATCH', $this->uri('example.org'), ['recipients' => [], 'active' => true])->assertOk()->assertJsonPath('data.active', false);

        $this->assertSame('{"bcc_rcpts":[],"active":0}', $this->stored('example.org'));
    }

    public function test_switching_copies_keeps_the_recipients(): void
    {
        $this->asAdmin();
        $this->redis()->hSet('QW_BCC', 'example.org', '{"bcc_rcpts":["a@example.org"],"active":0}');

        $this->send('PATCH', $this->uri('example.org'), ['active' => true])->assertOk()->assertJsonPath('data.recipients', ['a@example.org'])->assertJsonPath('data.active', true);
    }

    public function test_a_domain_without_settings_reads_as_empty_and_inactive(): void
    {
        $this->asAdmin();

        $this->getJson($this->uri('example.net'))->assertOk()->assertJsonPath('data.recipients', [])->assertJsonPath('data.active', false);
    }

    public function test_a_legacy_value_written_as_an_object_reads_as_a_list(): void
    {
        $this->asAdmin();
        $this->redis()->hSet('QW_BCC', 'example.org', '{"bcc_rcpts":{"0":"a@example.org","2":"b@example.org"},"active":1}');

        $this->getJson($this->uri('example.org'))->assertOk()->assertJsonPath('data.recipients', ['a@example.org', 'b@example.org'])->assertJsonPath('data.active', true);
    }

    public function test_foreign_unknown_and_alias_domains_answer_not_found(): void
    {
        $this->asDomainAdmin(['example.org']);

        foreach (['example.net', 'unknown.example', 'example.info'] as $domain) {
            $this->getJson($this->uri($domain))->assertNotFound();
            $this->send('PATCH', $this->uri($domain), ['active' => true])->assertNotFound();
        }

        $this->forgetCookies();
        $this->asAdmin();
        $this->getJson($this->uri('example.info'))->assertNotFound();
        $this->assertNull($this->stored('example.info'));
        $this->assertNull($this->stored('example.net'));
    }

    public function test_a_domain_administrator_who_loses_the_domain_gets_not_found_next(): void
    {
        $this->asDomainAdmin(['example.org']);
        $this->getJson($this->uri('example.org'))->assertOk();

        DB::table('domain_admins')->where('username', 'reseller')->where('domain', 'example.org')->delete();

        $this->getJson($this->uri('example.org'))->assertNotFound();
    }

    public function test_the_settings_reappear_when_a_deleted_domain_is_created_again(): void
    {
        $this->asAdmin();
        $this->mailcow()->domain('gone.example');
        $this->send('PATCH', $this->uri('gone.example'), ['recipients' => ['it@gone.example'], 'active' => true])->assertOk();

        $this->send('DELETE', '/api/v2/domains/gone.example')->assertNoContent();
        $this->assertNotNull($this->stored('gone.example'));
        $this->mailcow()->domain('gone.example');

        $this->getJson($this->uri('gone.example'))->assertOk()->assertJsonPath('data.recipients', ['it@gone.example']);
    }

    public function test_an_outdated_tag_is_refused_and_changes_are_audited(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson($this->uri('example.org'))->json('data.etag');
        $this->redis()->hSet('QW_BCC', 'example.org', '{"bcc_rcpts":["legacy@example.org"],"active":1}');

        $this->send('PATCH', $this->uri('example.org'), ['active' => false], ['If-Match' => $etag])->assertStatus(412);
        $this->send('PATCH', $this->uri('example.org'), ['recipients' => ['new@example.org']])->assertOk();

        $records = $this->successesOf('quotaNotificationRecipientsUpdate');
        $this->assertCount(1, $records);
        $this->assertSame(['quotaNotificationRecipients', 'example.org'], [$records[0]->target_type, $records[0]->target_id]);
        $this->assertSame(['old' => ['legacy@example.org'], 'new' => ['new@example.org']], $records[0]->details['changes']['recipients']);
    }

    public function test_mailbox_users_are_forbidden(): void
    {
        $this->asMailboxUser('user@example.org');

        $this->getJson($this->uri('example.org'))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
