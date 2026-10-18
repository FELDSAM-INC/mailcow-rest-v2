<?php

namespace Tests\Feature\Fail2Ban;

use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T076 (US5 AS-2, AS-3, FR-046, FR-047, SC-010): invalid settings are refused, naming
 * every field and entry with its rule; nothing is corrected and nothing changes. Every case sends
 * input the contract forbids, so request validation is off.
 */
class Fail2BanSettingsValidationInvalidTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        MailcowLogs::netfilterDefaults();
        MailcowLogs::f2bList('F2B_WHITELIST', ['192.0.2.0/24']);
        MailcowLogs::f2bList('F2B_BLACKLIST', ['203.0.113.7']);
        $this->withoutRequestValidation();
        $this->asAdmin();
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $patch
     * @return array<string, list<array{code: string, params: array<string, mixed>}>>
     */
    private function refused(mixed $patch): array
    {
        $response = $patch === null
            ? $this->call('PATCH', '/api/v2/fail2ban/settings', [], [], [], $this->transformHeadersToServerVars($this->csrf() + ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/json']), 'null')
            : $this->patchMerge('/api/v2/fail2ban/settings', (array) $patch, $this->csrf());

        $response->assertStatus(422)->assertJsonPath('type', '/api/v2/problems/validation-failed');
        $this->assertSame(['192.0.2.0/24' => '1'], MailcowLogs::hash('F2B_WHITELIST'), 'the allowlist changed');
        $this->assertSame(['203.0.113.7' => '1'], MailcowLogs::hash('F2B_BLACKLIST'), 'the denylist changed');
        $this->assertStringContainsString('"ban_time": 1800', (string) MailcowLogs::get('F2B_OPTIONS'));

        return (array) $response->json('errors');
    }

    public function test_invalid_out_of_range_values_name_their_limits(): void
    {
        $errors = $this->refused(['banTime' => 30, 'ipv4PrefixLength' => 4, 'ipv6PrefixLength' => 129, 'maxAttempts' => 0, 'retryWindow' => 0]);

        $this->assertSame('outOfRange', $errors['banTime'][0]['code']);
        $this->assertSame(['min' => 60, 'max' => 2147483647], $errors['banTime'][0]['params']);
        $this->assertSame(['min' => 8, 'max' => 32], $errors['ipv4PrefixLength'][0]['params']);
        $this->assertSame(['min' => 8, 'max' => 128], $errors['ipv6PrefixLength'][0]['params']);
        $this->assertSame('outOfRange', $errors['maxAttempts'][0]['code']);
        $this->assertSame('outOfRange', $errors['retryWindow'][0]['code']);
    }

    public function test_invalid_the_maximum_ban_time_is_not_below_the_ban_time(): void
    {
        $errors = $this->refused(['maxBanTime' => 1000, 'banTime' => 1200]);
        $this->assertSame('outOfRange', $errors['maxBanTime'][0]['code']);
        $this->assertSame(['min' => 1200, 'max' => 2147483647, 'minField' => 'banTime'], $errors['maxBanTime'][0]['params']);

        $errors = $this->refused(['banTime' => 20000]);
        $this->assertSame('mustNotExceed', $errors['banTime'][0]['code']);
        $this->assertSame(['field' => 'maxBanTime', 'value' => 10000], $errors['banTime'][0]['params']);
    }

    public function test_invalid_a_stored_value_outside_the_rules_does_not_block_an_edit_that_keeps_it(): void
    {
        MailcowLogs::f2bOptions(['ban_time' => 30, 'max_ban_time' => 10000, 'ban_time_increment' => true, 'max_attempts' => 10, 'retry_window' => 600, 'netban_ipv4' => 32, 'netban_ipv6' => 128, 'banlist_id' => 'x', 'manage_external' => 0]);

        $this->patchMerge('/api/v2/fail2ban/settings', ['maxAttempts' => 4], $this->csrf())->assertOk()->assertJsonPath('data.banTime', 30);
        $this->patchMerge('/api/v2/fail2ban/settings', ['maxAttempts' => 5, 'banTime' => 30], $this->csrf())->assertOk()->assertJsonPath('data.maxAttempts', 5);
    }

    public function test_invalid_list_entries_are_named(): void
    {
        $errors = $this->refused(['denylist' => ['0.0.0.0/0', '198.51.100.1'], 'allowlist' => ['not a host!', '10.0.0.0/8']]);

        $this->assertSame('reserved', $errors['denylist.0'][0]['code']);
        $this->assertSame('invalidFormat', $errors['allowlist.0'][0]['code']);
        $this->assertSame('ipNetworkOrHostname', $errors['allowlist.0'][0]['params']['format']);
        $this->assertArrayNotHasKey('allowlist.1', $errors);
    }

    public function test_invalid_an_entry_on_both_lists_is_refused(): void
    {
        $errors = $this->refused(['allowlist' => ['198.51.100.0/24'], 'denylist' => ['203.0.113.7', '198.51.100.0/24']]);

        $this->assertSame('conflictsWith', $errors['denylist.1'][0]['code']);
        $this->assertSame(['field' => 'allowlist', 'value' => '198.51.100.0/24'], $errors['denylist.1'][0]['params']);

        $errors = $this->refused(['allowlist' => ['203.0.113.7']]);
        $this->assertSame('conflictsWith', $errors['allowlist.0'][0]['code']);
        $this->assertSame('denylist', $errors['allowlist.0'][0]['params']['field']);
    }

    public function test_invalid_list_limits_types_and_an_empty_patch(): void
    {
        $errors = $this->refused(['allowlist' => array_map(fn (int $i): string => '10.'.intdiv($i, 65536).'.'.(intdiv($i, 256) % 256).'.'.($i % 256), range(1, 10001))]);
        $this->assertSame('tooMany', $errors['allowlist'][0]['code']);

        $errors = $this->refused(['denylist' => [str_repeat('a', 254)], 'allowlist' => ['']]);
        $this->assertSame('tooLong', $errors['denylist.0'][0]['code']);
        $this->assertSame('required', $errors['allowlist.0'][0]['code']);

        $errors = $this->refused(['banTime' => null, 'banTimeIncrement' => 'yes', 'maxAttempts' => '5']);
        $this->assertSame('invalidType', $errors['banTime'][0]['code']);
        $this->assertSame('invalidType', $errors['banTimeIncrement'][0]['code']);
        $this->assertSame('invalidType', $errors['maxAttempts'][0]['code']);

        $errors = $this->refused([]);
        $this->assertSame('required', $errors['body'][0]['code']);
    }

    public function test_invalid_every_refusal_is_audited_without_values(): void
    {
        $this->refused(['allowlist' => ['not a host!']]);

        $record = $this->onlyAuditRecord('fail2banSettingsUpdate');
        $this->assertSame('validationFailed', $record->failure_reason);
        $this->assertSame('fail2banSettings', $record->target_type);
        $this->assertSame('invalidFormat', $this->details($record)['errors']['allowlist.0'][0]['code']);
        $this->assertStringNotContainsString('not a host', (string) $record->details);
    }
}
