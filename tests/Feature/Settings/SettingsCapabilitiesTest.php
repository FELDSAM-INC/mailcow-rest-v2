<?php

namespace Tests\Feature\Settings;

use App\Services\Settings\SettingsLimits;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T031 (FR-094): the settings capabilities, the server settings and limits a client
 * needs before it submits, read from the current configuration for administrators (read-only keys
 * included), without any secret or connection detail.
 */
class SettingsCapabilitiesTest extends TestCase
{
    use SettingsTestHelpers;

    public function test_administrators_read_the_capabilities_by_session_and_by_key(): void
    {
        $this->asAdmin();
        $session = $this->getJson('/api/v2/capabilities/settings')->assertOk();
        $this->forgetCookies();
        $readWrite = $this->getJson('/api/v2/capabilities/settings', $this->adminKey())->assertOk();
        $readOnly = $this->getJson('/api/v2/capabilities/settings', $this->adminKey('readOnly'))->assertOk();

        $this->assertSame($session->json('data'), $readWrite->json('data'));
        $this->assertSame($session->json('data'), $readOnly->json('data'));
        $session->assertJsonPath('data.hostname', 'mail.example.org')
            ->assertJsonPath('data.demoMode', false)
            ->assertJsonPath('data.licenseVerificationEnabled', true)
            ->assertJsonPath('data.rspamdWebInterfaceUrl', '/rspamd/')
            ->assertJsonPath('data.logos', ['maxBytes' => 15728640, 'maxWidth' => 1920, 'maxHeight' => 1920, 'acceptedMediaTypes' => ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml']])
            ->assertJsonPath('data.brandColors', ['minimumContrastRatio' => 4.5, 'lightReferenceSurface' => '#ffffff', 'darkReferenceSurface' => '#222932'])
            ->assertJsonPath('data.limits', SettingsLimits::toArray())
            ->assertJsonPath('data.massMail', [
                'suggestedSender' => 'noreply@mail.example.org',
                'ratePerSecond' => 10,
                'maxRunningJobs' => 1,
                'maxRetries' => 3,
                'retryWindowSeconds' => 300,
                'maxSelectionEntries' => 1000,
                'maxBodyBytes' => 1048576,
            ]);
    }

    public function test_the_values_follow_the_server_settings(): void
    {
        $this->asAdmin();
        config([
            'restv2.demo_mode' => true,
            'restv2.license.enabled' => false,
            'restv2.mailcow_hostname' => 'MX.Example.NET',
            'restv2.rspamd_ui_url' => '/spam/',
            'restv2.mass_mail.rate' => 50,
            'restv2.vars_overrides.LOGO_LIMITS' => '{"max_size": 1048576, "max_width": 640, "max_height": 480}',
        ]);

        $this->getJson('/api/v2/capabilities/settings')->assertOk()
            ->assertJsonPath('data.demoMode', true)
            ->assertJsonPath('data.licenseVerificationEnabled', false)
            ->assertJsonPath('data.hostname', 'mx.example.net')
            ->assertJsonPath('data.massMail.suggestedSender', 'noreply@mx.example.net')
            ->assertJsonPath('data.rspamdWebInterfaceUrl', '/spam/')
            ->assertJsonPath('data.massMail.ratePerSecond', 50)
            ->assertJsonPath('data.logos.maxBytes', 1048576)
            ->assertJsonPath('data.logos.maxWidth', 640);
    }

    public function test_no_secret_or_connection_detail_appears(): void
    {
        $this->asAdmin();
        config(['restv2.lmtp.host' => 'secret-lmtp-host', 'restv2.license.verify_url' => 'https://secret-license.example', 'restv2.rspamd_settings_map_url' => 'http://secret-map:8081/settings.php']);

        $body = (string) $this->getJson('/api/v2/capabilities/settings')->assertOk()->getContent();

        $this->assertStringNotContainsString('secret', $body);
        $this->assertStringNotContainsString((string) config('app.key'), $body);
    }

    public function test_other_roles_are_forbidden(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/capabilities/settings')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->asMailboxUser('user@example.org');
        $this->getJson('/api/v2/capabilities/settings')->assertForbidden();
    }
}
