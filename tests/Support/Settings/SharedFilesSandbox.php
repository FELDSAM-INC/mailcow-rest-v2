<?php

namespace Tests\Support\Settings;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The shared mailcow files of feature 013 for one test (T008): a copy of the fixtures of
 * tests/fixtures/mailcow/ under storage/framework/testing/mailcow/, laid out as the API container
 * sees them (the Rspamd map files mode 0644 in a 0755 directory, as Rspamd's entrypoint leaves them,
 * and the two global Sieve files), with the `restv2.*_dir` settings pointing at it. Removed again at
 * the end of the test.
 */
final class SharedFilesSandbox
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = storage_path('framework/testing/mailcow');
        $fixtures = base_path('tests/fixtures/mailcow');

        $this->remove();
        File::ensureDirectoryExists($this->mapDir(), 0755);
        File::ensureDirectoryExists($this->sieveDir(), 0755);

        foreach (glob($fixtures.'/rspamd-custom/*.map') ?: [] as $map) {
            copy($map, $this->mapDir().'/'.basename($map));
            chmod($this->mapDir().'/'.basename($map), 0644);
        }

        copy($fixtures.'/global-sieve/global_sieve_before', $this->sieveDir().'/before');
        copy($fixtures.'/global-sieve/global_sieve_after', $this->sieveDir().'/after');
        chmod($this->mapDir(), 0755);

        config([
            'restv2.rspamd_map_dir' => $this->mapDir(),
            'restv2.global_sieve_dir' => $this->sieveDir(),
        ]);
    }

    public function mapDir(): string
    {
        return $this->root.'/rspamd_custom_maps';
    }

    public function sieveDir(): string
    {
        return $this->root.'/global_sieve';
    }

    public function mapPath(string $file): string
    {
        return $this->mapDir().'/'.$file;
    }

    public function mapContent(string $file): string
    {
        clearstatcache(true, $this->mapPath($file));

        return (string) file_get_contents($this->mapPath($file));
    }

    public function writeMap(string $file, string $content): void
    {
        file_put_contents($this->mapPath($file), $content);
    }

    public function mapMtime(string $file): int
    {
        clearstatcache(true, $this->mapPath($file));

        return (int) filemtime($this->mapPath($file));
    }

    public function mapInode(string $file): int
    {
        clearstatcache(true, $this->mapPath($file));

        return (int) fileinode($this->mapPath($file));
    }

    public function removeMap(string $file): void
    {
        @unlink($this->mapPath($file));
    }

    /** The path of `before` (pre-filter) or `after` (post-filter). */
    public function sievePath(string $which): string
    {
        return $this->sieveDir().'/'.$which;
    }

    public function sieve(string $which): string
    {
        clearstatcache(true, $this->sievePath($which));

        return (string) file_get_contents($this->sievePath($which));
    }

    public function writeSieve(string $which, string $content): void
    {
        file_put_contents($this->sievePath($which), $content);
    }

    public function removeSieve(string $which): void
    {
        @unlink($this->sievePath($which));
    }

    /** No file can be created in the map directory any more, as in a real deployment. */
    public function makeDirectoryReadOnly(): void
    {
        if (! chmod($this->mapDir(), 0555)) {
            throw new RuntimeException('The sandbox map directory could not be made read-only.');
        }
    }

    public function remove(): void
    {
        if (is_dir($this->root)) {
            @chmod($this->mapDir(), 0755);
            File::deleteDirectory($this->root);
        }
    }
}
