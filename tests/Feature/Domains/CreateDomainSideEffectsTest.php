<?php

namespace Tests\Feature\Domains;

use App\Clients\DockerApi\DockerCallResult;
use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Dkim\DkimPublicKey;
use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 002 T048 (FR-023, FR-025–FR-027): the side effects of a creation and its per-step report.
 */
class CreateDomainSideEffectsTest extends TestCase
{
    use DomainTestHelpers;

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function create(array $body): array
    {
        return (array) $this->postJson('/api/v2/domains', $body + ['dkimKeySize' => 1024], $this->csrf())->assertCreated()->json('data');
    }

    public function test_the_domain_map_entry_is_written(): void
    {
        $this->asAdmin();

        $this->create(['name' => 'example.org', 'dkimSelector' => '']);

        $this->assertSame('1', $this->redis()->hGet('DOMAIN_MAP', 'example.org'));
    }

    public function test_the_initial_rate_limit_comes_from_the_request_or_the_template(): void
    {
        $this->mailcow()->defaultDomainTemplate(['rl_value' => '20', 'rl_frame' => 'm']);
        $this->asAdmin();

        $a = $this->create(['name' => 'a.example', 'rateLimit' => ['value' => 10, 'frame' => 'hour'], 'dkimSelector' => '']);
        $this->assertSame('applied', $a['creationReport']['rateLimit']);
        $this->assertSame(['value' => 10, 'frame' => 'hour'], $a['rateLimit']);
        $this->assertSame('10 / 1h', $this->redis()->hGet('RL_VALUE', 'a.example'));

        $b = $this->create(['name' => 'b.example', 'dkimSelector' => '']);
        $this->assertSame('applied', $b['creationReport']['rateLimit']);
        $this->assertSame('20 / 1m', $this->redis()->hGet('RL_VALUE', 'b.example'));

        $c = $this->create(['name' => 'c.example', 'rateLimit' => null, 'dkimSelector' => '']);
        $this->assertSame('notRequested', $c['creationReport']['rateLimit']);
        $this->assertFalse($this->redis()->hGet('RL_VALUE', 'c.example'));
        $this->assertNull($c['rateLimit']);
    }

    public function test_a_dkim_key_is_generated_and_stored_where_rspamd_reads_it(): void
    {
        $this->asAdmin();

        $data = $this->create(['name' => 'example.org', 'dkimSelector' => 'sel1']);

        $this->assertSame('created', $data['creationReport']['dkimKey']);
        $this->assertSame('notConfigured', $data['creationReport']['dkimDnsPublication']);
        $this->assertSame('sel1', $this->redis()->hGet('DKIM_SELECTORS', 'example.org'));
        $pub = (string) $this->redis()->hGet('DKIM_PUB_KEYS', 'example.org');
        $this->assertDoesNotMatchRegularExpression('/-----|\s/', $pub);
        $this->assertSame(1024, (new DkimPublicKey($pub))->bits());
        $priv = (string) $this->redis()->hGet('DKIM_PRIV_KEYS', 'sel1.example.org');
        $details = openssl_pkey_get_details(openssl_pkey_get_private($priv) ?: throw new \RuntimeException('no key'));
        $this->assertIsArray($details);
        $this->assertSame($pub, implode(array_slice(array_filter(explode(PHP_EOL, $details['key'])), 1, -1)));
    }

    public function test_the_key_size_is_asserted_for_the_default_size(): void
    {
        $this->asAdmin();

        $this->postJson('/api/v2/domains', ['name' => 'example.org'], $this->csrf())->assertCreated();

        $this->assertSame(2048, (new DkimPublicKey((string) $this->redis()->hGet('DKIM_PUB_KEYS', 'example.org')))->bits());
    }

    public function test_an_existing_key_is_kept_byte_for_byte(): void
    {
        $this->redis()->hSet('DKIM_SELECTORS', 'example.org', 'old');
        $this->redis()->hSet('DKIM_PUB_KEYS', 'example.org', 'MIIBkept');
        $this->redis()->hSet('DKIM_PRIV_KEYS', 'old.example.org', 'PRIVATE-kept');
        $this->asAdmin();

        $data = $this->create(['name' => 'example.org', 'dkimSelector' => 'new']);

        $this->assertSame('alreadyExisted', $data['creationReport']['dkimKey']);
        $this->assertSame('skipped', $data['creationReport']['dkimDnsPublication']);
        $this->assertSame(['example.org' => 'old'], $this->redis()->hGetAll('DKIM_SELECTORS'));
        $this->assertSame(['example.org' => 'MIIBkept'], $this->redis()->hGetAll('DKIM_PUB_KEYS'));
        $this->assertSame(['old.example.org' => 'PRIVATE-kept'], $this->redis()->hGetAll('DKIM_PRIV_KEYS'));
    }

    public function test_an_explicitly_empty_selector_generates_no_key(): void
    {
        $this->mailcow()->defaultDomainTemplate(['dkim_selector' => 'dkim']);
        $this->asAdmin();

        $data = $this->create(['name' => 'example.org', 'dkimSelector' => '']);

        $this->assertSame('notRequested', $data['creationReport']['dkimKey']);
        $this->assertSame('skipped', $data['creationReport']['dkimDnsPublication']);
        $this->assertSame([], $this->redis()->hGetAll('DKIM_SELECTORS'));
    }

    public function test_the_groupware_directory_is_rebuilt_and_a_restart_requested(): void
    {
        $this->asAdmin();

        $plain = $this->create(['name' => 'a.example', 'dkimSelector' => '']);
        $this->assertSame('done', $plain['creationReport']['groupwareDirectoryUpdate']);
        $this->assertSame('notRequested', $plain['creationReport']['groupwareRestart']);
        $this->assertSame(1, $this->memcached()->flushCount());
        $this->dockerApi()->assertNothingCalled();

        $restarted = $this->create(['name' => 'b.example', 'dkimSelector' => '', 'restartGroupware' => true]);
        $this->assertSame('done', $restarted['creationReport']['groupwareRestart']);
        $this->dockerApi()->assertRestarted('sogo-mailcow');
    }

    public function test_a_failed_or_timed_out_restart_keeps_the_domain(): void
    {
        $this->asAdmin();

        $this->dockerApi()->respondTo('restart', DockerCallResult::failed('container not found'));
        $failed = $this->create(['name' => 'a.example', 'dkimSelector' => '', 'restartGroupware' => true]);
        $this->assertSame('failed', $failed['creationReport']['groupwareRestart']);
        $this->assertNotEmpty($failed['creationReport']['messages']['groupwareRestart']);

        $this->dockerApi()->respondTo('restart', DockerCallResult::unknown('timed out'));
        $unknown = $this->create(['name' => 'b.example', 'dkimSelector' => '', 'restartGroupware' => true]);
        $this->assertSame('failed', $unknown['creationReport']['groupwareRestart']);

        $this->assertSame(2, DB::table('domain')->count());
    }

    public function test_a_disabled_groupware_is_reported_and_never_called(): void
    {
        config(['restv2.skip_sogo' => true]);
        $this->asAdmin();

        $data = $this->create(['name' => 'example.org', 'dkimSelector' => '', 'restartGroupware' => true]);

        $this->assertSame('groupwareDisabled', $data['creationReport']['groupwareDirectoryUpdate']);
        $this->assertSame('groupwareDisabled', $data['creationReport']['groupwareRestart']);
        $this->dockerApi()->assertNothingCalled();
        $this->assertSame(0, $this->memcached()->flushCount());
    }

    public function test_a_failing_rate_limit_write_after_the_commit_keeps_the_domain(): void
    {
        $this->app->instance(SharedStore::class, new class($this->app->make(RedisFactory::class)) extends SharedStore
        {
            public function setFields(string $key, array $fields): Closure
            {
                if ($key === 'RL_VALUE') {
                    throw new SharedStoreUnavailable('connection lost');
                }

                return parent::setFields($key, $fields);
            }
        });
        $this->asAdmin();

        $data = $this->create(['name' => 'example.org', 'dkimSelector' => '', 'rateLimit' => ['value' => 5, 'frame' => 'second']]);

        $this->assertSame('failed', $data['creationReport']['rateLimit']);
        $this->assertArrayHasKey('rateLimit', $data['creationReport']['messages']);
        $this->assertNotNull($this->domainRow('example.org'));
        $this->assertSame('1', $this->redis()->hGet('DOMAIN_MAP', 'example.org'));
    }

    public function test_the_report_never_mentions_secrets(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->asAdmin();

        $body = (string) $this->postJson('/api/v2/domains', ['name' => 'example.org', 'dkimKeySize' => 1024], $this->csrf())->assertCreated()->getContent();

        $this->assertStringNotContainsString('PRIVATE KEY', $body);
    }
}
