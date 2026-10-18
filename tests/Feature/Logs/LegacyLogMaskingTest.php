<?php

namespace Tests\Feature\Logs;

use Illuminate\Support\Facades\DB;
use Tests\Support\Monitoring\LegacyUiLogRows;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T048 (US3 AS-5, FR-028, SC-006): secrets that legacy wrote into its request and
 * activity logs never leave the API, and searches cannot reveal them.
 */
class LegacyLogMaskingTest extends TestCase
{
    use LegacyUiLogRows;
    use MonitoringTestHelpers;

    private const PLANTED = ['Secret1!', '-----BEGIN PRIVATE KEY-----', 'S3cretBind', 'oauth-client-secret-value', 'pushover-token-value', 'app-pass-value'];

    /** @var list<string> */
    private array $bodies = [];

    protected function tearDown(): void
    {
        foreach ($this->bodies as $body) {
            foreach (self::PLANTED as $secret) {
                $this->assertStringNotContainsString($secret, $body, 'a planted secret left the API');
            }
        }

        parent::tearDown();
    }

    private function body(string $uri): string
    {
        return $this->bodies[] = (string) $this->getJson($uri)->assertOk()->getContent();
    }

    public function test_a_legacy_api_request_with_a_private_key_and_a_password_is_masked(): void
    {
        MailcowLogs::apiLog(1760781600, '/api/v1/add/dkim', 'POST', '192.0.2.20', [
            'attr' => ['domains' => 'example.org', 'password' => 'Secret1!', 'dkim' => ['private_key_file' => '-----BEGIN PRIVATE KEY-----'], 'ldap' => ['bind_password' => 'S3cretBind']],
        ]);
        $this->asAdmin();

        $data = json_decode($this->body('/api/v2/logs/legacy-api-requests'), true)['data'][0]['requestData'];

        $this->assertSame(['domains' => 'example.org', 'password' => '*', 'dkim' => ['private_key_file' => '*'], 'ldap' => ['bind_password' => '*']], $data['attr']);
    }

    public function test_a_search_for_a_masked_value_finds_nothing(): void
    {
        MailcowLogs::apiLog(1760781600, '/api/v1/edit/identity-provider', 'POST', '192.0.2.20', [
            'attr' => ['ldap' => ['bind_password' => 'S3cretBind']],
        ]);
        $this->asAdmin();

        $this->bodies[] = (string) $this->getJson('/api/v2/logs/legacy-api-requests?q=S3cretBind')->assertOk()->assertJsonPath('meta.total', 0)->getContent();
        $this->bodies[] = (string) $this->getJson('/api/v2/logs/legacy-api-requests?q=identity-provider')->assertOk()->assertJsonPath('meta.total', 1)->getContent();
    }

    public function test_legacy_ui_activity_calls_and_messages_are_masked(): void
    {
        $this->legacyUiLog('success', ['object_modified', 'example.org'], ['oauth2', 'add', ['client_secret' => 'oauth-client-secret-value', 'redirect_uri' => 'https://app.example']]);
        $this->legacyUiLog('success', 'pushover_settings_edited', ['pushover', 'edit', ['token' => 'pushover-token-value', 'key' => 'k1']]);
        $this->legacyUiLog('danger', ['ldap_error', ['bind_password' => 'S3cretBind']], ['identity_provider', 'edit', ['bind_password' => 'S3cretBind']]);
        $this->legacyUiLog('success', 'dkim_added', ['dkim', 'import', ['private_key_file' => '-----BEGIN PRIVATE KEY-----', 'domain' => 'example.org']]);
        $this->legacyUiLog('success', 'app_passwd_added', ['app_passwd', 'add', ['app_passwd' => 'app-pass-value', 'app_name' => 'Phone']]);
        $this->asAdmin();

        $entries = json_decode($this->body('/api/v2/logs/legacy-ui-activity'), true)['data'];

        $this->assertSame(['app_passwd', 'add', ['app_passwd' => '*', 'app_name' => 'Phone']], $entries[0]['call']);
        $this->assertSame(['dkim', 'import', ['private_key_file' => '*', 'domain' => 'example.org']], $entries[1]['call']);
        $this->assertSame([['bind_password' => '*']], $entries[2]['messageParameters']);
        $this->assertSame(['pushover', 'edit', ['token' => '*', 'key' => '*']], $entries[3]['call']);
        $this->assertSame(['oauth2', 'add', ['client_secret' => '*', 'redirect_uri' => 'https://app.example']], $entries[4]['call']);
        $this->assertSame('object_modified', $entries[4]['messageKey']);
        $this->assertSame(['example.org'], $entries[4]['messageParameters']);
    }

    public function test_searches_of_the_activity_log_cannot_reveal_masked_values(): void
    {
        $this->legacyUiLog('success', 'dkim_added', ['dkim', 'import', ['private_key_file' => '-----BEGIN PRIVATE KEY-----']]);
        $this->asAdmin();

        $this->bodies[] = (string) $this->getJson('/api/v2/logs/legacy-ui-activity?q=BEGIN')->assertOk()->assertJsonPath('meta.total', 0)->getContent();
        $this->assertSame(1, DB::table('logs')->count());
    }
}
