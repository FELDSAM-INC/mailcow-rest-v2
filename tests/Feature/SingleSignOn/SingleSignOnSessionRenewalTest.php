<?php

namespace Tests\Feature\SingleSignOn;

use App\Services\Auth\SessionManager;
use App\Services\IdentityProvider\SsoLinkStore;
use Carbon\CarbonImmutable;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\TestCase;

/**
 * T056 (FR-026, US2 AS-8, SC-009): a single sign-on session is renewed with the provider on the
 * first request more than 5 minutes after the last renewal, and ends when the provider or mailcow's
 * data no longer allow it.
 */
class SingleSignOnSessionRenewalTest extends TestCase
{
    use SingleSignOnTestHelpers;

    private FakeOidcProvider $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2027-03-01 09:00:00', 'UTC'));
        $this->mailcow()->keycloakConfig(['mappers' => '[]', 'templates' => '[]']);
        $this->externalMailbox('anna@example.org');
    }

    private function signIn(?string $refreshToken = 'provider-refresh-token'): void
    {
        $this->idp = $this->keycloakSignIn(IdentityProviderFixtures::userInfo('anna@example.org', 'Anna'), $refreshToken);
        $this->completeSso($this->startSso()['state'])->assertCreated();
    }

    private function linkKey(): string
    {
        $keys = Redis::connection('mailcow')->keys('restv2:sso-link:*');
        $this->assertCount(1, $keys);

        return (string) $keys[0];
    }

    private function assertEnded(string $reason): void
    {
        $this->getJson('/api/v2/me')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/identity-provider-session-ended');
        $this->getJson('/api/v2/session')->assertOk()->assertJsonPath('data.state', 'anonymous');
        $this->assertSame([], Redis::connection('mailcow')->keys('restv2:sso-link:*'));

        $end = array_values(array_filter($this->allAudit(), fn ($r) => $r->event_type === 'identityProviderSessionEnd'));
        $this->assertCount(1, $end);
        $this->assertSame(['anna@example.org', 'session', $reason, 'keycloak'], [$end[0]->actor_username, $end[0]->target_type, $end[0]->details['reason'], $end[0]->details['identityProvider']]);
    }

    public function test_no_renewal_within_five_minutes_and_a_renewal_after(): void
    {
        $this->signIn();
        $this->idp->tokenIssued('refresh_token', 'renewed-access-token', 'renewed-refresh-token')->userInfo(IdentityProviderFixtures::userInfo('anna@example.org', 'Anna'));

        $this->travel(4)->minutes();
        $this->getJson('/api/v2/me')->assertOk();
        $this->idp->assertSentCount('token:refresh_token', 0);

        $this->travel(2)->minutes();
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.authentication.method', 'singleSignOn');
        $this->idp->assertSentCount('token:refresh_token', 1);
        $this->assertSame('provider-refresh-token', $this->idp->last('token:refresh_token')['form']['refresh_token']);
        $this->assertSame(['Bearer renewed-access-token'], $this->idp->last('userinfo')['headers']['Authorization']);

        $link = $this->app->make(SsoLinkStore::class)->get(substr($this->linkKey(), strlen('restv2:sso-link:')));
        $this->assertNotNull($link);
        $this->assertSame(['renewed-access-token', 'renewed-refresh-token'], [$link['accessToken'], $link['refreshToken']]);

        $raw = (string) Redis::connection('mailcow')->get($this->linkKey());
        foreach (['provider-access-token', 'provider-refresh-token', 'renewed-access-token', 'renewed-refresh-token'] as $token) {
            $this->assertStringNotContainsString($token, $raw);
        }
    }

    public function test_a_refused_renewal_ends_the_session(): void
    {
        $this->signIn();
        $this->idp->tokenRefused('refresh_token', 'invalid_grant');
        $this->travel(6)->minutes();

        $this->assertEnded('providerRefused');
    }

    public function test_an_unreachable_provider_ends_the_session(): void
    {
        $this->signIn();
        $this->idp->connectionError('token:refresh_token', 7);
        $this->travel(6)->minutes();

        $this->assertEnded('providerUnavailable');
    }

    public function test_another_email_address_ends_the_session(): void
    {
        $this->signIn();
        $this->idp->tokenIssued('refresh_token')->userInfo(IdentityProviderFixtures::userInfo('mallory@example.org'));
        $this->travel(6)->minutes();

        $this->assertEnded('emailChanged');
    }

    public function test_an_unverified_email_ends_the_session_on_renewal(): void
    {
        $this->signIn();
        $claims = IdentityProviderFixtures::userInfo('anna@example.org');
        $claims['email_verified'] = false;
        $this->idp->tokenIssued('refresh_token')->userInfo($claims);
        $this->travel(6)->minutes();

        $this->assertEnded('providerRefused');
    }

    public function test_a_removed_or_changed_configuration_ends_the_session_at_the_next_request(): void
    {
        $this->signIn();
        DB::table('identity_provider')->where('key', 'client_secret')->update(['value' => '']);

        $this->assertEnded('configurationChanged');
    }

    public function test_a_mailbox_switched_to_mailcow_ends_the_session(): void
    {
        $this->signIn();
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['authsource' => 'mailcow']);

        $this->assertEnded('authSourceChanged');
    }

    public function test_without_a_refresh_token_the_session_is_never_renewed(): void
    {
        $this->signIn(null);
        $this->travel(30)->minutes();

        $this->getJson('/api/v2/me')->assertOk();
        $this->idp->assertSentCount('token:refresh_token', 0);

        DB::table('mailbox')->where('username', 'anna@example.org')->update(['authsource' => 'mailcow']);
        $this->getJson('/api/v2/me')->assertUnauthorized();
    }

    public function test_a_renewal_without_a_new_refresh_token_keeps_the_old_one(): void
    {
        $this->signIn();
        $this->idp->tokenIssued('refresh_token', 'renewed-access-token', null)->userInfo(IdentityProviderFixtures::userInfo('anna@example.org'));

        $this->travel(6)->minutes();
        $this->getJson('/api/v2/me')->assertOk();
        $this->travel(6)->minutes();
        $this->getJson('/api/v2/me')->assertOk();

        $sent = $this->idp->sent('token:refresh_token');
        $this->assertCount(2, $sent);
        $this->assertSame('provider-refresh-token', $sent[1]['form']['refresh_token']);
    }

    public function test_a_link_renewed_by_a_parallel_request_is_not_renewed_again(): void
    {
        $this->signIn();
        $this->travel(6)->minutes();
        $store = $this->app->make(SsoLinkStore::class);
        $id = substr($this->linkKey(), strlen('restv2:sso-link:'));
        $link = $store->get($id);
        $this->assertNotNull($link);
        $store->put($id, ['lastRenewedAt' => CarbonImmutable::now()->getTimestamp()] + $link);

        $this->getJson('/api/v2/me')->assertOk();

        $this->idp->assertSentCount('token:refresh_token', 0);
    }

    public function test_reading_the_session_and_signing_out_never_renew(): void
    {
        $this->signIn();
        $this->idp->tokenRefused('refresh_token');
        $this->travel(6)->minutes();

        $this->getJson('/api/v2/session')->assertOk()->assertJsonPath('data.state', 'active');
        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();

        $this->idp->assertSentCount('token:refresh_token', 0);
    }

    public function test_a_missing_provider_link_ends_the_session_without_contacting_the_provider(): void
    {
        $this->signIn();
        Redis::connection('mailcow')->del($this->linkKey());

        $this->assertEnded('providerLinkMissing');
        $this->idp->assertSentCount('token:refresh_token', 0);
    }

    public function test_browser_heartbeats_keep_the_provider_link_alive_without_renewing_tokens(): void
    {
        $this->signIn();
        $key = $this->linkKey();
        Redis::connection('mailcow')->expire($key, 5);

        $this->getJson('/api/v2/session')->assertOk()->assertJsonPath('data.state', 'active');

        $this->assertGreaterThan(7000, (int) Redis::connection('mailcow')->ttl($key));
        $this->idp->assertSentCount('token:refresh_token', 0);
    }

    public function test_a_single_sign_on_session_without_a_link_identifier_is_refused(): void
    {
        $this->signIn();
        $sessionId = (string) $this->sessionCookie();
        $store = new Store($this->sessionCookieName(), $this->app['session']->driver()->getHandler(), $sessionId, 'json');
        $store->start();
        $store->forget(SessionManager::PRINCIPAL.'.ssoLinkId');
        $store->save();

        $this->getJson('/api/v2/me')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/identity-provider-session-ended');
        $this->getJson('/api/v2/session')->assertOk()->assertJsonPath('data.state', 'anonymous');
        $this->idp->assertSentCount('token:refresh_token', 0);
    }

    public function test_an_unreadable_provider_link_ends_the_session(): void
    {
        $this->signIn();
        Redis::connection('mailcow')->set($this->linkKey(), 'unreadable-test-link');

        $this->assertEnded('providerLinkMissing');
        $this->idp->assertSentCount('token:refresh_token', 0);
    }
}
