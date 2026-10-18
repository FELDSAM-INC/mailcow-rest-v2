<?php

namespace Tests\Feature\SenderSettings;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T057 (FR-054, FR-055, FR-057, US5): updateMailboxSenderSettings.
 */
class UpdateMailboxSenderSettingsTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('other.example');
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $f->aliasDomain('foreign.net', 'foreign.example');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@example.org');
        $f->alias('sales@example.org', 'anna@example.org');
        $f->alias('info@example.org', 'anna@example.org', senderAllowed: 0);
        $f->alias('shared@example.org', 'bob@example.org');
        $f->alias('x@foreign.example', 'bob@example.org');
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function patchSettings(array $patch, array $headers = [], string $mailbox = 'anna@example.org'): TestResponse
    {
        return $this->patchMerge("/api/v2/mailboxes/{$mailbox}/sender-settings", $patch, $headers + $this->csrf());
    }

    public function test_any_address_is_stored_alone(): void
    {
        $this->asAdmin();

        $this->patchSettings(['explicitPermissions' => ['@example.org', '*']])->assertOk()->assertJsonPath('data.senderCheckDisabled', true);

        $this->assertSame(['anna@example.org *'], $this->senderAcls());
    }

    public function test_a_domain_administrator_keeps_the_entries_outside_their_scope(): void
    {
        $this->mailcow()->senderAcl('anna@example.org', '@other.example');
        $this->asDomainAdmin(['example.org']);

        $this->patchSettings(['explicitPermissions' => ['info@example.org']])->assertOk();
        $this->assertSame(['anna@example.org @other.example', 'anna@example.org info@example.org'], $this->senderAcls());

        DB::table('sender_acl')->delete();
        $this->mailcow()->senderAcl('anna@example.org', '@other.example');
        $this->patchSettings(['explicitPermissions' => ['@other.example', 'shared@example.org', '@example.net']])->assertOk();
        $this->assertSame(['anna@example.org @example.net', 'anna@example.org @other.example', 'anna@example.org shared@example.org'], $this->senderAcls());
    }

    public function test_a_domain_administrator_cannot_add_foreign_entries_or_any_address(): void
    {
        $this->asDomainAdmin(['example.org']);

        $errors = $this->patchSettings(['explicitPermissions' => ['@foreign.example', '*', 'x@foreign.example', 'shared@example.org', '@foreign.net', 'not valid']])->assertStatus(422)->json('errors');

        $this->assertSame(['notFound', ['resource' => 'domain']], [$errors['explicitPermissions.0'][0]['code'], $errors['explicitPermissions.0'][0]['params']]);
        $this->assertSame(['notEligible', ['allowedRoles' => ['admin']]], [$errors['explicitPermissions.1'][0]['code'], $errors['explicitPermissions.1'][0]['params']]);
        $this->assertSame(['notFound', ['resource' => 'address']], [$errors['explicitPermissions.2'][0]['code'], $errors['explicitPermissions.2'][0]['params']]);
        $this->assertArrayNotHasKey('explicitPermissions.3', $errors);
        $this->assertSame('notFound', $errors['explicitPermissions.4'][0]['code']);
        $this->assertSame(['invalidFormat', ['format' => 'senderPermission']], [$errors['explicitPermissions.5'][0]['code'], $errors['explicitPermissions.5'][0]['params']]);
        $this->assertSame([], $this->senderAcls());
    }

    public function test_external_sender_addresses_need_the_extend_sender_acl_flag_unless_unchanged(): void
    {
        $this->mailcow()->senderAcl('anna@example.org', 'news@mailer.example', 1);
        $this->mailcow()->senderAcl('anna@example.org', '@partner.example', 1);
        $this->asDomainAdmin(['example.org'], 'reseller', ['extend_sender_acl' => 0]);

        $this->patchSettings(['externalSenderAddresses' => ['other@mailer.example']])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/attribute-not-permitted')
            ->assertJsonPath('attributes', ['externalSenderAddresses'])
            ->assertJsonPath('requiredFlags', ['externalSenderAddresses' => 'extendSenderAcl']);
        $this->assertSame('attributeNotPermitted', $this->auditOf('mailboxSenderSettingsUpdate')[0]->failure_reason);

        $this->patchSettings(['externalSenderAddresses' => ['partner.example', 'NEWS@mailer.example'], 'explicitPermissions' => ['shared@example.org']])->assertOk();
        $this->assertContains('anna@example.org shared@example.org', $this->senderAcls());
    }

    public function test_external_sender_addresses_are_stored_and_become_groupware_identities(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['extend_sender_acl' => 1]);

        $this->patchSettings(['externalSenderAddresses' => ['news@mailer.example', 'partner.example', '@Partner.Example']])->assertOk()
            ->assertJsonPath('data.externalSenderAddresses', ['@partner.example', 'news@mailer.example']);

        $this->assertSame(['anna@example.org @partner.example external', 'anna@example.org news@mailer.example external'], $this->senderAcls());
        $this->assertSame('news@mailer.example', $this->viewRow('anna@example.org')?->ext_acl);
        $this->assertSame(1, $this->memcached()->flushCount());
        $this->assertSame('done', $this->auditOf('mailboxSenderSettingsUpdate')[0]->details['groupwareUpdate']);
    }

    public function test_external_entries_in_domains_of_this_server_are_refused(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['extend_sender_acl' => 1]);

        $errors = $this->patchSettings(['externalSenderAddresses' => ['a@example.net', '@foreign.example', 'foreign.net', 'not valid', 'ok@mailer.example']])->assertStatus(422)->json('errors');

        foreach ([0, 1, 2] as $i) {
            $this->assertSame(['notEligible', ['kind' => 'localDomain']], [$errors["externalSenderAddresses.{$i}"][0]['code'], $errors["externalSenderAddresses.{$i}"][0]['params']]);
        }
        $this->assertSame(['invalidFormat', ['format' => 'emailOrDomain']], [$errors['externalSenderAddresses.3'][0]['code'], $errors['externalSenderAddresses.3'][0]['params']]);
        $this->assertArrayNotHasKey('externalSenderAddresses.4', $errors);
    }

    public function test_an_alias_that_already_lets_the_mailbox_send_is_accepted_but_not_stored(): void
    {
        $this->asAdmin();

        $this->patchSettings(['explicitPermissions' => ['info@example.org', 'sales@example.org', 'anna@example.org']])->assertOk();

        $this->assertSame(['anna@example.org info@example.org'], $this->senderAcls());
    }

    public function test_replacement_rules(): void
    {
        $f = $this->mailcow();
        $f->senderAcl('anna@example.org', '@gone.example');
        $f->senderAcl('anna@example.org', 'news@mailer.example', 1);
        $this->asAdmin();

        $this->patchSettings(['explicitPermissions' => ['@gone.example', 'shared@example.org', 'SHARED@example.org']])->assertOk();
        $this->assertSame(['anna@example.org @gone.example', 'anna@example.org news@mailer.example external', 'anna@example.org shared@example.org'], $this->senderAcls());

        $this->patchSettings(['explicitPermissions' => ['shared@example.org']])->assertOk();
        $this->assertSame(['anna@example.org news@mailer.example external', 'anna@example.org shared@example.org'], $this->senderAcls());

        $record = collect($this->auditOf('mailboxSenderSettingsUpdate'))->last();
        $this->assertSame(['explicitPermissions' => ['old' => ['@gone.example', 'shared@example.org'], 'new' => ['shared@example.org']]], $record->details['changes']);

        $this->patchSettings(['explicitPermissions' => []], ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->assertCount(2, $this->senderAcls());
    }
}
