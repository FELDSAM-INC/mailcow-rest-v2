<?php

namespace Tests\Feature\Groupware;

use App\Clients\Memcached\MemcachedFlusher;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\SogoDirectory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

/**
 * Feature 002 T013 (research D13, FR-023, FR-034, FR-036): the full rebuild of
 * `_sogo_static_view` as legacy update_sogo_static_view() does it, followed by a memcached flush.
 */
class SogoDirectoryTest extends TestCase
{
    private function seedMailboxes(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->aliasDomain('alias.example', 'example.org');
        $f->mailbox('alice@example.org');
        DB::table('mailbox')->where('username', 'alice@example.org')->update(['name' => 'Alice Example']);
        $f->mailbox('bob@example.org', active: 0);
        $f->mailbox('room@example.org', kind: 'location');
        DB::table('mailbox')->where('username', 'room@example.org')->update(['multiple_bookings' => 2]);
        $f->alias('info@example.org', 'alice@example.org');
        $f->alias('sales@example.org', 'alice@example.org');
        $f->senderAcl('alice@example.org', 'alice@external.example', 1);
        DB::table('_sogo_static_view')->insert([
            'c_uid' => 'gone@example.org', 'domain' => 'example.org', 'c_name' => 'gone@example.org',
            'c_password' => '', 'mail' => 'gone@example.org', 'aliases' => '',
        ]);
    }

    public function test_the_view_holds_one_row_per_active_mailbox_as_legacy_writes_it(): void
    {
        $this->seedMailboxes();

        $this->assertSame(GroupwareOutcome::Done, $this->app->make(SogoDirectory::class)->rebuild());

        $rows = DB::table('_sogo_static_view')->orderBy('c_uid')->get()->keyBy('c_uid');
        $this->assertSame(['alice@example.org', 'room@example.org'], $rows->keys()->all());

        $alice = $rows['alice@example.org'];
        $this->assertSame('example.org', $alice->domain);
        $this->assertSame('alice@example.org', $alice->c_name);
        $this->assertSame('Alice Example', $alice->c_cn);
        $this->assertSame('alice@example.org', $alice->mail);
        $this->assertSame('info@example.org sales@example.org', $alice->aliases);
        $this->assertSame('alice@alias.example', $alice->ad_aliases);
        $this->assertSame('alice@external.example', $alice->ext_acl);
        $this->assertSame('', $alice->kind);
        $this->assertSame(-1, (int) $alice->multiple_bookings);
        $this->assertStringStartsWith('{SSHA256}', $alice->c_password);

        $this->assertSame('location', $rows['room@example.org']->kind);
        $this->assertSame(2, (int) $rows['room@example.org']->multiple_bookings);
        $this->assertSame(1, $this->memcached()->flushCount());
    }

    public function test_every_rebuild_writes_a_fresh_placeholder_password(): void
    {
        $this->seedMailboxes();
        $directory = $this->app->make(SogoDirectory::class);

        $directory->rebuild();
        $first = DB::table('_sogo_static_view')->where('c_uid', 'alice@example.org')->value('c_password');
        $directory->rebuild();
        $second = DB::table('_sogo_static_view')->where('c_uid', 'alice@example.org')->value('c_password');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, $this->memcached()->flushCount());
    }

    public function test_nothing_happens_when_the_groupware_is_disabled(): void
    {
        $this->seedMailboxes();
        config(['restv2.skip_sogo' => true]);

        $this->assertSame(GroupwareOutcome::GroupwareDisabled, $this->app->make(SogoDirectory::class)->rebuild());

        $this->assertSame(['gone@example.org'], DB::table('_sogo_static_view')->pluck('c_uid')->all());
        $this->assertSame(0, $this->memcached()->flushCount());
    }

    public function test_a_database_error_is_logged_and_reported_as_failed(): void
    {
        $log = Log::spy();
        $directory = new class($this->app->make(MemcachedFlusher::class)) extends SogoDirectory
        {
            protected function writeView(string $placeholderHash): void
            {
                throw new QueryException('mysql', 'INSERT INTO _sogo_static_view', [], new PDOException('boom'));
            }
        };

        $this->assertSame(GroupwareOutcome::Failed, $directory->rebuild());
        $log->shouldHaveReceived('error')->once();
        $this->assertSame(0, $this->memcached()->flushCount());
    }
}
