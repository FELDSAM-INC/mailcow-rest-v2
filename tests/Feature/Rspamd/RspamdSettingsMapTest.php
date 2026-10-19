<?php

namespace Tests\Feature\Rspamd;

use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T049 (FR-060): administrators read the effective Rspamd settings map exactly as
 * mailcow generates it; an unreachable endpoint is a temporary failure.
 */
class RspamdSettingsMapTest extends TestCase
{
    use SettingsTestHelpers;

    private const URI = '/api/v2/settings/rspamd-settings-map';

    public function test_the_map_is_returned_unchanged(): void
    {
        $this->asAdmin();
        $map = "settings {\n  additional_settings_7 {\n    priority = 10;\n  }\n}\n";
        $this->settingsMap()->returns($map);

        $this->getJson(self::URI)->assertOk()->assertExactJson(['data' => ['content' => $map]]);
        $this->getJson(self::URI, $this->adminKey('readOnly'))->assertOk();
    }

    public function test_an_unreachable_endpoint_is_a_temporary_failure(): void
    {
        $this->asAdmin();
        $this->settingsMap()->unavailable();

        $this->getJson(self::URI)->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertHeader('Retry-After');
    }

    public function test_other_roles_are_forbidden_and_never_reach_the_endpoint(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson(self::URI)->assertForbidden();

        $this->assertSame(0, $this->settingsMap()->fetches);
    }
}
