<?php

namespace Tests\Feature\SenderSettings;

use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T056 (FR-053, US5): getMailboxSenderSettings.
 */
class GetMailboxSenderSettingsTest extends TestCase
{
    use AliasTestHelpers;

    private int $sales = 0;

    private int $info = 0;

    private int $catchAll = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('example.com');
        $f->domain('other.example');
        $f->aliasDomain('example.net', 'example.org');
        $f->aliasDomain('xn--bcher-kva.example', 'example.org');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@example.org');
        $this->sales = $f->alias('sales@example.org', 'anna@example.org,bob@example.org');
        $this->info = $f->alias('info@example.org', 'anna@example.org', senderAllowed: 0);
        $this->catchAll = $f->alias('@example.org', 'anna@example.org');
        $f->alias('hanna@example.org', 'hanna@example.org,xanna@example.org');
        $f->senderAcl('anna@example.org', '@example.com');
        $f->senderAcl('anna@example.org', '@other.example');
        $f->senderAcl('anna@example.org', 'bob@example.org');
        $f->senderAcl('anna@example.org', '@gone.example');
        $f->senderAcl('anna@example.org', 'old@example.org');
        $f->senderAcl('anna@example.org', 'news@mailer.example', 1);
        $f->senderAcl('anna@example.org', '@partner.example', 1);
        $f->senderAcl('bob@example.org', '*');
    }

    public function test_an_administrator_reads_every_sender_address_and_why(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/mailboxes/Anna@Example.org/sender-settings')->assertOk();
        $data = (array) $response->json('data');

        $this->assertSame('anna@example.org', $data['mailbox']);
        $this->assertSame($response->headers->get('ETag'), $data['etag']);
        $this->assertSame([
            ['address' => 'anna@example.org', 'addressUnicode' => 'anna@example.org', 'source' => 'ownAddress', 'aliasId' => null, 'senderAllowed' => true, 'catchAll' => false],
            ['address' => 'anna@example.net', 'addressUnicode' => 'anna@example.net', 'source' => 'aliasDomainAddress', 'aliasId' => null, 'senderAllowed' => true, 'catchAll' => false],
            ['address' => 'anna@xn--bcher-kva.example', 'addressUnicode' => 'anna@bücher.example', 'source' => 'aliasDomainAddress', 'aliasId' => null, 'senderAllowed' => true, 'catchAll' => false],
            ['address' => '@example.org', 'addressUnicode' => '@example.org', 'source' => 'alias', 'aliasId' => $this->catchAll, 'senderAllowed' => true, 'catchAll' => true],
            ['address' => 'info@example.org', 'addressUnicode' => 'info@example.org', 'source' => 'alias', 'aliasId' => $this->info, 'senderAllowed' => false, 'catchAll' => false],
            ['address' => 'sales@example.org', 'addressUnicode' => 'sales@example.org', 'source' => 'alias', 'aliasId' => $this->sales, 'senderAllowed' => true, 'catchAll' => false],
        ], $data['implicitAddresses']);

        $permissions = collect((array) $data['explicitPermissions'])->keyBy('value');
        $this->assertSame(['@example.com', '@gone.example', '@other.example', 'bob@example.org', 'old@example.org'], $permissions->keys()->all());
        $this->assertSame(['value' => '@example.com', 'valueUnicode' => '@example.com', 'type' => 'domain', 'kind' => 'domain', 'changeable' => true, 'stale' => false], $permissions['@example.com']);
        $this->assertTrue($permissions['@gone.example']['stale']);
        $this->assertNull($permissions['@gone.example']['kind']);
        $this->assertSame('mailbox', $permissions['bob@example.org']['kind']);
        $this->assertTrue($permissions['old@example.org']['stale']);
        $this->assertFalse($data['senderCheckDisabled']);
        $this->assertSame(['@partner.example', 'news@mailer.example'], $data['externalSenderAddresses']);
        $this->assertSame(['@partner.example', 'news@mailer.example'], $data['externalSenderAddressesUnicode']);
    }

    public function test_entries_outside_a_domain_administrators_scope_are_not_changeable(): void
    {
        $this->asDomainAdmin(['example.org']);

        $permissions = collect((array) $this->getJson('/api/v2/mailboxes/anna@example.org/sender-settings')->assertOk()->json('data.explicitPermissions'))->keyBy('value');

        $this->assertFalse($permissions['@example.com']['changeable']);
        $this->assertFalse($permissions['@other.example']['changeable']);
        $this->assertTrue($permissions['bob@example.org']['changeable']);

        $bob = (array) $this->getJson('/api/v2/mailboxes/bob@example.org/sender-settings')->json('data');
        $this->assertTrue($bob['senderCheckDisabled']);
        $this->assertSame([['value' => '*', 'valueUnicode' => '*', 'type' => 'anyAddress', 'kind' => 'anyAddress', 'changeable' => false, 'stale' => false]], $bob['explicitPermissions']);
    }

    public function test_only_mailboxes_in_scope_have_sender_settings(): void
    {
        $f = $this->mailcow();
        $f->resource('Room1@example.org', 'Room 1');
        $this->mailbox('carl@example.com');
        $this->asDomainAdmin(['example.org']);

        $this->getJson('/api/v2/mailboxes/Room1@example.org/sender-settings')->assertNotFound();
        $this->getJson('/api/v2/mailboxes/sales@example.org/sender-settings')->assertNotFound();
        $this->getJson('/api/v2/mailboxes/nobody@example.org/sender-settings')->assertNotFound();
        $this->getJson('/api/v2/mailboxes/carl@example.com/sender-settings')->assertNotFound();
    }
}
