<?php

namespace Tests\Unit\Services;

use App\Services\Config\MailcowConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature 008 T007 (research R7, R18): the custom imapsync parameter allow-list `$IMAPSYNC_OPTIONS`
 * is read with 001's precedence: RESTV2_IMAPSYNC_OPTIONS (JSON), then the mounted vars files, then
 * upstream 2026-09a's list. A malformed server setting is ignored with a warning.
 */
class MailcowConfigImapsyncOptionsTest extends TestCase
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
     * A copy of 001's fixture directory whose vars.local.inc.php overrides the allow-list (the
     * operator's local file, T004).
     */
    private function localOverride(): void
    {
        $this->workDir = storage_path('framework/testing/mailcow-inc-imapsync-'.Str::random(8));
        File::ensureDirectoryExists($this->workDir);
        File::copy(self::FIXTURES.'/vars.inc.php', $this->workDir.'/vars.inc.php');
        file_put_contents($this->workDir.'/vars.local.inc.php', "<?php\n\$IMAPSYNC_OPTIONS['whitelist'] = ['maxsize', 'minage', 'showpasswords', 'MixedCase'];\n");
        config(['restv2.mailcow_inc_dir' => $this->workDir]);
    }

    /**
     * The upstream lists, read from the unmodified upstream vars.inc.php of the fixture.
     *
     * @return array{whitelist: list<string>, blacklist: list<string>}
     */
    private static function upstream(): array
    {
        $source = (string) file_get_contents(self::FIXTURES.'/vars.inc.php');
        preg_match("/'whitelist' => array\\((.*?)\\)/s", $source, $white);
        preg_match("/'blacklist' => array\\((.*?)\\)/s", $source, $black);
        $names = fn (string $list): array => array_values(array_map(fn (string $s): string => trim($s, " \n'"), array_filter(explode(',', $list), fn (string $s): bool => trim($s) !== '')));

        return ['whitelist' => $names($white[1] ?? ''), 'blacklist' => $names($black[1] ?? '')];
    }

    public function test_the_mounted_upstream_file_gives_the_114_and_8_upstream_entries_in_order(): void
    {
        $upstream = self::upstream();
        $this->assertCount(114, $upstream['whitelist']);
        $this->assertCount(8, $upstream['blacklist']);

        $this->assertSame($upstream, $this->config()->imapsyncOptions());
    }

    public function test_the_built_in_default_is_upstream_s_list_when_nothing_is_mounted(): void
    {
        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc']);

        $this->assertSame(self::upstream(), $this->config()->imapsyncOptions());
    }

    public function test_the_operator_s_local_file_applies(): void
    {
        $this->localOverride();

        $options = $this->config()->imapsyncOptions();

        $this->assertSame(['maxsize', 'minage', 'showpasswords', 'MixedCase'], $options['whitelist']);
        $this->assertSame(self::upstream()['blacklist'], $options['blacklist']);
    }

    public function test_the_server_setting_beats_the_files(): void
    {
        $this->localOverride();
        config(['restv2.vars_overrides.IMAPSYNC_OPTIONS' => '{"whitelist":["maxsize"],"blacklist":[]}']);

        $this->assertSame(['whitelist' => ['maxsize'], 'blacklist' => []], $this->config()->imapsyncOptions());
    }

    public function test_a_malformed_server_setting_falls_back_to_the_files_with_one_warning(): void
    {
        $this->localOverride();
        config(['restv2.vars_overrides.IMAPSYNC_OPTIONS' => '{"whitelist": [maxsize']);
        $config = $this->config();
        Log::spy();

        $this->assertSame(['maxsize', 'minage', 'showpasswords', 'MixedCase'], $config->imapsyncOptions()['whitelist']);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'RESTV2_IMAPSYNC_OPTIONS'));
    }
}
