<?php

namespace Tests\Unit\Services;

use App\Services\DomainTemplates\DomainTemplateAttributes;
use Database\Factories\MailcowRows;
use Tests\TestCase;

/**
 * Feature 002 T018 (research D22): domain template attributes are read tolerantly from the legacy
 * JSON and written with exactly the legacy keys (quotas in bytes).
 */
class DomainTemplateAttributesTest extends TestCase
{
    private const MIB = 1048576;

    private function codec(): DomainTemplateAttributes
    {
        return $this->app->make(DomainTemplateAttributes::class);
    }

    public function test_the_seeded_default_template_decodes_to_the_built_in_values(): void
    {
        $values = $this->codec()->decode((string) json_encode(MailcowRows::DEFAULT_DOMAIN_TEMPLATE));

        $this->assertSame([
            'tags' => [],
            'maxMailboxes' => 10,
            'maxAliases' => 400,
            'maxMailboxQuota' => 10240 * self::MIB,
            'domainQuota' => 10240 * self::MIB,
            'rateLimit' => null,
            'active' => true,
            'globalAddressList' => true,
            'backupMx' => false,
            'relayAllRecipients' => false,
            'relayUnknownOnly' => false,
            'dkimSelector' => 'dkim',
            'dkimKeySize' => 2048,
        ], $values);
        $this->assertSame($values, $this->codec()->defaults());
    }

    public function test_legacy_rows_are_read_tolerantly(): void
    {
        $values = $this->codec()->decode((string) json_encode([
            'tags' => '["gold","silver"]',
            'max_num_mboxes_for_domain' => '5',
            'def_quota_for_mbox' => '1073741824',
            'rl_value' => '10',
            'rl_frame' => 'h',
            'key_size' => 512,
            'gal' => '0',
        ]));

        $this->assertSame(['gold', 'silver'], $values['tags']);
        $this->assertSame(5, $values['maxMailboxes']);
        $this->assertSame(400, $values['maxAliases']);
        $this->assertArrayNotHasKey('defaultMailboxQuota', $values);
        $this->assertSame(['value' => 10, 'frame' => 'hour'], $values['rateLimit']);
        $this->assertSame(512, $values['dkimKeySize']);
        $this->assertFalse($values['globalAddressList']);
        $this->assertTrue($values['active']);
        $this->assertSame('dkim', $values['dkimSelector']);
    }

    public function test_the_legacy_default_mailbox_quota_is_read_apart_from_the_values(): void
    {
        // Owner decision 2026-10-18: kept for legacy's add form, never one of the values.
        $this->assertSame(1024 * self::MIB, $this->codec()->storedDefaultQuota('{"def_quota_for_mbox":"1073741824"}'));
        $this->assertSame(0, $this->codec()->storedDefaultQuota('{"def_quota_for_mbox":0}'));
        $this->assertNull($this->codec()->storedDefaultQuota('{"def_quota_for_mbox":"x"}'));
        $this->assertNull($this->codec()->storedDefaultQuota('{}'));
        $this->assertNull($this->codec()->storedDefaultQuota(null));
    }

    public function test_rate_limits_of_every_legacy_spelling(): void
    {
        $decode = fn (array $a): mixed => $this->codec()->decode((string) json_encode($a))['rateLimit'];

        $this->assertNull($decode(['rl_value' => '', 'rl_frame' => 's']));
        $this->assertNull($decode(['rl_frame' => 'h']));
        $this->assertNull($decode(['rl_value' => 'x', 'rl_frame' => 'h']));
        $this->assertSame(['value' => 10, 'frame' => 'minute'], $decode(['rl_value' => 10, 'rl_frame' => 'm']));
        $this->assertSame(['value' => 3, 'frame' => 'second'], $decode(['rl_value' => '3']));
    }

    public function test_missing_or_broken_json_gives_the_defaults(): void
    {
        $this->assertSame($this->codec()->defaults(), $this->codec()->decode(null));
        $this->assertSame($this->codec()->defaults(), $this->codec()->decode('not json'));
    }

    public function test_the_default_selector_is_the_server_setting(): void
    {
        config(['restv2.dkim.default_selector' => 'feldhost']);

        $this->assertSame('feldhost', $this->codec()->defaults()['dkimSelector']);
        $this->assertSame('feldhost', $this->codec()->decode('{}')['dkimSelector']);
    }

    public function test_encoding_writes_exactly_the_legacy_keys(): void
    {
        $values = array_merge($this->codec()->defaults(), [
            'tags' => ['gold'],
            'maxMailboxes' => 5,
            'maxAliases' => 0,
            'rateLimit' => ['value' => 10, 'frame' => 'day'],
            'relayAllRecipients' => true,
            'backupMx' => true,
        ]);

        $stored = json_decode($this->codec()->encode($values, 2048 * self::MIB), true);

        $this->assertSame([
            'tags' => ['gold'],
            'max_num_aliases_for_domain' => 0,
            'max_num_mboxes_for_domain' => 5,
            'def_quota_for_mbox' => 2048 * self::MIB,
            'max_quota_for_mbox' => 10240 * self::MIB,
            'max_quota_for_domain' => 10240 * self::MIB,
            'rl_frame' => 'd',
            'rl_value' => 10,
            'active' => 1,
            'gal' => 1,
            'backupmx' => 1,
            'relay_all_recipients' => 1,
            'relay_unknown_only' => 0,
            'dkim_selector' => 'dkim',
            'key_size' => 2048,
        ], $stored);

        $none = json_decode($this->codec()->encode($this->codec()->defaults(), 10240 * self::MIB), true);
        $this->assertSame('', $none['rl_value']);
        $this->assertSame('s', $none['rl_frame']);
    }
}
