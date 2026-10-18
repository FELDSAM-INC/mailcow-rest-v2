<?php

namespace App\Clients\Releases;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * GitHub's releases API (feature 011 research R10), asked like legacy update_check.php:
 * `User-Agent: mailcow-update-check`, `Accept: application/vnd.github+json`, 10 seconds. Any other
 * status than 200, a document without `tag_name` and `created_at`, or a network error is a failure.
 */
class GitHubReleaseSource implements ReleaseSource
{
    public function latest(string $source): array
    {
        return $this->release(rtrim($source, '/').'/releases/latest');
    }

    public function byTag(string $source, string $tag): array
    {
        return $this->release(rtrim($source, '/').'/releases/tags/'.rawurlencode($tag));
    }

    /**
     * @return array<string, mixed>
     */
    private function release(string $url): array
    {
        try {
            $response = $this->request()->get($url);
        } catch (Throwable $e) {
            throw new ReleaseSourceFailure('The release source is unreachable: '.$e->getMessage(), 0, $e);
        }

        $release = $response->json();

        if ($response->status() !== 200 || ! is_array($release) || ! is_string($release['tag_name'] ?? null) || ! is_string($release['created_at'] ?? null)) {
            throw new ReleaseSourceFailure("The release source answered {$url} with HTTP {$response->status()}.");
        }

        return $release;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => 'mailcow-update-check', 'Accept' => 'application/vnd.github+json'])->timeout(10);
    }
}
