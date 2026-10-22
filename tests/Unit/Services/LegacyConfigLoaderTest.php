<?php

namespace Tests\Unit\Services;

use App\Services\Config\LegacyConfigLoader;
use App\Services\Config\MailcowConfig;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class LegacyConfigLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/legacy-config-loader-'.Str::random(8));
        File::ensureDirectoryExists($this->directory);
        File::put($this->directory.'/vars.inc.php', <<<'PHP'
            <?php
            file_put_contents(__DIR__.'/export-count', 'x', FILE_APPEND);
            $SESSION_LIFETIME = 600;
            $LOADER_TEST_HOST = $_SERVER['HTTP_HOST'];
            PHP);
        config(['restv2.mailcow_inc_dir' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function loader(): LegacyConfigLoader
    {
        return new LegacyConfigLoader($this->app->make(CacheFactory::class)->store('restv2'));
    }

    public function test_a_cached_export_is_reused_by_a_new_loader_instance(): void
    {
        $loader = $this->loader();

        $this->assertSame(600, $loader->load()['SESSION_LIFETIME']);
        $this->assertSame(600, $loader->load()['SESSION_LIFETIME']);
        $this->assertSame(600, $this->loader()->load()['SESSION_LIFETIME']);
        $this->assertSame('x', File::get($this->directory.'/export-count'));
    }

    public function test_exported_names_and_hostname_invalidate_the_cached_export(): void
    {
        $loader = $this->loader();
        $this->assertArrayNotHasKey('LOADER_TEST_HOST', $loader->load());

        config(['restv2.vars_defaults.LOADER_TEST_HOST' => null]);
        $this->assertSame('mail.example.org:'.MailcowConfig::HTTPS_PORT_PLACEHOLDER, $loader->load()['LOADER_TEST_HOST']);

        config(['restv2.mailcow_hostname' => 'other.example.org']);
        $this->assertSame('other.example.org:'.MailcowConfig::HTTPS_PORT_PLACEHOLDER, $loader->load()['LOADER_TEST_HOST']);
        $this->assertSame('xxx', File::get($this->directory.'/export-count'));
    }

    public function test_unavailable_shared_cache_keeps_file_loading_and_per_instance_memo_working(): void
    {
        /** @var Repository&MockInterface $cache */
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('get')->twice()->andThrow(new RuntimeException('Cache unavailable'));
        $cache->shouldReceive('put')->twice()->andThrow(new RuntimeException('Cache unavailable'));
        $log = Log::spy();
        $loader = new LegacyConfigLoader($cache);

        $this->assertSame(600, $loader->load()['SESSION_LIFETIME']);
        $this->assertSame(600, $loader->load()['SESSION_LIFETIME']);
        $this->assertSame(600, (new LegacyConfigLoader($cache))->load()['SESSION_LIFETIME']);
        $this->assertSame('xx', File::get($this->directory.'/export-count'));
        $log->shouldNotHaveReceived('warning');
    }
}
