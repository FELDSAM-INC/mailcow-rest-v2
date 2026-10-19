<?php

namespace Tests\Feature\Quarantine;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T058 (FR-035, FR-036, FR-038, FR-069, AS-4.1, AS-4.2, AS-4.4): a mailbox's quarantine
 * notification frequency and category, stored in its attributes as legacy stores them; a mailbox
 * user needs the flag of each member that would change.
 */
class QuarantineNotificationSettingsTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('other.example');
        $this->quarantineMailbox('bob@example.org');
        $this->quarantineMailbox('otto@other.example');
        $this->quarantineMailbox('room@example.org', kind: 'location');
    }

    private function uri(string $mailbox): string
    {
        return "/api/v2/mailboxes/{$mailbox}/quarantine-notification-settings";
    }

    /**
     * @phpstan-impure
     */
    private function attribute(string $mailbox, string $key): ?string
    {
        $value = DB::table('mailbox')->where('username', $mailbox)->selectRaw("JSON_VALUE(`attributes`, '$.{$key}') AS `v`")->value('v');

        return is_string($value) ? $value : null;
    }

    public function test_the_settings_are_read_with_their_version(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->mailcow()->mailboxAttributes('alice@example.org', 'daily', 'add_header');

        $response = $this->getJson($this->uri('alice@example.org'))->assertOk();

        $this->assertSame('alice@example.org', $response->json('data.mailbox'));
        $this->assertSame('daily', $response->json('data.frequency'));
        $this->assertSame('junk', $response->json('data.category'));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_a_mailbox_without_the_attributes_reports_never_and_no_category(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->mailcow()->mailboxAttributes('alice@example.org', null, null);

        $this->getJson($this->uri('alice@example.org'))->assertOk()->assertJsonPath('data.frequency', 'never')->assertJsonPath('data.category', null);
    }

    public function test_a_change_is_stored_where_legacy_stores_it_and_nothing_else_changes(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $this->redis()->hSet('Q_LAST_NOTIFIED', 'alice@example.org', '1760000000');
        $item = $this->item('alice@example.org', overrides: ['notified' => 1]);

        $this->send('PATCH', $this->uri('alice@example.org'), ['frequency' => 'daily'])->assertOk()->assertJsonPath('data.frequency', 'daily')->assertJsonPath('data.category', 'rejected');

        $this->assertSame('daily', $this->attribute('alice@example.org', 'quarantine_notification'));
        $this->assertSame('reject', $this->attribute('alice@example.org', 'quarantine_category'));
        $this->assertSame(1, (int) $this->itemRow($item)?->notified);
        $this->assertSame('1760000000', $this->redis()->hGet('Q_LAST_NOTIFIED', 'alice@example.org'));

        $this->send('PATCH', $this->uri('alice@example.org'), ['category' => 'junk'])->assertOk();
        $this->assertSame('add_header', $this->attribute('alice@example.org', 'quarantine_category'));
    }

    public function test_a_mailbox_user_without_the_category_flag_cannot_change_it(): void
    {
        $this->asQuarantineUser('alice@example.org', ['quarantine_notification' => 1, 'quarantine_category' => 0]);

        $this->send('PATCH', $this->uri('alice@example.org'), ['frequency' => 'weekly', 'category' => 'junk'])
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/attribute-not-permitted')
            ->assertJsonPath('attributes', ['category'])
            ->assertJsonPath('requiredFlags', ['category' => 'quarantineCategory']);
        $this->assertSame('hourly', $this->attribute('alice@example.org', 'quarantine_notification'));

        $this->send('PATCH', $this->uri('alice@example.org'), ['frequency' => 'weekly', 'category' => 'rejected'])->assertOk()->assertJsonPath('data.frequency', 'weekly');
        $record = DB::table('restv2_audit_events')->where('outcome', 'failure')->first();
        $this->assertNotNull($record);
        $this->assertSame('attributeNotPermitted', $record->failure_reason);
    }

    public function test_domain_admins_and_admins_may_always_change(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
        DB::table('user_acl')->where('username', 'bob@example.org')->update(['quarantine_notification' => 0, 'quarantine_category' => 0]);
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->send('PATCH', $this->uri('bob@example.org'), ['frequency' => 'never', 'category' => 'all'])->assertOk();
        $this->send('PATCH', $this->uri('otto@other.example'), ['frequency' => 'never'])->assertNotFound();

        $this->forgetCookies();
        $this->asAdmin();
        $this->send('PATCH', $this->uri('otto@other.example'), ['frequency' => 'weekly'])->assertOk();
    }

    public function test_another_mailbox_and_a_resource_are_not_found(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->getJson($this->uri('bob@example.org'))->assertNotFound();
        $this->send('PATCH', $this->uri('bob@example.org'), ['frequency' => 'daily'])->assertNotFound();

        $this->forgetCookies();
        $this->asAdmin();
        $this->getJson($this->uri('room@example.org'))->assertNotFound();
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asQuarantineUser('alice@example.org');

        $this->send('PATCH', $this->uri('alice@example.org'), ['frequency' => 'monthly'])->assertStatus(422)->assertJsonPath('errors.frequency.0.code', 'notInList');
        $this->send('PATCH', $this->uri('alice@example.org'), [])->assertStatus(422)->assertJsonPath('errors.body.0.code', 'required');
    }

    public function test_an_outdated_version_is_refused(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->send('PATCH', $this->uri('alice@example.org'), ['frequency' => 'daily'], ['If-Match' => '"outdated"'])->assertStatus(412);

        $this->assertSame('hourly', $this->attribute('alice@example.org', 'quarantine_notification'));
    }

    public function test_the_change_is_audited_with_old_and_new_values(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->send('PATCH', $this->uri('alice@example.org'), ['frequency' => 'daily', 'category' => 'all'])->assertOk();

        $records = $this->auditOfTarget('quarantineNotificationSettings', 'alice@example.org');
        $this->assertCount(1, $records);
        $this->assertSame('quarantineNotificationSettingsUpdate', $records[0]->event_type);
        $this->assertSame(['frequency' => ['old' => 'hourly', 'new' => 'daily'], 'category' => ['old' => 'rejected', 'new' => 'all']], $records[0]->details['changes']);
    }
}
