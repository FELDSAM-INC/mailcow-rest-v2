<?php

namespace Tests\Support\Fakes;

use App\Clients\Releases\ReleaseSource;
use App\Clients\Releases\ReleaseSourceFailure;
use Tests\Support\Monitoring\MonitoringFixtures;

/**
 * The update check's release source without network (feature 011 T008): the recorded GitHub
 * releases unless a test scripts others, fail() for an unreachable or rate-limited source, and a
 * log of every call.
 */
final class FakeReleaseSource implements ReleaseSource
{
    /** @var list<array{method: string, source: string, tag: string|null}> */
    private array $calls = [];

    /** @var array<string, mixed>|null */
    private ?array $latest = null;

    /** @var array<string, array<string, mixed>> */
    private array $tags = [];

    private bool $failing = false;

    public function latest(string $source): array
    {
        $this->calls[] = ['method' => 'latest', 'source' => $source, 'tag' => null];
        $this->failIfFailing();

        return $this->latest ?? MonitoringFixtures::json('github-latest.json');
    }

    public function byTag(string $source, string $tag): array
    {
        $this->calls[] = ['method' => 'byTag', 'source' => $source, 'tag' => $tag];
        $this->failIfFailing();

        if (isset($this->tags[$tag])) {
            return $this->tags[$tag];
        }

        if ($tag === '2026-09a') {
            return MonitoringFixtures::json('github-tag-2026-09a.json');
        }

        throw new ReleaseSourceFailure("The release source has no release {$tag} (HTTP 404).");
    }

    /**
     * @param  array<string, mixed>  $release
     */
    public function withLatest(array $release): self
    {
        $this->latest = $release;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $release
     */
    public function withTag(string $tag, array $release): self
    {
        $this->tags[$tag] = $release;

        return $this;
    }

    public function fail(bool $failing = true): self
    {
        $this->failing = $failing;

        return $this;
    }

    /**
     * @return list<array{method: string, source: string, tag: string|null}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    private function failIfFailing(): void
    {
        if ($this->failing) {
            throw new ReleaseSourceFailure('The release source answered HTTP 403 (rate limit exceeded).');
        }
    }
}
