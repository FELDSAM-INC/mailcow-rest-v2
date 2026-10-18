<?php

namespace Tests\Unit\Support;

use App\Services\Config\MailcowConfig;
use App\Services\Mailcow\AutodiscoverConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\Config\TemporaryEnvironment;
use Tests\TestCase;

/**
 * Feature 010 T005 (research R9, R14, R17): `$SHOW_DKIM_PRIV_KEYS` and the service entries of
 * `$autodiscover_config` are read with 001's precedence: the RESTV2_ server setting, then the
 * mounted vars files, then the upstream default (no export; ports from mailcow.conf's port
 * variables). The `sieve` entry, which upstream's vars.inc.php does not define, falls back to
 * SIEVE_PORT as legacy dns_diagnostics.php does.
 */
class MailcowConfigDkimTest extends TestCase
{
    use TemporaryEnvironment;

    private const FIXTURES = __DIR__.'/../../fixtures/mailcow-inc';

    private ?string $workDir = null;

    protected function tearDown(): void
    {
        if ($this->workDir !== null) {
            File::deleteDirectory($this->workDir);
        }

        parent::tearDown();
    }

    private function config(): MailcowConfig
    {
        $this->app->forgetInstance(MailcowConfig::class);

        return $this->app->make(MailcowConfig::class);
    }

    /**
     * A copy of 001's fixture directory with an operator's vars.local.inc.php.
     */
    private function localOverride(string $php): void
    {
        $this->workDir = storage_path('framework/testing/mailcow-inc-dkim-'.Str::random(8));
        File::ensureDirectoryExists($this->workDir);
        File::copy(self::FIXTURES.'/vars.inc.php', $this->workDir.'/vars.inc.php');
        file_put_contents($this->workDir.'/vars.local.inc.php', "<?php\n".$php."\n");
        config(['restv2.mailcow_inc_dir' => $this->workDir]);
    }

    public function test_the_operator_s_local_file_applies(): void
    {
        $this->localOverride("\$SHOW_DKIM_PRIV_KEYS = true;\n\$autodiscover_config['imap']['port'] = 10993;\n\$autodiscover_config['smtp']['server'] = 'smtp.example.net';");
        $config = $this->config();

        $this->assertTrue($config->showDkimPrivateKeys());
        $this->assertSame(10993, $config->autodiscoverConfig()['imap']['port']);

        $ports = AutodiscoverConfig::resolve($config);
        $this->assertSame(10993, $ports->imapsPort);
        $this->assertSame('smtp.example.net', $ports->smtpHost);
        // Upstream's file reads IMAP_PORT and friends from the environment; unset there, the
        // port variables of this API's settings apply.
        $this->assertSame(143, $ports->imapPort);
        $this->assertSame(995, $ports->pop3sPort);
    }

    public function test_the_server_settings_win_over_the_files(): void
    {
        $this->localOverride("\$SHOW_DKIM_PRIV_KEYS = true;\n\$autodiscover_config['imap']['port'] = 10993;");
        config([
            'restv2.vars_overrides.SHOW_DKIM_PRIV_KEYS' => false,
            'restv2.vars_overrides.autodiscover_config' => '{"imap":{"server":"imap.example.net","port":20993,"tlsport":20143},"sieve":{"server":"sieve.example.net","port":24190}}',
        ]);
        $config = $this->config();

        $this->assertFalse($config->showDkimPrivateKeys());

        $ports = AutodiscoverConfig::resolve($config);
        $this->assertSame(20993, $ports->imapsPort);
        $this->assertSame(20143, $ports->imapPort);
        $this->assertSame('imap.example.net', $ports->imapHost);
        $this->assertSame(24190, $ports->sievePort);
        $this->assertSame('sieve.example.net', $ports->sieveHost);
        $this->assertSame(587, $ports->submissionPort);
    }

    public function test_the_string_setting_true_enables_the_export(): void
    {
        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc', 'restv2.vars_overrides.SHOW_DKIM_PRIV_KEYS' => 'true']);

        $this->assertTrue($this->config()->showDkimPrivateKeys());
    }

    public function test_without_the_mount_the_defaults_apply_with_the_port_variables(): void
    {
        config([
            'restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc',
            'restv2.mailcow_ports.IMAPS_PORT' => 1993,
            'restv2.mailcow_ports.SUBMISSION_PORT' => 1587,
        ]);
        $config = $this->config();

        $this->assertFalse($config->showDkimPrivateKeys());
        $this->assertSame([], $config->autodiscoverConfig());

        $ports = AutodiscoverConfig::resolve($config);
        $this->assertSame('mail.example.org', $ports->smtpHost);
        $this->assertSame('mail.example.org', $ports->imapHost);
        $this->assertSame(1993, $ports->imapsPort);
        $this->assertSame(143, $ports->imapPort);
        $this->assertSame(995, $ports->pop3sPort);
        $this->assertSame(110, $ports->pop3Port);
        $this->assertSame(465, $ports->smtpsPort);
        $this->assertSame(1587, $ports->submissionPort);
        $this->assertSame(4190, $ports->sievePort);
    }

    public function test_the_sieve_entry_falls_back_to_sieve_port(): void
    {
        $this->localOverride("\$autodiscover_config['imap']['port'] = 993;");
        config(['restv2.mailcow_ports.SIEVE_PORT' => 14190]);

        $config = $this->config();

        $this->assertArrayNotHasKey('sieve', $config->autodiscoverConfig());
        $this->assertSame(14190, AutodiscoverConfig::resolve($config)->sievePort);
    }

    public function test_the_port_variables_take_the_last_colon_separated_part(): void
    {
        $this->setEnvironment(['IMAPS_PORT' => '127.0.0.1:10993', 'SIEVE_PORT' => '4191', 'POP_PORT' => 'not-a-port', 'IMAP_PORT' => '']);

        $settings = (array) require base_path('config/restv2.php');

        $this->assertSame(10993, $settings['mailcow_ports']['IMAPS_PORT']);
        $this->assertSame(4191, $settings['mailcow_ports']['SIEVE_PORT']);
        $this->assertSame(110, $settings['mailcow_ports']['POP_PORT']);
        $this->assertSame(143, $settings['mailcow_ports']['IMAP_PORT']);
    }
}
