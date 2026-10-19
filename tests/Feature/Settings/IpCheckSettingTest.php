<?php

namespace Tests\Feature\Settings;

use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T082 (FR-035, AS-7.8): the IP check opt-in in legacy's `IP_CHECK`; while it is on,
 * feature 011's public address lookup works.
 */
class IpCheckSettingTest extends TestCase
{
    use SettingsTestHelpers;

    private const URI = '/api/v2/settings/ip-check';

    public function test_the_opt_in_is_off_by_default_and_enables_the_public_address_lookup(): void
    {
        $this->asAdmin();
        $this->getJson(self::URI)->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson('/api/v2/host/public-addresses')->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/ip-check-disabled');

        $this->send('PATCH', self::URI, ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);

        $this->assertSame('1', $this->sharedGet('IP_CHECK'));
        $this->getJson('/api/v2/host/public-addresses')->assertOk();

        $this->send('PATCH', self::URI, ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame('0', $this->sharedGet('IP_CHECK'));
    }

    public function test_demo_mode_refuses_changes_and_changes_are_audited(): void
    {
        $this->asAdmin();
        config(['restv2.demo_mode' => true]);
        $this->send('PATCH', self::URI, ['enabled' => true])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/demo-mode');
        $this->assertNull($this->sharedGet('IP_CHECK'));

        config(['restv2.demo_mode' => false]);
        $this->send('PATCH', self::URI, ['enabled' => true])->assertOk();
        $this->send('PATCH', self::URI, ['enabled' => true])->assertOk();

        $records = $this->successesOf('ipCheckUpdate');
        $this->assertCount(1, $records);
        $this->assertSame(['enabled' => ['old' => false, 'new' => true]], $records[0]->details['changes']);
    }

    public function test_invalid_values_and_an_outdated_tag_are_refused(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $etag = (string) $this->getJson(self::URI)->json('data.etag');
        $this->sharedValue('IP_CHECK', '1');

        $this->send('PATCH', self::URI, ['enabled' => 'yes'])->assertStatus(422);
        $this->send('PATCH', self::URI, [])->assertStatus(422);
        $this->send('PATCH', self::URI, ['enabled' => false], ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame('1', $this->sharedGet('IP_CHECK'));
    }
}
