<?php

namespace Tests\Feature\Audit;

use App\Auth\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Auth\AuthRequests;
use Tests\TestCase;

/**
 * T096 (FR-047, FR-059, FR-060, AS-1–AS-3, G18, G19): administrators list the audit records, newest
 * first, with filters, search and paging. Records never carry a version tag.
 */
class AuditEventListTest extends TestCase
{
    use ApiKeys;
    use AuthRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->admin('admin', 'x');
    }

    /**
     * Inserts an audit record with sensible defaults and returns its ID.
     *
     * @param  array<string, mixed>  $overrides  column => value
     */
    private function record(array $overrides = []): string
    {
        $id = (string) Str::ulid();

        DB::table('restv2_audit_events')->insert(array_merge([
            'id' => $id,
            'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'event_type' => 'signIn',
            'outcome' => 'success',
            'actor_username' => 'alice@example.org',
            'actor_role' => 'mailboxUser',
            'actor_api_key_id' => null,
            'target_type' => 'session',
            'target_id' => null,
            'target_name' => null,
            'client_address' => '192.0.2.10',
            'user_agent' => 'Firefox',
            'auth_method' => 'password',
            'failure_reason' => null,
            'request_id' => bin2hex(random_bytes(16)),
            'details' => '{}',
        ], $overrides));

        return $id;
    }

    /**
     * The IDs listed for a query string, as an administrator.
     *
     * @return list<string>
     */
    private function ids(string $query): array
    {
        return array_column($this->list($query)->assertOk()->json('data'), 'id');
    }

    /**
     * @return TestResponse<Response>
     */
    private function list(string $query = ''): TestResponse
    {
        return $this->getJson('/api/v2/audit-events'.($query === '' ? '' : '?'.$query));
    }

    private function at(int $minutesAgo): string
    {
        return CarbonImmutable::now('UTC')->subMinutes($minutesAgo)->format('Y-m-d H:i:s.u');
    }

    public function test_records_are_listed_newest_first_with_every_member(): void
    {
        $this->signInAs('admin', Role::Admin);
        $old = $this->record(['occurred_at' => '2026-10-18 10:00:00.123456', 'details' => '{"requestedRole":null,"restricted":false}']);
        $new = $this->record(['occurred_at' => $this->at(1), 'event_type' => 'apiKeyCreation', 'actor_username' => 'admin', 'actor_role' => 'admin', 'actor_api_key_id' => null, 'target_type' => 'apiKey', 'target_id' => '01JAAAAAAAAAAAAAAAAAAAAAAA', 'target_name' => 'Billing', 'user_agent' => null]);

        $response = $this->list()->assertOk();

        $this->assertSame([$new, $old], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta', ['total' => 2, 'limit' => 25, 'offset' => 0]);
        $this->assertSame([
            'id' => $old,
            'occurredAt' => '2026-10-18T10:00:00.123456Z',
            'eventType' => 'signIn',
            'outcome' => 'success',
            'actor' => ['username' => 'alice@example.org', 'role' => 'mailboxUser', 'apiKeyId' => null],
            'target' => ['type' => 'session', 'id' => null, 'name' => null],
            'clientAddress' => '192.0.2.10',
            'userAgent' => 'Firefox',
            'authMethod' => 'password',
            'failureReason' => null,
            'requestId' => $response->json('data.1.requestId'),
            'details' => ['requestedRole' => null, 'restricted' => false],
        ], $response->json('data.1'));
        $this->assertSame(['type' => 'apiKey', 'id' => '01JAAAAAAAAAAAAAAAAAAAAAAA', 'name' => 'Billing'], $response->json('data.0.target'));
        // Empty details are an object, and records carry no version tag.
        $this->assertStringContainsString('"details":{}', (string) $response->getContent());
        $this->assertArrayNotHasKey('etag', $response->json('data.0'));
        $this->assertNull($response->headers->get('ETag'));

        $this->assertSame([$old, $new], $this->ids('order=asc'));
    }

    public function test_the_time_range(): void
    {
        $this->signInAs('admin', Role::Admin);
        $a = $this->record(['occurred_at' => '2026-10-01 08:00:00.000000']);
        $b = $this->record(['occurred_at' => '2026-10-01 10:00:00.000000']);
        $c = $this->record(['occurred_at' => '2026-10-01 12:00:00.000000']);

        $this->assertSame([$c, $b], $this->ids('from=2026-10-01T10:00:00Z'));
        $this->assertSame([$a], $this->ids('to=2026-10-01T10:00:00Z'));
        // Offsets are converted to UTC; `from` is inclusive, `to` exclusive.
        $this->assertSame([$b], $this->ids('from='.urlencode('2026-10-01T12:00:00+02:00').'&to='.urlencode('2026-10-01T14:00:00+02:00')));
    }

    public function test_repeated_event_types_outcome_and_actor(): void
    {
        $this->signInAs('admin', Role::Admin);
        $signIn = $this->record(['occurred_at' => $this->at(4)]);
        $signOut = $this->record(['occurred_at' => $this->at(3), 'event_type' => 'signOut']);
        $failed = $this->record(['occurred_at' => $this->at(2), 'outcome' => 'failure', 'failure_reason' => 'invalidCredentials', 'actor_username' => 'mallory', 'actor_role' => null, 'target_type' => null]);
        $byKey = $this->record(['occurred_at' => $this->at(1), 'event_type' => 'apiKeyRevocation', 'actor_username' => 'admin', 'actor_api_key_id' => '01JBBBBBBBBBBBBBBBBBBBBBBB', 'auth_method' => 'apiKey']);

        $this->assertSame([$failed, $signOut, $signIn], $this->ids('eventType=signIn&eventType=signOut'));
        $this->assertSame([$byKey], $this->ids('eventType=apiKeyRevocation'));
        // A type of a feature this server does not implement yet matches nothing.
        $this->assertSame([], $this->ids('eventType=domainCreation'));
        $this->assertSame([$failed], $this->ids('outcome=failure'));
        $this->assertSame([$failed], $this->ids('actor=mallory'));
        $this->assertSame([$byKey], $this->ids('actor=01JBBBBBBBBBBBBBBBBBBBBBBB'));
    }

    public function test_the_client_address_exactly_or_within_a_prefix(): void
    {
        $this->signInAs('admin', Role::Admin);
        $v4 = $this->record(['occurred_at' => $this->at(4), 'client_address' => '192.0.2.10']);
        $v4b = $this->record(['occurred_at' => $this->at(3), 'client_address' => '192.0.2.200']);
        $other = $this->record(['occurred_at' => $this->at(2), 'client_address' => '198.51.100.1']);
        $v6 = $this->record(['occurred_at' => $this->at(1), 'client_address' => '2001:db8::1']);

        $this->assertSame([$v4], $this->ids('clientAddress=192.0.2.10'));
        $this->assertSame([$v6], $this->ids('clientAddress='.urlencode('2001:DB8:0::1')));
        $this->assertSame([$v4b, $v4], $this->ids('clientAddress='.urlencode('192.0.2.0/24')));
        $this->assertSame([$v4b], $this->ids('clientAddress='.urlencode('192.0.2.128/25')));
        $this->assertSame([$v6], $this->ids('clientAddress='.urlencode('2001:db8::/32')));
        $this->assertSame([$v6], $this->ids('clientAddress='.urlencode('2001:db8::/34')));
        $this->assertSame([], $this->ids('clientAddress='.urlencode('2001:db8:4000::/34')));
        $this->assertSame([$other, $v4b, $v4], $this->ids('clientAddress='.urlencode('0.0.0.0/0')));
    }

    public function test_repeated_authentication_methods(): void
    {
        $this->signInAs('admin', Role::Admin);
        $password = $this->record(['occurred_at' => $this->at(4), 'auth_method' => 'password']);
        $passkey = $this->record(['occurred_at' => $this->at(3), 'auth_method' => 'passkey']);
        $key = $this->record(['occurred_at' => $this->at(2), 'auth_method' => 'apiKey']);
        $this->record(['occurred_at' => $this->at(1), 'auth_method' => null]);

        $this->assertSame([$passkey, $password], $this->ids('authMethod=password&authMethod=passkey'));
        $this->assertSame([$key], $this->ids('authMethod=apiKey'));
        // A method of a feature this server does not implement yet matches nothing.
        $this->assertSame([], $this->ids('authMethod=singleSignOn'));
    }

    public function test_the_records_of_one_request(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $failure = $this->passwordSignIn('alice@example.org', 'wrong')->assertUnauthorized();
        $requestId = (string) $failure->json('requestId');
        $this->record(['occurred_at' => $this->at(1)]);
        $this->forgetCookies();
        $this->signInAs('admin', Role::Admin);

        $response = $this->list('requestId='.$requestId)->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.requestId', $requestId);
        $response->assertJsonPath('data.0.failureReason', 'invalidCredentials');
    }

    public function test_the_target_and_impersonation_filters(): void
    {
        $this->signInAs('admin', Role::Admin);
        $key = $this->record(['event_type' => 'apiKeyCreation', 'target_type' => 'apiKey', 'target_id' => '01JAAAAAAAAAAAAAAAAAAAAAAA', 'target_name' => 'Billing']);
        $this->record(['event_type' => 'apiKeyCreation', 'target_type' => 'apiKey', 'target_id' => '01JCCCCCCCCCCCCCCCCCCCCCCC', 'target_name' => 'Other']);
        $this->record(['occurred_at' => $this->at(1)]);

        $this->assertSame([$key], $this->ids('targetType=apiKey&targetId=01JAAAAAAAAAAAAAAAAAAAAAAA'));
        $this->assertCount(2, $this->ids('targetType=apiKey'));
        $this->assertSame([], $this->ids('targetType=domain'));
        // No record is written during an impersonation before feature 007.
        $this->assertSame([], $this->ids('impersonatedAccount=alice@example.org'));
    }

    public function test_search_and_paging(): void
    {
        $this->signInAs('admin', Role::Admin);
        $byActor = $this->record(['occurred_at' => $this->at(5), 'actor_username' => 'reseller']);
        $byTarget = $this->record(['occurred_at' => $this->at(4), 'target_name' => 'Reseller billing']);
        $byAgent = $this->record(['occurred_at' => $this->at(3), 'user_agent' => 'curl/8.0 (RESELLER bot)']);
        $this->record(['occurred_at' => $this->at(2)]);
        $this->record(['occurred_at' => $this->at(1), 'user_agent' => '100% bot']);

        $this->assertSame([$byAgent, $byTarget, $byActor], $this->ids('q=reseller'));
        $this->assertCount(1, $this->ids('q='.urlencode('100%')));
        $this->assertCount(0, $this->ids('q='.urlencode('1_0')));

        $page = $this->list('limit=2&offset=1')->assertOk();
        $page->assertJsonPath('meta', ['total' => 5, 'limit' => 2, 'offset' => 1]);
        $this->assertCount(2, $page->json('data'));
    }

    public function test_invalid_filters_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->signInAs('admin', Role::Admin);
        $code = fn (string $query, string $field): mixed => $this->list($query)->assertUnprocessable()->json("errors.{$field}.0");

        $this->assertSame('outOfRange', $code('limit=0', 'limit')['code']);
        $this->assertSame('outOfRange', $code('limit=1001', 'limit')['code']);
        $this->assertEquals(['code' => 'notInList', 'params' => ['allowed' => ['success', 'failure']], 'message' => 'Must be one of: success, failure.'], $code('outcome=maybe', 'outcome'));
        $this->assertSame(['format' => 'dateTime'], $code('from=yesterday', 'from')['params']);
        $this->assertSame(['format' => 'dateTime'], $code('to=2026-13-01T00:00:00Z', 'to')['params']);
        $this->assertSame(['format' => 'ipNetwork'], $code('clientAddress=999.1.1.1', 'clientAddress')['params']);
        $this->assertSame(['format' => 'requestId'], $code('requestId=XYZ', 'requestId')['params']);
        $this->assertSame(['max' => 20], $code(implode('&', array_fill(0, 21, 'eventType=signIn')), 'eventType')['params']);
        $this->assertSame(['max' => 20], $code(implode('&', array_fill(0, 21, 'authMethod=password')), 'authMethod')['params']);
        $this->assertSame('tooLong', $code('actor='.str_repeat('a', 256), 'actor')['code']);
        $this->assertSame('notInList', $code('sort=eventType', 'sort')['code']);
    }

    public function test_a_failed_sign_in_is_listed_with_the_attempted_username(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $this->passwordSignIn('alice@example.org', 'wrong')->assertUnauthorized();
        $this->forgetCookies();
        $this->signInAs('admin', Role::Admin);

        $response = $this->list('outcome=failure&clientAddress=192.0.2.10&eventType=signIn')->assertOk();

        $response->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.actor.username', 'alice@example.org')
            ->assertJsonPath('data.0.actor.role', null)
            ->assertJsonPath('data.0.clientAddress', '192.0.2.10')
            ->assertJsonPath('data.0.failureReason', 'invalidCredentials');
        $this->assertNotNull($response->json('data.0.occurredAt'));
    }

    public function test_key_creation_and_revocation_are_listed_without_the_secret(): void
    {
        $this->mailcow()->admin('other', 'x');
        $this->signInAs('admin', Role::Admin);
        $created = $this->postJson('/api/v2/api-keys', ['name' => 'Billing', 'ownerUsername' => 'admin', 'accessLevel' => 'readOnly'], ['X-CSRF-Token' => $this->csrfToken()])->assertCreated();
        $secret = (string) $created->json('data.secret');
        $this->postEmpty('/api/v2/api-keys/'.$created->json('data.id').'/revocation', ['X-CSRF-Token' => $this->csrfToken()])->assertOk();
        $this->forgetCookies();
        $this->signInAs('other', Role::Admin);

        $response = $this->list('eventType=apiKeyCreation&eventType=apiKeyRevocation&order=asc')->assertOk();

        $this->assertSame(['apiKeyCreation', 'apiKeyRevocation'], array_column($response->json('data'), 'eventType'));
        foreach ($response->json('data') as $record) {
            $this->assertSame('admin', $record['actor']['username']);
            $this->assertSame('Billing', $record['target']['name']);
        }
        $this->assertStringNotContainsString($secret, (string) $response->getContent());
        $this->assertStringNotContainsString(substr($secret, 12), (string) $response->getContent());
    }

    public function test_only_administrators_may_list(): void
    {
        $this->record();
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->mailcow()->mailboxWithDomain('alice@example.org');

        $this->list()->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/unauthenticated');

        $this->signInAs('alice@example.org', Role::MailboxUser);
        $this->list()->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->list()->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->getJson('/api/v2/audit-events', $this->keyHeader($this->apiKey('reseller', 'readOnly')))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->getJson('/api/v2/audit-events', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk()->assertJsonPath('meta.total', 1);
    }
}
