<?php

namespace Tests\Unit\Services;

use App\Services\Config\MailcowConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature 013 T014 (research R2): the logo limits (`$LOGO_LIMITS`) and the editable global regex
 * maps (`$RSPAMD_MAPS['regex']`) follow 001's precedence: the RESTV2_* server setting (JSON), then
 * the mounted vars files including the operator's local override, then the upstream defaults.
 */
class MailcowConfigSettingsTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/mailcow-inc';

    private const UPSTREAM_MAPS = [
        'Header-From: Denylist' => 'global_mime_from_blacklist.map',
        'Header-From: Allowlist' => 'global_mime_from_whitelist.map',
        'Envelope Sender Denylist' => 'global_smtp_from_blacklist.map',
        'Envelope Sender Allowlist' => 'global_smtp_from_whitelist.map',
        'Recipient Denylist' => 'global_rcpt_blacklist.map',
        'Recipient Allowlist' => 'global_rcpt_whitelist.map',
        'Fishy TLDS (only fired in combination with bad words)' => 'fishy_tlds.map',
        'Bad Words (only fired in combination with fishy TLDs)' => 'bad_words.map',
        'Bad Words DE (only fired in combination with fishy TLDs)' => 'bad_words_de.map',
        'Bad Languages' => 'bad_languages.map',
        'Bulk Mail Headers' => 'bulk_header.map',
        'Bad (Junk) Mail Headers' => 'bad_header.map',
        'Monitoring Hosts' => 'monitoring_nolog.map',
    ];

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

    private function localOverride(string $php): void
    {
        $this->workDir = storage_path('framework/testing/mailcow-inc-settings-'.Str::random(8));
        File::ensureDirectoryExists($this->workDir);
        File::copy(self::FIXTURES.'/vars.inc.php', $this->workDir.'/vars.inc.php');
        file_put_contents($this->workDir.'/vars.local.inc.php', "<?php\n".$php);
        config(['restv2.mailcow_inc_dir' => $this->workDir]);
    }

    public function test_the_mounted_upstream_files_give_the_upstream_values(): void
    {
        $this->assertSame(['max_size' => 15728640, 'max_width' => 1920, 'max_height' => 1920], $this->config()->logoLimits());
        $this->assertSame(self::UPSTREAM_MAPS, $this->config()->rspamdRegexMaps());
    }

    public function test_a_local_override_changes_a_limit_and_removes_a_map(): void
    {
        $this->localOverride("\$LOGO_LIMITS['max_width'] = 800;\nunset(\$RSPAMD_MAPS['regex']['Monitoring Hosts']);\n");

        $this->assertSame(['max_size' => 15728640, 'max_width' => 800, 'max_height' => 1920], $this->config()->logoLimits());
        $this->assertArrayNotHasKey('Monitoring Hosts', $this->config()->rspamdRegexMaps());
        $this->assertCount(12, $this->config()->rspamdRegexMaps());
    }

    public function test_the_server_settings_win(): void
    {
        config([
            'restv2.vars_overrides.LOGO_LIMITS' => '{"max_size": 1048576, "max_width": 640, "max_height": 480}',
            'restv2.vars_overrides.RSPAMD_MAPS' => '{"regex": {"Only One": "global_rcpt_blacklist.map"}}',
        ]);

        $this->assertSame(['max_size' => 1048576, 'max_width' => 640, 'max_height' => 480], $this->config()->logoLimits());
        $this->assertSame(['Only One' => 'global_rcpt_blacklist.map'], $this->config()->rspamdRegexMaps());
    }

    public function test_nothing_mounted_gives_the_upstream_defaults(): void
    {
        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc']);

        $this->assertSame(['max_size' => 15728640, 'max_width' => 1920, 'max_height' => 1920], $this->config()->logoLimits());
        $this->assertSame(self::UPSTREAM_MAPS, $this->config()->rspamdRegexMaps());
    }

    public function test_unusable_values_fall_back_and_unsafe_file_names_are_left_out(): void
    {
        config([
            'restv2.vars_overrides.LOGO_LIMITS' => '{"max_size": "big", "max_width": -1}',
            'restv2.vars_overrides.RSPAMD_MAPS' => '{"regex": {"Escape": "../../etc/passwd", "Good": "good.map", "Spaces": "a b.map"}}',
        ]);

        $this->assertSame(['max_size' => 15728640, 'max_width' => 1920, 'max_height' => 1920], $this->config()->logoLimits());
        $this->assertSame(['Good' => 'good.map'], $this->config()->rspamdRegexMaps());
    }
}
