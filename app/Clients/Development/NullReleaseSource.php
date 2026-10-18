<?php

namespace App\Clients\Development;

use App\Clients\Releases\ReleaseSource;
use App\Clients\Releases\ReleaseSourceFailure;

/**
 * Development stand-in for the update check's release source (RESTV2_FAKE_OUTBOUND=y with
 * APP_ENV=local): no request leaves the dev stack; the recorded GitHub releases answer.
 */
final class NullReleaseSource implements ReleaseSource
{
    public function latest(string $source): array
    {
        return self::fixture('github-latest.json');
    }

    public function byTag(string $source, string $tag): array
    {
        return $tag === '2026-09a' ? self::fixture('github-tag-2026-09a.json') : throw new ReleaseSourceFailure("No recorded release {$tag} (development stand-in).");
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $data = json_decode((string) @file_get_contents(base_path('tests/fixtures/monitoring/'.$name)), true);

        return is_array($data) ? $data : throw new ReleaseSourceFailure("The recorded payload {$name} is missing (development stand-in).");
    }
}
