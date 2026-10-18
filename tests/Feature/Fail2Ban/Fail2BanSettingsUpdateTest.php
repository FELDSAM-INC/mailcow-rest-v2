<?php

namespace Tests\Feature\Fail2Ban;

use App\Clients\Fail2Ban\Fail2BanStore;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\Support\Redis\UnavailableSharedStore;
use Tests\TestCase;

/**
 * Feature 011 T075 (US5 AS-1, AS-10, FR-045, FR-057, FR-061, research R18): changing the settings
 * by merge patch, atomically, with value-based version tags.
 */
class Fail2BanSettingsUpdateTest extends TestCase
{
    use MonitoringTestHelpers;

    private const ID = '1b6c4e1e-7b0f-4c9e-9a65-0f6a6c1c8f11';

    protected function setUp(): void
    {
        parent::setUp();

        MailcowLogs::netfilterDefaults(self::ID);
        MailcowLogs::f2bList('F2B_WHITELIST', ['192.0.2.0/24']);
        MailcowLogs::f2bList('F2B_BLACKLIST', ['203.0.113.7']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function storedOptions(): array
    {
        return (array) json_decode((string) MailcowLogs::get('F2B_OPTIONS'), true);
    }

    public function test_only_the_sent_value_changes(): void
    {
        $this->asAdmin();
        $before = (string) $this->getJson('/api/v2/fail2ban/settings')->json('data.etag');

        $response = $this->patchMerge('/api/v2/fail2ban/settings', ['banTime' => 3600], $this->csrf())->assertOk();

        $response->assertJsonPath('data.banTime', 3600);
        $this->assertNotSame($before, $response->json('data.etag'));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
        $this->assertSame('{"ban_time":3600,"ban_time_increment":true,"max_ban_time":10000,"netban_ipv4":32,"netban_ipv6":128,"max_attempts":10,"retry_window":600,"banlist_id":"'.self::ID.'","manage_external":0}', MailcowLogs::get('F2B_OPTIONS'));
        $this->assertSame(['192.0.2.0/24' => '1'], MailcowLogs::hash('F2B_WHITELIST'));
        $this->assertSame(['203.0.113.7' => '1'], MailcowLogs::hash('F2B_BLACKLIST'));
    }

    public function test_a_sent_list_replaces_the_whole_list(): void
    {
        $this->asAdmin();

        $this->patchMerge('/api/v2/fail2ban/settings', ['allowlist' => [' 10.0.0.0/8 ', 'mail.example.org', '10.0.0.0/8'], 'externallyEnforced' => true], $this->csrf())
            ->assertOk()
            ->assertJsonPath('data.allowlist', ['10.0.0.0/8', 'mail.example.org']);

        $this->assertSame(['10.0.0.0/8' => '1', 'mail.example.org' => '1'], MailcowLogs::hash('F2B_WHITELIST'));
        $this->assertSame(['203.0.113.7' => '1'], MailcowLogs::hash('F2B_BLACKLIST'));
        $this->assertSame(1, self::storedOptions()['manage_external']);
    }

    public function test_an_outdated_tag_is_refused_and_nothing_is_written(): void
    {
        $this->asAdmin();
        $tag = (string) $this->getJson('/api/v2/fail2ban/settings')->json('data.etag');
        MailcowLogs::f2bOptions(['ban_time' => 7200] + self::storedOptions());

        $this->patchMerge('/api/v2/fail2ban/settings', ['maxAttempts' => 3], $this->csrf() + ['If-Match' => $tag])->assertStatus(412);

        $this->assertSame(10, self::storedOptions()['max_attempts']);
    }

    public function test_a_tag_read_before_the_ban_service_restored_the_same_values_is_accepted(): void
    {
        MailcowLogs::f2bOptions(self::storedOptions(), python: false);
        $this->asAdmin();
        $tag = (string) $this->getJson('/api/v2/fail2ban/settings')->json('data.etag');
        MailcowLogs::f2bOptions(self::storedOptions(), python: true);

        $this->patchMerge('/api/v2/fail2ban/settings', ['maxAttempts' => 3], $this->csrf() + ['If-Match' => $tag])->assertOk();

        $this->assertSame(3, self::storedOptions()['max_attempts']);
    }

    public function test_a_concurrent_change_is_retried_and_both_changes_survive(): void
    {
        $store = $this->app->make(Fail2BanStore::class);
        $store->onceAfterRead(function (): void {
            $other = Redis::resolve('mailcow');
            $other->command('hset', ['F2B_BLACKLIST', '198.51.100.9', '1']);
        });
        $this->app->instance(Fail2BanStore::class, $store);
        $this->asAdmin();

        $this->patchMerge('/api/v2/fail2ban/settings', ['banTime' => 4000], $this->csrf())->assertOk();

        $this->assertSame(4000, self::storedOptions()['ban_time']);
        $this->assertArrayHasKey('198.51.100.9', MailcowLogs::hash('F2B_BLACKLIST'));
    }

    public function test_the_first_save_stores_a_complete_set_with_a_new_ban_list_id(): void
    {
        Redis::connection('mailcow')->command('del', ['F2B_OPTIONS']);
        $this->asAdmin();

        $this->patchMerge('/api/v2/fail2ban/settings', ['retryWindow' => 900], $this->csrf())->assertOk()->assertJsonPath('data.stored', true);

        $options = self::storedOptions();
        $this->assertSame(['ban_time', 'ban_time_increment', 'max_ban_time', 'netban_ipv4', 'netban_ipv6', 'max_attempts', 'retry_window', 'banlist_id', 'manage_external'], array_keys($options));
        $this->assertSame(900, $options['retry_window']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $options['banlist_id']);
    }

    public function test_an_unavailable_shared_store_changes_nothing(): void
    {
        UnavailableSharedStore::bind($this->app);
        $this->asAdmin();

        $this->patchMerge('/api/v2/fail2ban/settings', ['banTime' => 3600], $this->csrf())->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }
}
