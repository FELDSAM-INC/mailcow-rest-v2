<?php

namespace Tests\Feature\Groupware;

use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\SogoDirectory;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\TestCase;

/**
 * Feature 003 T011 (research R14): refresh() is legacy update_sogo_static_view($mailbox) for given
 * mailboxes: an active mailbox's row is upserted with legacy's statement, the rows of other
 * mailboxes are removed, orphans are cleaned up, and nothing happens when the groupware is disabled.
 */
class SogoDirectoryRefreshTest extends TestCase
{
    private function directory(): SogoDirectory
    {
        return $this->app->make(SogoDirectory::class);
    }

    /**
     * @phpstan-impure
     */
    private function row(string $username): ?stdClass
    {
        $row = DB::table('_sogo_static_view')->where('c_uid', $username)->first();

        return $row instanceof stdClass ? $row : null;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org', name: 'Anna');
        $this->mailcow()->legacyMailbox('bob@example.org');
        $this->mailcow()->alias('sales@example.org', 'anna@example.org,bob@example.org');
    }

    public function test_an_active_mailbox_is_upserted_with_legacys_columns(): void
    {
        $this->assertSame(GroupwareOutcome::Done, $this->directory()->refresh(['anna@example.org']));

        $row = $this->row('anna@example.org');
        $this->assertNotNull($row);
        $this->assertSame('example.org', $row->domain);
        $this->assertSame('anna@example.org', $row->c_name);
        $this->assertSame('Anna', $row->c_cn);
        $this->assertSame('anna@example.org', $row->mail);
        $this->assertStringContainsString('sales@example.org', (string) $row->aliases);
        $this->assertStringStartsWith('{SSHA256}', (string) $row->c_password);
        $this->assertNull($this->row('bob@example.org'), 'Only the given mailbox is written.');

        $first = (string) $row->c_password;
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['name' => 'Anna Smith']);
        $this->directory()->refresh(['anna@example.org']);

        $this->assertSame('Anna Smith', $this->row('anna@example.org')?->c_cn);
        $this->assertNotSame($first, $this->row('anna@example.org')?->c_password, 'Each write gets a fresh placeholder.');
    }

    public function test_inactive_and_deleted_mailboxes_lose_their_rows_and_orphans_are_removed(): void
    {
        $this->directory()->refresh(['anna@example.org', 'bob@example.org']);
        DB::table('_sogo_static_view')->insert(['c_uid' => 'ghost@example.org', 'domain' => 'example.org', 'c_name' => 'ghost@example.org', 'mail' => 'ghost@example.org', 'aliases' => '']);

        DB::table('mailbox')->where('username', 'anna@example.org')->update(['active' => 2]);
        DB::table('mailbox')->where('username', 'bob@example.org')->delete();
        $this->directory()->refresh(['anna@example.org', 'bob@example.org']);

        $this->assertNull($this->row('anna@example.org'));
        $this->assertNull($this->row('bob@example.org'));
        $this->assertNull($this->row('ghost@example.org'));
    }

    public function test_nothing_happens_when_the_groupware_is_disabled(): void
    {
        config(['restv2.skip_sogo' => true]);

        $this->assertSame(GroupwareOutcome::GroupwareDisabled, $this->directory()->refresh(['anna@example.org']));
        $this->assertNull($this->row('anna@example.org'));
    }

    public function test_the_cache_is_not_flushed_by_a_refresh(): void
    {
        $this->directory()->refresh(['anna@example.org']);

        $this->assertSame(0, $this->memcached()->flushCount());
    }
}
