<?php

namespace Tests\Feature\MailSettings;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T087 (feature 005 FR-058, FR-059, FR-072, US7 AS-1–AS-3): a mailbox's tagged-mail handling (in
 * mailcow's shared store) and TLS enforcement (in the mailbox), changed with the flag of each.
 */
class MailboxMailSettingsTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/mailboxes/anna@example.org/mail-settings';

    public function test_the_read_reflects_the_stored_values(): void
    {
        $this->asSelfServiceUser();
        $this->redis()->hSet('RCPT_WANTS_SUBFOLDER_TAG', 'anna@example.org', '1');
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['attributes' => DB::raw("JSON_SET(`attributes`, '$.tls_enforce_in', 1, '$.tls_enforce_out', true)")]);

        $response = $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data', ['mailbox' => 'anna@example.org', 'taggedMailHandling' => 'subfolder', 'tlsEnforceInbound' => true, 'tlsEnforceOutbound' => true, 'etag' => $this->getJson(self::URL)->json('data.etag')]);
        $response->assertHeader('ETag', (string) $response->json('data.etag'));

        $this->redis()->hSet('RCPT_WANTS_SUBJECT_TAG', 'anna@example.org', '1');
        $this->getJson(self::URL)->assertJsonPath('data.taggedMailHandling', 'subject');
    }

    public function test_changes_are_stored_where_legacy_stores_them(): void
    {
        $this->asSelfServiceUser();
        $this->redis()->hSet('RCPT_WANTS_SUBJECT_TAG', 'anna@example.org', '1');

        $this->patchMerge(self::URL, ['taggedMailHandling' => 'subfolder', 'tlsEnforceInbound' => true], $this->csrf())
            ->assertOk()
            ->assertJsonPath('data.taggedMailHandling', 'subfolder')
            ->assertJsonPath('data.tlsEnforceInbound', true);

        $this->assertSame('1', $this->redis()->hGet('RCPT_WANTS_SUBFOLDER_TAG', 'anna@example.org'));
        $this->assertFalse($this->redis()->hExists('RCPT_WANTS_SUBJECT_TAG', 'anna@example.org'));
        $attributes = $this->mailboxAttributes('anna@example.org');
        $this->assertSame([1, 0], [$attributes['tls_enforce_in'], $attributes['tls_enforce_out']]);
        $this->assertSame(['taggedMailHandling' => ['old' => 'subject', 'new' => 'subfolder'], 'tlsEnforceInbound' => ['old' => false, 'new' => true]], $this->onlyAuditRecord('mailSettingsUpdate')->details['changes']);

        $this->patchMerge(self::URL, ['taggedMailHandling' => 'none'], $this->csrf())->assertOk();
        $this->assertFalse($this->redis()->hExists('RCPT_WANTS_SUBFOLDER_TAG', 'anna@example.org'));
    }

    public function test_the_tag_covers_only_the_three_settings(): void
    {
        $this->asSelfServiceUser();
        $before = $this->getJson(self::URL)->json('data.etag');

        DB::table('mailbox')->where('username', 'anna@example.org')->update(['quota' => 5 * 1048576]);
        $this->assertSame($before, $this->getJson(self::URL)->json('data.etag'));

        $this->redis()->hSet('RCPT_WANTS_SUBJECT_TAG', 'anna@example.org', '1');
        $this->assertNotSame($before, $this->getJson(self::URL)->json('data.etag'));
    }

    public function test_flags_gate_changes_but_not_unchanged_values(): void
    {
        $this->asSelfServiceUser('anna@example.org', userAcl: ['tls_policy' => 0, 'delimiter_action' => 1]);

        $this->patchMerge(self::URL, ['tlsEnforceInbound' => true], $this->csrf())
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['tlsPolicy']);
        $this->patchMerge(self::URL, ['tlsEnforceInbound' => false, 'tlsEnforceOutbound' => false, 'taggedMailHandling' => 'subject'], $this->csrf())->assertOk();

        DB::table('user_acl')->where('username', 'anna@example.org')->update(['delimiter_action' => 0]);
        $this->patchMerge(self::URL, ['taggedMailHandling' => 'none'], $this->csrf())->assertStatus(403)->assertJsonPath('requiredFlags', ['delimiterAction']);
        $this->patchMerge(self::URL, ['taggedMailHandling' => 'subject'], $this->csrf())->assertOk();
    }

    public function test_without_the_shared_store_nothing_changes(): void
    {
        $this->asSelfServiceUser();
        $headers = $this->csrf();
        $this->breakSharedStore();

        $this->patchMerge(self::URL, ['taggedMailHandling' => 'subfolder', 'tlsEnforceInbound' => true], $headers)
            ->assertStatus(503)
            ->assertJsonPath('dependency', 'sharedStore');

        $this->assertSame('0', $this->mailboxAttributes('anna@example.org')['tls_enforce_in']);
    }

    public function test_an_outdated_tag_is_refused(): void
    {
        $this->asSelfServiceUser();
        $tag = (string) $this->getJson(self::URL)->json('data.etag');
        $this->redis()->hSet('RCPT_WANTS_SUBJECT_TAG', 'anna@example.org', '1');

        $this->patchMerge(self::URL, ['tlsEnforceInbound' => true], $this->csrf() + ['If-Match' => $tag])->assertStatus(412);
    }

    public function test_scope_and_keys(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailboxWithDomain('dora@example.net');
        $this->asDomainAdmin(['example.org']);
        $this->patchMerge(self::URL, ['tlsEnforceOutbound' => true], $this->csrf())->assertOk();
        $this->getJson('/api/v2/mailboxes/dora@example.net/mail-settings')->assertNotFound();

        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->getJson('/api/v2/mailboxes/dora@example.net/mail-settings')->assertNotFound();

        $this->forgetCookies();
        $readOnly = $this->keyHeader($this->apiKey('reseller', 'readOnly'));
        $this->getJson(self::URL, $readOnly)->assertOk();
        $this->patchMerge(self::URL, ['tlsEnforceOutbound' => false], $readOnly)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
    }
}
