<?php

namespace Tests\Feature\SelfService;

use App\Services\Config\MailcowConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T016 (feature 005 research R18, R23): the legacy variables of the reset, the WebAuthn
 * second-factor registration, the webmail hand-off's legacy session and the autodiscover settings,
 * read with 001's precedence (RESTV2_<NAME>, the mounted vars files, the upstream default).
 */
class MailcowConfigSelfServiceTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/mailcow-inc';

    private ?string $workDir = null;

    /** @var array<string, array{string|false, mixed, mixed}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        if ($this->workDir !== null) {
            File::deleteDirectory($this->workDir);
        }

        foreach ($this->savedEnv as $name => [$value, $env, $server]) {
            putenv($value === false ? $name : "{$name}={$value}");
            $env === null ? Arr::forget($_ENV, $name) : $_ENV[$name] = $env;
            $server === null ? Arr::forget($_SERVER, $name) : $_SERVER[$name] = $server;
        }

        parent::tearDown();
    }

    private function config(): MailcowConfig
    {
        $this->app->forgetInstance(MailcowConfig::class);

        return $this->app->make(MailcowConfig::class);
    }

    private function copyFixtures(string $local = "<?php\n"): string
    {
        $this->workDir = storage_path('framework/testing/mailcow-inc-'.Str::random(8));
        File::ensureDirectoryExists($this->workDir);
        File::copy(self::FIXTURES.'/vars.inc.php', $this->workDir.'/vars.inc.php');
        file_put_contents($this->workDir.'/vars.local.inc.php', $local);
        config(['restv2.mailcow_inc_dir' => $this->workDir]);

        return $this->workDir;
    }

    /**
     * Sets a variable of the process environment, which the export subprocess inherits.
     */
    private function setEnv(string $name, string $value): void
    {
        $this->savedEnv[$name] ??= [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function request(string $host): Request
    {
        return Request::create('https://'.$host.'/api/v2/me/mailbox/client-configuration', 'GET', [], [], [], ['HTTP_HOST' => $host]);
    }

    public function test_the_upstream_values_are_read_from_the_files(): void
    {
        $config = $this->config();

        $this->assertSame(15, $config->pwResetTokenLifetime());
        $this->assertSame(3, $config->pwResetTokenLimit());
        $this->assertFalse($config->webauthnUvFlagRegister());
        $this->assertSame('MCSESSID', $config->sessionName());
        $this->assertSame('Lax', $config->sessionSameSitePolicy());
    }

    public function test_local_overrides_and_server_settings_win(): void
    {
        $this->copyFixtures("<?php\n\$PW_RESET_TOKEN_LIFETIME = 30;\n\$PW_RESET_TOKEN_LIMIT = 5;\n\$SESSION_NAME = 'LEGACYSESS';\n\$WEBAUTHN_UV_FLAG_REGISTER = 'required';\n");
        $config = $this->config();
        $this->assertSame(30, $config->pwResetTokenLifetime());
        $this->assertSame(5, $config->pwResetTokenLimit());
        $this->assertSame('LEGACYSESS', $config->sessionName());
        $this->assertSame('required', $config->webauthnUvFlagRegister());

        config([
            'restv2.vars_overrides.PW_RESET_TOKEN_LIFETIME' => '10',
            'restv2.vars_overrides.SESSION_SAMESITE_POLICY' => 'Strict',
            'restv2.vars_overrides.WEBAUTHN_UV_FLAG_REGISTER' => 'false',
        ]);
        $config = $this->config();
        $this->assertSame(10, $config->pwResetTokenLifetime());
        $this->assertSame('Strict', $config->sessionSameSitePolicy());
        $this->assertFalse($config->webauthnUvFlagRegister());
    }

    public function test_without_the_files_the_upstream_defaults_apply(): void
    {
        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc']);
        $config = $this->config();

        $this->assertSame(15, $config->pwResetTokenLifetime());
        $this->assertSame(3, $config->pwResetTokenLimit());
        $this->assertSame('MCSESSID', $config->sessionName());

        $autodiscover = $config->autodiscoverSettings($this->request('mail.example.org'));
        $this->assertSame(['server' => 'mail.example.org', 'port' => 993, 'tlsport' => 143], $autodiscover['imap']);
        $this->assertSame(['server' => 'mail.example.org', 'port' => 465, 'tlsport' => 587], $autodiscover['smtp']);
        $this->assertSame(['server' => 'mail.example.org', 'port' => 443], $autodiscover['caldav']);
        $this->assertSame('https://mail.example.org/Microsoft-Server-ActiveSync', $autodiscover['activesync']['url']);
        $this->assertSame('activesync', $autodiscover['autodiscoverType']);
        $this->assertSame('no', $autodiscover['useEASforOutlook']);
    }

    public function test_the_autodiscover_settings_of_the_files_follow_the_request_port(): void
    {
        $config = $this->config();

        $standard = $config->autodiscoverSettings($this->request('mail.example.org'));
        $this->assertSame(443, $standard['caldav']['port']);
        $this->assertSame(443, $standard['carddav']['port']);
        $this->assertSame('https://mail.example.org/Microsoft-Server-ActiveSync', $standard['activesync']['url']);
        $this->assertSame(['server' => 'mail.example.org', 'port' => 993, 'tlsport' => 143], $standard['imap']);

        $custom = $config->autodiscoverSettings($this->request('mail.example.org:8443'));
        $this->assertSame(8443, $custom['caldav']['port']);
        $this->assertSame('https://mail.example.org:8443/Microsoft-Server-ActiveSync', $custom['activesync']['url']);
    }

    /** The mail services are feature 010's resolution (App\Services\Mailcow\AutodiscoverConfig). */
    public function test_ports_keep_the_port_of_an_address_and_an_unset_port_takes_mailcows_default(): void
    {
        $this->setEnv('IMAPS_PORT', '127.0.0.1:10993');
        $this->setEnv('POP_PORT', '');
        $config = $this->config();

        $autodiscover = $config->autodiscoverSettings($this->request('mail.example.org'));
        $this->assertSame(10993, $autodiscover['imap']['port']);
        $this->assertSame(110, $autodiscover['pop3']['tlsport']);

        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc', 'restv2.mailcow_ports.IMAPS_PORT' => 10993]);
        $defaults = $this->config()->autodiscoverSettings($this->request('mail.example.org'));
        $this->assertSame(10993, $defaults['imap']['port']);
        $this->assertSame(110, $defaults['pop3']['tlsport']);
    }

    public function test_an_operator_override_of_the_autodiscover_settings_wins(): void
    {
        $this->copyFixtures("<?php\n\$autodiscover_config['useEASforOutlook'] = 'yes';\n\$autodiscover_config['imap']['server'] = 'imap.example.org';\n");

        $autodiscover = $this->config()->autodiscoverSettings($this->request('mail.example.org'));
        $this->assertSame('yes', $autodiscover['useEASforOutlook']);
        $this->assertSame('imap.example.org', $autodiscover['imap']['server']);

        config(['restv2.vars_overrides.autodiscover_config' => json_encode(['autodiscoverType' => 'imap', 'imap' => ['server' => 'x.example.org', 'port' => 993, 'tlsport' => 143]])]);
        $override = $this->config()->autodiscoverSettings($this->request('mail.example.org'));
        $this->assertSame('imap', $override['autodiscoverType']);
        $this->assertSame('x.example.org', $override['imap']['server']);
    }
}
