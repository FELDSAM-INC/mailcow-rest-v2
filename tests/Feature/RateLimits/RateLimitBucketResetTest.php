<?php

namespace Tests\Feature\RateLimits;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T071 (FR-057, AS-6.6): an administrator resets a rate-limit bucket: the spam filter's
 * counters (a Redis hash named like the bucket) are deleted and the rate-limited log entries
 * (`RL_LOG`) whose bucket identifier equals it are removed, atomically against the filter's
 * pushes. Only "nothing matched" is "not found".
 */
class RateLimitBucketResetTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
    }

    /**
     * A rate-limited log line as Rspamd's metadata exporter writes it (`pipe_rl.php`).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function logLine(string $hash, array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'time' => 1760860800,
            'rcpt' => 'bob@example.net',
            'from' => 'alice@example.org',
            'user' => 'alice@example.org',
            'rl_info' => 'alice@example.org',
            'rl_name' => 'alice@example.org',
            'rl_hash' => $hash,
            'qid' => 'ABC123',
            'ip' => '192.0.2.1',
            'message_id' => '<m@example.org>',
            'header_subject' => 'Hello',
            'header_from' => 'alice@example.org',
        ], $overrides), JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<string>
     */
    private function log(): array
    {
        $lines = $this->redis()->lRange('RL_LOG', 0, -1);

        return is_array($lines) ? array_values(array_map('strval', $lines)) : [];
    }

    public function test_the_counters_and_the_buckets_log_entries_are_removed(): void
    {
        $this->asAdmin();
        $this->redis()->hSet('RLabc=', 'cnt', '10');
        $own = [$this->logLine('RLabc='), $this->logLine('RLabc=', ['qid' => 'DEF456']), $this->logLine('RLabc=', ['qid' => 'GHI789'])];
        $other = $this->logLine('RLother=');
        $mention = $this->logLine('RLother2=', ['header_subject' => 'about RLabc= and more']);

        foreach ([$own[0], $other, $own[1], $mention, $own[2]] as $line) {
            $this->redis()->lPush('RL_LOG', $line);
        }

        $this->send('DELETE', '/api/v2/rate-limit-buckets/RLabc=')->assertNoContent();

        $this->assertSame(0, $this->redis()->exists('RLabc='));
        $this->assertSame([$mention, $other], $this->log());

        $record = $this->auditOfTarget('rateLimitBucket', 'RLabc=')[0];
        $this->assertSame(['rateLimitBucketReset', 'success'], [$record->event_type, $record->outcome]);
        $this->assertSame(['countersExisted' => true, 'logEntriesRemoved' => 3], $record->details);
    }

    public function test_an_expired_bucket_with_log_entries_is_reset(): void
    {
        $this->asAdmin();
        $this->redis()->lPush('RL_LOG', $this->logLine('RLexpired='));

        $this->send('DELETE', '/api/v2/rate-limit-buckets/RLexpired=')->assertNoContent();

        $this->assertSame([], $this->log());
        $this->assertSame(['countersExisted' => false, 'logEntriesRemoved' => 1], $this->auditOfTarget('rateLimitBucket', 'RLexpired=')[0]->details);
    }

    public function test_a_bucket_without_counters_and_log_entries_is_not_found(): void
    {
        $this->asAdmin();
        $this->redis()->lPush('RL_LOG', $this->logLine('RLother='));
        $this->redis()->lPush('RL_LOG', 'not json RLnothing=');

        $this->send('DELETE', '/api/v2/rate-limit-buckets/RLnothing=')->assertNotFound();

        $this->assertCount(2, $this->log());
        $record = $this->auditOfTarget('rateLimitBucket', 'RLnothing=')[0];
        $this->assertSame(['failure', 'notFound'], [$record->outcome, $record->failure_reason]);
    }

    public function test_a_key_that_is_not_a_hash_is_not_deleted(): void
    {
        $this->asAdmin();
        $this->redis()->set('RLx', 'a string');

        $this->send('DELETE', '/api/v2/rate-limit-buckets/RLx')->assertNotFound();

        $this->assertSame('a string', $this->redis()->get('RLx'));
    }

    public function test_an_invalid_bucket_identifier_is_not_found(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();
        $this->redis()->hSet('foo', 'cnt', '1');

        $this->send('DELETE', '/api/v2/rate-limit-buckets/foo')->assertNotFound();
        $this->send('DELETE', '/api/v2/rate-limit-buckets/RL'.str_repeat('a', 127))->assertNotFound();

        $this->assertSame(1, $this->redis()->exists('foo'));
    }

    public function test_entries_pushed_after_the_reset_are_kept(): void
    {
        $this->asAdmin();
        $this->redis()->hSet('RLabc=', 'cnt', '10');
        $this->redis()->lPush('RL_LOG', $this->logLine('RLabc='));

        $this->send('DELETE', '/api/v2/rate-limit-buckets/RLabc=')->assertNoContent();
        $later = $this->logLine('RLabc=', ['qid' => 'LATER1']);
        $this->redis()->lPush('RL_LOG', $later);

        $this->assertSame([$later], $this->log());
    }

    public function test_a_domain_administrator_is_refused(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['ratelimit' => 1], scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->redis()->hSet('RLabc=', 'cnt', '10');

        $this->send('DELETE', '/api/v2/rate-limit-buckets/RLabc=')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->assertSame(1, $this->redis()->exists('RLabc='));
        $this->assertSame('permissionDenied', $this->auditOfTarget('rateLimitBucket', 'RLabc=')[0]->failure_reason);
    }

    public function test_an_unavailable_shared_store_is_a_temporary_failure(): void
    {
        $this->asAdmin();
        $this->breakSharedStore();

        $this->send('DELETE', '/api/v2/rate-limit-buckets/RLabc=')->assertStatus(503)->assertJsonPath('dependency', 'sharedStore');

        $this->assertSame('temporaryFailure', DB::table('restv2_audit_events')->where('target_type', 'rateLimitBucket')->value('failure_reason'));
    }
}
