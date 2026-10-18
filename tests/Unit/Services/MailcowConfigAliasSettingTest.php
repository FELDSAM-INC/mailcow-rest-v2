<?php

namespace Tests\Unit\Services;

use App\Services\Config\MailcowConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature 004 T005 (FR-007, research R21): `ALIAS_DISABLE_EXTERNAL_DOMAINS` is read with 001's
 * precedence: RESTV2_ALIAS_DISABLE_EXTERNAL_DOMAINS, then the mounted vars files, then the upstream
 * default `false`. A change of the files is picked up by the next call.
 */
class MailcowConfigAliasSettingTest extends TestCase
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

    private function copyFixtures(): string
    {
        $this->workDir = storage_path('framework/testing/mailcow-inc-'.Str::random(8));
        File::ensureDirectoryExists($this->workDir);

        foreach (['vars.inc.php', 'vars.local.inc.php'] as $file) {
            File::copy(self::FIXTURES.'/'.$file, $this->workDir.'/'.$file);
        }

        config(['restv2.mailcow_inc_dir' => $this->workDir]);

        return $this->workDir;
    }

    public function test_the_setting_is_off_by_default(): void
    {
        $this->assertFalse($this->config()->aliasDisableExternalDomains());

        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc']);
        $this->assertFalse($this->config()->aliasDisableExternalDomains());
    }

    public function test_the_local_file_turns_it_on_and_a_change_is_picked_up(): void
    {
        $dir = $this->copyFixtures();
        $config = $this->config();
        $this->assertFalse($config->aliasDisableExternalDomains());

        file_put_contents($dir.'/vars.local.inc.php', "<?php\n\$ALIAS_DISABLE_EXTERNAL_DOMAINS = true;\n");
        touch($dir.'/vars.local.inc.php', time() + 5);

        $this->assertTrue($config->aliasDisableExternalDomains());
    }

    public function test_the_server_setting_beats_the_files(): void
    {
        $dir = $this->copyFixtures();
        file_put_contents($dir.'/vars.local.inc.php', "<?php\n\$ALIAS_DISABLE_EXTERNAL_DOMAINS = true;\n");

        config(['restv2.vars_overrides.ALIAS_DISABLE_EXTERNAL_DOMAINS' => 'false']);
        $this->assertFalse($this->config()->aliasDisableExternalDomains());

        config(['restv2.vars_overrides.ALIAS_DISABLE_EXTERNAL_DOMAINS' => 'true']);
        file_put_contents($dir.'/vars.local.inc.php', "<?php\n");
        $this->assertTrue($this->config()->aliasDisableExternalDomains());
    }
}
