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
        $this->assertSame(['alice@example.org', 'bob@example.org'], DB::table('mailbox')->orderBy('username')->pluck('username')->all());
        $this->assertSame('JBSWY3DPEHPK3PXP', DB::table('tfa')->where('username', 'alice@example.org')->value('secret'));
        $this->assertSame('1', json_decode((string) DB::table('mailbox')->where('username', 'bob@example.org')->value('attributes'), true)['force_pw_update']);
        $this->assertStringStartsWith('{BLF-CRYPT}', (string) DB::table('admin')->where('username', 'admin')->value('password'));
    }
}
