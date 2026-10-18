<?php

namespace Tests\Feature\Logs;

use Illuminate\Support\Facades\DB;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T050 (FR-022, FR-030, research R14): the login log (table `sasl_log`), with local
 * times converted to UTC and keyset paging.
 */
class LoginLogTest extends TestCase
{
    use MonitoringTestHelpers;

    private function login(string $username, string $service, string $ip, string $localTime, int $appPassword = 0): void
    {
        DB::table('sasl_log')->insert(['username' => $username, 'service' => $service, 'real_rip' => $ip, 'datetime' => $localTime, 'app_password' => $appPassword]);
    }

    public function test_local_times_are_returned_in_utc(): void
    {
        $this->login('alice@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:00', 3);
        $this->asAdmin();

        $entry = $this->getJson('/api/v2/logs/logins')->assertOk()->json('data.0');

        $this->assertSame('2026-10-18T10:00:00Z', $entry['time']);
        $this->assertSame('alice@example.org', $entry['username']);
        $this->assertSame('imap', $entry['service']);
        $this->assertSame('192.0.2.1', $entry['clientAddress']);
        $this->assertTrue($entry['appPasswordUsed']);
    }

    public function test_the_web_ui_label_follows_the_setting(): void
    {
        $this->login('alice@example.org', 'MAILCOWUI', '192.0.2.1', '2026-10-18 12:00:00');
        $this->asAdmin();

        $this->getJson('/api/v2/logs/logins')->assertJsonPath('data.0.service', 'MAILCOWUI');

        config(['restv2.sasl_log_service' => 'WEBUI']);
        $this->getJson('/api/v2/logs/logins')->assertJsonPath('data.0.service', 'WEBUI');
        $this->getJson('/api/v2/logs/logins?service=webui')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v2/logs/logins?service=mailcowui')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v2/logs/logins?q=WEBUI')->assertJsonPath('meta.total', 1);
    }

    public function test_entries_with_the_same_time_come_back_exactly_once_across_pages(): void
    {
        $this->login('a@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:00');
        $this->login('b@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:00');
        $this->login('a@example.org', 'smtp', '192.0.2.1', '2026-10-18 12:00:00');
        $this->login('a@example.org', 'imap', '192.0.2.9', '2026-10-18 12:00:00');
        $this->login('c@example.org', 'pop3', '192.0.2.5', '2026-10-18 12:00:00');
        $this->asAdmin();

        $seen = [];
        $before = null;

        for ($page = 0; $page < 3; $page++) {
            $entries = (array) $this->getJson('/api/v2/logs/logins?limit=2'.($before === null ? '' : '&before='.$before))->assertOk()->json('data');

            foreach ($entries as $entry) {
                $seen[] = $entry['service'].'|'.$entry['clientAddress'].'|'.$entry['username'];
                $before = $entry['id'];
            }
        }

        $this->assertSame([
            'smtp|192.0.2.1|a@example.org',
            'pop3|192.0.2.5|c@example.org',
            'imap|192.0.2.9|a@example.org',
            'imap|192.0.2.1|b@example.org',
            'imap|192.0.2.1|a@example.org',
        ], $seen);
    }

    public function test_the_cursor_survives_a_replaced_older_row(): void
    {
        $this->login('a@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:03');
        $this->login('b@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:02');
        $this->login('c@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:01');
        $this->asAdmin();

        $first = (array) $this->getJson('/api/v2/logs/logins?limit=1')->json('data');
        DB::statement("REPLACE INTO sasl_log (username, service, real_rip, datetime, app_password) VALUES ('c@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:09', 0)");

        $rest = array_column((array) $this->getJson('/api/v2/logs/logins?before='.$first[0]['id'])->json('data'), 'username');

        $this->assertSame(['b@example.org'], $rest, 'the replaced row moved to the newest page');
    }

    public function test_from_and_to_are_converted_from_utc(): void
    {
        $this->login('a@example.org', 'imap', '192.0.2.1', '2026-10-18 11:59:59');
        $this->login('b@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:00');
        $this->login('c@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:30');
        $this->asAdmin();

        $this->assertSame(['b@example.org'], array_column((array) $this->getJson('/api/v2/logs/logins?from=2026-10-18T10:00:00Z&to=2026-10-18T10:00:10Z')->json('data'), 'username'));
    }

    public function test_username_and_client_address_filters(): void
    {
        $this->login('alice@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:00');
        $this->login('bob@example.org', 'imap', '198.51.100.2', '2026-10-18 12:00:01');
        $this->asAdmin();

        $this->assertSame(['alice@example.org'], array_column((array) $this->getJson('/api/v2/logs/logins?username=ALICE')->json('data'), 'username'));
        $this->assertSame(['bob@example.org'], array_column((array) $this->getJson('/api/v2/logs/logins?clientAddress=198.51')->json('data'), 'username'));
        $this->assertSame(['bob@example.org', 'alice@example.org'], array_column((array) $this->getJson('/api/v2/logs/logins?username=alice&username=bob')->json('data'), 'username'));
    }

    public function test_oldest_first(): void
    {
        $this->login('a@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:00');
        $this->login('b@example.org', 'imap', '192.0.2.1', '2026-10-18 12:00:01');
        $this->asAdmin();

        $this->assertSame(['a@example.org', 'b@example.org'], array_column((array) $this->getJson('/api/v2/logs/logins?order=asc')->json('data'), 'username'));
    }
}
