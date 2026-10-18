<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use App\Clients\Dovecot\SieveScriptReader;
use App\Clients\Pushover\PushoverClient;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Fakes\FakePushoverClient;
use Tests\Support\Fakes\FakeSieveScriptReader;
use Tests\Support\FeatureRegistry;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T124 (feature 005 SC-007, FR-001 to FR-006): the 51 operations of account self-service for 12
 * kinds of caller, derived from FR-005 and the request pipeline (authentication, key scope, pending
 * actions, role, scope, flag): `401` without credentials, `403` for key scope, sessions-only
 * operations, pending actions and roles, `404` for mailboxes outside the caller's scope.
 */
class SelfServiceAuthorizationMatrixTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const CALLERS = [
        'anonymous',
        'restrictedSession',
        'adminSession',
        'domainAdminSession',
        'foreignDomainAdminSession',
        'mailboxSession',
        'foreignMailboxSession',
        'adminReadOnlyKey',
        'adminKey',
        'domainAdminReadOnlyKey',
        'domainAdminKey',
        'foreignDomainAdminKey',
    ];

    private const ALL_USER_FLAGS = [
        'spam_alias' => 1, 'tls_policy' => 1, 'spam_score' => 1, 'spam_policy' => 1, 'delimiter_action' => 1, 'syncjobs' => 1,
        'eas_reset' => 1, 'sogo_profile_reset' => 1, 'pushover' => 1, 'quarantine' => 1, 'quarantine_attachments' => 1,
        'quarantine_notification' => 1, 'quarantine_category' => 1, 'app_passwds' => 1, 'pw_reset' => 1,
    ];

    /** @var array<string, string> */
    private array $ids = [];

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PushoverClient::class, new FakePushoverClient);
        $this->app->instance(SieveScriptReader::class, new FakeSieveScriptReader);
        $f = $this->mailcow();
        $f->mailboxWithDomain('anna@example.org', userAcl: self::ALL_USER_FLAGS);
        $f->mailbox('rita@example.org', MailcowFactory::PASSWORD, attributes: ['force_pw_update' => '1'], userAcl: self::ALL_USER_FLAGS);
        $f->mailboxWithDomain('dora@example.net', userAcl: self::ALL_USER_FLAGS);
        $f->admin('admin', MailcowFactory::PASSWORD);
        $f->domainAdmin('reseller', ['example.org'], ['filters' => 1, 'pushover' => 1, 'app_passwds' => 1]);
        $f->domainAdmin('foreign', ['example.net'], ['filters' => 1, 'pushover' => 1, 'app_passwds' => 1]);
        $f->pushoverSettings('anna@example.org', str_repeat('k', 30), str_repeat('t', 30));
        $this->ids = [
            '{appPassword}' => (string) $f->appPasswd('anna@example.org', 'Phone'),
            '{filter}' => (string) $f->sieveFilterRow('anna@example.org', 'Filter'),
        ];
        $f->temporaryAliasRow('abcdef.ghijkl@example.org', 'anna@example.org');
        $this->configureResetMessage();
        $this->keys = [
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'adminKey' => $this->apiKey('admin')['secret'],
            'domainAdminReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
            'domainAdminKey' => $this->apiKey('reseller')['secret'],
            'foreignDomainAdminKey' => $this->apiKey('foreign')['secret'],
        ];
    }

    /**
     * operation => [method, path, body (null: none), kind, success status].
     *
     * Kinds: `public` (anyone, also signed out), `reset` (browser sessions, signed in or not),
     * `own` (sessions of every role; the restricted session only where the forced password change
     * may be resolved: `ownPassword`), `ownMailbox` (sessions of mailbox users), `read` and `change`
     * (the mailbox in scope), `adminRead`/`adminChange` (administrators and domain administrators,
     * no mailbox in the path), `filterChange` (the mailbox in scope; mailbox users never).
     *
     * @return array<string, array{string, string, array<string, mixed>|null, string, int}>
     */
    private static function requests(): array
    {
        $m = '/api/v2/mailboxes/anna@example.org';
        $token = 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA';

        return [
            'getSelfServiceCapabilities' => ['GET', '/api/v2/capabilities/self-service', null, 'public', 200],
            'changeOwnPassword' => ['PUT', '/api/v2/me/password', ['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => 'Matrix-N3w!Passw0rd'], 'ownPassword', 200],
            'getOwnPasswordPolicy' => ['GET', '/api/v2/me/password-policy', null, 'ownPassword', 200],
            'listOwnSecondFactors' => ['GET', '/api/v2/me/second-factors', null, 'own', 200],
            'enrolOwnSecondFactor' => ['POST', '/api/v2/me/second-factors', ['type' => 'totp', 'name' => 'Phone', 'code' => '123456', 'currentPassword' => MailcowFactory::PASSWORD], 'own', 400],
            'startOwnTotpEnrolment' => ['POST', '/api/v2/me/second-factors/totp-enrolment', null, 'own', 200],
            'createOwnSecondFactorWebAuthnOptions' => ['POST', '/api/v2/me/second-factors/webauthn-registration-options', null, 'own', 200],
            'removeAllOwnSecondFactors' => ['POST', '/api/v2/me/second-factors/removal', ['currentPassword' => MailcowFactory::PASSWORD], 'own', 204],
            'getOwnSecondFactor' => ['GET', '/api/v2/me/second-factors/999999', null, 'own', 404],
            'deleteOwnSecondFactor' => ['DELETE', '/api/v2/me/second-factors/999999', null, 'own', 404],
            'getOwnRecoveryEmail' => ['GET', '/api/v2/me/recovery-email', null, 'ownMailbox', 200],
            'replaceOwnRecoveryEmail' => ['PUT', '/api/v2/me/recovery-email', ['address' => 'private@example.net', 'currentPassword' => MailcowFactory::PASSWORD], 'ownMailbox', 200],
            'getOwnMailboxOverview' => ['GET', '/api/v2/me/mailbox', null, 'ownMailbox', 200],
            'createWebmailHandOff' => ['POST', '/api/v2/me/webmail-handoff', null, 'ownMailbox', 200],
            'getOwnClientConfiguration' => ['GET', '/api/v2/me/mailbox/client-configuration', null, 'ownMailbox', 200],
            'getOwnAppleProfile' => ['GET', '/api/v2/me/mailbox/apple-profile?variant=email', null, 'ownMailbox', 200],
            'createOwnAppleProfileWithAppPassword' => ['POST', '/api/v2/me/mailbox/apple-profile', ['variant' => 'email'], 'ownMailbox', 201],
            'requestPasswordReset' => ['POST', '/api/v2/password-resets', ['address' => 'nobody@example.org'], 'reset', 200],
            'checkPasswordResetToken' => ['POST', '/api/v2/password-resets/token-check', ['token' => $token], 'reset', 400],
            'createPasswordResetWebAuthnChallenge' => ['POST', '/api/v2/password-resets/webauthn-challenge', ['token' => $token], 'reset', 400],
            'completePasswordReset' => ['POST', '/api/v2/password-resets/completion', ['token' => $token, 'newPassword' => 'Matrix-N3w!Passw0rd'], 'reset', 400],
            'applyMailboxMailSettingsBulkAction' => ['POST', '/api/v2/mailboxes/mail-settings/bulk-actions', ['action' => 'setTlsEnforcement', 'tlsEnforceInbound' => true, 'targets' => [['id' => 'anna@example.org']]], 'adminChange', 200],
            'listAppPasswords' => ['GET', $m.'/app-passwords', null, 'read', 200],
            'createAppPassword' => ['POST', $m.'/app-passwords', ['name' => 'New'], 'change', 201],
            'getAppPassword' => ['GET', $m.'/app-passwords/{appPassword}', null, 'read', 200],
            'updateAppPassword' => ['PATCH', $m.'/app-passwords/{appPassword}', ['name' => 'Renamed'], 'change', 200],
            'deleteAppPassword' => ['DELETE', $m.'/app-passwords/{appPassword}', null, 'change', 204],
            'listTemporaryAliases' => ['GET', $m.'/temporary-aliases', null, 'read', 200],
            'createTemporaryAlias' => ['POST', $m.'/temporary-aliases', ['domain' => 'example.org'], 'change', 201],
            'getTemporaryAlias' => ['GET', $m.'/temporary-aliases/abcdef.ghijkl@example.org', null, 'read', 200],
            'updateTemporaryAlias' => ['PATCH', $m.'/temporary-aliases/abcdef.ghijkl@example.org', ['permanent' => true], 'change', 200],
            'deleteTemporaryAlias' => ['DELETE', $m.'/temporary-aliases/abcdef.ghijkl@example.org', null, 'change', 204],
            'listTemporaryAliasDomains' => ['GET', $m.'/temporary-alias-domains', null, 'read', 200],
            'getPushNotificationSettings' => ['GET', $m.'/push-notifications', null, 'read', 200],
            'replacePushNotificationSettings' => ['PUT', $m.'/push-notifications', ['title' => 'Mail'], 'change', 200],
            'deletePushNotificationSettings' => ['DELETE', $m.'/push-notifications', null, 'change', 204],
            'verifyPushNotificationSettings' => ['POST', $m.'/push-notifications/verification', null, 'change', 200],
            'getMailboxMailSettings' => ['GET', $m.'/mail-settings', null, 'read', 200],
            'updateMailboxMailSettings' => ['PATCH', $m.'/mail-settings', ['taggedMailHandling' => 'subject'], 'change', 200],
            'listRecentLogins' => ['GET', $m.'/recent-logins', null, 'read', 200],
            'clearRecentLogins' => ['DELETE', $m.'/recent-logins', null, 'change', 204],
            'resetActiveSyncCache' => ['DELETE', $m.'/activesync-cache', null, 'change', 204],
            'resetGroupwareProfile' => ['DELETE', $m.'/groupware-profile', null, 'change', 204],
            'listSieveFilters' => ['GET', '/api/v2/sieve-filters', null, 'adminRead', 200],
            'validateSieveScript' => ['POST', '/api/v2/sieve-filters/validation', ['script' => 'keep;'], 'adminRead', 200],
            'listMailboxSieveFilters' => ['GET', $m.'/sieve-filters', null, 'read', 200],
            'createSieveFilter' => ['POST', $m.'/sieve-filters', ['description' => 'New', 'kind' => 'postfilter', 'script' => 'keep;'], 'filterChange', 201],
            'getSieveFilter' => ['GET', $m.'/sieve-filters/{filter}', null, 'read', 200],
            'updateSieveFilter' => ['PATCH', $m.'/sieve-filters/{filter}', ['description' => 'Renamed'], 'filterChange', 200],
            'deleteSieveFilter' => ['DELETE', $m.'/sieve-filters/{filter}', null, 'filterChange', 204],
            'getActiveSieveScript' => ['GET', $m.'/active-sieve-script', null, 'read', 200],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (array_keys(self::requests()) as $operation) {
            foreach (self::CALLERS as $caller) {
                $cases["{$operation} as {$caller}"] = [$operation, $caller];
            }
        }

        return $cases;
    }

    /**
     * @return array{int, string|null} status and problem type name (null: any or none)
     */
    private static function expected(string $operation, string $caller): array
    {
        [$method, , , $kind, $success] = self::requests()[$operation];
        $key = str_ends_with($caller, 'Key');
        $readOnlyKey = str_ends_with($caller, 'ReadOnlyKey');
        $mailboxUser = in_array($caller, ['mailboxSession', 'foreignMailboxSession'], true);
        $foreign = str_starts_with($caller, 'foreign');
        $writes = $method !== 'GET' && $operation !== 'validateSieveScript';

        // A read-only key's change is refused before anything else looks at the route (001).
        if ($readOnlyKey && $writes && $kind !== 'public') {
            return [403, 'insufficient-key-scope'];
        }

        return match ($kind) {
            'public' => [$success, null],
            'reset' => $key ? [403, 'session-required'] : [$success, null],
            'own', 'ownPassword', 'ownMailbox' => match (true) {
                $caller === 'anonymous' => [401, 'unauthenticated'],
                $key => [403, 'session-required'],
                $caller === 'restrictedSession' => $kind === 'ownPassword' ? [$success, null] : [403, 'pending-action-required'],
                $kind === 'ownMailbox' && ! $mailboxUser => [403, 'forbidden'],
                default => [$success, null],
            },
            default => match (true) {
                $caller === 'anonymous' => [401, 'unauthenticated'],
                $caller === 'restrictedSession' => [403, 'pending-action-required'],
                in_array($kind, ['adminRead', 'adminChange'], true) && $mailboxUser => [403, 'forbidden'],
                in_array($kind, ['adminRead', 'adminChange'], true) => [$success, null],
                $foreign => [404, null],
                $kind === 'filterChange' && $mailboxUser => [403, 'forbidden'],
                default => [$success, null],
            },
        };
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function send(string $caller, string $method, string $path, ?array $body): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'restrictedSession' => $this->signInAs('rita@example.org', Role::MailboxUser),
            'adminSession' => $this->signInAs('admin', Role::Admin),
            'domainAdminSession' => $this->signInAs('reseller', Role::DomainAdmin),
            'foreignDomainAdminSession' => $this->signInAs('foreign', Role::DomainAdmin),
            'mailboxSession' => $this->signInAs('anna@example.org', Role::MailboxUser),
            'foreignMailboxSession' => $this->signInAs('dora@example.net', Role::MailboxUser),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET') {
            $headers['X-CSRF-Token'] = $caller === 'anonymous'
                ? (string) $this->getJson('/api/v2/csrf-token')->json('data.token')
                : $this->csrfToken();
        }

        return match (true) {
            $body === null && $method === 'POST' => $this->postEmpty($path, $headers),
            $body === null => $this->json($method, $path, [], $headers),
            $method === 'PATCH' => $this->patchMerge($path, $body, $headers),
            default => $this->json($method, $path, $body, $headers),
        };
    }

    #[DataProvider('cases')]
    public function test_every_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body] = self::requests()[$operation];
        [$status, $type] = self::expected($operation, $caller);
        $path = strtr($path, $this->ids);

        $response = $this->send($caller, $method, $path, $body);

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        } elseif ($status >= 400 && $status !== 404) {
            $this->assertNotContains($response->json('type'), ['/api/v2/problems/forbidden', '/api/v2/problems/unauthenticated', '/api/v2/problems/insufficient-key-scope']);
        }
    }

    public function test_the_matrix_covers_every_operation_of_the_feature(): void
    {
        $this->assertEqualsCanonicalizing(FeatureRegistry::load()->features['005-account-self-service']['operations'], array_keys(self::requests()));
    }
}
