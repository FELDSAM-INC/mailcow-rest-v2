<?php

namespace Tests\Unit\Services;

use App\Services\Config\MailcowConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T029 (R14): legacy PHP configuration is read from mailcow's mounted data/web/inc, with RESTV2_*
 * server settings winning and upstream defaults as the fallback, cached by file fingerprint.
 */
class MailcowConfigTest extends TestCase
{
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
     * A writable copy of the fixture directory.
     *
     * @param  list<string>  $files
     */
    private function copyFixtures(array $files = ['vars.inc.php', 'vars.local.inc.php']): string
    {
        $this->workDir = storage_path('framework/testing/mailcow-inc-'.Str::random(8));
        File::ensureDirectoryExists($this->workDir);

        foreach ($files as $file) {
            File::copy(self::FIXTURES.'/'.$file, $this->workDir.'/'.$file);
        }

        config(['restv2.mailcow_inc_dir' => $this->workDir]);

        return $this->workDir;
    }

    public function test_values_come_from_the_local_override(): void
    {
        $config = $this->config();

        $this->assertSame(7200, $config->sessionLifetime());
        $this->assertSame('cs-cz', $config->defaultLanguage());
        $this->assertSame(['cs-cz' => 'Čeština (Czech)', 'en-gb' => 'English', 'sk-sk' => 'Slovenčina (Slovak)'], $config->availableLanguages());
        // Not overridden locally: the values of the mounted vars.inc.php.
        $this->assertSame(['apple', 'android-key', 'android-safetynet', 'fido-u2f', 'none', 'packed', 'tpm'], $config->fido2Formats());
        $this->assertSame('preferred', $config->fido2UvRegister());
        $this->assertSame('preferred', $config->fido2UvLogin());
        $this->assertTrue($config->fido2UserPresent());
        $this->assertFalse($config->webauthnUvLogin());
        $this->assertTrue($config->webauthnUserPresent());
        $this->assertSame('mailcow UI', $config->otpLabel());
    }

    public function test_a_missing_directory_gives_the_upstream_defaults_and_one_warning(): void
    {
        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc']);
        $log = Log::spy();

        $config = $this->config();

        $this->assertSame(10800, $config->sessionLifetime());
        $this->assertSame('en-gb', $config->defaultLanguage());
        $this->assertCount(31, $config->availableLanguages());
        $this->assertSame('mailcow UI', $config->otpLabel());
        $this->assertSame(10800, $this->config()->sessionLifetime());

        $log->shouldHaveReceived('warning')->once();
    }

    public function test_a_syntax_error_in_the_local_file_falls_back_to_the_defaults(): void
    {
        $dir = $this->copyFixtures();
        file_put_contents($dir.'/vars.local.inc.php', "<?php\n\$SESSION_LIFETIME = ;\n");
        $log = Log::spy();

        $config = $this->config();

        $this->assertSame(10800, $config->sessionLifetime());
        $this->assertSame('en-gb', $config->defaultLanguage());
        $log->shouldHaveReceived('warning')->once();
    }

    public function test_the_built_in_apps_are_exported(): void
    {
        $dir = $this->copyFixtures(['vars.inc.php']);

        $this->assertSame([[
            'name' => 'Webmail',
            'link' => '/SOGo/so',
            'user_link' => '/SOGo/so',
            'hide' => true,
        ]], $this->config()->mailcowApps());

        file_put_contents($dir.'/vars.local.inc.php', <<<'PHP'
            <?php
            $MAILCOW_APPS[] = array('name' => 'Wiki', 'link' => 'https://wiki.example.org/', 'description' => 'Docs');
            PHP);

        $apps = $this->config()->mailcowApps();
        $this->assertCount(2, $apps);
        $this->assertSame(['name' => 'Wiki', 'link' => 'https://wiki.example.org/', 'description' => 'Docs'], $apps[1]);
    }

    public function test_server_settings_beat_the_files(): void
    {
        config([
            'restv2.vars_overrides.SESSION_LIFETIME' => '600',
            'restv2.vars_overrides.MAILCOW_APPS' => '[{"name":"Portal","link":"https://portal.example.org/"}]',
            'restv2.vars_overrides.FIDO2_UV_FLAG_LOGIN' => 'required',
            'restv2.vars_overrides.WEBAUTHN_USER_PRESENT_FLAG' => 'false',
        ]);

        $config = $this->config();

        $this->assertSame(600, $config->sessionLifetime());
        $this->assertSame([['name' => 'Portal', 'link' => 'https://portal.example.org/']], $config->mailcowApps());
        $this->assertSame('required', $config->fido2UvLogin());
        $this->assertFalse($config->webauthnUserPresent());
        // Not overridden: still from the files.
        $this->assertSame('cs-cz', $config->defaultLanguage());
    }

    public function test_the_export_is_cached_by_file_fingerprint(): void
    {
        $dir = $this->copyFixtures();
        $local = $dir.'/vars.local.inc.php';
        $config = $this->config();

        $this->assertSame(7200, $config->sessionLifetime());
        $keys = Redis::connection('mailcow')->keys('restv2:mailcow-vars:*');
        $this->assertCount(1, $keys);

        // Same size, same modification time: the cached export is used.
        $mtime = (int) filemtime($local);
        file_put_contents($local, str_replace('7200', '3600', (string) file_get_contents($local)));
        touch($local, $mtime);
        $this->assertSame(7200, $config->sessionLifetime());

        // A new modification time changes the fingerprint: the next call re-exports.
        touch($local, $mtime + 60);
        $this->assertSame(3600, $config->sessionLifetime());
        $this->assertCount(2, Redis::connection('mailcow')->keys('restv2:mailcow-vars:*'));
    }

    public function test_the_subprocess_leaves_error_reporting_alone(): void
    {
        $this->copyFixtures();
        $before = error_reporting();

        $this->config()->sessionLifetime();

        $this->assertSame($before, error_reporting());
    }

    public function test_the_session_lifetime_is_applied_at_boot(): void
    {
        // 7200 s from the fixture, in minutes.
        $this->assertSame(120, config('session.lifetime'));
    }

    public function test_the_tagging_limit_and_docker_timeout_come_from_the_vars_files(): void
    {
        // Feature 002 T008 (research D24): upstream values of the mounted vars.inc.php.
        $config = $this->config();

        $this->assertSame(25, $config->taggingLimit());
        $this->assertSame(60, $config->dockerTimeout());
    }

    public function test_a_local_tagging_limit_wins_over_vars_inc(): void
    {
        $dir = $this->copyFixtures();
        file_put_contents($dir.'/vars.local.inc.php', "\n\$TAGGING_LIMIT = 30;\n\$DOCKER_TIMEOUT = 45;\n", FILE_APPEND);

        $config = $this->config();

        $this->assertSame(30, $config->taggingLimit());
        $this->assertSame(45, $config->dockerTimeout());
    }

    public function test_server_settings_win_over_the_files(): void
    {
        config(['restv2.vars_overrides.TAGGING_LIMIT' => '40', 'restv2.vars_overrides.DOCKER_TIMEOUT' => '15']);

        $config = $this->config();

        $this->assertSame(40, $config->taggingLimit());
        $this->assertSame(15, $config->dockerTimeout());
    }

    public function test_the_upstream_defaults_apply_without_the_directory(): void
    {
        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc']);
        Log::spy();

        $config = $this->config();

        $this->assertSame(25, $config->taggingLimit());
        $this->assertSame(60, $config->dockerTimeout());
    }

    public function test_last_logins_are_shown_by_default(): void
    {
        // Feature 003 T003 (FR-010): legacy's $SHOW_LAST_LOGIN of the mounted vars.inc.php.
        $this->assertTrue($this->config()->showLastLogin());
    }

    public function test_a_local_file_can_turn_off_the_last_logins(): void
    {
        $dir = $this->copyFixtures();
        file_put_contents($dir.'/vars.local.inc.php', "\n\$SHOW_LAST_LOGIN = false;\n", FILE_APPEND);

        $this->assertFalse($this->config()->showLastLogin());
    }

    public function test_the_last_login_server_setting_wins(): void
    {
        config(['restv2.vars_overrides.SHOW_LAST_LOGIN' => 'n']);

        $this->assertFalse($this->config()->showLastLogin());
    }

    public function test_the_mailbox_defaults_are_the_upstream_values_without_the_directory(): void
    {
        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc']);
        Log::spy();

        $defaults = $this->config()->mailboxDefaultAttributes();

        $this->assertSame([
            'tls_enforce_in' => false,
            'tls_enforce_out' => false,
            'force_pw_update' => false,
            'force_tfa' => false,
            'sogo_access' => true,
            'tagged_mail_handler' => 'none',
            'quarantine_notification' => 'hourly',
            'imap_access' => true,
            'pop3_access' => true,
            'smtp_access' => true,
            'sieve_access' => true,
            'eas_access' => true,
            'dav_access' => true,
            'quarantine_category' => 'reject',
            'mailbox_format' => 'maildir:',
        ], $defaults);
    }

    public function test_a_local_file_overrides_a_mailbox_default(): void
    {
        $dir = $this->copyFixtures();
        file_put_contents($dir.'/vars.local.inc.php', "\n\$MAILBOX_DEFAULT_ATTRIBUTES['sogo_access'] = false;\n\$MAILBOX_DEFAULT_ATTRIBUTES['quarantine_notification'] = 'daily';\n", FILE_APPEND);

        $defaults = $this->config()->mailboxDefaultAttributes();

        $this->assertFalse($defaults['sogo_access']);
        $this->assertSame('daily', $defaults['quarantine_notification']);
        $this->assertTrue($defaults['imap_access']);
    }

    public function test_the_mailbox_defaults_server_setting_is_merged_over_the_upstream_values(): void
    {
        config(['restv2.vars_overrides.MAILBOX_DEFAULT_ATTRIBUTES' => '{"pop3_access":false,"tagged_mail_handler":"subject"}']);

        $defaults = $this->config()->mailboxDefaultAttributes();

        $this->assertFalse($defaults['pop3_access']);
        $this->assertSame('subject', $defaults['tagged_mail_handler']);
        $this->assertTrue($defaults['sogo_access']);
        $this->assertSame('maildir:', $defaults['mailbox_format']);
    }
}
