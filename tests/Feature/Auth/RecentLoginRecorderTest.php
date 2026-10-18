<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\RecentLoginRecorder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T035 (FR-027, R16): a mailbox user's UI sign-in is a recent login in mailcow's sasl_log, written
 * like legacy's REPLACE INTO.
 */
class RecentLoginRecorderTest extends TestCase
{
    public function test_it_replaces_the_previous_entry_and_refreshes_the_time(): void
    {
        DB::table('sasl_log')->insert([
            ['service' => 'MAILCOWUI', 'real_rip' => '192.0.2.1', 'username' => 'alice@example.org', 'app_password' => 1, 'datetime' => '2020-01-01 00:00:00'],
            ['service' => 'imap', 'real_rip' => '192.0.2.1', 'username' => 'alice@example.org', 'app_password' => 0, 'datetime' => '2020-01-01 00:00:00'],
        ]);

        $this->app->make(RecentLoginRecorder::class)->record('alice@example.org', '192.0.2.1');

        $rows = DB::table('sasl_log')->where('username', 'alice@example.org')->where('service', 'MAILCOWUI')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('192.0.2.1', $rows[0]->real_rip);
        $this->assertSame(0, (int) $rows[0]->app_password);
        $this->assertGreaterThan('2020-01-01 00:00:00', $rows[0]->datetime);

        $other = DB::table('sasl_log')->where('service', 'imap')->sole();
        $this->assertSame('2020-01-01 00:00:00', $other->datetime);
    }

    public function test_one_entry_per_address_and_the_configured_service_label(): void
    {
        config(['restv2.sasl_log_service' => 'WEBUI']);
        $recorder = $this->app->make(RecentLoginRecorder::class);

        $recorder->record('alice@example.org', '192.0.2.1');
        $recorder->record('alice@example.org', '192.0.2.1');
        $recorder->record('alice@example.org', '2001:db8::1');

        $this->assertSame(2, DB::table('sasl_log')->where('username', 'alice@example.org')->where('service', 'WEBUI')->count());
    }
}
