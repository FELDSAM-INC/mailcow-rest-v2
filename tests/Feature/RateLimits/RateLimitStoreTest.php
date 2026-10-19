<?php

namespace Tests\Feature\RateLimits;

use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\RateLimits\RateLimitStore;
use App\Services\RateLimits\RateLimitWrite;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T014 (research D9): rate limits in mailcow's `RL_VALUE` hash, `"<value> / 1<s|m|h|d>"`
 * as legacy ratelimit('edit') writes them.
 *
 * Feature 006 T068 (research R12), on the real Redis: values in the legacy format are parsed and
 * anything else is reported as invalid with its stored text; writes compare the stored value with
 * the expected one atomically (a Lua script) before `HSET` or `HDEL`, so that a change made
 * meanwhile is never overwritten.
 */
class RateLimitStoreTest extends TestCase
{
    use MailboxTestHelpers;

    private function store(): RateLimitStore
    {
        return $this->app->make(RateLimitStore::class);
    }

    private function field(string $object): ?string
    {
        $value = Redis::connection('mailcow')->hget('RL_VALUE', $object);

        return is_string($value) ? $value : null;
    }

    public function test_set_writes_the_legacy_format(): void
    {
        $this->store()->set('example.org', 10, 'hour');

        $this->assertSame('10 / 1h', Redis::connection('mailcow')->hget('RL_VALUE', 'example.org'));
    }

    public function test_get_parses_every_frame(): void
    {
        $redis = Redis::connection('mailcow');
        $redis->hset('RL_VALUE', 'a.example', '10 / 1h');
        $redis->hset('RL_VALUE', 'b.example', '5 / 1s');
        $redis->hset('RL_VALUE', 'c.example', '7 / 1d');
        $redis->hset('RL_VALUE', 'd.example', '3 / 1m');
        $redis->hset('RL_VALUE', 'bad.example', 'nonsense');
        $redis->hset('RL_VALUE', 'zero.example', '0 / 1h');
        $redis->hset('RL_VALUE', 'frame.example', '10 / 1w');

        $this->assertSame(['value' => 10, 'frame' => 'hour'], $this->store()->get('a.example'));
        $this->assertSame(['value' => 5, 'frame' => 'second'], $this->store()->get('b.example'));
        $this->assertSame(['value' => 7, 'frame' => 'day'], $this->store()->get('c.example'));
        $this->assertSame(['value' => 3, 'frame' => 'minute'], $this->store()->get('d.example'));
        $this->assertNull($this->store()->get('bad.example'));
        $this->assertNull($this->store()->get('zero.example'));
        $this->assertNull($this->store()->get('frame.example'));
        $this->assertNull($this->store()->get('missing.example'));

        $this->assertSame(
            ['a.example' => ['value' => 10, 'frame' => 'hour'], 'missing.example' => null],
            $this->store()->getMany(['a.example', 'missing.example']),
        );
    }

    public function test_delete_removes_the_field(): void
    {
        Redis::connection('mailcow')->hset('RL_VALUE', 'example.org', '10 / 1h');

        $this->store()->delete('example.org');

        $this->assertNull($this->store()->get('example.org'));
    }

    public function test_a_legacy_value_is_classified_as_a_limit(): void
    {
        $this->assertSame(['limit' => ['value' => 100, 'frame' => 'hour'], 'invalidStoredValue' => null], RateLimitStore::classify('100 / 1h'));
        $this->assertSame(['limit' => ['value' => 2147483647, 'frame' => 'second'], 'invalidStoredValue' => null], RateLimitStore::classify('2147483647 / 1s'));
        $this->assertSame(['limit' => null, 'invalidStoredValue' => null], RateLimitStore::classify(null));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidValues(): array
    {
        return [
            'zero' => ['0 / 1h'],
            'negative' => ['-5 / 1m'],
            'no spaces' => ['10/1h'],
            'week' => ['10 / 1w'],
            'text' => ['abc'],
            'too large' => ['2147483648 / 1h'],
            'leading zero' => ['010 / 1h'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_other_values_are_invalid_with_their_stored_text(string $raw): void
    {
        $this->assertSame(['limit' => null, 'invalidStoredValue' => $raw], RateLimitStore::classify($raw));
    }

    public function test_a_write_with_the_current_value_is_stored(): void
    {
        Redis::connection('mailcow')->hset('RL_VALUE', 'example.org', '10 / 1m');

        $this->assertSame(RateLimitWrite::Changed, $this->store()->setIfMatches('example.org', '10 / 1m', '100 / 1h'));
        $this->assertSame('100 / 1h', $this->field('example.org'));
        $this->assertSame('100 / 1h', $this->store()->raw('example.org'));
    }

    public function test_a_write_with_a_stale_value_changes_nothing(): void
    {
        Redis::connection('mailcow')->hset('RL_VALUE', 'example.org', '20 / 1m');

        $this->assertSame(RateLimitWrite::Outdated, $this->store()->setIfMatches('example.org', '10 / 1m', '100 / 1h'));
        $this->assertSame(RateLimitWrite::Outdated, $this->store()->setIfMatches('example.org', null, '100 / 1h'));
        $this->assertSame(RateLimitWrite::Outdated, $this->store()->removeIfMatches('example.org', '10 / 1m'));
        $this->assertSame('20 / 1m', $this->field('example.org'));

        $this->assertSame(RateLimitWrite::Outdated, $this->store()->setIfMatches('other.example', '20 / 1m', '1 / 1d'));
        $this->assertNull($this->field('other.example'));
    }

    public function test_an_absent_field_expected_absent_is_written(): void
    {
        $this->assertSame(RateLimitWrite::Changed, $this->store()->setIfMatches('alice@example.org', null, '10 / 1s'));
        $this->assertSame('10 / 1s', $this->field('alice@example.org'));
    }

    public function test_an_empty_stored_value_is_not_an_absent_one(): void
    {
        Redis::connection('mailcow')->hset('RL_VALUE', 'example.org', '');

        $this->assertSame(RateLimitWrite::Outdated, $this->store()->setIfMatches('example.org', null, '1 / 1h'));
        $this->assertSame(RateLimitWrite::Changed, $this->store()->setIfMatches('example.org', '', '1 / 1h'));
    }

    public function test_a_removal_deletes_the_field_and_an_absent_field_reports_no_change(): void
    {
        $redis = Redis::connection('mailcow');
        $redis->hset('RL_VALUE', 'example.org', '10 / 1m');
        $redis->hset('RL_VALUE', 'other.example', '5 / 1d');

        $this->assertSame(RateLimitWrite::Changed, $this->store()->removeIfMatches('example.org', '10 / 1m'));
        $this->assertNull($this->field('example.org'));
        $this->assertSame(RateLimitWrite::Unchanged, $this->store()->removeIfMatches('example.org', null));
        $this->assertSame(['other.example' => '5 / 1d'], $this->store()->all());
    }

    public function test_all_returns_every_field(): void
    {
        $this->assertSame([], $this->store()->all());
        $redis = Redis::connection('mailcow');
        $redis->hset('RL_VALUE', 'example.org', '10 / 1m');
        $redis->hset('RL_VALUE', 'alice@example.org', 'abc');

        $all = $this->store()->all();
        ksort($all);
        $this->assertSame(['alice@example.org' => 'abc', 'example.org' => '10 / 1m'], $all);
    }

    public function test_an_unreachable_store_fails_writes_as_unavailable(): void
    {
        $this->breakSharedStore();

        $this->expectException(SharedStoreUnavailable::class);
        $this->store()->setIfMatches('example.org', null, '1 / 1h');
    }

    public function test_an_unreachable_store_fails_reads_as_unavailable(): void
    {
        $this->breakSharedStore();

        $this->expectException(SharedStoreUnavailable::class);
        $this->store()->all();
    }
}
