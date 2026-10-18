<?php

namespace Tests\Unit\Fail2Ban;

use App\Services\Fail2Ban\Fail2BanSettingsValues;
use App\Services\Fail2Ban\Fail2BanVersionTag;
use Tests\TestCase;

/**
 * Feature 011 T071 (FR-057, research R18): version tags over values, not bytes, so that the ban
 * service's own rewrites of unchanged values never outdate a client's tag.
 */
class Fail2BanVersionTagTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $allowlist
     * @param  list<string>  $denylist
     */
    private function tag(array $options, array $allowlist = [], array $denylist = []): string
    {
        return Fail2BanVersionTag::settings(Fail2BanSettingsValues::fromStored($options, $allowlist, $denylist));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function storedOptions(array $overrides = []): array
    {
        return array_merge(['ban_time' => 1800, 'ban_time_increment' => true, 'max_ban_time' => 10000, 'netban_ipv4' => 32, 'netban_ipv6' => 128, 'max_attempts' => 10, 'retry_window' => 600, 'banlist_id' => 'a5c6d0b2-1d2e-4c1a-9b8f-6f1e2d3c4b5a', 'manage_external' => 0], $overrides);
    }

    public function test_equal_values_give_equal_tags_whatever_their_encoding(): void
    {
        $base = $this->tag(self::storedOptions(), ['10.0.0.0/8', 'mail.example.org'], ['203.0.113.0/24']);

        $this->assertSame($base, $this->tag(self::storedOptions(['ban_time' => '1800']), ['10.0.0.0/8', 'mail.example.org'], ['203.0.113.0/24']));
        $this->assertSame($base, $this->tag(self::storedOptions(['ban_time_increment' => 1]), ['10.0.0.0/8', 'mail.example.org'], ['203.0.113.0/24']));
        $this->assertSame($base, $this->tag(self::storedOptions(), ['mail.example.org', '10.0.0.0/8'], ['203.0.113.0/24']), 'list order does not matter');
    }

    public function test_any_value_change_changes_the_tag(): void
    {
        $base = $this->tag(self::storedOptions(), ['10.0.0.0/8'], []);

        $this->assertNotSame($base, $this->tag(self::storedOptions(['ban_time' => 3600]), ['10.0.0.0/8'], []));
        $this->assertNotSame($base, $this->tag(self::storedOptions(['banlist_id' => 'b5c6d0b2-1d2e-4c1a-9b8f-6f1e2d3c4b5a']), ['10.0.0.0/8'], []));
        $this->assertNotSame($base, $this->tag(self::storedOptions(), ['10.0.0.0/8', '192.0.2.1'], []));
        $this->assertNotSame($base, $this->tag(self::storedOptions(), ['10.0.0.0/8'], ['192.0.2.1']));
        $this->assertNotSame($base, $this->tag(self::storedOptions(['manage_external' => 1]), ['10.0.0.0/8'], []));
    }

    public function test_the_settings_tag_is_keyed_with_the_app_key(): void
    {
        $values = Fail2BanSettingsValues::fromStored(self::storedOptions(), [], []);
        $tag = Fail2BanVersionTag::settings($values);

        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]+"$/', $tag);
        $this->assertStringNotContainsString(hash('sha256', (string) json_encode(Fail2BanVersionTag::canonical($values))), $tag);

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->assertNotSame($tag, Fail2BanVersionTag::settings($values));
    }

    public function test_the_rules_tag_follows_order_and_content_only(): void
    {
        $tag = Fail2BanVersionTag::rules(['a (\d+)', 'b (\d+)']);

        $this->assertSame($tag, Fail2BanVersionTag::rules(['a (\d+)', 'b (\d+)']));
        $this->assertNotSame($tag, Fail2BanVersionTag::rules(['b (\d+)', 'a (\d+)']));
        $this->assertNotSame($tag, Fail2BanVersionTag::rules(['a (\d+)']));
    }
}
