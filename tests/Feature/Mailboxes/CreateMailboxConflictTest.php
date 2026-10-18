<?php

namespace Tests\Feature\Mailboxes;

use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T038 (FR-021, AS-4): an address held by a mailbox, a resource, an alias or a temporary
 * alias cannot be given to a new mailbox; the conflict names the holder.
 */
class CreateMailboxConflictTest extends TestCase
{
    use MailboxTestHelpers;

    public function test_each_kind_of_holder_is_named(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org');
        $this->mailcow()->mailbox('room@example.org', kind: 'location');
        $this->mailcow()->alias('sales@example.org', 'anna@example.org');
        $this->mailcow()->temporaryAlias('tmp@example.org', 'anna@example.org');
        $this->asAdmin();

        foreach (['ANNA' => 'mailbox', 'room' => 'resource', 'sales' => 'alias', 'tmp' => 'temporaryAlias'] as $local => $holder) {
            $this->createMailbox(['localPart' => $local, 'domain' => 'Example.org', 'password' => '{PLAIN}x'])
                ->assertStatus(409)
                ->assertJsonPath('type', '/api/v2/problems/address-in-use')
                ->assertJsonPath('address', strtolower($local).'@example.org')
                ->assertJsonPath('holder', $holder);
        }
    }
}
