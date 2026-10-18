<?php

namespace App\Services\Auth;

use App\Auth\AuthMethod;
use App\Auth\PasswordSource;
use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\Fail2Ban\Fail2BanReporter;
use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Services\IdentityProvider\IdentityProviderConfig;
use App\Services\IdentityProvider\IdentityProviderRepository;
use App\Services\IdentityProvider\MailboxProvisioner;
use App\Services\IdentityProvider\ProviderPasswordVerifier;
use App\Services\IdentityProvider\ProviderVerification;
use App\Services\IdentityProvider\SingleSignOnService;
use App\Support\Hashing\LegacyPasswordVerifier;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Password sign-in, `createSession` (FR-001–FR-008, FR-014, FR-026, FR-027, R12, R16–R18), ported
 * from legacy `check_login()` and the role login functions:
 *
 *  1. refuse a throttled username and address (429, still published to fail2ban and audited),
 *     also when its second-factor count is exhausted (FR-024);
 *  2. try the accounts in legacy order; an unknown account verifies a dummy hash instead;
 *  3. on failure: publish to fail2ban (not for external identity sources), count, delay, audit,
 *     and answer one generic error;
 *  4. on success: reset the count, record a mailbox user's recent login, then either open a
 *     pending sign-in (usable second factors), refuse (only U2F or HOTP active), or re-activate
 *     the account's second factors and sign in (no active factor);
 *  5. pending actions restrict the session (evaluated per request by PendingActionEvaluator).
 *
 * Feature 012 (FR-029 to FR-039, research R8): a mailbox whose password lives in LDAP or in
 * Keycloak (mailpassword flow) is verified by the provider, and a provider user without a mailbox
 * gets one (legacy user_login()'s "user does not exist" branch). Every provider call is made before
 * a database transaction opens. Failures follow FR-033: a wrong password or unknown user is reported
 * to fail2ban and counted; an unavailable provider answers `503 identity-provider-unavailable`, and
 * a password that cannot be verified or a mailbox that cannot be created answer the generic error,
 * neither reported nor counted.
 */
class PasswordSignInService
{
    public function __construct(
        private readonly AccountResolver $accounts,
        private readonly LegacyPasswordVerifier $verifier,
        private readonly SignInThrottle $throttle,
        private readonly Fail2BanReporter $fail2ban,
        private readonly RecentLoginRecorder $recentLogins,
        private readonly SecondFactorService $secondFactors,
        private readonly SessionManager $sessions,
        private readonly PendingActionEvaluator $pendingActions,
        private readonly SignInAudit $audit,
        private readonly IdentityProviderRepository $identityProviders,
        private readonly ProviderPasswordVerifier $providerPasswords,
        private readonly MailboxProvisioner $provisioner,
    ) {}

    public function signIn(Request $request, string $username, string $password, ?Role $requestedRole): void
    {
        $username = strtolower(trim($username));
        $ip = (string) $request->ip();
        $key = $this->throttle->passwordKey($username, $ip);
        $details = ['requestedRole' => $requestedRole?->value, 'step' => 'password'];

        try {
            $this->throttle->assertNotThrottled($key);
            // A correct password does not reset second-factor failures (FR-024): an exhausted
            // second-factor count refuses the password step too, before the password is checked,
            // so that the answer reveals nothing about the password.
            $this->throttle->assertNotThrottled($this->throttle->secondFactorKey($username, $ip));
        } catch (ProblemException $e) {
            // fail2ban sees every attempt, also beyond the hard limit (R17).
            $this->fail2ban->invalidPassword($username, $ip);
            $this->audit->failure($username, $requestedRole, AuthMethod::Password, 'throttled', $details);

            throw $e;
        }

        $result = $this->resolve($request, $username, $password, $requestedRole);

        if ($result['account'] === null) {
            $method = $result['source']->method(false);
            $failureDetails = $details + $result['details'];

            if ($result['unavailable']) {
                // FR-033: an outage is neither a wrong password nor counted.
                $this->audit->failure($username, $requestedRole, $method, 'identityProviderUnavailable', $failureDetails);

                throw SingleSignOnService::unavailable();
            }

            if ($result['fail2ban']) {
                $this->fail2ban->invalidPassword($username, $ip);
            }

            if ($result['count']) {
                $this->throttle->delay($this->throttle->hit($key));
            }

            $this->audit->failure($username, $requestedRole, $method, $result['reason'], $failureDetails);

            throw new ProblemException(ProblemType::SignInFailed);
        }

        $this->throttle->clear($key);
        $this->complete($request, $result['account'], $requestedRole, $result['source'], $result['details']);
    }

    /**
     * The account whose password verified with who verified it and the provider details, or the
     * failure: its reason, whether it is reported to fail2ban and counted, and whether the provider
     * was unavailable.
     *
     * @return array{account: AccountCandidate|null, source: PasswordSource, reason: string, fail2ban: bool, count: bool, unavailable: bool, details: array<string, mixed>}
     */
    private function resolve(Request $request, string $username, #[\SensitiveParameter] string $password, ?Role $requestedRole): array
    {
        $checked = false;
        $external = false;
        $inactive = false;
        $mailboxFound = false;
        $candidates = $this->accounts->resolveForPassword($username, $requestedRole);

        foreach ($candidates as $candidate) {
            $mailboxFound = $mailboxFound || $candidate->role === Role::MailboxUser;

            if ($candidate->status === CandidateStatus::ExternalIdentitySource) {
                $config = $this->identityProviders->current();

                if ($config !== null && $candidate->authsource === $config->type->legacy() && ProviderPasswordVerifier::canVerify($config)) {
                    return $this->viaProvider($request, $config, $candidate, $username, $password);
                }

                $external = true;

                continue;
            }

            $checked = true;

            if (! $this->verifier->verify($candidate->passwordHash, $password)) {
                continue;
            }

            if ($candidate->status === CandidateStatus::Usable) {
                return self::success($candidate, PasswordSource::Mailcow);
            }

            $inactive = true;
        }

        // Legacy user_login(): no mailbox of the username; the provider may know the user (FR-032).
        if (! $mailboxFound && $requestedRole !== Role::Admin && $requestedRole !== Role::DomainAdmin && ! AccountResolver::isMalformed($username)) {
            $config = $this->identityProviders->current();

            if ($config !== null && ProviderPasswordVerifier::canVerify($config)) {
                return $this->viaProvider($request, $config, null, $username, $password);
            }
        }

        if (! $checked) {
            // No password could be checked: equalise the timing of an existing account (FR-005).
            $this->verifier->verify(LegacyPasswordVerifier::DUMMY_HASH, $password);
        }

        $reason = match (true) {
            $inactive => 'accountInactive',
            $external && ! $checked => 'externalIdentitySource',
            default => 'invalidCredentials',
        };

        return self::failure(PasswordSource::Mailcow, $reason, $reason !== 'externalIdentitySource', true);
    }

    /**
     * The provider verifies the password of an existing external mailbox, or of a provider user
     * without a mailbox, who then gets one (FR-029, FR-031 to FR-039).
     *
     * @return array{account: AccountCandidate|null, source: PasswordSource, reason: string, fail2ban: bool, count: bool, unavailable: bool, details: array<string, mixed>}
     */
    private function viaProvider(Request $request, IdentityProviderConfig $config, ?AccountCandidate $candidate, string $username, #[\SensitiveParameter] string $password): array
    {
        $source = PasswordSource::from($config->type->legacy());
        $base = ['identityProvider' => $config->type->value];
        $verification = $this->providerPasswords->verify($config, $username, $password);

        switch ($verification->outcome) {
            case ProviderVerification::UNAVAILABLE:
                return self::failure($source, 'identityProviderUnavailable', false, false, $base, true);
            case ProviderVerification::NOT_VERIFIABLE:
                return self::failure($source, 'passwordNotVerifiable', false, false, $base);
            case ProviderVerification::WRONG_PASSWORD:
            case ProviderVerification::UNKNOWN_USER:
                return self::failure($source, 'invalidCredentials', true, true, $base);
        }

        $requestId = RequestContext::requestId($request);
        $created = false;

        if ($candidate === null) {
            $result = $this->provisioner->provision($config, $username, $verification->mappingValue, $verification->displayName, $requestId);

            if ($result->isFailure()) {
                return self::failure($source, (string) $result->reason, false, false, $base + ($result->cause === null ? [] : ['cause' => $result->cause]));
            }

            $created = $result->isCreated();
        }

        // Legacy's order: the update from the mapped template comes right after the verification,
        // before the status checks and the second-factor step (FR-031).
        $templateApplied = ! $created && $this->provisioner->updateFromTemplate($config, $username, $verification->mappingValue, $verification->displayName, $requestId);
        $mailbox = $this->accounts->findMailboxAccount($username);

        if ($mailbox === null || (string) $mailbox->getAttribute('authsource') !== $config->type->legacy()
            || (int) $mailbox->getAttribute('active') !== 1 || (int) $mailbox->getAttribute('d_active') !== 1) {
            return self::failure($source, 'accountInactive', true, true, $base);
        }

        return self::success(new AccountCandidate($username, Role::MailboxUser, '', CandidateStatus::Usable, $config->type->legacy()), $source, $base + [
            'mailboxCreated' => $created,
            'templateApplied' => $templateApplied,
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{account: AccountCandidate, source: PasswordSource, reason: string, fail2ban: bool, count: bool, unavailable: bool, details: array<string, mixed>}
     */
    private static function success(AccountCandidate $account, PasswordSource $source, array $details = []): array
    {
        return ['account' => $account, 'source' => $source, 'reason' => '', 'fail2ban' => false, 'count' => false, 'unavailable' => false, 'details' => $details];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{account: null, source: PasswordSource, reason: string, fail2ban: bool, count: bool, unavailable: bool, details: array<string, mixed>}
     */
    private static function failure(PasswordSource $source, string $reason, bool $fail2ban, bool $count, array $details = [], bool $unavailable = false): array
    {
        return ['account' => null, 'source' => $source, 'reason' => $reason, 'fail2ban' => $fail2ban, 'count' => $count, 'unavailable' => $unavailable, 'details' => $details];
    }

    /**
     * @param  array<string, mixed>  $providerDetails  feature 012's sign-in audit details
     */
    private function complete(Request $request, AccountCandidate $account, ?Role $requestedRole, PasswordSource $source, array $providerDetails): void
    {
        $session = $request->session();
        $ip = (string) $request->ip();
        $recordRecentLogin = fn () => $account->role === Role::MailboxUser
            ? $this->recentLogins->record($account->username, $ip)
            : null;

        if (! $this->secondFactors->hasActiveEntries($account->username)) {
            $principal = new Principal(
                username: $account->username,
                role: $account->role,
                accountKind: $account->accountKind(),
                authMethod: $source->method(false),
                signedInAt: CarbonImmutable::now('UTC'),
                identityProvider: $source->identityProvider(),
                passwordSource: $source === PasswordSource::Mailcow ? null : $source,
            );

            DB::transaction(function () use ($account, $principal, $requestedRole, $recordRecentLogin, $providerDetails): void {
                $recordRecentLogin();
                // Legacy "disable second factor for the next login": every entry becomes active
                // again (FR-026).
                DB::update("UPDATE `tfa` SET `active` = '1' WHERE `username` = ?", [$account->username]);
                $this->audit->success($principal, [
                    'requestedRole' => $requestedRole?->value,
                    'restricted' => $this->pendingActions->evaluate($principal) !== [],
                ] + $providerDetails);
            });

            $this->sessions->promote($session, $principal, $request->userAgent());
            $this->throttle->clear($this->throttle->secondFactorKey($account->username, $ip));

            return;
        }

        // The password verified, so the recent login is recorded also when a second factor
        // follows, as legacy check_login() does.
        $recordRecentLogin();
        $methods = $this->secondFactors->usableMethods($account->username);

        if ($methods->isEmpty()) {
            $this->audit->failure($account->username, $account->role, $source->method(false), 'secondFactorUnavailable', [
                'requestedRole' => $requestedRole?->value,
                'step' => 'password',
            ] + array_intersect_key($providerDetails, ['identityProvider' => true]));

            throw new ProblemException(ProblemType::SecondFactorUnavailable);
        }

        $this->sessions->beginPending(
            $session,
            $account->username,
            $account->role,
            $account->accountKind(),
            $methods->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            $request->userAgent(),
            $requestedRole,
            $source,
        );
    }
}
