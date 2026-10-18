<?php

namespace App\Clients\Releases;

/**
 * A GitHub-compatible release source (`<source>/releases/latest`, `<source>/releases/tags/<tag>`),
 * asked by the update check (feature 011 research R10, legacy update_check.php). Faked in tests.
 */
interface ReleaseSource
{
    /**
     * The newest release of the source, decoded; it has at least `tag_name` and `created_at`.
     *
     * @return array<string, mixed>
     *
     * @throws ReleaseSourceFailure on a network error, a non-200 answer or an incomplete document
     */
    public function latest(string $source): array;

    /**
     * The release of a tag, decoded; it has at least `tag_name` and `created_at`.
     *
     * @return array<string, mixed>
     *
     * @throws ReleaseSourceFailure
     */
    public function byTag(string $source, string $tag): array;
}
