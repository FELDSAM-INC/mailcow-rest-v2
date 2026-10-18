<?php

namespace Tests\Feature\Mailboxes;

use App\Support\Hashing\LegacyPasswordVerifier;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T035 (FR-018–FR-022, FR-027, FR-028, US2): createMailbox stores exactly the rows
 * legacy's "Add mailbox" stores, answers 201 with the location and version tag and never the
 * password, audits the creation, and refuses invalid input with every business violation at once.
 */
class CreateMailboxTest extends TestCase
{
    use MailboxTestHelpers;

    private const PASSWORD = 'Passw0rd!dev';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
    }

    public function test_a_mailbox_is_stored_exactly_as_legacy_stores_it(): void
    {
        $this->asAdmin();

        $response = $this->createMailbox(['localPart' => ' Anna.Smith ', 'domain' => 'Example.org', 'password' => self::PASSWORD])
            ->assertCreated()
            ->assertHeader('Location', '/api/v2/mailboxes/anna.smith@example.org');

        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertSame('anna.smith@example.org', $response->json('data.address'));
        $this->assertSame('anna.smith', $response->json('data.displayName'));
        $this->assertSame(3072 * self::MIB, $response->json('data.quota'), 'The domain default quota for a new mailbox.');
        $this->assertSame(0, $response->json('data.quotaUsed'));
        $this->assertStringNotContainsString('BLF-CRYPT', (string) $response->getContent());
        $this->assertStringNotContainsString(self::PASSWORD, (string) $response->getContent());

        $row = $this->mailboxRow('anna.smith@example.org');
        $this->assertNotNull($row);
        $this->assertSame(['anna.smith', 'example.org', 'anna.smith', 3072 * self::MIB, 'mailcow', 1, ''], [$row->local_part, $row->domain, $row->name, (int) $row->quota, $row->authsource, (int) $row->active, $row->kind]);
        $this->assertTrue((new LegacyPasswordVerifier)->verify((string) $row->password, self::PASSWORD));
        $this->assertSame('{}', $row->custom_attributes);

        $attributes = $this->mailboxAttributes('anna.smith@example.org');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $attributes['passwd_update']);
        unset($attributes['passwd_update']);
        $this->assertSame([
            'force_pw_update' => '0', 'force_tfa' => '0', 'tls_enforce_in' => '0', 'tls_enforce_out' => '0',
            'sogo_access' => '1', 'imap_access' => '1', 'pop3_access' => '1', 'smtp_access' => '1',
            'sieve_access' => '1', 'eas_access' => '1', 'dav_access' => '1', 'relayhost' => '0',
            'mailbox_format' => 'maildir:', 'quarantine_notification' => 'hourly', 'quarantine_category' => 'reject',
            'attribute_hash' => '',
        ], $attributes);

        foreach (['quota2', 'quota2replica'] as $table) {
            $usage = DB::table($table)->where('username', 'anna.smith@example.org')->first();
            $this->assertSame([0, 0], [(int) $usage?->bytes, (int) $usage?->messages], $table);
        }

        $alias = DB::table('alias')->where('address', 'anna.smith@example.org')->first();
        $this->assertSame(['anna.smith@example.org', 'example.org', 1], [$alias?->goto, $alias?->domain, (int) $alias?->active]);

        $acl = (array) DB::table('user_acl')->where('username', 'anna.smith@example.org')->first();
        unset($acl['username']);
        $this->assertSame([
            'spam_alias' => 1, 'tls_policy' => 1, 'spam_score' => 1, 'spam_policy' => 1, 'delimiter_action' => 1,
            'syncjobs' => 0, 'eas_reset' => 1, 'sogo_profile_reset' => 0, 'pushover' => 1, 'quarantine' => 1,
            'quarantine_attachments' => 1, 'quarantine_notification' => 1, 'quarantine_category' => 1,
            'app_passwds' => 1, 'pw_reset' => 1,
        ], array_map('intval', $acl));
    }

    public function test_the_display_name_tags_and_status_are_stored(): void
    {
        $this->asAdmin();

        $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => self::PASSWORD, 'displayName' => '<Anna Smith>', 'status' => 'loginDisabled', 'tags' => ['Sales', 'sales', ' VIP ']])
            ->assertCreated()
            ->assertJsonPath('data.displayName', 'Anna Smith')
            ->assertJsonPath('data.status', 'loginDisabled')
            ->assertJsonPath('data.tags', ['Sales', 'VIP']);

        $this->assertSame(2, (int) $this->mailboxRow('anna@example.org')?->active);
        $this->assertSame(2, (int) DB::table('alias')->where('address', 'anna@example.org')->value('active'));
        $this->assertSame(['Sales', 'VIP'], $this->mailboxTags('anna@example.org'));
    }

    public function test_the_creation_is_audited_without_the_password(): void
    {
        $this->asAdmin();

        $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => self::PASSWORD])->assertCreated();

        $records = $this->auditOf('mailboxCreation');
        $this->assertCount(1, $records);
        $record = $records[0];
        $this->assertSame(['success', 'mailbox', 'anna@example.org', 'anna'], [$record->outcome, $record->target_type, $record->target_id, $record->target_name]);
        $this->assertTrue($record->details['passwordSet']);
        $this->assertFalse($record->details['preHashed']);
        $this->assertNull($record->details['templateId']);
        $this->assertSame(['rateLimit' => 'notRequested', 'taggedMailHandling' => 'notRequested', 'groupwareDirectoryUpdate' => 'done'], $record->details['creationReport']);
        $this->assertSame(3072 * self::MIB, $record->details['values']['quota']);
        $this->assertArrayNotHasKey('password', $record->details['values']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($record->details) ?: '');
    }

    public function test_inactive_and_backup_mx_domains_accept_mailboxes(): void
    {
        $this->mailcow()->domain('inactive.example', 0);
        $this->mailcow()->domain('relay.example', attributes: ['backupmx' => 1]);
        $this->asAdmin();

        $this->createMailbox(['localPart' => 'a', 'domain' => 'inactive.example', 'password' => self::PASSWORD])->assertCreated();
        $this->createMailbox(['localPart' => 'a', 'domain' => 'relay.example', 'password' => self::PASSWORD])->assertCreated()->assertJsonPath('data.domainRelayed', true);
    }

    public function test_alias_domains_unknown_and_foreign_domains_are_refused(): void
    {
        $this->mailcow()->domain('other.example');
        $this->mailcow()->aliasDomain('alias.example', 'example.org');
        $this->asAdmin();

        $this->createMailbox(['localPart' => 'a', 'domain' => 'alias.example', 'password' => self::PASSWORD])->assertStatus(422)
            ->assertJsonPath('errors.domain.0.code', 'notEligible')
            ->assertJsonPath('errors.domain.0.params.kind', 'aliasDomain');
        $this->createMailbox(['localPart' => 'a', 'domain' => 'nowhere.example', 'password' => self::PASSWORD])->assertStatus(422)
            ->assertJsonPath('errors.domain.0.code', 'notFound');

        $this->forgetCookies();
        $this->asDomainAdmin(['example.org']);
        $this->createMailbox(['localPart' => 'a', 'domain' => 'other.example', 'password' => self::PASSWORD])->assertStatus(422)
            ->assertJsonPath('errors.domain.0.code', 'notFound');
        $this->assertSame(0, DB::table('mailbox')->count());
    }

    public function test_invalid_members_that_creation_does_not_accept_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        foreach (['recoveryEmail' => 'a@b.example', 'customAttributes' => ['a' => 'b'], 'relayhostId' => 1, 'senderPermissions' => ['*'], 'address' => 'x@example.org'] as $member => $value) {
            $this->createMailbox(['localPart' => 'a', 'domain' => 'example.org', 'password' => self::PASSWORD, $member => $value])
                ->assertStatus(422)
                ->assertJsonPath("errors.{$member}.0.code", 'immutable');
        }

        $this->assertSame(0, DB::table('mailbox')->count());
    }

    public function test_invalid_shapes_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $errors = $this->createMailbox([
            'localPart' => 5,
            'domain' => str_repeat('d', 256),
            'quota' => -1048576,
            'displayName' => str_repeat('n', 256),
            'status' => 'frozen',
            'protocolAccess' => ['pop3' => 'no', 'gopher' => true],
            'userPermissions' => ['syncjobs' => 1],
            'taggedMailHandling' => 'folder',
            'quarantineNotification' => 'monthly',
            'quarantineCategory' => 'spam',
            'rateLimit' => ['value' => 0, 'frame' => 'week'],
        ])->assertStatus(422)->json('errors');

        $this->assertSame('invalidType', $errors['localPart'][0]['code']);
        $this->assertSame('tooLong', $errors['domain'][0]['code']);
        $this->assertSame('outOfRange', $errors['quota'][0]['code']);
        $this->assertSame('tooLong', $errors['displayName'][0]['code']);
        $this->assertSame('notInList', $errors['status'][0]['code']);
        $this->assertSame('invalidType', $errors['protocolAccess.pop3'][0]['code']);
        $this->assertSame('immutable', $errors['protocolAccess.gopher'][0]['code']);
        $this->assertSame('invalidType', $errors['userPermissions.syncjobs'][0]['code']);
        $this->assertSame('notInList', $errors['taggedMailHandling'][0]['code']);
        $this->assertSame('notInList', $errors['quarantineNotification'][0]['code']);
        $this->assertSame('notInList', $errors['quarantineCategory'][0]['code']);
        $this->assertSame('outOfRange', $errors['rateLimit.value'][0]['code']);
        $this->assertSame('notInList', $errors['rateLimit.frame'][0]['code']);
    }

    public function test_invalid_business_values_are_reported_together(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $errors = $this->createMailbox([
            'localPart' => '.anna',
            'domain' => 'example.org',
            'password' => self::PASSWORD,
            'quota' => 1000,
            'tags' => ['  '],
            'templateId' => 999,
        ])->assertStatus(422)->json('errors');

        $this->assertEquals(['code' => 'invalidFormat', 'params' => ['format' => 'localPart']], array_intersect_key($errors['localPart'][0], ['code' => 1, 'params' => 1]));
        $this->assertEquals(['code' => 'notMultipleOf', 'params' => ['factor' => 1048576]], array_intersect_key($errors['quota'][0], ['code' => 1, 'params' => 1]));
        $this->assertSame('required', $errors['tags.0'][0]['code']);
        $this->assertSame('notFound', $errors['templateId'][0]['code']);
        $this->assertSame(0, DB::table('mailbox')->count());
    }
}
