<?php

namespace Tests\Feature\MassMail;

use Illuminate\Support\Facades\DB;
use Tests\Support\MassMail\MassMailTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T066 (FR-072, FR-073, AS-6.1, AS-6.6): the recipient preview of a selection, which
 * starts from every receiving mailbox (not a resource, active or allowed to receive), may be limited
 * to domains and then include or exclude mailboxes; every named entry must belong to that set.
 */
class MassMailPreviewTest extends TestCase
{
    use MassMailTestHelpers;

    private const URI = '/api/v2/mass-mail-jobs/recipient-preview';

    protected function setUp(): void
    {
        parent::setUp();
        $this->receivingMailboxes(['example.org' => 50, 'example.net' => 40, 'example.com' => 30]);
        $this->extraMailbox('inactive1@example.org', 0);
        $this->extraMailbox('inactive2@example.net', 0);
        $this->extraMailbox('alice@example.org', 2);
        $this->extraMailbox('room1@example.org', 1, 'location');
        $this->extraMailbox('room2@example.net', 1, 'location');
        $this->extraMailbox('beamer@example.com', 1, 'thing');
        $this->extraMailbox('team@example.com', 1, 'group');
        $this->mailcow()->aliasDomain('example.info', 'example.org');
    }

    public function test_every_receiving_mailbox_is_previewed_sorted_and_paged(): void
    {
        $this->asAdmin();

        $response = $this->send('POST', self::URI, ['selection' => (object) []])->assertOk();

        $response->assertJsonPath('meta', ['total' => 121, 'limit' => 25, 'offset' => 0, 'recipientCount' => 121])
            ->assertJsonPath('data.0', ['address' => 'alice@example.org', 'domain' => 'example.org'])
            ->assertJsonCount(25, 'data');
        $page = $this->send('POST', self::URI.'?limit=10&offset=120', ['selection' => (object) []])->assertOk();
        $page->assertJsonCount(1, 'data')->assertJsonPath('meta.recipientCount', 121);
    }

    public function test_a_search_filters_the_rows_but_not_the_recipient_count(): void
    {
        $this->asAdmin();

        $this->send('POST', self::URI.'?q=alice', ['selection' => (object) []])->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.recipientCount', 121)
            ->assertJsonPath('data.0.address', 'alice@example.org');
    }

    public function test_domains_include_and_exclude_narrow_the_selection(): void
    {
        $this->asAdmin();

        $this->send('POST', self::URI, ['selection' => ['domains' => ['EXAMPLE.net']]])->assertOk()->assertJsonPath('meta.recipientCount', 40);
        $this->send('POST', self::URI, ['selection' => ['domains' => ['example.net', 'example.com'], 'exclude' => ['user001@example.net']]])->assertOk()->assertJsonPath('meta.recipientCount', 69);
        $this->send('POST', self::URI, ['selection' => ['include' => ['alice@example.org', 'USER002@example.com', 'alice@example.org']]])->assertOk()
            ->assertJsonPath('meta.recipientCount', 2)
            ->assertJsonPath('data.1.address', 'user002@example.com');
        $this->send('POST', self::URI, ['selection' => ['exclude' => ['alice@example.org']]])->assertOk()->assertJsonPath('meta.recipientCount', 120);
    }

    public function test_invalid_selections_are_refused_with_each_failing_entry(): void
    {
        $this->asAdmin();

        $errors = (array) $this->send('POST', self::URI, ['selection' => ['include' => ['alice@example.org'], 'exclude' => ['user001@example.org']]])->assertStatus(422)->json('errors');

        $this->assertSame(['mutuallyExclusive', ['with' => 'include']], [$errors['selection.exclude'][0]['code'], $errors['selection.exclude'][0]['params']]);
    }

    public function test_unknown_and_ineligible_entries_are_named(): void
    {
        $this->asAdmin();

        $errors = (array) $this->send('POST', self::URI, ['selection' => [
            'domains' => ['example.org', 'example.info', 'nowhere.example'],
            'exclude' => ['ghost@example.org', 'inactive1@example.org', 'room1@example.org', 'user001@example.net', 'user002@example.org'],
        ]])->assertStatus(422)->json('errors');

        $this->assertSame('notFound', $errors['selection.domains.1'][0]['code']);
        $this->assertSame('notFound', $errors['selection.domains.2'][0]['code']);
        $this->assertSame('notFound', $errors['selection.exclude.0'][0]['code']);
        $this->assertSame(['notEligible', ['reason' => 'notReceiving']], [$errors['selection.exclude.1'][0]['code'], $errors['selection.exclude.1'][0]['params']]);
        $this->assertSame(['notEligible', ['reason' => 'notReceiving']], [$errors['selection.exclude.2'][0]['code'], $errors['selection.exclude.2'][0]['params']]);
        $this->assertSame(['notEligible', ['reason' => 'outsideSelectedDomains']], [$errors['selection.exclude.3'][0]['code'], $errors['selection.exclude.3'][0]['params']]);
        $this->assertArrayNotHasKey('selection.exclude.4', $errors);
        $this->assertArrayNotHasKey('selection.domains.0', $errors);
    }

    public function test_too_many_and_malformed_entries_are_invalid(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $errors = (array) $this->send('POST', self::URI, ['selection' => ['include' => array_map(fn (int $i): string => "u{$i}@example.org", range(1, 1001))]])->assertStatus(422)->json('errors');
        $this->assertSame(['tooMany', ['max' => 1000]], [$errors['selection.include'][0]['code'], $errors['selection.include'][0]['params']]);
        $this->send('POST', self::URI, ['selection' => ['domains' => 'example.org']])->assertStatus(422);
        $this->send('POST', self::URI, [])->assertStatus(422);
    }

    public function test_a_read_only_administrator_key_may_preview_and_nothing_is_written_or_audited(): void
    {
        $this->send('POST', self::URI, ['selection' => ['domains' => ['example.com']]], $this->adminKey('readOnly'))->assertOk()->assertJsonPath('meta.recipientCount', 30);

        $this->assertSame(0, DB::table('restv2_mass_mail_jobs')->count());
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }

    public function test_other_roles_are_forbidden(): void
    {
        $this->asDomainAdmin(['example.org']);

        $this->send('POST', self::URI, ['selection' => (object) []])->assertForbidden();
    }
}
