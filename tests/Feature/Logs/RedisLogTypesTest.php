<?php

namespace Tests\Feature\Logs;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T046 (FR-022, FR-023, FR-025, FR-031, FR-032, research R12): the ten logs kept in
 * mailcow's shared store, written in their producers' exact formats.
 */
class RedisLogTypesTest extends TestCase
{
    use MonitoringTestHelpers;

    private const T = 1760781600;

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(string $path, string $query = ''): array
    {
        return (array) $this->getJson("/api/v2/logs/{$path}".($query === '' ? '' : "?{$query}"))->assertOk()->json('data');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function syslogTypes(): array
    {
        return ['postfix' => ['postfix', 'POSTFIX_MAILLOG'], 'dovecot' => ['dovecot', 'DOVECOT_MAILLOG'], 'sogo' => ['sogo', 'SOGO_LOG']];
    }

    #[DataProvider('syslogTypes')]
    public function test_syslog_entries(string $path, string $key): void
    {
        MailcowLogs::syslog($key, self::T, 'warning', 'postfix/smtp', 'connect to mx.partner.example timed out');
        MailcowLogs::syslog($key, self::T + 1, 'err', 'postfix/smtpd', 'fatal error');
        $this->asAdmin();

        $entries = $this->entries($path);

        $this->assertCount(2, $entries);
        $this->assertSame('2025-10-18T10:00:01Z', $entries[0]['time']);
        $this->assertSame('err', $entries[0]['priority']);
        $this->assertSame('error', $entries[0]['severity']);
        $this->assertSame('postfix/smtpd', $entries[0]['program']);
        $this->assertSame('fatal error', $entries[0]['message']);
        $this->assertSame('warning', $entries[1]['severity']);
        $this->assertMatchesRegularExpression('/^1760781601\.[0-9a-f]{16}\.0$/', $entries[0]['id']);
    }

    public function test_priority_and_program_filters_are_substrings_with_or_across_values(): void
    {
        MailcowLogs::syslog('POSTFIX_MAILLOG', self::T, 'info', 'postfix/smtpd', 'a');
        MailcowLogs::syslog('POSTFIX_MAILLOG', self::T, 'info', 'postfix/cleanup', 'b');
        MailcowLogs::syslog('POSTFIX_MAILLOG', self::T, 'warning', 'postfix/qmgr', 'c');
        MailcowLogs::syslog('POSTFIX_MAILLOG', self::T, 'notice', 'dovecot', 'd');
        $this->asAdmin();

        $this->assertSame(['c', 'b', 'a'], array_column($this->entries('postfix', 'program=POSTFIX/'), 'message'));
        $this->assertSame(['c', 'a'], array_column($this->entries('postfix', 'program=smtpd&program=QMGR'), 'message'));
        $this->assertSame(['c'], array_column($this->entries('postfix', 'priority=warn'), 'message'));
    }

    public function test_netfilter_entries(): void
    {
        MailcowLogs::netfilter(self::T, 'warn', '203.0.113.5 matched rule id 3');
        MailcowLogs::netfilter(self::T + 1, 'crit', 'Banning 203.0.113.0/24 for 30 minutes');
        $this->asAdmin();

        $entries = $this->entries('netfilter');

        $this->assertSame(['crit', 'warn'], array_column($entries, 'priority'));
        $this->assertSame(['error', 'warning'], array_column($entries, 'severity'));
        $this->assertSame(['id', 'time', 'priority', 'severity', 'message'], array_keys($entries[0]));
        $this->assertSame(['warn'], array_column($this->entries('netfilter', 'severity=warning'), 'priority'));
    }

    public function test_autodiscover_entries_and_filters(): void
    {
        MailcowLogs::autodiscover(self::T, 'Microsoft Office/16.0', 'alice@example.org', '192.0.2.10', 'activesync');
        MailcowLogs::autodiscover(self::T + 1, null, 'none', '192.0.2.11', 'Error: invalid or missing request data');
        $this->asAdmin();

        $entries = $this->entries('autodiscover');

        $this->assertSame(['id', 'time', 'userAgent', 'user', 'clientAddress', 'service'], array_keys($entries[0]));
        $this->assertNull($entries[0]['userAgent']);
        $this->assertSame('none', $entries[0]['user']);
        $this->assertSame('Microsoft Office/16.0', $entries[1]['userAgent']);
        $this->assertSame(['alice@example.org'], array_column($this->entries('autodiscover', 'user=ALICE'), 'user'));
        $this->assertSame(['activesync'], array_column($this->entries('autodiscover', 'service=sync&service=nothing'), 'service'));
    }

    public function test_watchdog_entries_convert_text_numbers(): void
    {
        MailcowLogs::watchdogHealth(self::T, 'Postfix', 83, 5, 6, -1);
        MailcowLogs::watchdogMessage(self::T + 1, 'Dovecot hit error limit');
        $this->asAdmin();

        $entries = $this->entries('watchdog');

        $this->assertSame(['service' => null, 'healthLevelPercent' => null, 'healthPointsNow' => null, 'healthPointsTotal' => null, 'healthPointsChange' => null, 'message' => 'Dovecot hit error limit'], array_slice($entries[0], 2));
        $this->assertSame(['service' => 'Postfix', 'healthLevelPercent' => 83, 'healthPointsNow' => 5, 'healthPointsTotal' => 6, 'healthPointsChange' => -1, 'message' => null], array_slice($entries[1], 2));
        $this->assertSame(['Postfix'], array_column($this->entries('watchdog', 'service=post'), 'service'));
    }

    public function test_acme_messages_are_decoded_or_stripped(): void
    {
        MailcowLogs::acme(self::T, 'base64,'.base64_encode('mail.example.org - Certificate renewed'));
        MailcowLogs::acme(self::T + 1, 'base64,mail.example.org - Renewal failed: rate limited');
        MailcowLogs::acme(self::T + 2, 'mail.example.org - Checking certificates');
        $this->asAdmin();

        $this->assertSame([
            'mail.example.org - Checking certificates',
            'mail.example.org - Renewal failed: rate limited',
            'mail.example.org - Certificate renewed',
        ], array_column($this->entries('acme'), 'message'));
    }

    public function test_cron_entries_and_filters(): void
    {
        MailcowLogs::cron(self::T, 'info', 'Keycloak Sync', 'Synced 12 users');
        MailcowLogs::cron(self::T + 1, 'err', 'LDAP Sync', 'Bind failed');
        $this->asAdmin();

        $entries = $this->entries('cron');

        $this->assertSame(['LDAP Sync', 'Keycloak Sync'], array_column($entries, 'task'));
        $this->assertSame(['error', 'info'], array_column($entries, 'severity'));
        $this->assertSame(['LDAP Sync'], array_column($this->entries('cron', 'task=ldap'), 'task'));
        $this->assertSame(['Keycloak Sync'], array_column($this->entries('cron', 'priority=INFO'), 'task'));
    }

    public function test_rate_limit_entries(): void
    {
        MailcowLogs::rateLimit(self::T);
        MailcowLogs::rateLimit(self::T + 1, ['rl_name' => 'err', 'rl_hash' => 'err', 'rl_info' => '', 'user' => 'bob@example.org', 'from' => 'bob@example.org', 'rcpt' => 'x@remote.example']);
        $this->asAdmin();

        $entries = $this->entries('rate-limits');

        $this->assertNull($entries[0]['rateLimitName']);
        $this->assertNull($entries[0]['rateLimitHash']);
        $this->assertSame(['x@remote.example'], $entries[0]['recipients']);
        $this->assertSame([
            'rateLimitName' => 'alice@example.org',
            'rateLimitHash' => 'RLsdz3tuabozgd4oxywmxq',
            'sender' => 'alice@example.org',
            'recipients' => ['bob@partner.example', 'carol@partner.example'],
            'user' => 'alice@example.org',
            'messageId' => '<rl-0001@example.org>',
            'headerFrom' => 'Alice <alice@example.org>',
            'subject' => 'Newsletter',
            'scannerQueueId' => 'B1C2D3E4F5',
            'clientAddress' => '192.0.2.44',
        ], array_slice($entries[1], 2));
        $this->assertSame(['bob@example.org'], array_column($this->entries('rate-limits', 'user=bob'), 'user'));
        $this->assertSame(['alice@example.org'], array_column($this->entries('rate-limits', 'rateLimitName=alice'), 'sender'));
        $this->assertSame(['bob@example.org'], array_column($this->entries('rate-limits', 'sender=BOB'), 'sender'));
    }

    public function test_legacy_api_request_entries_and_filters(): void
    {
        MailcowLogs::apiLog(self::T, '/api/v1/add/domain', 'POST', '192.0.2.20', ['attr' => ['domain' => 'example.org'], 'items' => ['example.org']]);
        MailcowLogs::apiLog(self::T + 1, '/api/v1/get/domain/all', 'GET', '198.51.100.7', []);
        $this->asAdmin();

        $entries = $this->entries('legacy-api-requests');

        $this->assertSame(['id', 'time', 'uri', 'method', 'clientAddress', 'requestData'], array_keys($entries[0]));
        $this->assertSame([], $entries[0]['requestData']);
        $this->assertSame(['attr' => ['domain' => 'example.org'], 'items' => ['example.org']], $entries[1]['requestData']);
        $this->assertSame(['/api/v1/add/domain'], array_column($this->entries('legacy-api-requests', 'method=post'), 'uri'));
        $this->assertSame(['/api/v1/get/domain/all'], array_column($this->entries('legacy-api-requests', 'clientAddress=198.51'), 'uri'));
    }

    public function test_unparseable_lines_are_left_out_of_the_list_and_the_total(): void
    {
        MailcowLogs::syslog('POSTFIX_MAILLOG', self::T, 'info', 'postfix/smtpd', 'valid');
        MailcowLogs::raw('POSTFIX_MAILLOG', 'not json');
        MailcowLogs::raw('POSTFIX_MAILLOG', '["a list"]');
        MailcowLogs::raw('POSTFIX_MAILLOG', '{"priority":"info","message":"no time"}');
        MailcowLogs::raw('POSTFIX_MAILLOG', '{"time":"yesterday","message":"text time"}');
        $this->asAdmin();

        $this->getJson('/api/v2/logs/postfix')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.message', 'valid');
    }

    public function test_an_empty_log_is_an_empty_list(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/logs/sogo')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }
}
