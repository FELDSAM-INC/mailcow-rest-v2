<?php

namespace App\Services\Auth;

use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\Fail2Ban\Fail2BanReporter;
use App\Exceptions\ProblemException;
use App\Support\Hashing\LegacyPasswordVerifier;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Password sign-in, `createSession` (FR-001–FR-008, FR-014, FR-026, FR-027, R12, R16–R18), ported
 * from legacy `check_login()` and the role login functions:
 *
 *  1. refuse a throttled username and address (429, still published to fail2ban and audited);
 *  2. try the accounts in legacy order; an unknown account verifies a dummy hash instead;
 *  3. on failure: publish to fail2ban (not for external identity sources), count, delay, audit,
 *     and answer one generic error;
 *  4. on success: reset the count, record a mailbox user's recent login, then either open a
 *     pending sign-in (usable second factors), refuse (only U2F or HOTP active), or re-activate
 *     the account's second factors and sign in (no active factor);
 *  5. pending actions restrict the session (evaluated per request by PendingActionEvaluator).
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
    ) {}

    public function signIn(Request $request, string $username, string $password, ?Role $requestedRole): void
    {
        $username = strtolower(trim($username));
        $ip = (string) $request->ip();
        $key = $this->throttle->passwordKey($username, $ip);
        $details = ['requestedRole' => $requestedRole?->value, 'step' => 'password'];

        try {
            $this->throttle->assertNotThrottled($key);
        } catch (ProblemException $e) {
            // fail2ban sees every attempt, also beyond the hard limit (R17).
            $this->fail2ban->invalidPassword($username, $ip);
            $this->audit->failure($username, $requestedRole, AuthMethod::Password, 'throttled', $details);

            throw $e;
        }

        [$account, $reason] = $this->resolve($username, $password, $requestedRole);

        if ($account === null) {
            if ($reason !== 'externalIdentitySource') {
                $this->fail2ban->invalidPassword($username, $ip);
            }

            $this->throttle->delay($this->throttle->hit($key));
            $this->audit->failure($username, $requestedRole, AuthMethod::Password, $reason, $details);

            throw new ProblemException(ProblemType::SignInFailed);
        }

        $this->throttle->clear($key);
        $this->complete($request, $account, $requestedRole);
    }

    /**
     * The account whose password verified, or null with the failure reason.
     *
     * @return array{AccountCandidate, null}|array{null, string}
     */
    private function resolve(string $username, string $password, ?Role $requestedRole): array
    {
        $checked = false;
        $external = false;
        $inactive = false;

        foreach ($this->accounts->resolveForPassword($username, $requestedRole) as $candidate) {
            if ($candidate->status === CandidateStatus::ExternalIdentitySource) {
                $external = true;

                continue;
            }

            $checked = true;

            if (! $this->verifier->verify($candidate->passwordHash, $password)) {
                continue;
            }

            if ($candidate->status === CandidateStatus::Usable) {
                return [$candidate, null];
            }

            $inactive = true;
        }

        if (! $checked) {
            // No password could be checked: equalise the timing of an existing account (FR-005).
            $this->verifier->verify(LegacyPasswordVerifier::DUMMY_HASH, $password);
        }

        return [null, match (true) {
            $inactive => 'accountInactive',
            $external && ! $checked => 'externalIdentitySource',
            default => 'invalidCredentials',
        }];
    }

    private function complete(Request $request, AccountCandidate $account, ?Role $requestedRole): void
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
                authMethod: AuthMethod::Password,
                signedInAt: CarbonImmutable::now('UTC'),
            );

            DB::transaction(function () use ($account, $principal, $requestedRole, $recordRecentLogin): void {
                $recordRecentLogin();
                // Legacy "disable second factor for the next login": every entry becomes active
                // again (FR-026).
                DB::update("UPDATE `tfa` SET `active` = '1' WHERE `username` = ?", [$account->username]);
                $this->audit->success($principal, [
                    'requestedRole' => $requestedRole?->value,
                    'restricted' => $this->pendingActions->evaluate($principal) !== [],
                ]);
            });

            $this->sessions->promote($session, $principal, $request->userAgent());

            return;
        }

        // The password verified, so the recent login is recorded also when a second factor
        // follows, as legacy check_login() does.
        $recordRecentLogin();
        $methods = $this->secondFactors->usableMethods($account->username);

        if ($methods->isEmpty()) {
            $this->audit->failure($account->username, $account->role, AuthMethod::Password, 'secondFactorUnavailable', [
                'requestedRole' => $requestedRole?->value,
                'step' => 'password',
            ]);

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
        );
    }
}
