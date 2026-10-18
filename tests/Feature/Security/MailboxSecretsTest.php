<?php

namespace Tests\Feature\Security;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T087 (FR-060, SC-010): after creations with plain and pre-hashed passwords, a password
 * change, a rejected weak password, reads, a rename and a deletion, no response body, audit record or
 * log line contains any of the passwords or stored password hashes.
 */
class MailboxSecretsTest extends TestCase
{
    use MailboxTestHelpers;

    private const PLAIN = 'Pl41n-Mailbox-S3cret!';

    private const CHANGED = 'Ch4nged-Mailbox-S3cret!';

    private const WEAK = 'weakpw';

    public function test_no_password_or_hash_leaks(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('seeded@example.org', password: 'Seeded-S3cret!');
        $this->redis()->hMSet('PASSWD_POLICY', ['length' => '8']);
        $preHashed = MailcowRows::hash('SSHA512', 'Pre-Hashed-S3cret!');
        $log = storage_path('logs/testing.log');
        $offset = is_file($log) ? (int) filesize($log) : 0;
        $this->asAdmin();

        $bodies = [
            (string) $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => self::PLAIN])->assertCreated()->getContent(),
            (string) $this->createMailbox(['localPart' => 'bob', 'domain' => 'example.org', 'password' => $preHashed])->assertCreated()->getContent(),
            (string) $this->createMailbox(['localPart' => 'weak', 'domain' => 'example.org', 'password' => self::WEAK])->assertStatus(422)->getContent(),
            (string) $this->patchMailbox('anna@example.org', ['password' => self::CHANGED])->assertOk()->getContent(),
            (string) $this->patchMailbox('anna@example.org', ['password' => self::WEAK])->assertStatus(422)->getContent(),
            (string) $this->getJson('/api/v2/mailboxes')->assertOk()->getContent(),
            (string) $this->getJson('/api/v2/mailboxes/seeded@example.org/deletion-preview')->assertOk()->getContent(),
            (string) $this->postJson('/api/v2/mailboxes/anna@example.org/rename', ['newLocalPart' => 'anna2', 'keepOldAddressAsAlias' => true], $this->csrf())->assertOk()->getContent(),
            (string) $this->getJson('/api/v2/audit-events?limit=1000')->assertOk()->getContent(),
        ];
        $hashes = DB::table('mailbox')->pluck('password')->filter()->map(fn (mixed $h): string => (string) $h)->all();
        $bodies[] = (string) $this->deleteMailbox('bob@example.org')->assertNoContent()->getContent();

        $this->assertCount(3, $hashes);
        $logged = is_file($log) ? (string) file_get_contents($log, offset: $offset) : '';
        $audit = (string) json_encode(DB::table('restv2_audit_events')->get());

        foreach ([...$bodies, $logged, $audit] as $text) {
            foreach ([self::PLAIN, self::CHANGED, self::WEAK, 'Seeded-S3cret!', 'Pre-Hashed-S3cret!', $preHashed, ...$hashes] as $secret) {
                $this->assertStringNotContainsString($secret, $text);
            }

            foreach (['{BLF-CRYPT}', '{SSHA512}', '$2y$'] as $marker) {
                $this->assertStringNotContainsString($marker, $text);
            }
        }
    }
}
