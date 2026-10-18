<?php

namespace Tests\Feature\Config;

use App\Services\Config\MailcowConfig;
use App\Services\Monitoring\InstallationVersionService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\TestCase;

/**
 * Feature 011 T012 (research R9, FR-018): the installed version comes from mailcow's generated
 * app_info.inc.php and DOCKER_TIMEOUT from vars(.local).inc.php, through 001's configuration reader.
 */
class MailcowConfigInstallationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/monitoring-inc-'.Str::random(8));
        File::ensureDirectoryExists($this->dir);
        File::copy(base_path('tests/fixtures/mailcow-inc/vars.inc.php'), $this->dir.'/vars.inc.php');
        File::copy(MonitoringFixtures::DIR.'/inc/vars.local.inc.php', $this->dir.'/vars.local.inc.php');
        File::copy(MonitoringFixtures::DIR.'/inc/app_info.inc.php', $this->dir.'/app_info.inc.php');
        config(['restv2.mailcow_inc_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function config(): MailcowConfig
    {
        $this->app->forgetInstance(MailcowConfig::class);

        return $this->app->make(MailcowConfig::class);
    }

    public function test_the_installed_version_and_docker_timeout_are_read_from_the_files(): void
    {
        $config = $this->config();
        $version = $this->app->make(InstallationVersionService::class)->current();

        $this->assertSame(30, $config->dockerTimeout());
        $this->assertSame('2026-09a', $version->tag);
        $this->assertSame('master', $version->branch);
        $this->assertNull($version->commit, 'an empty commit is null');
        $this->assertSame('2026-09-15T08:11:12Z', $version->commitDate);
        $this->assertSame('https://github.com/mailcow/mailcow-dockerized', $version->projectUrl);
        $this->assertSame('mailcow', $config->appInfo()['owner']);
        $this->assertSame('mailcow-dockerized', $config->appInfo()['repo']);
    }

    public function test_the_server_setting_overrides_docker_timeout(): void
    {
        config(['restv2.vars_overrides.DOCKER_TIMEOUT' => '45']);

        $this->assertSame(45, $this->config()->dockerTimeout());
    }

    public function test_without_the_files_the_defaults_apply(): void
    {
        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc']);
        $config = $this->config();

        $this->assertSame(60, $config->dockerTimeout());
        $this->assertSame(['version' => null, 'branch' => null, 'commit' => null, 'commitDate' => null, 'url' => null, 'owner' => null, 'repo' => null], $config->appInfo());
    }

    public function test_without_app_info_the_version_is_unknown(): void
    {
        File::delete($this->dir.'/app_info.inc.php');

        $version = $this->app->make(InstallationVersionService::class)->current();

        $this->assertNull($version->tag);
        $this->assertNull($version->branch);
        $this->assertSame(30, $this->config()->dockerTimeout());
    }

    public function test_an_update_of_app_info_is_picked_up_by_the_next_call(): void
    {
        $config = $this->config();
        $this->assertSame('2026-09a', $config->appInfo()['version']);

        File::put($this->dir.'/app_info.inc.php', "<?php\n  \$MAILCOW_GIT_VERSION=\"2026-10\";\n  \$MAILCOW_BRANCH=\"master\";\n  \$MAILCOW_GIT_COMMIT=\"0123456789abcdef\";\n  \$MAILCOW_GIT_COMMIT_DATE=\"\";\n?>\n");
        touch($this->dir.'/app_info.inc.php', time() + 5);

        $info = $config->appInfo();
        $this->assertSame('2026-10', $info['version']);
        $this->assertSame('0123456789abcdef', $info['commit']);
        $this->assertNull($info['commitDate'], 'an empty date is null');
        $this->assertNull($info['url'], 'a variable the file does not define is null');
    }

    public function test_the_version_values_can_be_server_settings(): void
    {
        config(['restv2.vars_overrides.MAILCOW_GIT_VERSION' => '2026-09b', 'restv2.vars_overrides.MAILCOW_BRANCH' => 'feldhost']);

        $info = $this->config()->appInfo();

        $this->assertSame('2026-09b', $info['version']);
        $this->assertSame('feldhost', $info['branch']);
    }

    public function test_an_unparseable_commit_date_is_null(): void
    {
        config(['restv2.vars_overrides.MAILCOW_GIT_COMMIT_DATE' => 'yesterday-ish']);

        $this->assertNull($this->app->make(InstallationVersionService::class)->current()->commitDate);
    }
}
