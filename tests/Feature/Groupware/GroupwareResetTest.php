<?php

namespace Tests\Feature\Groupware;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T113 (feature 005 FR-064 to FR-066, US10 AS-1 to AS-4): the ActiveSync cache and groupware
 * profile resets, exactly as legacy mailbox('delete', 'eas_cache' | 'sogo_profile') runs them.
 */
class GroupwareResetTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    // `_` would match any character in an unescaped LIKE pattern: axb@example.org must stay.
    private const MAILBOX = 'a_b@example.org';

    private const OTHER = 'axb@example.org';

    private const TABLES = ['sogo_user_profile', 'sogo_cache_folder', 'sogo_acl', 'sogo_store', 'sogo_quick_contact', 'sogo_quick_appointment', 'sogo_folder_info'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxWithDomain(self::MAILBOX);
        $this->mailcow()->mailbox(self::OTHER);
        $this->mailcow()->mailboxWithDomain('dora@example.net');
        $this->mailcow()->sogoData(self::MAILBOX);
        $this->mailcow()->sogoData(self::OTHER);
        // The other mailbox shares its calendar with this one.
        $otherCalendar = (int) DB::table('sogo_folder_info')->where('c_path2', self::OTHER)->where('c_path3', 'Calendar')->value('c_folder_id');
        DB::table('sogo_acl')->insert(['c_folder_id' => $otherCalendar, 'c_object' => '/Users/'.self::OTHER.'/Calendar/personal', 'c_uid' => self::MAILBOX, 'c_role' => 'ObjectEditor']);
    }

    /**
     * Rows per groupware table.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return array_combine(self::TABLES, array_map(fn (string $table): int => DB::table($table)->count(), self::TABLES));
    }

    private function url(string $what, string $mailbox = self::MAILBOX): string
    {
        return '/api/v2/mailboxes/'.$mailbox.'/'.$what;
    }

    public function test_the_activesync_reset_removes_only_the_cache_of_the_mailbox(): void
    {
        $this->asSelfServiceUser(self::MAILBOX);
        $before = $this->counts();

        $this->deleteJson($this->url('activesync-cache'), [], $this->csrf())->assertNoContent();

        $this->assertSame(0, DB::table('sogo_cache_folder')->where('c_uid', self::MAILBOX)->count());
        $this->assertSame(array_merge($before, ['sogo_cache_folder' => $before['sogo_cache_folder'] - 1]), $this->counts());
        $record = $this->auditRecords('activeSyncCacheReset')[0];
        $this->assertSame('success', $record->outcome);
        $this->assertSame('groupwareData', $record->target_type);
        $this->assertSame(self::MAILBOX, $record->target_id);
        $this->assertSame(['removed' => ['sogo_cache_folder' => 1]], $record->details);
    }

    public function test_the_profile_reset_removes_exactly_legacys_rows(): void
    {
        $this->asAdmin();

        $this->deleteJson($this->url('groupware-profile'), [], $this->csrf())->assertNoContent();

        foreach (['sogo_user_profile', 'sogo_cache_folder'] as $table) {
            $this->assertSame(0, DB::table($table)->where('c_uid', self::MAILBOX)->count(), $table);
            $this->assertSame(1, DB::table($table)->where('c_uid', self::OTHER)->count(), $table);
        }

        $this->assertSame(0, DB::table('sogo_folder_info')->where('c_path2', self::MAILBOX)->count());
        $this->assertSame(2, DB::table('sogo_folder_info')->where('c_path2', self::OTHER)->count());
        // The grant to the mailbox and the grants on its objects are gone; the other's own grant stays.
        $this->assertSame([['/Users/'.self::OTHER.'/Calendar/personal', 'colleague@example.net']], DB::table('sogo_acl')->get(['c_object', 'c_uid'])->map(fn (object $r): array => [$r->c_object, $r->c_uid])->all());
        $this->assertSame(['sogo_user_profile' => 1, 'sogo_cache_folder' => 1, 'sogo_acl' => 1, 'sogo_store' => 2, 'sogo_quick_contact' => 1, 'sogo_quick_appointment' => 1, 'sogo_folder_info' => 2], $this->counts());

        $this->assertSame(['removed' => [
            'sogo_user_profile' => 1,
            'sogo_cache_folder' => 1,
            'sogo_acl' => 2,
            'sogo_store' => 2,
            'sogo_quick_contact' => 1,
            'sogo_quick_appointment' => 1,
            'sogo_folder_info' => 2,
        ]], $this->auditRecords('groupwareProfileReset')[0]->details);
    }

    public function test_the_flags_are_needed(): void
    {
        DB::table('user_acl')->where('username', self::MAILBOX)->update(['eas_reset' => 0, 'sogo_profile_reset' => 0]);
        $this->asSelfServiceUser(self::MAILBOX);
        $before = $this->counts();

        $this->deleteJson($this->url('activesync-cache'), [], $this->csrf())
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['easReset']);
        $this->deleteJson($this->url('groupware-profile'), [], $this->csrf())
            ->assertStatus(403)
            ->assertJsonPath('requiredFlags', ['sogoProfileReset']);

        $this->assertSame($before, $this->counts());
        $this->assertSame(['easReset'], $this->auditRecords('activeSyncCacheReset')[0]->details['requiredFlags']);
        $this->assertSame('failure', $this->auditRecords('groupwareProfileReset')[0]->outcome);
    }

    public function test_domain_administrators_reach_their_domains(): void
    {
        $this->asDomainAdmin(['example.org']);

        $this->deleteJson($this->url('activesync-cache'), [], $this->csrf())->assertNoContent();
        $this->deleteJson($this->url('groupware-profile'), [], $this->csrf())->assertNoContent();
        $this->deleteJson($this->url('activesync-cache', 'dora@example.net'), [], $this->csrf())->assertNotFound();
        $this->deleteJson($this->url('groupware-profile', 'dora@example.net'), [], $this->csrf())->assertNotFound();
    }

    public function test_a_mailbox_user_reaches_only_the_own_mailbox(): void
    {
        $this->asSelfServiceUser('dora@example.net');

        $this->deleteJson($this->url('activesync-cache'), [], $this->csrf())->assertNotFound();
        $this->assertSame(1, DB::table('sogo_cache_folder')->where('c_uid', self::MAILBOX)->count());
    }

    public function test_without_the_groupware_both_are_refused(): void
    {
        config(['restv2.skip_sogo' => true]);
        $this->asAdmin();
        $before = $this->counts();

        $this->deleteJson($this->url('activesync-cache'), [], $this->csrf())
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/groupware-disabled');
        $this->deleteJson($this->url('groupware-profile'), [], $this->csrf())
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/groupware-disabled');

        $this->assertSame($before, $this->counts());
    }

    public function test_keys(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');

        $this->deleteJson($this->url('activesync-cache'), [], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->deleteJson($this->url('groupware-profile'), [], $this->keyHeader($this->apiKey('admin', 'readWrite')))->assertNoContent();
    }
}
