<?php

namespace Tests\Feature\OwnAccount;

use App\Auth\Role;
use App\Services\Config\MailcowConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T118 (feature 005 FR-083, US12 AS-3 to AS-5): the client configuration of the signed-in mailbox,
 * from the server's autodiscover settings.
 */
class ClientConfigurationTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/me/mailbox/client-configuration';

    private ?string $workDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The autodiscover settings built from mailcow.conf (no vars files mounted).
        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc']);
        $this->app->forgetInstance(MailcowConfig::class);
        $this->asSelfServiceUser();
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['name' => 'Anna & Co']);
    }

    protected function tearDown(): void
    {
        if ($this->workDir !== null) {
            File::deleteDirectory($this->workDir);
        }

        parent::tearDown();
    }

    public function test_the_configuration_from_the_server_settings(): void
    {
        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame([
            'address' => 'anna@example.org',
            'displayName' => 'Anna & Co',
            'imap' => ['host' => 'mail.example.org', 'tlsPort' => 993, 'starttlsPort' => 143],
            'pop3' => ['host' => 'mail.example.org', 'tlsPort' => 995, 'starttlsPort' => 110],
            'smtp' => ['host' => 'mail.example.org', 'tlsPort' => 465, 'starttlsPort' => 587],
            'caldav' => ['host' => 'mail.example.org', 'port' => 443, 'principalPath' => '/SOGo/dav/anna@example.org/'],
            'carddav' => ['host' => 'mail.example.org', 'port' => 443, 'principalPath' => '/SOGo/dav/anna@example.org/'],
            'activeSync' => ['url' => 'https://mail.example.org/Microsoft-Server-ActiveSync'],
            'authentication' => 'password',
            'autodiscoverType' => 'activesync',
            'outlookUsesActiveSync' => false,
            // legacy user.php's $clientconfigstr.
            'documentationUrl' => 'https://mailcow.github.io/mailcow-dockerized-docs/client/client/#host=mail.example.org&email=anna%40example.org&name=Anna+%26+Co&ui=localhost&port=443',
        ], $data);
    }

    public function test_ports_of_addresses_and_the_request_port(): void
    {
        config(['restv2.ports.IMAPS_PORT' => '127.0.0.1:10993']);

        $data = $this->getJson('http://mail.example.org:8443'.self::URL)->assertOk()->json('data');

        $this->assertSame(10993, $data['imap']['tlsPort']);
        $this->assertSame(8443, $data['caldav']['port']);
        $this->assertSame(8443, $data['carddav']['port']);
        $this->assertSame('https://mail.example.org:8443/Microsoft-Server-ActiveSync', $data['activeSync']['url']);
        $this->assertStringEndsWith('&ui=mail.example.org&port=8443', $data['documentationUrl']);
    }

    public function test_an_operator_override_wins(): void
    {
        $this->workDir = storage_path('framework/testing/mailcow-inc-'.Str::random(8));
        File::ensureDirectoryExists($this->workDir);
        File::copy(base_path('tests/fixtures/mailcow-inc/vars.inc.php'), $this->workDir.'/vars.inc.php');
        file_put_contents($this->workDir.'/vars.local.inc.php', "<?php\n\$autodiscover_config['useEASforOutlook'] = 'yes';\n\$autodiscover_config['imap']['server'] = 'imap.example.org';\n\$autodiscover_config['autodiscoverType'] = 'imap';\n");
        config(['restv2.mailcow_inc_dir' => $this->workDir]);
        $this->app->forgetInstance(MailcowConfig::class);

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame('imap.example.org', $data['imap']['host']);
        $this->assertSame('imap', $data['autodiscoverType']);
        $this->assertTrue($data['outlookUsesActiveSync']);
        $this->assertStringEndsWith('&outlookEAS=1', $data['documentationUrl']);
    }

    public function test_without_the_groupware(): void
    {
        config(['restv2.skip_sogo' => true]);

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertNull($data['caldav']);
        $this->assertNull($data['carddav']);
        $this->assertNull($data['activeSync']);
        $this->assertSame(993, $data['imap']['tlsPort']);
    }

    public function test_only_mailbox_users_with_a_session(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->signInAs('admin', Role::Admin);
        $this->getJson(self::URL)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->getJson(self::URL, $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/session-required');
    }
}
