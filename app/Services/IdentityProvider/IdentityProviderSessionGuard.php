<?php

namespace App\Services\IdentityProvider;

use App\Audit\AuditEventType;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Clients\Oidc\OidcClient;
use App\Clients\Oidc\OidcEndpoints;
use App\Clients\Oidc\OidcFailure;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionManager;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a single sign-on session tied to the provider (FR-026, research R7). On every request in
 * the modes `session` and `authenticated` it checks that the configuration is still of the
 * principal's type and complete, and that the mailbox still authenticates against it. When the
 * provider issued a renewal token and the last renewal is more than 5 minutes ago, it renews the
 * tokens (under a lock, so that parallel requests renew once) and reads the user details again. A
 * refused or failed renewal, another e-mail address, or a failed local check ends the session with
 * `401 identity-provider-session-ended`, audited.
 */
class IdentityProviderSessionGuard
{
    /** Session-end reasons and their audit failure reasons. */
    private const REASONS = [
        'providerRefused' => 'providerRefused',
        'providerUnavailable' => 'identityProviderUnavailable',
        'emailChanged' => 'providerRefused',
        'configurationChanged' => 'singleSignOnNotAvailable',
        'authSourceChanged' => 'mailboxNotLinked',
        'providerLinkMissing' => 'identityProviderUnavailable',
    ];

    public function __construct(
        private readonly IdentityProviderRepository $repository,
        private readonly SsoLinkStore $links,
        private readonly OidcClient $oidc,
        private readonly SessionManager $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function check(Request $request, Principal $principal): void
    {
        if ($principal->authMethod !== AuthMethod::SingleSignOn) {
            return;
        }

        $config = $this->repository->current();

        if ($config === null || $config->type !== $principal->identityProvider || ! $config->isComplete()) {
            $this->end($request, $principal, 'configurationChanged');
        }

        $authsource = DB::table('mailbox')->where('username', $principal->username)->value('authsource');

        if ($authsource !== $config->type->legacy()) {
            $this->end($request, $principal, 'authSourceChanged');
        }

        $linkId = $principal->ssoLinkId;
        $link = $linkId === null ? null : $this->links->get($linkId);

        if ($linkId === null || $link === null) {
            $this->end($request, $principal, 'providerLinkMissing');
        }

        if ($link['refreshToken'] !== null && $this->due($link)) {
            Cache::lock('lock:sso-link:'.$linkId, 10)->block(10, function () use ($request, $principal, $config, $linkId): void {
                $link = $this->links->get($linkId);

                if ($link === null) {
                    $this->end($request, $principal, 'providerLinkMissing');
                }

                if ($link['refreshToken'] === null || ! $this->due($link)) {
                    return;
                }

                $endpoints = OidcEndpoints::forConfig($config);

                try {
                    $tokens = $this->oidc->refresh($endpoints, $link['refreshToken']);
                    $email = SingleSignOnService::email($this->oidc->userInfo($endpoints, $tokens->accessToken)['email'] ?? null);
                } catch (OidcFailure $e) {
                    $this->end($request, $principal, $e->isUnavailable() ? 'providerUnavailable' : 'providerRefused');
                }

                if ($email !== $principal->username) {
                    // Deviation: legacy switches the session to the renewed address.
                    $this->end($request, $principal, 'emailChanged');
                }

                $this->links->put($linkId, [
                    'accessToken' => $tokens->accessToken,
                    // A renewal without a new refresh token keeps the old one (plan parity note 1).
                    'refreshToken' => $tokens->refreshToken ?? $link['refreshToken'],
                    'expiresAt' => $tokens->expiresAt,
                    'lastRenewedAt' => CarbonImmutable::now()->getTimestamp(),
                    'username' => $link['username'],
                    'type' => $link['type'],
                ]);
            });
        }

        $this->links->touch($linkId);
    }

    /**
     * @param  array{lastRenewedAt: int}  $link
     */
    private function due(array $link): bool
    {
        return CarbonImmutable::now()->getTimestamp() - $link['lastRenewedAt'] > (int) config('restv2.identity_provider.sso_renewal_interval', 300);
    }

    private function end(Request $request, Principal $principal, string $reason): never
    {
        DB::transaction(fn () => $this->audit->record(
            AuditEventType::IdentityProviderSessionEnd,
            false,
            $principal,
            ['type' => 'session', 'id' => null, 'name' => null],
            $principal->authMethod->value,
            self::REASONS[$reason],
            ['identityProvider' => $principal->identityProvider?->value, 'reason' => $reason],
        ));

        $this->sessions->end($request->session());

        throw new ProblemException(ProblemType::IdentityProviderSessionEnded);
    }
}
