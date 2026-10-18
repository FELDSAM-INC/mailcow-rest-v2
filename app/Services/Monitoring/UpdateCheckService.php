<?php

namespace App\Services\Monitoring;

use App\Clients\Releases\ReleaseSource;
use App\Clients\Releases\ReleaseSourceFailure;
use App\Exceptions\ProblemException;
use App\Services\Config\MailcowConfig;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * The update status (feature 011 research R10, FR-019, FR-020, legacy update_check.php): when the
 * server setting enables the check and the installation follows the stable branch, the newest
 * release of the source is compared with the installed version's release by creation time. Results
 * are cached per source and installed version (`restv2:update-check:<sha1(source)>:<version>`):
 * successes for an hour, failures for 5 minutes, refreshed by one request at a time. A disabled
 * check makes no outbound request; without the cache no request is made either (`503`).
 */
class UpdateCheckService
{
    private const SUCCESS_TTL = 3600;

    private const FAILURE_TTL = 300;

    public function __construct(
        private readonly ReleaseSource $releases,
        private readonly InstallationVersionService $installation,
        private readonly Repository $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $version = $this->installation->current();

        if (! config('restv2.update_check.enabled', true) || $version->tag === null || $version->branch !== (string) config('restv2.update_check.stable_branch', 'master')) {
            return self::result('disabled', $version->tag, null, null, CarbonImmutable::now('UTC'));
        }

        $source = $this->source();
        $key = 'update-check:'.sha1($source).':'.$version->tag;

        try {
            $cached = $this->cache->get($key);

            if (is_array($cached)) {
                return $cached;
            }

            $store = $this->cache->getStore();

            if ($store instanceof LockProvider) {
                return $store->lock('lock:'.$key, 30)->block(25, fn (): array => $this->cachedOrCheck($key, $source, $version->tag));
            }

            return $this->cachedOrCheck($key, $source, $version->tag);
        } catch (LockTimeoutException) {
            return $this->cachedOrCheck($key, $source, $version->tag);
        } catch (ProblemException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ProblemException(ProblemType::TemporaryFailure, 'mailcow\'s shared store is unavailable; retry later.', ['dependency' => 'sharedStore'], ['Retry-After' => '5']);
        }
    }

    /**
     * The release source: the setting, else GitHub's API for the installation's owner and
     * repository (mailcow/mailcow-dockerized when unknown).
     */
    public function source(): string
    {
        $configured = (string) config('restv2.update_check.source_url', '');

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $info = app(MailcowConfig::class)->appInfo();

        return 'https://api.github.com/repos/'.rawurlencode($info['owner'] ?? 'mailcow').'/'.rawurlencode($info['repo'] ?? 'mailcow-dockerized');
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedOrCheck(string $key, string $source, string $installed): array
    {
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $now = CarbonImmutable::now('UTC');

        try {
            $latest = $this->releases->latest($source);
            $current = $this->releases->byTag($source, $installed);
            $newer = CarbonImmutable::parse((string) $latest['created_at'])->greaterThan(CarbonImmutable::parse((string) $current['created_at']));
            $result = self::result(
                $newer ? 'updateAvailable' : 'upToDate',
                $installed,
                $newer ? [
                    'tag' => (string) $latest['tag_name'],
                    'url' => is_string($latest['html_url'] ?? null) ? $latest['html_url'] : $source,
                    'publishedAt' => self::time($latest['published_at'] ?? null),
                    'notes' => is_string($latest['body'] ?? null) ? $latest['body'] : null,
                ] : null,
                is_string($current['body'] ?? null) ? $current['body'] : null,
                $now,
            );
            $this->cache->put($key, $result, self::SUCCESS_TTL);
        } catch (ReleaseSourceFailure) {
            $result = self::result('checkFailed', $installed, null, null, $now);
            $this->cache->put($key, $result, self::FAILURE_TTL);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $latest
     * @return array<string, mixed>
     */
    private static function result(string $status, ?string $installed, ?array $latest, ?string $notes, CarbonImmutable $at): array
    {
        return [
            'status' => $status,
            'installedVersion' => $installed,
            'latestRelease' => $latest,
            'installedReleaseNotes' => $notes,
            'collectedAt' => $at->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private static function time(mixed $value): ?string
    {
        try {
            return is_string($value) ? CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s\Z') : null;
        } catch (Throwable) {
            return null;
        }
    }
}
