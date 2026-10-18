<?php

namespace Tests\Support\IdentityProvider;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Helpers of feature 012's single sign-on tests: a started single sign-on (its state parsed from
 * the authorization address), its completion, and the template "Staff".
 */
trait SingleSignOnTestHelpers
{
    use IdentityProviderTestHelpers;

    protected const HOST = 'https://mail.example.org';

    /** Values of the template "Staff" (legacy keys, quota in bytes). */
    protected const STAFF = [
        'quota' => 2147483648,
        'tags' => ['staff'],
        'active' => 1,
        'force_pw_update' => 0,
        'force_tfa' => 0,
        'sogo_access' => 1,
        'imap_access' => 1,
        'pop3_access' => 0,
        'smtp_access' => 1,
        'sieve_access' => 1,
        'eas_access' => 0,
        'dav_access' => 1,
        'tls_enforce_in' => 1,
        'tls_enforce_out' => 0,
        'quarantine_notification' => 'daily',
        'quarantine_category' => 'add_header',
        'rl_value' => '',
        'rl_frame' => 's',
        'acl_spam_alias' => 1,
        'acl_tls_policy' => 0,
        'acl_spam_score' => 1,
        'acl_spam_policy' => 1,
        'acl_delimiter_action' => 1,
        'acl_syncjobs' => 0,
        'acl_eas_reset' => 1,
        'acl_sogo_profile_reset' => 0,
        'acl_pushover' => 1,
        'acl_quarantine' => 1,
        'acl_quarantine_attachments' => 1,
        'acl_quarantine_notification' => 1,
        'acl_quarantine_category' => 1,
        'acl_app_passwds' => 1,
    ];

    /**
     * The template "Staff" (and the Default template, which creations combine with it).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function staffTemplate(array $overrides = []): int
    {
        if (! DB::table('templates')->where('type', 'mailbox')->where('template', 'Default')->exists()) {
            $this->mailcow()->defaultMailboxTemplate();
        }

        return $this->mailcow()->mailboxTemplate('Staff', array_merge(self::STAFF, $overrides));
    }

    /**
     * Starts a single sign-on on the host and returns its state, authorization address and answer.
     *
     * @return array{state: string, url: string, query: array<string, mixed>, response: TestResponse<Response>}
     */
    protected function startSso(string $host = self::HOST): array
    {
        $response = $this->postEmpty($host.'/api/v2/session/single-sign-on', ['X-CSRF-Token' => $this->csrfToken()])->assertOk();
        $url = (string) $response->json('data.authorizationUrl');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['state' => (string) ($query['state'] ?? ''), 'url' => $url, 'query' => $query, 'response' => $response];
    }

    /**
     * @return TestResponse<Response>
     */
    protected function completeSso(string $state, string $code = 'provider-code', string $host = self::HOST): TestResponse
    {
        return $this->postJson($host.'/api/v2/session/single-sign-on/completion', ['code' => $code, 'state' => $state], ['X-CSRF-Token' => $this->csrfToken()]);
    }

    /**
     * A configured Keycloak with a provider that issues tokens and the given user details.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function keycloakSignIn(array $claims, ?string $refreshToken = 'provider-refresh-token'): FakeOidcProvider
    {
        return FakeOidcProvider::install()
            ->tokenIssued('authorization_code', 'provider-access-token', $refreshToken)
            ->userInfo($claims);
    }

    /**
     * A mailbox with an authentication source, in an active domain.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function externalMailbox(string $username, string $authsource = 'keycloak', array $attributes = [], int $active = 1, int $domainActive = 1): void
    {
        $this->mailcow()->ensureDomain(explode('@', $username, 2)[1], $domainActive);
        $this->mailcow()->mailbox($username, MailcowRows::LDAP_BIND_PASSWORD, active: $active, authsource: $authsource, attributes: $attributes);
    }

    /**
     * @return list<\stdClass>
     *
     * @phpstan-impure
     */
    protected function signInAudit(): array
    {
        return array_values(array_filter($this->allAudit(), fn (\stdClass $row): bool => $row->event_type === 'signIn'));
    }
}
