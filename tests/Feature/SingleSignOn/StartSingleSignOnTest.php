<?php

namespace Tests\Feature\SingleSignOn;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\TestCase;

/**
 * T049 (FR-021, US2 AS-9): starting a single sign-on.
 */
class StartSingleSignOnTest extends TestCase
{
    use SingleSignOnTestHelpers;

    public function test_without_a_usable_oidc_configuration_it_is_not_available(): void
    {
        foreach ([fn () => null, fn () => $this->mailcow()->ldapConfig(), fn () => $this->mailcow()->keycloakConfig(['realm' => ''])] as $setup) {
            $setup();
            $this->postEmpty(self::HOST.'/api/v2/session/single-sign-on', ['X-CSRF-Token' => $this->csrfToken()])
                ->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/single-sign-on-not-available');
        }
    }

    public function test_keycloak_authorization_address(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 10:00:00', 'UTC'));

        $started = $this->startSso();

        $this->assertStringStartsWith('https://sso.example.org/realms/example/protocol/openid-connect/auth?', $started['url']);
        $query = $started['query'];
        $this->assertSame('mailcow', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('https://mail.example.org/ng/sso/return', $query['redirect_uri']);
        $this->assertSame('profile email openid', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', (string) $query['code_challenge']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $started['state']);
        $started['response']->assertJsonPath('data.expiresAt', '2027-01-10T10:10:00Z');
        $this->assertSame([], $this->allAudit(), 'starting is not audited');
    }

    public function test_the_return_address_follows_the_host_name(): void
    {
        $this->mailcow()->keycloakConfig(['version' => '19.0.3']);

        $this->assertSame('https://mail.example.net/ng/sso/return', $this->startSso('https://mail.example.net')['query']['redirect_uri']);
        $this->assertSame('https://mail.example.org/ng/sso/return', $this->startSso('https://unknown.example.com')['query']['redirect_uri']);
        $this->assertSame('profile email', $this->startSso()['query']['scope']);
    }

    public function test_generic_oidc_uses_its_endpoint_and_scopes(): void
    {
        $this->mailcow()->genericOidcConfig(['client_scopes' => 'openid email']);

        $started = $this->startSso();

        $this->assertStringStartsWith('https://idp.example.net/oauth2/authorize?', $started['url']);
        $this->assertSame('openid email', $started['query']['scope']);
    }

    public function test_the_session_keeps_only_a_hash_of_the_state_and_a_new_start_replaces_it(): void
    {
        $this->mailcow()->keycloakConfig();

        $first = $this->startSso();
        $second = $this->startSso();

        $raw = (string) Redis::connection('mailcow')->get('restv2:session:'.$this->sessionCookie());
        $stored = (string) (unserialize($raw) ?: $raw);
        $this->assertStringContainsString(hash('sha256', $second['state']), $stored);
        $this->assertStringNotContainsString($second['state'], $stored);
        $this->assertStringNotContainsString(hash('sha256', $first['state']), $stored);
    }

    public function test_the_anti_forgery_token_is_required_and_keys_are_refused(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->mailcow()->admin('admin', 'Passw0rd!');

        $this->postEmpty(self::HOST.'/api/v2/session/single-sign-on')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->postEmpty(self::HOST.'/api/v2/session/single-sign-on', $this->keyHeader($this->apiKey('admin')))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/session-required');
    }
}
