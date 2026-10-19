<?php

namespace Tests\Unit\Quarantine;

use App\Auth\ApiKeyReference;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Quarantine\ReleaseMessageFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\LargeMessage;
use Tests\TestCase;

/**
 * Feature 006 T026 (research R3, FR-019, FR-020): the message a release delivers, in the
 * `original` format (only `X-Spam-Flag` header lines renamed, byte-identical otherwise) or the
 * `attachment` format (a wrapper in the acting user's language with the stored message as
 * `<qid>.eml`), and the envelope sender fallback.
 */
class ReleaseMessageFactoryTest extends TestCase
{
    private function factory(): ReleaseMessageFactory
    {
        return $this->app->make(ReleaseMessageFactory::class);
    }

    private function user(string $username = 'alice@example.org'): Principal
    {
        return new Principal($username, Role::MailboxUser, Role::MailboxUser->accountKind(), AuthMethod::Password);
    }

    public function test_original_renames_only_x_spam_flag_lines(): void
    {
        $stored = LargeMessage::fixture('spam-flag');

        $released = $this->factory()->original($stored)->toString();

        $this->assertSame(preg_replace('/^X-Spam-Flag: (.*)/m', 'X-Pre-Release-Spam-Flag: $1', $stored), $released);
        $this->assertStringContainsString("X-Pre-Release-Spam-Flag: YES\r\n", $released);
        $this->assertStringContainsString("\r\nX-Pre-Release-Spam-Flag: this body line looks like a header\r\n", $released);
        $this->assertStringContainsString("  X-Spam-Flag: indented, not renamed\r\n", $released);
        $this->assertStringContainsString("X-Spam: Yes\r\n", $released);
        $this->assertSame(strlen($stored) + 2 * strlen('Pre-Release-'), strlen($released));
    }

    public function test_attachment_wraps_the_stored_message_in_english(): void
    {
        DB::table('restv2_preferences')->insert(['account_kind' => 'mailbox', 'username' => 'alice@example.org', 'language' => 'en-gb', 'created_at' => now(), 'updated_at' => now()]);
        $stored = LargeMessage::fixture('plain');

        $message = $this->factory()->attachment($stored, '4XyZ1q2w3eR', 'sender@example.net', 'alice@example.org', $this->user());
        $raw = $message->toString();

        $this->assertSame('Potentially damaging quarantine item 4XyZ1q2w3eR', $message->getSubject());
        $this->assertSame('sender@example.net', $message->getFrom()[0]->getAddress());
        $this->assertSame('alice@example.org', $message->getTo()[0]->getAddress());
        $this->assertSame('We have attached your message as eml file to this message.', $message->getTextBody());
        $attachments = $message->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('message/rfc822', $attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype());
        $this->assertSame('4XyZ1q2w3eR.eml', $attachments[0]->getFilename());
        $this->assertSame($stored, $attachments[0]->getBody());
        $this->assertStringContainsString('4XyZ1q2w3eR.eml', $raw);
    }

    public function test_attachment_uses_the_users_language(): void
    {
        config(['restv2.vars_overrides.AVAILABLE_LANGUAGES' => json_encode(['de-de' => 'Deutsch', 'en-gb' => 'English'])]);
        DB::table('restv2_preferences')->insert(['account_kind' => 'mailbox', 'username' => 'alice@example.org', 'language' => 'de-de', 'created_at' => now(), 'updated_at' => now()]);
        $strings = json_decode((string) file_get_contents(base_path('resources/legacy/quarantine-release-strings.json')), true);

        $message = $this->factory()->attachment('Subject: x', 'QID1', 'sender@example.net', 'alice@example.org', $this->user());

        $this->assertSame(sprintf($strings['de-de']['subject'], 'QID1'), $message->getSubject());
        $this->assertSame($strings['de-de']['body'], $message->getTextBody());
    }

    public function test_an_api_key_uses_the_servers_default_language(): void
    {
        config(['restv2.vars_overrides.DEFAULT_LANG' => 'cs-cz']);
        DB::table('restv2_preferences')->insert(['account_kind' => 'admin', 'username' => 'admin', 'language' => 'de-de', 'created_at' => now(), 'updated_at' => now()]);
        $strings = json_decode((string) file_get_contents(base_path('resources/legacy/quarantine-release-strings.json')), true);
        $key = new Principal('admin', Role::Admin, Role::Admin->accountKind(), AuthMethod::ApiKey, apiKey: new ApiKeyReference('01JKEY', 'billing', 'mcv2_abcdefg', 'readWrite'));

        $message = $this->factory()->attachment('Subject: x', 'QID1', 'sender@example.net', 'alice@example.org', $key);

        $this->assertSame(sprintf($strings['cs-cz']['subject'], 'QID1'), $message->getSubject());
    }

    public function test_a_language_without_translation_falls_back_to_english(): void
    {
        $this->assertSame('Potentially damaging quarantine item Q', $this->factory()->subjectFor('xx-yy', 'Q'));
        $this->assertSame('We have attached your message as eml file to this message.', $this->factory()->bodyFor('xx-yy'));
    }

    public function test_the_envelope_sender_falls_back_for_empty_and_non_addresses(): void
    {
        $this->assertSame('sender@example.net', ReleaseMessageFactory::envelopeSender('sender@example.net'));
        $this->assertSame('sender-unknown@rspamd', ReleaseMessageFactory::envelopeSender(''));
        $this->assertSame('sender-unknown@rspamd', ReleaseMessageFactory::envelopeSender(null));
        $this->assertSame('sender-unknown@rspamd', ReleaseMessageFactory::envelopeSender('unknown'));
    }
}
