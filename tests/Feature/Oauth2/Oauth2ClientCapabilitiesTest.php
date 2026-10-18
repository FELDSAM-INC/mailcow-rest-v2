<?php

namespace Tests\Feature\Oauth2;

use App\Services\Config\MailcowConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IdentityProvider\Oauth2TestHelpers;
use Tests\TestCase;

/**
 * T080 (FR-049, FR-064, research R20): the OAuth2 client capabilities.
 */
class Oauth2ClientCapabilitiesTest extends TestCase
{
    use Oauth2TestHelpers;

    public function test_the_capabilities_with_the_upstream_lifetimes(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/capabilities/oauth2-clients')->assertOk()->assertExactJson(['data' => [
            'endpoints' => ['authorization' => '/oauth/authorize', 'token' => '/oauth/token', 'profile' => '/oauth/profile'],
            'scope' => 'profile',
            'grantTypes' => ['authorization_code', 'refresh_token', 'password'],
            'accessTokenLifetimeSeconds' => 86400,
            'refreshTokenLifetimeSeconds' => 2678400,
            'redirectUrisMaxLength' => 2000,
            'bulkLimit' => 100,
            'defaultPageSize' => 25,
            'maxPageSize' => 1000,
        ]]);
        $this->assertSame([], $this->allAudit());
    }

    public function test_the_operators_values_and_the_server_settings_apply(): void
    {
        $dir = storage_path('framework/testing/mailcow-inc-'.Str::random(8));
        File::ensureDirectoryExists($dir);
        File::copy(base_path('tests/fixtures/mailcow-inc/vars.inc.php'), $dir.'/vars.inc.php');
        file_put_contents($dir.'/vars.local.inc.php', "<?php\n\$ACCESS_TOKEN_LIFETIME = 3600;\n\$REFRESH_TOKEN_LIFETIME = 7200;\n");
        config(['restv2.mailcow_inc_dir' => $dir]);
        $this->app->forgetInstance(MailcowConfig::class);
        $this->asAdmin();

        try {
            $this->getJson('/api/v2/capabilities/oauth2-clients')->assertOk()
                ->assertJsonPath('data.accessTokenLifetimeSeconds', 3600)
                ->assertJsonPath('data.refreshTokenLifetimeSeconds', 7200);

            config(['restv2.vars_overrides.ACCESS_TOKEN_LIFETIME' => '600']);
            $this->app->forgetInstance(MailcowConfig::class);
            $this->getJson('/api/v2/capabilities/oauth2-clients')->assertOk()->assertJsonPath('data.accessTokenLifetimeSeconds', 600);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_read_only_keys_read_and_domain_administrators_may_not(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->getJson('/api/v2/capabilities/oauth2-clients', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();

        $this->mailcow()->ensureDomain('example.org');
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/capabilities/oauth2-clients')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
