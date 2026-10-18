<?php

namespace Tests\Feature\MailboxTemplates;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T072 (FR-049–FR-053, US6): administrators create, read, change and delete mailbox
 * templates stored with legacy's keys; names are unique without regard to letter case; the Default
 * template cannot be renamed or deleted; templates never change existing mailboxes.
 */
class MailboxTemplateCrudTest extends TestCase
{
    use MailboxTestHelpers;

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function storeTemplate(array $body): TestResponse
    {
        return $this->postJson('/api/v2/mailbox-templates', $body, $this->csrf());
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function patchTemplate(int $id, array $patch, array $headers = []): TestResponse
    {
        return $this->patchMerge('/api/v2/mailbox-templates/'.$id, $patch, $this->csrf() + $headers);
    }

    public function test_a_template_is_created_with_legacys_keys_and_defaults(): void
    {
        $this->asAdmin();

        $response = $this->storeTemplate(['name' => ' Students ', 'quota' => self::GIB, 'protocolAccess' => ['pop3' => false], 'userPermissions' => ['syncjobs' => true], 'tags' => ['Student'], 'rateLimit' => ['value' => 10, 'frame' => 'minute']])->assertCreated();
        $id = (int) $response->json('data.id');

        $response->assertHeader('Location', '/api/v2/mailbox-templates/'.$id);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertSame('Students', $response->json('data.name'));
        $this->assertFalse($response->json('data.isDefault'));

        $row = DB::table('templates')->where('id', $id)->first();
        $this->assertSame(['mailbox', 'Students'], [$row?->type, $row?->template]);
        $this->assertSame([
            'quota' => self::GIB, 'tags' => ['Student'], 'tagged_mail_handler' => 'none', 'quarantine_notification' => 'hourly',
            'quarantine_category' => 'reject', 'rl_frame' => 'm', 'rl_value' => '10', 'force_pw_update' => 0, 'force_tfa' => 0,
            'sogo_access' => 1, 'active' => 1, 'tls_enforce_in' => 0, 'tls_enforce_out' => 0, 'imap_access' => 1, 'pop3_access' => 0,
            'smtp_access' => 1, 'sieve_access' => 1, 'eas_access' => 1, 'dav_access' => 1, 'acl_spam_alias' => 1, 'acl_tls_policy' => 1,
            'acl_spam_score' => 1, 'acl_spam_policy' => 1, 'acl_delimiter_action' => 1, 'acl_syncjobs' => 1, 'acl_eas_reset' => 1,
            'acl_sogo_profile_reset' => 0, 'acl_pushover' => 1, 'acl_quarantine' => 1, 'acl_quarantine_attachments' => 1,
            'acl_quarantine_notification' => 1, 'acl_quarantine_category' => 1, 'acl_app_passwds' => 1, 'acl_pw_reset' => 1,
        ], json_decode((string) $row?->attributes, true));

        $minimal = $this->storeTemplate(['name' => 'Minimal'])->assertCreated()->json('data');
        $this->assertSame(0, $minimal['quota'], 'Unlimited, as the legacy Default template.');
        $this->assertNull($minimal['rateLimit']);
        $this->assertSame('rejected', $minimal['quarantineCategory']);

        $record = $this->auditOf('mailboxTemplateCreation')[0];
        $this->assertSame(['mailboxTemplate', (string) $id, 'Students'], [$record->target_type, $record->target_id, $record->target_name]);
        $this->assertSame(self::GIB, $record->details['values']['quota']);
        $this->assertSame(['value' => 10, 'frame' => 'minute'], $record->details['values']['rateLimit']);
        $this->assertArrayNotHasKey('name', $record->details['values']);
    }

    public function test_the_list_sorts_searches_pages_and_tags_each_entry(): void
    {
        $this->mailcow()->defaultMailboxTemplate();
        $this->mailcow()->mailboxTemplate('staff', ['quota' => self::GIB]);
        $this->mailcow()->mailboxTemplate('Students', ['quota' => 2 * self::GIB]);
        $this->asAdmin();

        $this->assertSame(['Default', 'staff', 'Students'], array_column((array) $this->getJson('/api/v2/mailbox-templates')->assertOk()->json('data'), 'name'));
        $this->assertSame(['Students', 'staff', 'Default'], array_column((array) $this->getJson('/api/v2/mailbox-templates?order=desc')->json('data'), 'name'));
        $this->assertSame(['Students', 'staff', 'Default'], array_column((array) $this->getJson('/api/v2/mailbox-templates?sort=id&order=desc')->json('data'), 'name'));
        $this->assertSame(['staff', 'Students'], array_column((array) $this->getJson('/api/v2/mailbox-templates?q=ST')->json('data'), 'name'));
        $this->assertSame(['staff'], array_column((array) $this->getJson('/api/v2/mailbox-templates?limit=1&offset=1')->json('data'), 'name'));
        $this->assertSame(3, $this->getJson('/api/v2/mailbox-templates?sort=createdAt')->json('meta.total'));

        foreach ((array) $this->getJson('/api/v2/mailbox-templates')->json('data') as $entry) {
            $this->assertSame($this->getJson('/api/v2/mailbox-templates/'.$entry['id'])->assertOk()->headers->get('ETag'), $entry['etag']);
        }
    }

    public function test_a_change_keeps_every_value_not_sent(): void
    {
        $id = $this->mailcow()->mailboxTemplate('Staff', ['quota' => self::GIB, 'pop3_access' => 0, 'imap_access' => 1, 'rl_value' => 5, 'rl_frame' => 'h', 'tags' => ['A']]);
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/mailbox-templates/'.$id)->headers->get('ETag');

        $data = $this->patchTemplate($id, ['quota' => 2 * self::GIB, 'protocolAccess' => ['imap' => false]], ['If-Match' => $etag])->assertOk()->json('data');

        $this->assertSame(2 * self::GIB, $data['quota']);
        $this->assertSame(['imap' => false, 'pop3' => false], array_intersect_key($data['protocolAccess'], ['imap' => 1, 'pop3' => 1]));
        $this->assertSame(['value' => 5, 'frame' => 'hour'], $data['rateLimit']);
        $this->assertSame(['A'], $data['tags']);
        $this->assertSame(['protocolAccess.imap' => ['old' => true, 'new' => false], 'quota' => ['old' => self::GIB, 'new' => 2 * self::GIB]], $this->auditOf('mailboxTemplateUpdate')[0]->details['changes']);

        $this->patchTemplate($id, ['rateLimit' => null])->assertOk()->assertJsonPath('data.rateLimit', null);
        $this->assertSame('', json_decode((string) DB::table('templates')->where('id', $id)->value('attributes'), true)['rl_value']);
        $this->patchTemplate($id, ['quota' => 3 * self::GIB], ['If-Match' => $etag])->assertStatus(412);
    }

    public function test_rate_limits_are_stored_as_legacy_strings_and_read_in_both_forms(): void
    {
        // Legacy's form stores the string "5"; earlier API versions stored the integer 5.
        $legacy = $this->mailcow()->mailboxTemplate('Legacy', ['rl_value' => '5', 'rl_frame' => 'h']);
        $earlier = $this->mailcow()->mailboxTemplate('Earlier', ['rl_value' => 5, 'rl_frame' => 'h']);
        $this->asAdmin();

        foreach ([$legacy, $earlier] as $id) {
            $this->getJson('/api/v2/mailbox-templates/'.$id)->assertOk()->assertJsonPath('data.rateLimit', ['value' => 5, 'frame' => 'hour']);
        }

        $this->patchTemplate($earlier, ['rateLimit' => ['value' => 8, 'frame' => 'day']])->assertOk();
        $stored = json_decode((string) DB::table('templates')->where('id', $earlier)->value('attributes'), true);
        $this->assertSame(['8', 'd'], [$stored['rl_value'], $stored['rl_frame']]);
    }

    public function test_names_are_unique_without_regard_to_case(): void
    {
        $this->mailcow()->mailboxTemplate('Staff');
        $other = $this->mailcow()->mailboxTemplate('Other');
        $this->asAdmin();

        $this->storeTemplate(['name' => 'staff'])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/mailbox-template-name-exists');
        $this->patchTemplate($other, ['name' => 'STAFF'])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/mailbox-template-name-exists');
        $this->patchTemplate($other, ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
    }

    public function test_the_default_template_cannot_be_renamed_or_deleted(): void
    {
        $id = $this->mailcow()->defaultMailboxTemplate();
        $this->asAdmin();

        $this->patchTemplate($id, ['name' => 'Other'])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/mailbox-template-protected');
        $this->deleteJson('/api/v2/mailbox-templates/'.$id, [], $this->csrf())->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/mailbox-template-protected');
        $this->patchTemplate($id, ['quota' => self::GIB])->assertOk()->assertJsonPath('data.isDefault', true);
        $this->assertSame('Default', DB::table('templates')->where('id', $id)->value('template'));
    }

    public function test_mailboxes_keep_their_values_when_the_template_changes_or_goes(): void
    {
        $this->mailcow()->domain('example.org');
        $id = $this->mailcow()->mailboxTemplate('Small', ['quota' => self::GIB, 'pop3_access' => 0]);
        $this->asAdmin();
        $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x', 'templateId' => $id])->assertCreated();
        $before = $this->getJson('/api/v2/mailboxes/anna@example.org')->headers->get('ETag');

        $this->patchTemplate($id, ['quota' => 2 * self::GIB, 'protocolAccess' => ['pop3' => true]])->assertOk();
        $this->deleteJson('/api/v2/mailbox-templates/'.$id, [], $this->csrf())->assertNoContent();

        $this->assertSame($before, $this->getJson('/api/v2/mailboxes/anna@example.org')->headers->get('ETag'));
        $this->assertNull(DB::table('templates')->where('id', $id)->first());
        $this->assertSame(1, count($this->auditOf('mailboxTemplateDeletion')));
        $this->assertSame(2 * self::GIB, $this->auditOf('mailboxTemplateDeletion')[0]->details['values']['quota']);
    }

    public function test_a_legacy_template_missing_keys_is_read_with_the_defaults(): void
    {
        $id = $this->mailcow()->defaultMailboxTemplate();
        $this->asAdmin();

        $data = $this->getJson('/api/v2/mailbox-templates/'.$id)->assertOk()->json('data');

        $this->assertSame('none', $data['taggedMailHandling']);
        $this->assertTrue($data['userPermissions']['pwReset']);
        $this->assertSame(0, $data['quota']);
        $this->assertArrayHasKey('acl_spam_alias', MailcowRows::DEFAULT_MAILBOX_TEMPLATE);
    }

    public function test_an_unchanged_patch_records_nothing(): void
    {
        $id = $this->mailcow()->mailboxTemplate('Staff', ['quota' => self::GIB]);
        $this->asAdmin();

        $this->patchTemplate($id, ['name' => 'Staff', 'quota' => self::GIB])->assertOk();
        $this->assertSame([], $this->auditOf('mailboxTemplateUpdate'));
    }
}
