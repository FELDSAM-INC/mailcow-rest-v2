<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\UpdateCheckService;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T098 (US7 AS-1 to AS-3, FR-019, FR-020, research R10): the update status, cached per
 * installed version, with the update check as a server setting.
 */
class UpdateStatusTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['restv2.vars_overrides.MAILCOW_GIT_VERSION' => '2026-09a', 'restv2.vars_overrides.MAILCOW_BRANCH' => 'master']);
    }

    public function test_a_newer_release_is_an_available_update(): void
    {
        $this->asAdmin();

        $data = $this->getJson('/api/v2/installation/update-status')->assertOk()->json('data');

        $this->assertSame('updateAvailable', $data['status']);
        $this->assertSame('2026-09a', $data['installedVersion']);
        $this->assertSame([
            'tag' => '2026-10',
            'url' => 'https://github.com/mailcow/mailcow-dockerized/releases/tag/2026-10',
            'publishedAt' => '2026-10-14T12:00:00Z',
            'notes' => MonitoringFixtures::json('github-latest.json')['body'],
        ], $data['latestRelease']);
        $this->assertSame("## Fixes\n\n- Netfilter rule ordering", $data['installedReleaseNotes']);
        $this->assertSame([
            ['method' => 'latest', 'source' => 'https://api.github.com/repos/mailcow/mailcow-dockerized', 'tag' => null],
            ['method' => 'byTag', 'source' => 'https://api.github.com/repos/mailcow/mailcow-dockerized', 'tag' => '2026-09a'],
        ], $this->releases()->calls());
    }

    public function test_the_same_or_an_older_release_is_up_to_date(): void
    {
        $this->releases()->withLatest(MonitoringFixtures::json('github-tag-2026-09a.json'));
        $this->asAdmin();

        $this->getJson('/api/v2/installation/update-status')->assertOk()->assertJsonPath('data.status', 'upToDate')->assertJsonPath('data.latestRelease', null);
    }

    private function cacheKey(string $version = '2026-09a'): string
    {
        return 'update-check:'.sha1('https://api.github.com/repos/mailcow/mailcow-dockerized').':'.$version;
    }

    public function test_a_failed_check_is_cached_for_five_minutes(): void
    {
        $this->releases()->fail();
        $this->asAdmin();

        $this->getJson('/api/v2/installation/update-status')->assertOk()->assertJsonPath('data.status', 'checkFailed');
        $this->getJson('/api/v2/installation/update-status')->assertOk()->assertJsonPath('data.status', 'checkFailed');
        $this->assertSame(1, $this->releases()->callCount());
        $this->assertEqualsWithDelta(300, Redis::connection('restv2')->command('ttl', [$this->cacheKey()]), 2);

        // Once the entry expired, the source is asked again.
        Cache::store('restv2')->forget($this->cacheKey());
        $this->releases()->fail(false);
        $this->getJson('/api/v2/installation/update-status')->assertOk()->assertJsonPath('data.status', 'updateAvailable');
    }

    public function test_a_successful_check_is_cached_for_an_hour(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/installation/update-status')->assertOk();
        $this->getJson('/api/v2/installation/update-status')->assertOk();

        $this->assertSame(2, $this->releases()->callCount(), 'the second request asked the source again');
        $this->assertEqualsWithDelta(3600, Redis::connection('restv2')->command('ttl', [$this->cacheKey()]), 2);
    }

    public function test_a_disabled_check_makes_no_request(): void
    {
        config(['restv2.update_check.enabled' => false]);
        $this->asAdmin();

        $this->getJson('/api/v2/installation/update-status')->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->assertSame(0, $this->releases()->callCount());
    }

    public function test_another_branch_or_no_installed_version_is_disabled(): void
    {
        $this->asAdmin();

        config(['restv2.vars_overrides.MAILCOW_BRANCH' => 'nightly']);
        $this->getJson('/api/v2/installation/update-status')->assertOk()->assertJsonPath('data.status', 'disabled');

        config(['restv2.vars_overrides.MAILCOW_BRANCH' => 'master', 'restv2.vars_overrides.MAILCOW_GIT_VERSION' => null]);
        $this->getJson('/api/v2/installation/update-status')->assertOk()->assertJsonPath('data.status', 'disabled')->assertJsonPath('data.installedVersion', null);

        $this->assertSame(0, $this->releases()->callCount());
    }

    public function test_the_source_follows_the_setting_and_the_installation(): void
    {
        config(['restv2.vars_overrides.MAILCOW_GIT_OWNER' => 'feldsam-inc', 'restv2.vars_overrides.MAILCOW_GIT_REPO' => 'mailhosting']);
        $this->asAdmin();
        $this->getJson('/api/v2/installation/update-status')->assertOk();
        $this->assertSame('https://api.github.com/repos/feldsam-inc/mailhosting', $this->releases()->calls()[0]['source']);

        config(['restv2.update_check.source_url' => 'https://git.example.org/api/v1/repos/x/y']);
        $this->getJson('/api/v2/installation/update-status')->assertOk();
        $this->assertSame('https://git.example.org/api/v1/repos/x/y', $this->releases()->calls()[2]['source']);
    }

    public function test_a_changed_version_uses_a_new_cache_entry(): void
    {
        $this->releases()->withTag('2026-10', MonitoringFixtures::json('github-latest.json'));
        $this->asAdmin();
        $this->getJson('/api/v2/installation/update-status')->assertOk()->assertJsonPath('data.status', 'updateAvailable');

        config(['restv2.vars_overrides.MAILCOW_GIT_VERSION' => '2026-10']);
        $this->getJson('/api/v2/installation/update-status')->assertOk()->assertJsonPath('data.status', 'upToDate')->assertJsonPath('data.installedVersion', '2026-10');
    }

    public function test_an_unavailable_cache_is_a_temporary_failure_without_requests(): void
    {
        $broken = new class(Cache::store('restv2')->getStore()) extends Repository
        {
            /**
             * @param  string  $key
             */
            public function get($key, $default = null): mixed
            {
                throw new RuntimeException('Connection refused');
            }
        };
        $this->app->when(UpdateCheckService::class)->needs(\Illuminate\Contracts\Cache\Repository::class)->give(fn () => $broken);
        $this->asAdmin();

        $this->getJson('/api/v2/installation/update-status')->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
        $this->assertSame(0, $this->releases()->callCount());
    }
}
