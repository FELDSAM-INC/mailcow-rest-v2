<?php

namespace App\Services\Config;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Loads raw values from mailcow's mounted PHP configuration in an isolated subprocess (R14).
 *
 * The fingerprint includes the directory, requested variable names, hostname and each legacy
 * file's size, modification time and inode. Both the per-instance memo and shared Redis cache use
 * it, so changed files are read on the next call. Failed exports are cached too, preserving one
 * warning per fingerprint while the shared cache is available. Override precedence, typed
 * settings and request-dependent autodiscover resolution belong to MailcowConfig.
 */
class LegacyConfigLoader
{
    private const CACHE_PREFIX = 'mailcow-vars:';

    private const CACHE_TTL = 86400;

    private const TIMEOUT = 5.0;

    private ?string $memoFingerprint = null;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Repository $cache) {}

    /**
     * The variables evaluated from the mounted files, or [] when they are missing or invalid.
     *
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    public function load(): array
    {
        $dir = $this->directory();
        $fingerprint = $this->fingerprint($dir);

        if ($fingerprint === $this->memoFingerprint) {
            return $this->memo;
        }

        $key = self::CACHE_PREFIX.$fingerprint;
        $cached = $this->cacheGet($key);

        if (is_array($cached) && array_key_exists('ok', $cached)) {
            $values = $cached['ok'] === true && is_array($cached['values'] ?? null) ? $cached['values'] : [];
        } else {
            [$ok, $values, $reason] = $this->export($dir);

            if (! $ok) {
                Log::warning("mailcow configuration not read ({$reason}); upstream defaults apply. Mount mailcow's data/web/inc at RESTV2_MAILCOW_INC_DIR or set RESTV2_* server settings.");
            }

            $this->cachePut($key, ['ok' => $ok, 'values' => $values]);
        }

        $this->memoFingerprint = $fingerprint;

        return $this->memo = $values;
    }

    private function directory(): ?string
    {
        $dir = trim((string) config('restv2.mailcow_inc_dir'));

        if ($dir === '') {
            return null;
        }

        return str_starts_with($dir, '/') ? rtrim($dir, '/') : base_path(rtrim($dir, '/'));
    }

    private function fingerprint(?string $dir): string
    {
        $names = $this->names();
        $parts = ['dir' => $dir, 'names' => $names, 'host' => $this->hostname()];

        if ($dir !== null) {
            clearstatcache(true);

            foreach (['vars.inc.php', 'vars.local.inc.php', 'app_info.inc.php'] as $file) {
                $stat = @stat($dir.'/'.$file);
                $parts[$file] = $stat === false ? null : [$stat['size'], $stat['mtime'], $stat['ino']];
            }
        }

        return hash('sha256', (string) json_encode($parts));
    }

    /**
     * @return array{bool, array<string, mixed>, string}
     */
    private function export(?string $dir): array
    {
        if ($dir === null || ! is_file($dir.'/vars.inc.php')) {
            return [false, [], 'directory '.($dir ?? '(not set)').' has no vars.inc.php'];
        }

        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $process = new Process([
            $php, '-n', '-d', 'display_errors=stderr',
            resource_path('bin/export-mailcow-vars.php'),
            $dir,
            $this->hostname(),
            ...$this->names(),
        ]);
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable $e) {
            return [false, [], 'the export failed: '.$e->getMessage()];
        }

        if (! $process->isSuccessful()) {
            return [false, [], 'the export exited with '.$process->getExitCode().': '.trim(substr($process->getErrorOutput(), 0, 300))];
        }

        $values = json_decode($process->getOutput(), true);

        return is_array($values)
            ? [true, $values, '']
            : [false, [], 'the export printed no JSON object'];
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_values(array_unique([
            ...array_keys((array) config('restv2.vars_overrides')),
            ...array_keys((array) config('restv2.vars_defaults')),
        ]));
    }

    private function hostname(): string
    {
        return (string) (config('restv2.mailcow_hostname') ?: 'localhost');
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function cachePut(string $key, array $value): void
    {
        try {
            $this->cache->put($key, $value, self::CACHE_TTL);
        } catch (Throwable) {
            // Redis unavailable: keep the per-instance memo; a new loader evaluates the files again.
        }
    }
}
