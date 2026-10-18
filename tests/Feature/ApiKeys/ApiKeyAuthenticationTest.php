<?php

namespace Tests\Feature\ApiKeys;

use App\Auth\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * T082 (FR-033, FR-038–FR-041, SC-005, R8, R19): `X-API-Key` authentication acts as the key's
 * owner, distinguishes every key outcome with its own problem type, and reports invalid keys to
 * mailcow's fail2ban.
 */
class ApiKeyAuthenticationTest extends TestCase
{
    use ApiKeys;

    private const IP = '192.0.2.7';

    private const REPORT = 'mailcow UI: Invalid password for API_USER by ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withServerVariables(['REMOTE_ADDR' => self::IP]);
        $this->mailcow()->admin('admin', 'x');
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->ensureDomain('example.net');
        $this->mailcow()->domainAdmin('reseller', ['example.org', 'example.net'], ['login_as' => 0]);
    }

    public function test_a_domain_admin_key_acts_as_its_owner(): void
    {
        $key = $this->apiKey('reseller', 'readOnly', name: 'Billing');

        $response = $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();

        $response->assertJsonPath('data.username', 'reseller')
            ->assertJsonPath('data.role', 'domainAdmin')
            ->assertJsonPath('data.scope', ['type' => 'domains', 'domains' => ['example.net', 'example.org']])
            ->assertJsonPath('data.permissions.loginAs', false)
            ->assertJsonPath('data.permissions.syncjobs', true)
            ->assertJsonPath('data.authentication', [
                'method' => 'apiKey',
                'apiKey' => ['id' => $key['id'], 'name' => 'Billing', 'prefix' => $key['prefix'], 'accessLevel' => 'readOnly'],
            ])
            ->assertJsonPath('data.pendingActions', []);
        $this->assertArrayNotHasKey('preferences', $response->json('data'));
    }

    public function test_a_read_only_key_may_read_but_not_change_state(): void
    {
        $key = $this->apiKey('admin', 'readOnly');
        $channel = ChannelSpy::fail2ban();

        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();

        $this->deleteJson('/api/v2/session', [], $this->keyHeader($key))
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->postJson('/api/v2/api-keys', ['name' => 'x', 'ownerUsername' => 'admin', 'accessLevel' => 'readOnly'], $this->keyHeader($key))
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $channel->assertNothingPublished();
        $this->assertSame(1, DB::table('restv2_api_keys')->count(), 'no key was created');
    }

    public function test_unknown_malformed_legacy_and_overlong_keys_are_invalid_and_reported(): void
    {
        $values = [
            'unknown' => 'mcv2_'.str_repeat('A', 40),
            'malformed' => 'not a key',
            'legacy' => 'ABCDEF-123456-ABCDEF-123456-ABCDEF',
            'overlong' => 'mcv2_'.str_repeat('a', 295),
        ];

        foreach ($values as $case => $value) {
            $channel = ChannelSpy::fail2ban();

            $this->getJson('/api/v2/me', ['X-API-Key' => $value])
                ->assertUnauthorized()
                ->assertJsonPath('type', '/api/v2/problems/api-key-invalid');

            $this->assertSame([self::REPORT.self::IP], $channel->messages(1), $case);
            $channel->stop();
        }
    }

    public function test_an_expired_key_is_refused_and_reported(): void
    {
        $key = $this->apiKey(expiresAt: CarbonImmutable::now()->addMinute());
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();
        $channel = ChannelSpy::fail2ban();

        $this->travel(61)->seconds();

        $this->getJson('/api/v2/me', $this->keyHeader($key))
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/api-key-expired');
        $channel->assertPublished(self::REPORT.self::IP);
    }

    public function test_a_revoked_key_is_refused_and_reported(): void
    {
        $key = $this->apiKey(revokedAt: CarbonImmutable::now()->subMinute());
        $channel = ChannelSpy::fail2ban();

        $this->getJson('/api/v2/me', $this->keyHeader($key))
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/api-key-revoked');
        $channel->assertPublished(self::REPORT.self::IP);
    }

    public function test_a_revoked_key_wins_over_an_expired_one(): void
    {
        $key = $this->apiKey(expiresAt: CarbonImmutable::now()->subDay(), revokedAt: CarbonImmutable::now()->subHour());

        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertJsonPath('type', '/api/v2/problems/api-key-revoked');
    }

    public function test_an_inactive_or_deleted_owner_makes_the_key_invalid(): void
    {
        $key = $this->apiKey('reseller');

        DB::table('admin')->where('username', 'reseller')->update(['active' => 0]);
        $channel = ChannelSpy::fail2ban();
        $this->getJson('/api/v2/me', $this->keyHeader($key))
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/api-key-invalid');
        $channel->assertPublished(self::REPORT.self::IP);

        DB::table('admin')->where('username', 'reseller')->update(['active' => 1]);
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();

        DB::table('admin')->where('username', 'reseller')->delete();
        $this->getJson('/api/v2/me', $this->keyHeader($key))
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/api-key-invalid');
    }

    public function test_the_owner_role_is_read_on_every_request(): void
    {
        $key = $this->apiKey('reseller');
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertJsonPath('data.role', 'domainAdmin');

        DB::table('admin')->where('username', 'reseller')->update(['superadmin' => 1]);

        $this->getJson('/api/v2/me', $this->keyHeader($key))
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.scope', ['type' => 'allDomains']);
    }

    public function test_allowed_networks_restrict_the_client_address(): void
    {
        $key = $this->apiKey(networks: ['192.0.2.0/24', '2001:db8::/32']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $channel = ChannelSpy::fail2ban();
        $this->getJson('/api/v2/me', $this->keyHeader($key))
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/api-key-source-not-allowed');
        $channel->assertPublished(self::REPORT.'198.51.100.1');

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.7']);
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::5']);
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();
    }

    public function test_a_key_decides_over_a_session_cookie(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $key = $this->apiKey('admin');

        $response = $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();

        $response->assertJsonPath('data.username', 'admin')->assertJsonPath('data.authentication.method', 'apiKey');
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_key_requests_need_no_anti_forgery_token_and_create_no_session(): void
    {
        $key = $this->apiKey('admin');

        // Refused because keys may not create keys, not for the missing token.
        $this->postJson('/api/v2/api-keys', ['name' => 'x', 'ownerUsername' => 'admin', 'accessLevel' => 'readOnly'], $this->keyHeader($key))
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();

        $this->assertSame([], Redis::connection('mailcow')->keys('restv2:session:*'));
    }

    public function test_an_empty_header_is_no_key(): void
    {
        $channel = ChannelSpy::fail2ban();

        $this->getJson('/api/v2/me', ['X-API-Key' => '  '])
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/unauthenticated');
        $channel->assertNothingPublished();
    }

    public function test_the_last_use_is_recorded_at_most_once_a_minute_per_address(): void
    {
        $this->freezeTime();
        $key = $this->apiKey('admin');

        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();
        $first = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $this->assertSame(['last_used_at' => $first, 'last_used_from' => self::IP], $this->lastUse($key['id']));

        $this->travel(30)->seconds();
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();
        $this->assertSame(['last_used_at' => $first, 'last_used_from' => self::IP], $this->lastUse($key['id']));

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.8']);
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();
        $second = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $this->assertSame(['last_used_at' => $second, 'last_used_from' => '192.0.2.8'], $this->lastUse($key['id']));

        $this->travel(61)->seconds();
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();
        $this->assertSame(['last_used_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'), 'last_used_from' => '192.0.2.8'], $this->lastUse($key['id']));
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    private function lastUse(string $id): array
    {
        return (array) DB::table('restv2_api_keys')->where('id', $id)->first(['last_used_at', 'last_used_from']);
    }

    public function test_a_domain_admin_key_without_domains_has_an_empty_scope(): void
    {
        $this->mailcow()->domainAdmin('lonely', []);
        $key = $this->apiKey('lonely');

        $this->getJson('/api/v2/me', $this->keyHeader($key))
            ->assertOk()
            ->assertJsonPath('data.scope', ['type' => 'domains', 'domains' => []]);
    }

    public function test_an_owner_with_pending_actions_is_not_restricted(): void
    {
        DB::table('admin')->where('username', 'admin')->update(['attributes' => json_encode(['force_pw_update' => '1', 'force_tfa' => '1'])]);
        $key = $this->apiKey('admin');

        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk()->assertJsonPath('data.pendingActions', []);
        $this->getJson('/api/v2/api-keys', $this->keyHeader($key))->assertOk();
    }

    public function test_legacy_api_keys_are_untouched(): void
    {
        DB::table('api')->insert(['api_key' => 'ABCDEF-123456-ABCDEF-123456-ABCDEF', 'allow_from' => '192.0.2.0/24', 'access' => 'rw', 'active' => 1]);
        $before = DB::table('api')->get()->map(fn ($row) => (array) $row)->all();
        $key = $this->apiKey('admin');

        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();
        $this->getJson('/api/v2/me', ['X-API-Key' => 'ABCDEF-123456-ABCDEF-123456-ABCDEF'])->assertJsonPath('type', '/api/v2/problems/api-key-invalid');
        $this->getJson('/api/v2/me', ['X-API-Key' => 'mcv2_'.str_repeat('B', 40)])->assertUnauthorized();

        $this->assertSame($before, DB::table('api')->get()->map(fn ($row) => (array) $row)->all());
    }
}
