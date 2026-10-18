<?php

namespace Tests\Feature\Mailboxes;

use App\Services\Mailboxes\MailboxVersion;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T010 (FR-038, research R11): the version tag covers a mailbox's stored settings, its
 * user permissions and its tags, whichever implementation changed them, and never usage figures,
 * last logins, shared-store values or the modification time.
 */
class MailboxVersionTest extends TestCase
{
    use MailboxTestHelpers;

    private function tag(string $address = 'anna@example.org'): ?string
    {
        return $this->app->make(MailboxVersion::class)->ofMailbox($address);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org', tags: ['Sales']);
    }

    public function test_the_tag_is_stable_for_unchanged_rows(): void
    {
        $this->assertNotNull($this->tag());
        $this->assertSame($this->tag(), $this->tag());
        $this->assertNull($this->tag('nobody@example.org'));
    }

    public function test_legacy_changes_of_stored_settings_change_the_tag(): void
    {
        $changes = [
            fn () => DB::table('mailbox')->where('username', 'anna@example.org')->update(['name' => 'Anna Smith']),
            fn () => DB::table('mailbox')->where('username', 'anna@example.org')->update(['quota' => 1048576]),
            fn () => DB::table('mailbox')->where('username', 'anna@example.org')->update(['active' => 2]),
            fn () => DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.imap_access', '0') WHERE `username` = ?", ['anna@example.org']),
            fn () => DB::table('mailbox')->where('username', 'anna@example.org')->update(['custom_attributes' => '{"department":"Sales"}']),
            fn () => DB::table('mailbox')->where('username', 'anna@example.org')->update(['authsource' => 'ldap']),
            fn () => DB::table('user_acl')->where('username', 'anna@example.org')->update(['syncjobs' => 1]),
            fn () => DB::table('tags_mailbox')->insert(['username' => 'anna@example.org', 'tag_name' => 'VIP']),
        ];

        $seen = [$this->tag()];

        foreach ($changes as $index => $change) {
            $change();
            $tag = $this->tag();
            $this->assertNotContains($tag, $seen, "Change {$index} kept the tag.");
            $seen[] = $tag;
        }
    }

    public function test_usage_logins_shared_store_values_and_the_modification_time_keep_the_tag(): void
    {
        $before = $this->tag();

        DB::table('quota2')->where('username', 'anna@example.org')->update(['bytes' => 123456, 'messages' => 7]);
        $this->mailcow()->saslLog('anna@example.org', 'imap', '2026-10-18 10:00:00');
        $this->redis()->hSet('RL_VALUE', 'anna@example.org', '10 / 1h');
        $this->redis()->hSet('RCPT_WANTS_SUBJECT_TAG', 'anna@example.org', '1');
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['modified' => '2026-10-18 10:00:00']);

        $this->assertSame($before, $this->tag());
    }

    public function test_tags_are_compared_without_regard_to_order(): void
    {
        $this->mailcow()->mailboxTag('anna@example.org', 'alpha');
        $before = $this->tag();

        DB::table('tags_mailbox')->where('username', 'anna@example.org')->delete();
        $this->mailcow()->mailboxTag('anna@example.org', 'alpha');
        $this->mailcow()->mailboxTag('anna@example.org', 'Sales');

        $this->assertSame($before, $this->tag());
    }

    public function test_template_tags_change_with_the_name_or_the_attributes(): void
    {
        $id = $this->mailcow()->mailboxTemplate('Small', ['quota' => 1073741824]);
        $version = $this->app->make(MailboxVersion::class);
        $before = $version->ofTemplate($id);

        DB::table('templates')->where('id', $id)->update(['modified' => '2026-10-18 10:00:00']);
        $this->assertSame($before, $version->ofTemplate($id));

        DB::table('templates')->where('id', $id)->update(['template' => 'Smaller']);
        $renamed = $version->ofTemplate($id);
        $this->assertNotSame($before, $renamed);

        DB::table('templates')->where('id', $id)->update(['attributes' => json_encode(['quota' => 2147483648])]);
        $this->assertNotSame($renamed, $version->ofTemplate($id));
    }
}
