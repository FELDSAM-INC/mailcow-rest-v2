<?php

namespace App\Services\IdentityProvider;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\IdentityProviderType;
use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\Oidc\OidcClient;
use App\Clients\Oidc\OidcEndpoints;
use App\Clients\Oidc\OidcFailure;
use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Services\Auth\AccountResolver;
use App\Services\Auth\RecentLoginRecorder;
use App\Services\Auth\SessionManager;
use App\Services\Auth\SignInAudit;
use App\Support\Http\Base64Url;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Single sign-on of mailbox users through Keycloak or a generic OIDC provider (FR-020 to FR-028,
 * FR-063, research R4, R5), a port of legacy identity_provider('get-redirect') and ('verify-sso')
 * with the spec's deviations: a single-use state bound to the browser session that expires after 10
 * minutes, PKCE (S256), the new web app's return address, the configured type only, a recent-login
 * entry and audit records. Failures are never reported to fail2ban or counted by the sign-in
 * throttle (no password is involved).
 */
class SingleSignOnService
{
    /** The pending single sign-on in the session. */
    public const PENDING = 'restv2.sso';

    public function __construct(
        private readonly IdentityProviderRepository $repository,
        private readonly AppReturnUrls $returnUrls,
        private readonly OidcClient $oidc,
        private readonly MailboxProvisioner $provisioner,
        private readonly AccountResolver $accounts,
        private readonly RecentLoginRecorder $recentLogins,
        private readonly SessionManager $sessions,
        private readonly SsoLinkStore $links,
        private readonly SignInAudit $audit,
    ) {}

    /**
     * Starts a single sign-on and returns the provider's authorization address and the expiry.
     *
     * @return array{authorizationUrl: string, expiresAt: CarbonImmutable}
     */
    public function start(Request $request): array
    {
        $config = $this->usable() ?? throw new ProblemException(ProblemType::SingleSignOnNotAvailable);
        $state = Base64Url::encode(random_bytes(32));
        $returnUrl = $this->returnUrls->forHost((string) $request->getHttpHost());
        $authorization = $this->oidc->authorizationUrl(OidcEndpoints::forConfig($config), $returnUrl, $state);
        $now = CarbonImmutable::now('UTC');
        $expiresAt = $now->addSeconds((int) config('restv2.identity_provider.sso_pending_ttl', 600));

        $request->session()->put(self::PENDING, [
            'stateHash' => hash('sha256', $state),
            'codeVerifier' => $authorization['codeVerifier'],
            'returnUrl' => $returnUrl,
            'type' => $config->type->value,
            'issuedAt' => $now->getTimestamp(),
            'expiresAt' => $expiresAt->getTimestamp(),
        ]);

        return ['authorizationUrl' => $authorization['url'], 'expiresAt' => $expiresAt];
    }

    /**
     * Completes the pending single sign-on with the provider's code and state and signs the
     * mailbox user in.
     */
    public function complete(Request $request, string $code, string $state): void
    {
        $session = $request->session();
        $pending = $this->consume($session, $state);
        $config = $this->usable();

        if ($config === null) {
            $this->fail(null, null, 'singleSignOnNotAvailable', []);

            throw new ProblemException(ProblemType::SingleSignOnNotAvailable);
        }

        $endpoints = OidcEndpoints::forConfig($config);

        try {
            $tokens = $this->oidc->exchangeCode($endpoints, $code, (string) $pending['codeVerifier'], (string) $pending['returnUrl']);
            $details = $this->oidc->userInfo($endpoints, $tokens->accessToken);
        } catch (OidcFailure $e) {
            if ($e->isUnavailable()) {
                $this->fail(null, $config->type, 'identityProviderUnavailable', []);

                throw self::unavailable();
            }

            $this->fail(null, $config->type, 'providerRefused', []);

            throw new ProblemException(ProblemType::SignInFailed);
        }

        $email = self::email($details['email'] ?? null);

        if ($email === null) {
            $this->fail(null, $config->type, 'noEmailAddress', []);

            throw new ProblemException(ProblemType::SignInFailed);
        }

        // Mailbox selection is by address, so the provider must attest to its ownership.
        if (($details['email_verified'] ?? null) !== true) {
            $this->fail($email, $config->type, 'providerRefused', []);

            throw new ProblemException(ProblemType::SignInFailed);
        }

        $mappingValue = self::firstValue($details['mailcow_template'] ?? null);
        $name = is_string($details['name'] ?? null) ? $details['name'] : null;
        $requestId = RequestContext::requestId($request);
        $mailbox = $this->accounts->findMailboxAccount($email);
        $created = false;
        $templateApplied = false;

        if ($mailbox === null) {
            $result = $this->provisioner->provision($config, $email, $mappingValue, $name, $requestId);

            if ($result->isFailure()) {
                $this->fail($email, $config->type, (string) $result->reason, $result->cause === null ? [] : ['cause' => $result->cause]);

                throw new ProblemException(ProblemType::SignInFailed);
            }

            $created = $result->isCreated();
            $mailbox = $this->accounts->findMailboxAccount($email);

            if ($mailbox !== null && ! $created && (string) $mailbox->getAttribute('authsource') === $config->type->legacy()) {
                $templateApplied = $this->provisioner->updateFromTemplate($config, $email, $mappingValue, $name, $requestId);
                $mailbox = $this->accounts->findMailboxAccount($email);
            }
        } elseif ((string) $mailbox->getAttribute('authsource') === $config->type->legacy()) {
            $templateApplied = $this->provisioner->updateFromTemplate($config, $email, $mappingValue, $name, $requestId);
            $mailbox = $this->accounts->findMailboxAccount($email);
        }

        if ($mailbox === null || (string) $mailbox->getAttribute('authsource') !== $config->type->legacy()) {
            $this->fail($email, $config->type, 'mailboxNotLinked', []);

            throw new ProblemException(ProblemType::SignInFailed);
        }

        if ((int) $mailbox->getAttribute('active') !== 1 || (int) $mailbox->getAttribute('d_active') !== 1) {
            $this->fail($email, $config->type, 'accountInactive', []);

            throw new ProblemException(ProblemType::SignInFailed);
        }

        $principal = new Principal(
            username: $email,
            role: Role::MailboxUser,
            accountKind: AccountKind::Mailbox,
            authMethod: AuthMethod::SingleSignOn,
            signedInAt: CarbonImmutable::now('UTC'),
            identityProvider: $config->type,
            ssoLinkId: $this->links->create($tokens, $email, $config->type),
        );

        DB::transaction(function () use ($principal, $email, $request, $config, $created, $templateApplied): void {
            // Deviation: legacy records no recent login for a single sign-on (FR-024).
            $this->recentLogins->record($email, (string) $request->ip());
            $this->audit->success($principal, [
                'requestedRole' => null,
                'identityProvider' => $config->type->value,
                'mailboxCreated' => $created,
                'templateApplied' => $templateApplied,
            ]);
        });

        $this->sessions->promote($session, $principal, $request->userAgent());
    }

    /**
     * The provider returned the browser with an error instead of a code (FR-063).
     */
    public function reportProviderError(Request $request, string $error, string $state): void
    {
        $pending = $this->consume($request->session(), $state);

        $this->fail(null, IdentityProviderType::tryFrom((string) $pending['type']), 'providerReturnedError', ['providerError' => $error]);
    }

    public function cancel(Request $request): void
    {
        $request->session()->forget(self::PENDING);
    }

    public static function unavailable(): ProblemException
    {
        $retryAfter = (string) max(1, (int) config('restv2.identity_provider.retry_after', 30));

        return new ProblemException(ProblemType::IdentityProviderUnavailable, null, [], ['Retry-After' => $retryAfter]);
    }

    /**
     * Removes the pending single sign-on, whatever the outcome, and returns it when the state
     * matches and has not expired; otherwise the attempt is refused (and audited).
     *
     * @return array<string, mixed>
     */
    private function consume(Session $session, string $state): array
    {
        $pending = $session->pull(self::PENDING);

        if (! is_array($pending)
            || (int) ($pending['expiresAt'] ?? 0) <= CarbonImmutable::now()->getTimestamp()
            || ! is_string($pending['stateHash'] ?? null)
            || ! hash_equals($pending['stateHash'], hash('sha256', $state))) {
            $this->fail(null, is_array($pending) ? IdentityProviderType::tryFrom((string) ($pending['type'] ?? '')) : null, 'signInStateInvalid', []);

            throw new ProblemException(ProblemType::SignInStateInvalid);
        }

        return $pending;
    }

    private function usable(): ?IdentityProviderConfig
    {
        $config = $this->repository->usable();

        return $config !== null && $config->type->offersSingleSignOn() ? $config : null;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function fail(?string $email, ?IdentityProviderType $type, string $reason, array $details): void
    {
        $this->audit->failure($email, $email === null ? null : Role::MailboxUser, AuthMethod::SingleSignOn, $reason, ['requestedRole' => null] + ($type === null ? [] : ['identityProvider' => $type->value]) + $details);
    }

    /**
     * The e-mail address of the user details, trimmed and lower-cased, or null when it is missing
     * or not an address.
     */
    public static function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $email = strtolower(trim($value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    /**
     * The first value of a claim that is a string or a list of strings (legacy uses `[0]`).
     */
    public static function firstValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[array_key_first($value)] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
