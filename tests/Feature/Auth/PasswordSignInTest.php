<?php

namespace Tests\Feature\Auth;

use App\Support\Hashing\LegacyPasswordVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Hashing\SpyPasswordVerifier;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * T054 (FR-001–FR-005, FR-008, FR-011, AS-1, AS-6): createSession signs every role in with legacy's
 * account resolution and answers one generic error for every failure.
 */
class PasswordSignInTest extends TestCase
{
    use AuthRequests;

    private const PASSWORD = MailcowFactory::PASSWORD;

    public function test_an_admin_without_second_factor_signs_in(): void
    {
        $this->mailcow()->admin('admin', self::PASSWORD);

        $response = $this->passwordSignIn('admin')->assertCreated();

        $response->assertHeader('Location', '/api/v2/session')
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.username', 'admin')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.authMethod', 'password')
            ->assertJsonPath('data.pendingActions', []);
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'active');
    }

    public function test_a_domain_admin_and_a_mailbox_user_sign_in_with_their_roles(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->mailcow()->mailboxWithDomain('alice@example.org');

        $this->passwordSignIn('reseller')->assertCreated()->assertJsonPath('data.role', 'domainAdmin');
        $this->passwordSignIn('alice@example.org')->assertCreated()->assertJsonPath('data.role', 'mailboxUser');
    }

    public function test_a_username_in_admin_and_mailbox_resolves_to_the_admin_unless_the_role_is_named(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        // Both accounts share the username; each has its own password.
        $this->mailcow()->admin('both@example.org', 'admin-password');
        $this->mailcow()->mailbox('both@example.org', self::PASSWORD);

        $this->passwordSignIn('both@example.org', 'admin-password')->assertCreated()->assertJsonPath('data.role', 'admin');
        $this->passwordSignIn('both@example.org', self::PASSWORD)->assertCreated()->assertJsonPath('data.role', 'mailboxUser');
        $this->passwordSignIn('both@example.org', 'admin-password', 'mailboxUser')->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
        $this->passwordSignIn('both@example.org', self::PASSWORD, 'mailboxUser')->assertCreated()->assertJsonPath('data.role', 'mailboxUser');
        $this->passwordSignIn('both@example.org', 'admin-password', 'admin')->assertCreated()->assertJsonPath('data.role', 'admin');
    }

    public function test_a_named_role_of_another_type_fails(): void
    {
        $this->mailcow()->admin('admin', self::PASSWORD);

        $this->passwordSignIn('admin', self::PASSWORD, 'domainAdmin')->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
        $this->passwordSignIn('admin', self::PASSWORD, 'mailboxUser')->assertUnauthorized();
    }

    public function test_the_resolution_order_is_admin_then_domain_admin_then_mailbox(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        // A domain admin and a mailbox with the same username and password: the domain admin wins.
        $this->mailcow()->domainAdmin('dual@example.org', ['example.org']);
        $this->mailcow()->mailbox('dual@example.org', self::PASSWORD);

        $this->passwordSignIn('dual@example.org')->assertCreated()->assertJsonPath('data.role', 'domainAdmin');
    }

    public function test_the_username_is_trimmed_and_lower_cased(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');

        $this->passwordSignIn(' Alice@Example.ORG ')->assertCreated()
            ->assertJsonPath('data.username', 'alice@example.org');
    }

    public function test_a_malformed_username_fails(): void
    {
        $this->passwordSignIn('a b!', 'whatever')->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
    }

    public function test_every_failure_gets_the_same_generic_error(): void
    {
        $factory = $this->mailcow();
        $factory->ensureDomain('example.org');
        $factory->ensureDomain('inactive.example', 0);
        $factory->admin('inactive-admin', self::PASSWORD, active: 0);
        $factory->domainAdmin('inactive-reseller', ['example.org'], active: 0);
        $factory->mailbox('inactive@example.org', self::PASSWORD, active: 0);
        $factory->mailbox('user@inactive.example', self::PASSWORD);
        $factory->mailbox('room@example.org', self::PASSWORD, kind: 'location');
        $factory->mailbox('car@example.org', self::PASSWORD, kind: 'thing');
        $factory->mailbox('team@example.org', self::PASSWORD, kind: 'group');
        $factory->mailbox('alice@example.org', self::PASSWORD);

        $bodies = [];

        foreach (['inactive-admin', 'inactive-reseller', 'inactive@example.org', 'user@inactive.example', 'room@example.org', 'car@example.org', 'team@example.org'] as $username) {
            $response = $this->passwordSignIn($username)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
            $bodies[] = [$response->json('title'), $response->json('detail')];
        }

        $bodies[] = [
            $this->passwordSignIn('alice@example.org', 'wrong')->assertUnauthorized()->json('title'),
            $this->passwordSignIn('alice@example.org', 'wrong')->json('detail'),
        ];
        $bodies[] = [
            $this->passwordSignIn('nobody@example.org')->assertUnauthorized()->json('title'),
            $this->passwordSignIn('nobody@example.org')->json('detail'),
        ];

        $this->assertCount(1, array_unique(array_map('serialize', $bodies)), 'Every failure must look the same.');
    }

    public function test_an_app_password_never_signs_in(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        DB::table('app_passwd')->insert([
            'name' => 'Phone',
            'mailbox' => 'alice@example.org',
            'domain' => 'example.org',
            'password' => MailcowFactory::hash('BLF-CRYPT', 'app-secret-123'),
            'active' => 1,
        ]);

        $this->passwordSignIn('alice@example.org', 'app-secret-123')->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
        $this->passwordSignIn('alice@example.org', 'app-secret-123', 'mailboxUser')->assertUnauthorized();
    }

    public function test_mailboxes_of_external_identity_sources_cannot_sign_in_with_a_password(): void
    {
        $this->mailcow()->ensureDomain('example.org');

        foreach (['ldap', 'keycloak', 'generic-oidc'] as $source) {
            $username = "{$source}@example.org";
            $this->mailcow()->mailbox($username, self::PASSWORD, authsource: $source);

            $this->passwordSignIn($username)->assertUnauthorized()
                ->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
        }
    }

    public function test_an_unknown_account_still_runs_one_dummy_verification(): void
    {
        $spy = new SpyPasswordVerifier;
        $this->app->instance(LegacyPasswordVerifier::class, $spy);

        $this->passwordSignIn('nobody@example.org', 'whatever')->assertUnauthorized();

        $this->assertSame([LegacyPasswordVerifier::DUMMY_HASH], $spy->verifiedHashes);
        $this->assertStringStartsWith('{BLF-CRYPT}', $spy->verifiedHashes[0]);
    }

    public function test_the_session_identifier_is_renewed_on_success(): void
    {
        $this->mailcow()->admin('admin', self::PASSWORD);
        $token = $this->csrfToken();
        $before = $this->sessionCookie();
        $this->assertNotNull($before);

        $this->postJson('/api/v2/session', ['username' => 'admin', 'password' => self::PASSWORD], ['X-CSRF-Token' => $token])->assertCreated();

        $this->assertNotSame($before, $this->sessionCookie());
        $this->assertFalse((bool) Redis::connection('mailcow')->exists('restv2:session:'.$before));
        $this->assertTrue((bool) Redis::connection('mailcow')->exists('restv2:session:'.$this->sessionCookie()));
        // The anti-forgery token survives the renewal (R7).
        $this->assertSame($token, $this->csrfToken());
    }

    public function test_a_failed_sign_in_keeps_the_session_identifier(): void
    {
        $this->csrfToken();
        $before = $this->sessionCookie();

        $this->passwordSignIn('nobody', 'wrong')->assertUnauthorized();

        $this->assertSame($before, $this->sessionCookie());
    }

    public function test_a_second_sign_in_replaces_the_first_principal(): void
    {
        $this->mailcow()->admin('admin', self::PASSWORD);
        $this->mailcow()->mailboxWithDomain('alice@example.org');

        $this->passwordSignIn('admin')->assertCreated();
        $this->passwordSignIn('alice@example.org')->assertCreated();

        $this->getJson('/api/v2/session')
            ->assertJsonPath('data.username', 'alice@example.org')
            ->assertJsonPath('data.role', 'mailboxUser');
    }

    public function test_a_missing_anti_forgery_token_is_refused(): void
    {
        $this->mailcow()->admin('admin', self::PASSWORD);
        $this->csrfToken();

        $this->postJson('/api/v2/session', ['username' => 'admin', 'password' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_invalid_requests_with_missing_fields_are_refused_and_not_counted(): void
    {
        $this->withoutRequestValidation();
        $token = $this->csrfToken();

        $response = $this->postJson('/api/v2/session', [], ['X-CSRF-Token' => $token])->assertUnprocessable();

        $response->assertJsonPath('type', '/api/v2/problems/validation-failed')
            ->assertJsonPath('errors.username.0.code', 'required')
            ->assertJsonPath('errors.password.0.code', 'required');

        $this->postJson('/api/v2/session', ['username' => 'alice@example.org'], ['X-CSRF-Token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0.code', 'required');

        $this->postJson('/api/v2/session', ['username' => 'alice@example.org', 'password' => 'x', 'role' => 'superuser'], ['X-CSRF-Token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0.code', 'notInList');

        // Nothing was counted towards throttling, nothing audited.
        $this->assertSame([], Redis::connection('mailcow')->keys('restv2:signin:*'));
        $this->assertSame([], $this->auditRecords('signIn'));
    }

    public function test_a_malformed_json_body_is_a_bad_request(): void
    {
        $this->withoutRequestValidation();
        $token = $this->csrfToken();

        $this->call('POST', '/api/v2/session', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], '{"username": "admin", ')
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'about:blank');
    }
}
