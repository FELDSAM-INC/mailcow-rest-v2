<?php

namespace Tests\Feature\RateLimits;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T069 (FR-050, FR-052 to FR-056, AS-6.1 to AS-6.5): the rate limits of domains, alias
 * domains and mailboxes in mailcow's `RL_VALUE` hash, as legacy ratelimit('edit') stores them, the
 * field being the normalised object; a mailbox reports the limit that applies to it, its own or
 * its domain's.
 */
class RateLimitTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('other.example');
        $this->mailcow()->domain('xn--bcher-kva.example');
        $this->mailcow()->aliasDomain('example.info', 'example.org');
        $this->mailcow()->aliasDomain('other.info', 'other.example');
        $this->quarantineMailbox('alice@example.org');
        $this->quarantineMailbox('otto@other.example');
        $this->quarantineMailbox('room@example.org', kind: 'location');
    }

    private function field(string $object): ?string
    {
        $value = $this->redis()->hGet('RL_VALUE', $object);

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, int>  $daAcl
     */
    private function asReseller(array $daAcl = ['ratelimit' => 1]): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org'], $daAcl, scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);
    }

    public function test_a_domain_administrator_sets_the_limit_of_their_domain(): void
    {
        $this->asReseller();

        $response = $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 100, 'frame' => 'hour'])->assertOk();

        $this->assertSame('100 / 1h', $this->field('example.org'));
        $this->assertSame(['object' => 'example.org', 'kind' => 'domain', 'limit' => ['value' => 100, 'frame' => 'hour'], 'invalidStoredValue' => null], array_diff_key((array) $response->json('data'), ['etag' => true]));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
        $this->getJson('/api/v2/domains/example.org/rate-limit')->assertOk()->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_the_domain_name_is_normalised(): void
    {
        $this->asAdmin();

        $this->send('PUT', '/api/v2/domains/EXAMPLE.ORG/rate-limit', ['value' => 5, 'frame' => 'minute'])->assertOk()->assertJsonPath('data.object', 'example.org');
        $this->send('PUT', '/api/v2/domains/b%C3%BCcher.example/rate-limit', ['value' => 7, 'frame' => 'day'])->assertOk()->assertJsonPath('data.object', 'xn--bcher-kva.example');

        $this->assertSame('5 / 1m', $this->field('example.org'));
        $this->assertSame('7 / 1d', $this->field('xn--bcher-kva.example'));
        $this->assertNull($this->field('EXAMPLE.ORG'));
    }

    public function test_a_mailbox_reports_the_limit_that_applies_to_it(): void
    {
        $this->asReseller();
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 100, 'frame' => 'hour'])->assertOk();

        $this->getJson('/api/v2/mailboxes/alice@example.org/rate-limit')->assertOk()
            ->assertJsonPath('data.kind', 'mailbox')
            ->assertJsonPath('data.limit', null)
            ->assertJsonPath('data.effectiveLimit', ['value' => 100, 'frame' => 'hour'])
            ->assertJsonPath('data.effectiveSource', 'domain');

        $this->send('PUT', '/api/v2/mailboxes/ALICE@example.org/rate-limit', ['value' => 10, 'frame' => 'minute'])->assertOk()
            ->assertJsonPath('data.object', 'alice@example.org')
            ->assertJsonPath('data.limit', ['value' => 10, 'frame' => 'minute'])
            ->assertJsonPath('data.effectiveLimit', ['value' => 10, 'frame' => 'minute'])
            ->assertJsonPath('data.effectiveSource', 'mailbox');
        $this->assertSame('10 / 1m', $this->field('alice@example.org'));

        $this->send('DELETE', '/api/v2/mailboxes/alice@example.org/rate-limit')->assertNoContent();
        $this->assertNull($this->field('alice@example.org'));
        $this->getJson('/api/v2/mailboxes/alice@example.org/rate-limit')->assertOk()->assertJsonPath('data.effectiveSource', 'domain');

        $this->send('DELETE', '/api/v2/domains/example.org/rate-limit')->assertNoContent();
        $this->getJson('/api/v2/mailboxes/alice@example.org/rate-limit')->assertOk()
            ->assertJsonPath('data.effectiveLimit', null)
            ->assertJsonPath('data.effectiveSource', 'none');
    }

    public function test_invalid_limits_are_refused_and_nothing_changes(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();
        $this->redis()->hSet('RL_VALUE', 'example.org', '3 / 1d');

        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 0, 'frame' => 'hour'])->assertStatus(422)->assertJsonPath('errors.value.0.code', 'outOfRange');
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => -1, 'frame' => 'hour'])->assertStatus(422)->assertJsonPath('errors.value.0.code', 'outOfRange');
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 2147483648, 'frame' => 'hour'])->assertStatus(422)->assertJsonPath('errors.value.0.code', 'outOfRange');
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 10, 'frame' => 'week'])->assertStatus(422)->assertJsonPath('errors.frame.0.code', 'notInList');
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => '10', 'frame' => 'hour'])->assertStatus(422)->assertJsonPath('errors.value.0.code', 'invalidType');
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['frame' => 'hour'])->assertStatus(422)->assertJsonPath('errors.value.0.code', 'required');
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 10, 'frame' => 'hour', 'object' => 'x'])->assertStatus(422)->assertJsonPath('errors.object.0.code', 'unknownField');

        $this->assertSame('3 / 1d', $this->field('example.org'));
        $this->assertSame(['validationFailed'], DB::table('restv2_audit_events')->where('outcome', 'failure')->distinct()->pluck('failure_reason')->all());
    }

    public function test_an_invalid_stored_value_is_reported_and_can_be_replaced_and_removed(): void
    {
        $this->asAdmin();
        $this->redis()->hSet('RL_VALUE', 'example.org', '10 / 1w');

        $this->getJson('/api/v2/domains/example.org/rate-limit')->assertOk()
            ->assertJsonPath('data.limit', null)
            ->assertJsonPath('data.invalidStoredValue', '10 / 1w');
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 10, 'frame' => 'day'])->assertOk()->assertJsonPath('data.invalidStoredValue', null);
        $this->assertSame('10 / 1d', $this->field('example.org'));

        $this->redis()->hSet('RL_VALUE', 'example.org', 'abc');
        $this->send('DELETE', '/api/v2/domains/example.org/rate-limit')->assertNoContent();
        $this->assertNull($this->field('example.org'));
        $this->assertSame(['kind' => 'domain', 'old' => 'abc', 'new' => null], $this->auditOfTarget('rateLimit', 'example.org')[1]->details);
    }

    public function test_removing_a_limit_that_is_not_set_changes_nothing_and_is_not_audited(): void
    {
        $this->asAdmin();

        $this->send('DELETE', '/api/v2/domains/example.org/rate-limit')->assertNoContent();
        $this->send('DELETE', '/api/v2/mailboxes/alice@example.org/rate-limit')->assertNoContent();

        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'like', 'rateLimit%')->count());
    }

    public function test_a_domain_administrator_without_ratelimit_reads_but_cannot_change(): void
    {
        $this->asReseller(['ratelimit' => 0]);
        $this->redis()->hSet('RL_VALUE', 'example.org', '3 / 1d');

        $this->getJson('/api/v2/domains/example.org/rate-limit')->assertOk();
        $this->getJson('/api/v2/alias-domains/example.info/rate-limit')->assertOk();
        $this->getJson('/api/v2/mailboxes/alice@example.org/rate-limit')->assertOk();
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 1, 'frame' => 'hour'])
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['ratelimit']);
        $this->send('DELETE', '/api/v2/mailboxes/alice@example.org/rate-limit')->assertForbidden()->assertJsonPath('requiredFlags', ['ratelimit']);

        $this->assertSame('3 / 1d', $this->field('example.org'));
        $record = $this->auditOfTarget('rateLimit', 'example.org')[0];
        $this->assertSame(['failure', 'permissionDenied'], [$record->outcome, $record->failure_reason]);
        $this->assertSame(['ratelimit'], $record->details['requiredFlags']);
    }

    public function test_a_mailbox_user_is_refused_for_every_operation(): void
    {
        $this->asQuarantineUser('alice@example.org');

        foreach (['/api/v2/domains/example.org/rate-limit', '/api/v2/alias-domains/example.info/rate-limit', '/api/v2/mailboxes/alice@example.org/rate-limit'] as $uri) {
            $this->getJson($uri)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
            $this->send('PUT', $uri, ['value' => 1, 'frame' => 'hour'])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
            $this->send('DELETE', $uri)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        }

        $this->assertSame([], $this->store());
    }

    public function test_an_alias_domain_limit_is_stored_under_the_alias_domain(): void
    {
        $this->asReseller();

        $this->send('PUT', '/api/v2/alias-domains/Example.Info/rate-limit', ['value' => 20, 'frame' => 'second'])->assertOk()
            ->assertJsonPath('data.object', 'example.info')
            ->assertJsonPath('data.kind', 'aliasDomain');
        $this->assertSame('20 / 1s', $this->field('example.info'));

        $this->getJson('/api/v2/alias-domains/other.info/rate-limit')->assertNotFound();
        $this->send('PUT', '/api/v2/alias-domains/other.info/rate-limit', ['value' => 20, 'frame' => 'second'])->assertNotFound();
        $this->getJson('/api/v2/domains/example.info/rate-limit')->assertNotFound();
        $this->getJson('/api/v2/alias-domains/example.org/rate-limit')->assertNotFound();
    }

    public function test_resources_and_objects_outside_the_scope_are_not_found(): void
    {
        $this->asReseller();

        $this->getJson('/api/v2/mailboxes/room@example.org/rate-limit')->assertNotFound();
        $this->send('PUT', '/api/v2/mailboxes/room@example.org/rate-limit', ['value' => 1, 'frame' => 'hour'])->assertNotFound();
        $this->getJson('/api/v2/mailboxes/otto@other.example/rate-limit')->assertNotFound();
        $this->getJson('/api/v2/domains/other.example/rate-limit')->assertNotFound();
        $this->send('DELETE', '/api/v2/domains/other.example/rate-limit')->assertNotFound();
        $this->getJson('/api/v2/domains/missing.example/rate-limit')->assertNotFound();

        $this->assertSame([], $this->store());
    }

    public function test_an_outdated_version_is_refused(): void
    {
        $this->asAdmin();
        $this->redis()->hSet('RL_VALUE', 'example.org', '3 / 1d');
        $etag = (string) $this->getJson('/api/v2/domains/example.org/rate-limit')->json('data.etag');

        $this->redis()->hSet('RL_VALUE', 'example.org', '4 / 1d');
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 1, 'frame' => 'hour'], ['If-Match' => $etag])->assertStatus(412);
        $this->send('DELETE', '/api/v2/domains/example.org/rate-limit', null, ['If-Match' => $etag])->assertStatus(412);
        $this->assertSame('4 / 1d', $this->field('example.org'));

        $current = (string) $this->getJson('/api/v2/domains/example.org/rate-limit')->json('data.etag');
        $this->assertNotSame($etag, $current);
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 1, 'frame' => 'hour'], ['If-Match' => $current])->assertOk();
        $this->assertSame('1 / 1h', $this->field('example.org'));
        $this->assertSame('versionOutdated', $this->auditOfTarget('rateLimit', 'example.org')[0]->failure_reason);
    }

    public function test_an_unavailable_shared_store_is_a_temporary_failure(): void
    {
        $this->asAdmin();
        $this->breakSharedStore();

        $this->getJson('/api/v2/domains/example.org/rate-limit')->assertStatus(503)->assertJsonPath('dependency', 'sharedStore')->assertHeader('Retry-After');
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 1, 'frame' => 'hour'])->assertStatus(503)->assertJsonPath('dependency', 'sharedStore');
        $this->send('DELETE', '/api/v2/mailboxes/alice@example.org/rate-limit')->assertStatus(503);

        $this->assertSame(['temporaryFailure', 'temporaryFailure'], DB::table('restv2_audit_events')->where('event_type', 'like', 'rateLimit%')->orderBy('id')->pluck('failure_reason')->all());
    }

    public function test_read_only_keys_read_but_do_not_write(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $key = $this->apiKey('admin', 'readOnly');

        $this->getJson('/api/v2/domains/example.org/rate-limit', ['X-API-Key' => $key['secret']])->assertOk();
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 1, 'frame' => 'hour'], ['X-API-Key' => $key['secret']])
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->assertNull($this->field('example.org'));
    }

    public function test_changes_are_audited_with_old_and_new_values(): void
    {
        $this->asAdmin();

        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 100, 'frame' => 'hour'])->assertOk();
        $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 50, 'frame' => 'hour'])->assertOk();
        $this->send('DELETE', '/api/v2/domains/example.org/rate-limit')->assertNoContent();
        $this->send('PUT', '/api/v2/mailboxes/alice@example.org/rate-limit', ['value' => 1, 'frame' => 'second'])->assertOk();

        $records = $this->auditOfTarget('rateLimit', 'example.org');
        $this->assertSame(['rateLimitUpdate', 'rateLimitUpdate', 'rateLimitRemoval'], array_column($records, 'event_type'));
        $this->assertSame(['kind' => 'domain', 'old' => null, 'new' => '100 / 1h'], $records[0]->details);
        $this->assertSame(['kind' => 'domain', 'old' => '100 / 1h', 'new' => '50 / 1h'], $records[1]->details);
        $this->assertSame(['kind' => 'domain', 'old' => '50 / 1h', 'new' => null], $records[2]->details);
        $this->assertSame('example.org', $records[0]->target_name);
        $this->assertSame(['kind' => 'mailbox', 'old' => null, 'new' => '1 / 1s'], $this->auditOfTarget('rateLimit', 'alice@example.org')[0]->details);
    }

    /**
     * @return array<string, string>
     */
    private function store(): array
    {
        $all = $this->redis()->hGetAll('RL_VALUE');

        return is_array($all) ? $all : [];
    }
}
