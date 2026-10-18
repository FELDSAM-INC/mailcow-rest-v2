<?php

namespace Tests\Unit\Clients;

use App\Clients\Releases\GitHubReleaseSource;
use App\Clients\Releases\ReleaseSourceFailure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\TestCase;

/**
 * Feature 011 T099 (research R10): GitHub's releases API, as legacy update_check.php asks it.
 */
class GitHubReleaseSourceTest extends TestCase
{
    private const SOURCE = 'https://api.github.com/repos/mailcow/mailcow-dockerized';

    public function test_the_latest_release_and_a_tag(): void
    {
        $options = [];
        Http::fake(function (Request $request, array $o) use (&$options) {
            $options[] = $o;

            return Http::response(str_ends_with($request->url(), '/latest') ? MonitoringFixtures::json('github-latest.json') : MonitoringFixtures::json('github-tag-2026-09a.json'));
        });

        $source = new GitHubReleaseSource;
        $this->assertSame('2026-10', $source->latest(self::SOURCE)['tag_name']);
        $this->assertSame('2026-09a', $source->byTag(self::SOURCE, '2026-09a')['tag_name']);

        Http::assertSent(fn (Request $r): bool => $r->url() === self::SOURCE.'/releases/latest'
            && $r->header('User-Agent') === ['mailcow-update-check']
            && $r->header('Accept') === ['application/vnd.github+json']);
        Http::assertSent(fn (Request $r): bool => $r->url() === self::SOURCE.'/releases/tags/2026-09a');
        $this->assertEquals(10, $options[0]['timeout']);
    }

    public function test_an_error_status_is_a_failure(): void
    {
        Http::fake(fn () => Http::response(['message' => 'API rate limit exceeded'], 403));

        $this->expectException(ReleaseSourceFailure::class);
        (new GitHubReleaseSource)->latest(self::SOURCE);
    }

    public function test_an_incomplete_release_is_a_failure(): void
    {
        Http::fake(fn () => Http::response(['tag_name' => '2026-10']));

        $this->expectException(ReleaseSourceFailure::class);
        (new GitHubReleaseSource)->latest(self::SOURCE);
    }

    public function test_a_network_error_is_a_failure(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 6: Could not resolve host'));

        $this->expectException(ReleaseSourceFailure::class);
        (new GitHubReleaseSource)->latest(self::SOURCE);
    }
}
