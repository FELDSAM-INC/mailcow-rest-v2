<?php

namespace Tests\Feature\Database;

use Database\Seeders\DevelopmentSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * T111: the development accounts are seeded only with APP_ENV=local.
 */
class DevelopmentSeederTest extends TestCase
{
    public function test_it_refuses_outside_the_local_environment(): void
    {
        $this->expectException(RuntimeException::class);

        (new DevelopmentSeeder)->run();
    }

    public function test_it_seeds_the_quickstart_accounts_locally(): void
    {
        $this->app['env'] = 'local';

        (new DevelopmentSeeder)->run();

        $this->assertSame(['admin', 'reseller'], DB::table('admin')->orderBy('username')->pluck('username')->all());
        $this->assertSame(['example.net', 'example.org'], DB::table('domain_admins')->where('username', 'reseller')->orderBy('domain')->pluck('domain')->all());
        $this->assertSame(['alice@example.org', 'bob@example.org', 'carol@example.net', 'dave@example.org'], DB::table('mailbox')->orderBy('username')->pluck('username')->all());
        $this->assertSame('JBSWY3DPEHPK3PXP', DB::table('tfa')->where('username', 'alice@example.org')->value('secret'));
        $this->assertSame('1', json_decode((string) DB::table('mailbox')->where('username', 'bob@example.org')->value('attributes'), true)['force_pw_update']);
        $this->assertStringStartsWith('{BLF-CRYPT}', (string) DB::table('admin')->where('username', 'admin')->value('password'));

        // Feature 003 T090: the mailbox templates and limits of quickstart Part B.
        $this->assertSame(['Default', 'Small'], DB::table('templates')->where('type', 'mailbox')->orderBy('template')->pluck('template')->all());
        $small = json_decode((string) DB::table('templates')->where('type', 'mailbox')->where('template', 'Small')->value('attributes'), true);
        $this->assertSame([1073741824, 0, 1], [$small['quota'], $small['pop3_access'], $small['acl_syncjobs']]);
        $this->assertSame([10, 5120], [(int) DB::table('domain')->where('domain', 'example.org')->value('mailboxes'), (int) DB::table('domain')->where('domain', 'example.org')->value('quota')]);
        $this->assertSame(0, (int) DB::table('da_acl')->where('username', 'reseller')->value('protocol_access'));

        // Feature 008 T066: the sync jobs and permission flags of quickstart Part B.
        $jobs = DB::table('imapsync')->orderBy('id')->get();
        $this->assertSame(['alice@example.org', 'alice@example.org', 'carol@example.net'], $jobs->pluck('user2')->all());
        $this->assertNull($jobs[0]->last_run);
        $this->assertSame([0, 'EXIT_AUTHENTICATION_FAILURE_USER1'], [(int) $jobs[1]->success, $jobs[1]->exit_status]);
        $this->assertNotSame('', (string) $jobs[1]->returned_text);
        $this->assertSame([1, 0, 1], array_map(fn (string $u): int => (int) DB::table('user_acl')->where('username', $u)->value('syncjobs'), ['alice@example.org', 'dave@example.org', 'carol@example.net']));
        $this->assertSame(1, (int) DB::table('da_acl')->where('username', 'reseller')->value('syncjobs'));
    }
}
